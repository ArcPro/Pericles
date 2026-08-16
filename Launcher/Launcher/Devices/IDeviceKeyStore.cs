namespace Launcher.Devices;

public interface IDeviceKeyStore
{
    Task<bool> ExistsAsync(CancellationToken cancellationToken);

    Task SaveAsync(byte[] privateKey, CancellationToken cancellationToken);

    Task<byte[]> LoadAsync(CancellationToken cancellationToken);
}
