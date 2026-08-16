namespace Launcher.Authentication;

public sealed record AuthSession(
    string UserId,
    string Email,
    string AccessToken,
    DateTimeOffset ExpiresAt);
