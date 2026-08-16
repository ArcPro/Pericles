namespace Launcher.Devices;

public sealed class DeviceIdentityCorruptedException : Exception
{
    public DeviceIdentityCorruptedException(string message, Exception? innerException = null)
        : base(message, innerException)
    {
    }
}
