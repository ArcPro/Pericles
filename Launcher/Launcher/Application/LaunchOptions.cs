using System.IO;

namespace Launcher.Application;

public sealed class LaunchOptions
{
    public const string SectionName = "Launch";
    public int GameStartTimeoutSeconds { get; init; } = 60;
    public int ApplicationStartTimeoutSeconds { get; init; } = 15;
    public int IpcConnectTimeoutSeconds { get; init; } = 10;
    public int ModuleLoadTimeoutSeconds { get; init; } = 10;
    public int RuntimeCleanupAgeHours { get; init; } = 24;
    public string RuntimeRoot { get; init; } = "";

    public string GetRuntimeRoot() => string.IsNullOrWhiteSpace(RuntimeRoot)
        ? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Pericles", "Runtime")
        : Environment.ExpandEnvironmentVariables(RuntimeRoot);
}
