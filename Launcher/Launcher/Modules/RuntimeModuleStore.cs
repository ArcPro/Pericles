using System.Diagnostics;
using System.IO;
using System.Security.Cryptography;
using System.Text.Json;
using Launcher.Application;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace Launcher.Modules;

public sealed class RuntimeModuleStore : IRuntimeModuleStore
{
    private readonly string _root;
    private readonly TimeSpan _cleanupAge;
    private readonly ILogger<RuntimeModuleStore> _logger;

    public RuntimeModuleStore(IOptions<LaunchOptions> options, ILogger<RuntimeModuleStore> logger)
    {
        _root = ValidateRuntimeRoot(options.Value.GetRuntimeRoot());
        _cleanupAge = TimeSpan.FromHours(options.Value.RuntimeCleanupAgeHours);
        _logger = logger;
    }

    public async Task<RuntimeModuleHandle> WriteAsync(PreparedModule module, CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(module);
        cancellationToken.ThrowIfCancellationRequested();
        Directory.CreateDirectory(_root);
        string sessionDirectory = System.IO.Path.Combine(_root, Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(sessionDirectory);
        string path = System.IO.Path.Combine(sessionDirectory, "module.dll");
        string temporary = path + ".tmp";
        try
        {
            await using (var stream = new FileStream(
                temporary,
                FileMode.CreateNew,
                FileAccess.Write,
                FileShare.None,
                81920,
                FileOptions.Asynchronous | FileOptions.WriteThrough))
            {
                await stream.WriteAsync(module.Payload, cancellationToken).ConfigureAwait(false);
                await stream.FlushAsync(cancellationToken).ConfigureAwait(false);
                stream.Flush(flushToDisk: true);
            }
            File.Move(temporary, path);
            byte[] actualHash;
            await using (var stream = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.Read))
            {
                actualHash = await SHA256.HashDataAsync(stream, cancellationToken).ConfigureAwait(false);
            }
            byte[] expectedHash;
            try
            {
                expectedHash = Convert.FromHexString(module.Hash);
            }
            catch (FormatException exception)
            {
                throw new RuntimeModuleIntegrityException("The prepared module hash is invalid.", exception);
            }
            if (!CryptographicOperations.FixedTimeEquals(actualHash, expectedHash))
            {
                throw new RuntimeModuleIntegrityException("The runtime module hash does not match the prepared module.");
            }
            var handle = new RuntimeModuleHandle(
                this,
                sessionDirectory,
                path,
                module.GameSlug,
                module.Version,
                module.Hash);
            await WriteMetadataAsync(handle, null, null, cancellationToken).ConfigureAwait(false);
            _logger.LogInformation("Runtime module created for {GameSlug} version {Version}.", module.GameSlug, module.Version);
            return handle;
        }
        catch
        {
            TryDeleteSessionDirectory(sessionDirectory);
            throw;
        }
    }

    public Task MarkInUseAsync(
        RuntimeModuleHandle module,
        ApplicationInstance application,
        CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(module);
        ArgumentNullException.ThrowIfNull(application);
        return WriteMetadataAsync(
            module,
            application.ProcessId,
            application.ProcessStartedAt,
            cancellationToken);
    }

    public async Task CleanupStaleAsync(CancellationToken cancellationToken)
    {
        if (!Directory.Exists(_root)) return;
        DateTimeOffset threshold = DateTimeOffset.UtcNow.Subtract(_cleanupAge);
        foreach (string directory in Directory.EnumerateDirectories(_root, "*", SearchOption.TopDirectoryOnly))
        {
            cancellationToken.ThrowIfCancellationRequested();
            if (!IsDirectChild(directory) || Directory.GetLastWriteTimeUtc(directory) > threshold.UtcDateTime) continue;
            RuntimeMetadata? metadata = await ReadMetadataAsync(directory, cancellationToken).ConfigureAwait(false);
            if (metadata?.ProcessId is int processId
                && metadata.ProcessStartedAt is DateTimeOffset processStartedAt
                && IsSameProcessActive(processId, processStartedAt))
            {
                continue;
            }
            TryDeleteSessionDirectory(directory);
        }
    }

