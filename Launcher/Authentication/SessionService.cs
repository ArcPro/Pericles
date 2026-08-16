namespace Launcher.Authentication;

public sealed class SessionService(IAuthenticationService authenticationService) : ISessionService
{
    public AuthSession? CurrentSession { get; private set; }

    public bool IsAuthenticated => CurrentSession is not null && CurrentSession.ExpiresAt > DateTimeOffset.UtcNow;

    public async Task<AuthSession> LoginAsync(
        string email,
        string password,
        CancellationToken cancellationToken)
    {
        AuthSession session = await authenticationService.LoginAsync(email, password, cancellationToken);
        CurrentSession = session;
        return session;
    }

    public async Task LogoutAsync(CancellationToken cancellationToken)
    {
        AuthSession? session = CurrentSession;
        try
        {
            if (session is not null)
            {
                await authenticationService.LogoutAsync(session.AccessToken, cancellationToken);
            }
        }
        finally
        {
            CurrentSession = null;
        }
    }

    public void ClearLocalSession() => CurrentSession = null;
}
