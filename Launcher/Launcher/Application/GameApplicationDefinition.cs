namespace Launcher.Application;

public sealed record GameApplicationDefinition(
    string GameSlug,
    uint SteamAppId,
    IReadOnlyList<string> GameProcessNames,
    IReadOnlyList<string> GameExecutableRelativePaths,
    string ApplicationIdentity,
    string ApplicationPath,
    string ProcessName,
    string PipeName,
    string? EntryPoint,
    bool IsSelfHosted = false);

public sealed record ApplicationInstance(
    string ExecutablePath,
    int ProcessId,
    DateTimeOffset ProcessStartedAt,
    bool WasStarted,
    string IpcSessionNonce,
    byte[] IpcSessionSecret);

public sealed record GameProcessInstance(
    string ExecutablePath,
    int ProcessId,
    DateTimeOffset ProcessStartedAt,
    bool WasStarted);
