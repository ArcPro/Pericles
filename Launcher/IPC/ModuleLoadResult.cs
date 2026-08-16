namespace Launcher.IPC;

public sealed record ModuleLoadResult(string GameSlug, string Version, string Code)
{
    public bool Succeeded => Code is "loaded" or "already_loaded";
}
