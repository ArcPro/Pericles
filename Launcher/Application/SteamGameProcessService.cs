using System.ComponentModel;
using System.Diagnostics;
using System.IO;
using System.Text.RegularExpressions;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Microsoft.Win32;

namespace Launcher.Application;

public sealed partial class SteamGameProcessService(
    IOptions<LaunchOptions> options,
    ILogger<SteamGameProcessService> logger) : IGameProcessService
{
    public async Task<GameProcessInstance> EnsureRunningAsync(
        GameApplicationDefinition game,
        CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(game);
        IReadOnlyList<string> executablePaths = FindInstalledExecutables(game);
        if (executablePaths.Count == 0)
        {
            throw new GameInstallationNotFoundException();
        }

        GameProcessInstance? existing = FindRunningProcess(game, executablePaths, wasStarted: false);
        if (existing is not null)
        {
            logger.LogInformation("Steam game {GameSlug} is already running as process {ProcessId}.", game.GameSlug, existing.ProcessId);
            return existing;
        }

        try
        {
            Process.Start(new ProcessStartInfo($"steam://rungameid/{game.SteamAppId}")
            {
                UseShellExecute = true
            })?.Dispose();
        }
        catch (Exception exception) when (exception is InvalidOperationException or Win32Exception)
        {
            throw new GameStartException("Steam could not start the configured game.", exception);
        }

        DateTimeOffset deadline = DateTimeOffset.UtcNow.AddSeconds(options.Value.GameStartTimeoutSeconds);
        while (DateTimeOffset.UtcNow < deadline)
        {
            cancellationToken.ThrowIfCancellationRequested();
            GameProcessInstance? started = FindRunningProcess(game, executablePaths, wasStarted: true);
            if (started is not null)
            {
                logger.LogInformation("Steam game {GameSlug} started as process {ProcessId}.", game.GameSlug, started.ProcessId);
                return started;
            }
            await Task.Delay(500, cancellationToken).ConfigureAwait(false);
        }

        throw new GameStartTimeoutException();
    }

    private static IReadOnlyList<string> FindInstalledExecutables(GameApplicationDefinition game)
    {
        var paths = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (string steamRoot in GetSteamRoots())
        {
            foreach (string libraryRoot in GetLibraryRoots(steamRoot))
            {
                string manifestPath = Path.Combine(libraryRoot, "steamapps", $"appmanifest_{game.SteamAppId}.acf");
                if (!File.Exists(manifestPath)) continue;
                string? installDirectory = ReadInstallDirectory(manifestPath);
                if (installDirectory is null) continue;
                string gameRoot = Path.GetFullPath(Path.Combine(libraryRoot, "steamapps", "common", installDirectory));
                foreach (string relativePath in game.GameExecutableRelativePaths)
                {
                    string candidate = Path.GetFullPath(Path.Combine(gameRoot, relativePath));
                    if (candidate.StartsWith(gameRoot + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase)
                        && File.Exists(candidate))
                    {
                        paths.Add(candidate);
                    }
                }
            }
        }
        return paths.ToArray();
    }

    private static IEnumerable<string> GetSteamRoots()
    {
        var roots = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        AddRegistryPath(roots, Registry.CurrentUser.OpenSubKey(@"Software\Valve\Steam"), "SteamPath");
        using (RegistryKey baseKey = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry32))
        {
            AddRegistryPath(roots, baseKey.OpenSubKey(@"Software\Valve\Steam"), "InstallPath");
        }
        string programFilesX86 = Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86);
        if (programFilesX86.Length > 0) roots.Add(Path.Combine(programFilesX86, "Steam"));
        return roots.Where(Directory.Exists).Select(Path.GetFullPath);
    }

    private static void AddRegistryPath(HashSet<string> roots, RegistryKey? key, string valueName)
    {
        using (key)
        {
            if (key?.GetValue(valueName) is string value && !string.IsNullOrWhiteSpace(value))
            {
                roots.Add(Environment.ExpandEnvironmentVariables(value));
            }
        }
    }

    private static IEnumerable<string> GetLibraryRoots(string steamRoot)
    {
        var roots = new HashSet<string>(StringComparer.OrdinalIgnoreCase) { steamRoot };
        string librariesPath = Path.Combine(steamRoot, "steamapps", "libraryfolders.vdf");
        if (!File.Exists(librariesPath)) return roots;
        try
        {
            foreach (Match match in VdfPathRegex().Matches(File.ReadAllText(librariesPath)))
            {
                string path = match.Groups[1].Value.Replace(@"\\", @"\");
                if (Directory.Exists(path)) roots.Add(Path.GetFullPath(path));
            }
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            // The primary Steam library can still be checked if the VDF is temporarily locked.
        }
        return roots;
    }

    private static string? ReadInstallDirectory(string manifestPath)
    {
        try
        {
            Match match = InstallDirectoryRegex().Match(File.ReadAllText(manifestPath));
            if (!match.Success) return null;
            string value = match.Groups[1].Value.Trim();
            return value.Length > 0
                && !Path.IsPathFullyQualified(value)
                && string.Equals(Path.GetFileName(value), value, StringComparison.Ordinal)
                && value is not "." and not ".."
                    ? value
                    : null;
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            return null;
        }
    }

    private static GameProcessInstance? FindRunningProcess(
        GameApplicationDefinition game,
        IReadOnlyList<string> expectedPaths,
        bool wasStarted)
    {
        foreach (string processName in game.GameProcessNames)
        {
            foreach (Process process in Process.GetProcessesByName(processName))
            {
                using (process)
                {
                    try
                    {
                        if (process.HasExited) continue;
                        string actualPath = Path.GetFullPath(process.MainModule?.FileName ?? "");
                        if (!expectedPaths.Contains(actualPath, StringComparer.OrdinalIgnoreCase)) continue;
                        return new GameProcessInstance(
                            actualPath,
                            process.Id,
                            new DateTimeOffset(process.StartTime.ToUniversalTime(), TimeSpan.Zero),
                            wasStarted);
                    }
                    catch (Exception exception) when (exception is InvalidOperationException or Win32Exception)
                    {
                        // Ignore an exited or inaccessible candidate; never accept it without path validation.
                    }
                }
            }
        }
        return null;
    }

    [GeneratedRegex("\\\"path\\\"\\s+\\\"([^\\\"]+)\\\"", RegexOptions.IgnoreCase)]
    private static partial Regex VdfPathRegex();

    [GeneratedRegex("\\\"installdir\\\"\\s+\\\"([^\\\"]+)\\\"", RegexOptions.IgnoreCase)]
    private static partial Regex InstallDirectoryRegex();
}
