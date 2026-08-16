namespace Launcher.Authentication;

public interface IAuthenticationService
{
    Task<AuthSession> LoginAsync(string email, string password, CancellationToken cancellationToken);

    Task LogoutAsync(string accessToken, CancellationToken cancellationToken);
}
