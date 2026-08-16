namespace Launcher.Authentication;

public interface ISessionService
{
    AuthSession? CurrentSession { get; }

    bool IsAuthenticated { get; }

    Task<AuthSession> LoginAsync(string email, string password, CancellationToken cancellationToken);

    Task LogoutAsync(CancellationToken cancellationToken);

    void ClearLocalSession();
}
