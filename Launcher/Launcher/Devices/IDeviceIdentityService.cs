namespace Launcher.Devices;

public interface IDeviceIdentityService
{
    Task<DeviceIdentity> GetOrCreateAsync(CancellationToken cancellationToken);
}
