namespace Launcher.Modules;

public sealed class RuntimeModuleHandle : IAsyncDisposable
{
    private readonly RuntimeModuleStore _owner;
    private bool _disposed;

    internal RuntimeModuleHandle(
        RuntimeModuleStore owner,
        string sessionDirectory,
        string path,
        string gameSlug,
        string version,
        string expectedSha256)
    {
        _owner = owner;
        SessionDirectory = sessionDirectory;
        Path = path;
        GameSlug = gameSlug;
        Version = version;
        ExpectedSha256 = expectedSha256;
    }

    internal string SessionDirectory { get; }
    public string Path { get; }
    public string GameSlug { get; }
    public string Version { get; }
    public string ExpectedSha256 { get; }

    public async ValueTask DisposeAsync()
    {
        if (_disposed) return;
        _disposed = true;
        await _owner.TryReleaseAsync(this).ConfigureAwait(false);
    }
}
