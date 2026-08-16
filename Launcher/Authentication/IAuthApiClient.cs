namespace Launcher.Authentication;

public interface IAuthApiClient
{
    Task<LoginResponse> LoginAsync(string email, string password, CancellationToken cancellationToken);

    Task<UserInfo> GetCurrentUserAsync(string accessToken, CancellationToken cancellationToken);

    Task LogoutAsync(string accessToken, CancellationToken cancellationToken);
}
