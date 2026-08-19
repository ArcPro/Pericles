using System.Diagnostics;
using System.IO;
using System.Security.Cryptography;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Pericles.ApplicationSdk;

namespace Launcher.Application;

public sealed class TargetApplicationService(
    IOptions<LaunchOptions> options,
    ILogger<TargetApplicationService> logger) : ITargetApplicationService, IDisposable
{
    private readonly Dictionary<string, ManagedSession> _sessions = new(StringComparer.Ordinal);
    private readonly object _gate = new();

    public async Task<ApplicationInstance> EnsureRunningAsync(
        GameApplicationDefinition game,
        CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(game);
        string expectedPath = Path.GetFullPath(game.ApplicationPath);
        if (!File.Exists(expectedPath))
        {
            throw new ApplicationNotFoundException();
        }

        ManagedSession? managed;
        lock (_gate)
        {
            _sessions.TryGetValue(game.GameSlug, out managed);
        }
        if (managed is not null)
        {
            ApplicationInstance? reusable = TryReuseManagedSession(managed, expectedPath);
            if (reusable is not null)
            {
                logger.LogInformation("Reusing the launcher-authenticated application process {ProcessId}.", reusable.ProcessId);
                return reusable;
            }
            RemoveSession(game.GameSlug, managed);
        }

        foreach (Process candidate in Process.GetProcessesByName(game.ProcessName))
        {
            using (candidate)
            {
                if (game.IsSelfHosted && candidate.Id == Environment.ProcessId)
                {
                    continue;
                }
                if (IsMatchingActiveProcess(candidate, expectedPath))
                {
                    throw new ApplicationSessionUnavailableException();
                }
            }
        }

        logger.LogInformation("Starting the configured target application.");
        string sessionNonce = Base64Url(RandomNumberGenerator.GetBytes(32));
        byte[] sessionSecret = RandomNumberGenerator.GetBytes(32);
        Process? process;
        try
        {
            var startInfo = new ProcessStartInfo(expectedPath)
            {
                UseShellExecute = false,
                WorkingDirectory = Path.GetDirectoryName(expectedPath)!
            };
            if (game.IsSelfHosted)
            {
                startInfo.ArgumentList.Add(ModuleHostMode.ModeArgument);
                startInfo.ArgumentList.Add("--game");
                startInfo.ArgumentList.Add(game.GameSlug);
                startInfo.ArgumentList.Add("--identity");
                startInfo.ArgumentList.Add(game.ApplicationIdentity);
                startInfo.ArgumentList.Add("--pipe");
                startInfo.ArgumentList.Add(game.PipeName);
                startInfo.ArgumentList.Add("--parent-pid");
                startInfo.ArgumentList.Add(Environment.ProcessId.ToString(System.Globalization.CultureInfo.InvariantCulture));
            }
#if !DEBUG
            startInfo.CreateNoWindow = true;
            startInfo.WindowStyle = ProcessWindowStyle.Hidden;
#endif
            startInfo.Environment[PericlesModuleHostOptions.SessionNonceEnvironmentVariable] = sessionNonce;
            startInfo.Environment[PericlesModuleHostOptions.SessionSecretEnvironmentVariable] = Base64Url(sessionSecret);
            startInfo.Environment[PericlesModuleHostOptions.RuntimeRootEnvironmentVariable] = options.Value.GetRuntimeRoot();
            process = Process.Start(startInfo);
        }
        catch (Exception exception) when (exception is ArgumentException or InvalidOperationException or System.ComponentModel.Win32Exception)
        {
            CryptographicOperations.ZeroMemory(sessionSecret);
            throw new ApplicationStartException("The configured application could not be started.", exception);
        }
        if (process is null)
        {
            CryptographicOperations.ZeroMemory(sessionSecret);
            throw new ApplicationStartException("The configured application could not be started.");
        }
        bool secretRetained = false;
        try
        {
            using (process)
            {
                DateTimeOffset deadline = DateTimeOffset.UtcNow.AddSeconds(options.Value.ApplicationStartTimeoutSeconds);
                while (DateTimeOffset.UtcNow < deadline)
                {
                    cancellationToken.ThrowIfCancellationRequested();
                    if (process.HasExited)
                    {
                        throw new ApplicationCrashedException();
                    }
                    try
                    {
                        string? actualPath = GetProcessPath(process);
                        if (string.Equals(actualPath, expectedPath, StringComparison.OrdinalIgnoreCase))
                        {
                            logger.LogInformation("Configured target application started as process {ProcessId}.", process.Id);
                            var session = new ManagedSession(
                                game.GameSlug,
                                expectedPath,
                                process.Id,
                                new DateTimeOffset(process.StartTime.ToUniversalTime(), TimeSpan.Zero),
                                sessionNonce,
                                sessionSecret);
                            lock (_gate)
                            {
                                _sessions[game.GameSlug] = session;
                            }
                            secretRetained = true;
                            return CreateInstance(session, true);
                        }
                    }
                    catch (Exception exception) when (exception is ArgumentException or InvalidOperationException or System.ComponentModel.Win32Exception)
                    {
                        // Process metadata can be temporarily unavailable immediately after start.
                    }
                    await Task.Delay(100, cancellationToken).ConfigureAwait(false);
                }
            }
            throw new ApplicationStartException("The configured application did not become ready in time.");
        }
        finally
        {
            if (!secretRetained) CryptographicOperations.ZeroMemory(sessionSecret);
        }
    }

    public void Dispose()
    {
        lock (_gate)
        {
            foreach (ManagedSession session in _sessions.Values)
            {
                CryptographicOperations.ZeroMemory(session.Secret);
            }
            _sessions.Clear();
        }
    }

    private static ApplicationInstance? TryReuseManagedSession(ManagedSession session, string expectedPath)
    {
        try
        {
            using Process process = Process.GetProcessById(session.ProcessId);
            DateTimeOffset startedAt = new(process.StartTime.ToUniversalTime(), TimeSpan.Zero);
            if (process.HasExited
                || startedAt != session.StartedAt
                || !string.Equals(GetProcessPath(process), expectedPath, StringComparison.OrdinalIgnoreCase))
            {
                return null;
            }
            return CreateInstance(session, false);
        }
        catch (Exception exception) when (exception is ArgumentException or InvalidOperationException or System.ComponentModel.Win32Exception)
        {
            return null;
        }
    }

    private static bool IsMatchingActiveProcess(Process process, string expectedPath)
    {
        try
        {
            return !process.HasExited
                && string.Equals(GetProcessPath(process), expectedPath, StringComparison.OrdinalIgnoreCase);
        }
        catch (Exception exception) when (exception is ArgumentException or InvalidOperationException or System.ComponentModel.Win32Exception)
        {
            // An inaccessible or exited process is not a compatible configured instance.
            return false;
        }
    }

    private static string? GetProcessPath(Process process)
    {
        string? path = process.MainModule?.FileName;
        return string.IsNullOrWhiteSpace(path) ? null : Path.GetFullPath(path);
    }

    private void RemoveSession(string gameSlug, ManagedSession expected)
    {
        lock (_gate)
        {
            if (_sessions.TryGetValue(gameSlug, out ManagedSession? current) && ReferenceEquals(current, expected))
            {
                _sessions.Remove(gameSlug);
                CryptographicOperations.ZeroMemory(current.Secret);
            }
        }
    }

    private static ApplicationInstance CreateInstance(ManagedSession session, bool started) => new(
        session.ExecutablePath,
        session.ProcessId,
        session.StartedAt,
        started,
        session.Nonce,
        session.Secret.ToArray());

    private static string Base64Url(byte[] value) => Convert.ToBase64String(value)
        .TrimEnd('=')
        .Replace('+', '-')
        .Replace('/', '_');

    private sealed record ManagedSession(
        string GameSlug,
        string ExecutablePath,
        int ProcessId,
        DateTimeOffset StartedAt,
        string Nonce,
        byte[] Secret);
}
