using System.IO;

namespace Launcher.Devices;

public sealed class DeviceOptions
{
    public const string SectionName = "Device";

    public string? StorageDirectory { get; init; }

    public string MutexName { get; init; } = @"Local\Pericles.DeviceIdentity.v1";

    public int ChallengeTimeoutSeconds { get; init; } = 60;

    public string ResolveStorageDirectory() => string.IsNullOrWhiteSpace(StorageDirectory)
        ? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Pericles")
        : Path.GetFullPath(Environment.ExpandEnvironmentVariables(StorageDirectory));
}
