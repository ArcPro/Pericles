using Microsoft.Extensions.Logging;

namespace Launcher.Authentication;

public sealed class ApiAuthenticationService(
    IAuthApiClient apiClient,
    ILogger<ApiAuthenticationService> logger) : IAuthenticationService
{
    public async Task<AuthSession> LoginAsync(
        string email,
        string password,
        CancellationToken cancellationToken)
    {
        LoginResponse login = await apiClient.LoginAsync(email, password, cancellationToken);
        UserInfo user = login.User;
        var session = new AuthSession(
            user.Id,
            user.Email,
            login.AccessToken,
            DateTimeOffset.UtcNow.AddSeconds(login.ExpiresIn));

        logger.LogInformation("Authenticated session established for user {UserId}.", user.Id);
        return session;
    }

    public Task LogoutAsync(string accessToken, CancellationToken cancellationToken) =>
        apiClient.LogoutAsync(accessToken, cancellationToken);
}
