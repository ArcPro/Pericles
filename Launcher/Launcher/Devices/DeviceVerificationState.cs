namespace Launcher.Devices;

public sealed class DeviceVerificationState
{
    public DeviceSession? CurrentSession { get; private set; }

    public bool DeviceVerified => CurrentSession?.DeviceVerified == true;

    internal void SetVerified(DeviceSession session) => CurrentSession = session;

    public void Clear() => CurrentSession = null;
}
