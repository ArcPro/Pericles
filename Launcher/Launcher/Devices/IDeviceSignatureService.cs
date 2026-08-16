namespace Launcher.Devices;

public interface IDeviceSignatureService
{
    Task<string> SignAsync(ReadOnlyMemory<byte> data, CancellationToken cancellationToken);
}
