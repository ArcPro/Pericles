namespace Launcher.Devices;

public sealed record DeviceSession(
    DeviceIdentity Identity,
    DateTimeOffset VerifiedAt,
    IReadOnlyList<DeviceInfo> Devices)
{
    public bool DeviceVerified => true;
}
