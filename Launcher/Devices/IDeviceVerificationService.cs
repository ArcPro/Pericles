using Launcher.Authentication;

namespace Launcher.Devices;

public interface IDeviceVerificationService
{
    Task<DeviceSession> VerifyCurrentDeviceAsync(AuthSession authSession, CancellationToken cancellationToken);

    Task<IReadOnlyList<DeviceInfo>> RefreshDevicesAsync(AuthSession authSession, CancellationToken cancellationToken);

    Task RevokeDeviceAsync(AuthSession authSession, string deviceId, CancellationToken cancellationToken);
}
