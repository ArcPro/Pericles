using System.Security.Cryptography;

namespace Launcher.Modules;

public sealed class PreparedModule : IDisposable
{
    private bool _disposed;

    public PreparedModule(string gameSlug, string moduleSlug, string version, byte[] payload, string hash)
    {
        GameSlug = gameSlug;
        ModuleSlug = moduleSlug;
        Version = version;
        Payload = payload;
        Hash = hash;
    }

    public string GameSlug { get; }
    public string ModuleSlug { get; }
    public string Version { get; }
    public byte[] Payload { get; }
    public string Hash { get; }

    public void Dispose()
    {
        if (_disposed) return;
        CryptographicOperations.ZeroMemory(Payload);
        _disposed = true;
    }
}
