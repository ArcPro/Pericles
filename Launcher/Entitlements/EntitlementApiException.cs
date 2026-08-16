namespace Launcher.Entitlements;

public enum EntitlementApiError
{
    InvalidKey,
    KeyUsed,
    KeyExpired,
    KeyRevoked,
    AlreadyLifetime,
    RateLimited,
    SubscriptionRequired,
    BoundElsewhere,
    DeviceVerificationRequired,
    InvalidToken,
    Validation,
    Timeout,
    Unavailable,
    ServerError
}

public sealed class EntitlementApiException(
    EntitlementApiError error,
    string message,
    Exception? innerException = null) : Exception(message, innerException)
{
    public EntitlementApiError Error { get; } = error;
}
