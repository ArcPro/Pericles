namespace Launcher.Devices;

public enum DeviceApiError
{
    DeviceLimitReached,
    DeviceRevoked,
    DeviceClaimed,
    DeviceNotFound,
    PublicKeyMismatch,
    ChallengeExpired,
    ChallengeUsed,
    InvalidSignature,
    InvalidToken,
    Validation,
    Unavailable,
    Timeout,
    ServerError
}

public sealed class DeviceApiException : Exception
{
    public DeviceApiException(DeviceApiError error, string message, Exception? innerException = null)
        : base(message, innerException)
    {
        Error = error;
    }

    public DeviceApiError Error { get; }
}