    internal async Task TryReleaseAsync(RuntimeModuleHandle module)
    {
        RuntimeMetadata? metadata = await ReadMetadataAsync(
            module.SessionDirectory,
            CancellationToken.None).ConfigureAwait(false);
        if (metadata?.ProcessId is int processId
            && metadata.ProcessStartedAt is DateTimeOffset processStartedAt
            && IsSameProcessActive(processId, processStartedAt))
        {
            _logger.LogDebug(
                "Runtime module retained while application process {ProcessId} is active.",
                processId);
            return;
        }

        TryDeleteSessionDirectory(module.SessionDirectory);
    }

    private async Task WriteMetadataAsync(
        RuntimeModuleHandle module,
        int? processId,
        DateTimeOffset? processStartedAt,
        CancellationToken cancellationToken)
    {
        var metadata = new RuntimeMetadata(
            module.GameSlug,
            module.Version,
            module.ExpectedSha256,
            DateTimeOffset.UtcNow,
            processId,
            processStartedAt);
        string path = System.IO.Path.Combine(module.SessionDirectory, "runtime.json");
        string temporary = path + ".tmp";
        byte[] json = JsonSerializer.SerializeToUtf8Bytes(metadata, new JsonSerializerOptions(JsonSerializerDefaults.Web));
        await File.WriteAllBytesAsync(temporary, json, cancellationToken).ConfigureAwait(false);
        File.Move(temporary, path, overwrite: true);
    }

    private static async Task<RuntimeMetadata?> ReadMetadataAsync(string directory, CancellationToken cancellationToken)
    {
        string path = System.IO.Path.Combine(directory, "runtime.json");
        if (!File.Exists(path)) return null;
        try
        {
            byte[] json = await File.ReadAllBytesAsync(path, cancellationToken).ConfigureAwait(false);
            return JsonSerializer.Deserialize<RuntimeMetadata>(json, new JsonSerializerOptions(JsonSerializerDefaults.Web));
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException or JsonException)
        {
            return null;
        }
    }

    private bool IsDirectChild(string directory)
    {
        string canonical = System.IO.Path.GetFullPath(directory);
        return string.Equals(Directory.GetParent(canonical)?.FullName, _root, StringComparison.OrdinalIgnoreCase);
    }

    private static bool IsSameProcessActive(int processId, DateTimeOffset expectedStart)
    {
        try
        {
            using Process process = Process.GetProcessById(processId);
            DateTimeOffset actual = new(process.StartTime.ToUniversalTime(), TimeSpan.Zero);
            return !process.HasExited && (actual - expectedStart).Duration() < TimeSpan.FromSeconds(1);
        }
        catch (Exception exception) when (exception is ArgumentException or InvalidOperationException or System.ComponentModel.Win32Exception)
        {
            return false;
        }
    }

    private void TryDeleteSessionDirectory(string directory)
    {
        if (!IsDirectChild(directory)) return;
        try
        {
            FileAttributes attributes = File.GetAttributes(directory);
            if ((attributes & FileAttributes.ReparsePoint) != 0)
            {
                Directory.Delete(directory, recursive: false);
            }
            else
            {
                Directory.Delete(directory, recursive: true);
            }
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            _logger.LogDebug("Runtime module directory retained for cleanup on a later startup.");
        }
    }

    private static string ValidateRuntimeRoot(string configured)
    {
        string allowed = System.IO.Path.GetFullPath(System.IO.Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "Pericles",
            "Runtime")).TrimEnd(System.IO.Path.DirectorySeparatorChar);
        string candidate = System.IO.Path.GetFullPath(configured).TrimEnd(System.IO.Path.DirectorySeparatorChar);
        if (!string.Equals(candidate, allowed, StringComparison.OrdinalIgnoreCase)
            && !candidate.StartsWith(allowed + System.IO.Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidOperationException("The runtime root must stay below the Pericles LocalAppData runtime directory.");
        }
        return candidate;
    }

    private sealed record RuntimeMetadata(
        string GameSlug,
        string Version,
        string Sha256,
        DateTimeOffset CreatedAt,
        int? ProcessId,
        DateTimeOffset? ProcessStartedAt);
}
