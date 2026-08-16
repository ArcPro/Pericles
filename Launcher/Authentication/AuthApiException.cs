namespace Launcher.Authentication;

public enum AuthApiError
{
    InvalidCredentials,
    AccountDisabled,
    RateLimited,
    InvalidToken,
    TokenExpired,
    Validation,
    Unavailable,
    Timeout,
    ServerError
}

public sealed class AuthApiException : Exception
{
    public AuthApiException(AuthApiError error, string message, Exception? innerException = null)
        : base(message, innerException)
    {
        Error = error;
    }

    public AuthApiError Error { get; }
}
