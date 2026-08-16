namespace Launcher.Devices;

public interface IDeviceApiClient
{
    Task<DeviceRegistrationResponse> RegisterAsync(
        RegisterDeviceRequest request,
        string accessToken,
        CancellationToken cancellationToken);

    Task<DeviceChallengeResponse> RequestChallengeAsync(
        string deviceId,
        string accessToken,
        CancellationToken cancellationToken);

    Task<DeviceVerificationResponse> VerifyAsync(
        VerifyDeviceRequest request,
        string accessToken,
        CancellationToken cancellationToken);

    Task<IReadOnlyList<DeviceInfo>> GetDevicesAsync(
        string currentDeviceId,
        string accessToken,
        CancellationToken cancellationToken);

    Task RevokeAsync(string deviceId, string accessToken, CancellationToken cancellationToken);
}
