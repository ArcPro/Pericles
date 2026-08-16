using Launcher.Authentication;
using Xunit;

namespace Launcher.Tests.Authentication;

public sealed class SessionServiceTests
{
    [Fact]
    public async Task LogoutClearsSession()
    {
        var authentication = new FakeAuthenticationService();
        var sessions = new SessionService(authentication);
        await sessions.LoginAsync("user@example.com", "Password123!", CancellationToken.None);

        await sessions.LogoutAsync(CancellationToken.None);

        Assert.False(sessions.IsAuthenticated);
        Assert.Null(sessions.CurrentSession);
        Assert.Equal("test-token", authentication.LoggedOutToken);
    }

    [Fact]
    public async Task LogoutClearsSessionWhenServerIsUnavailable()
    {
        var authentication = new FakeAuthenticationService { FailLogout = true };
        var sessions = new SessionService(authentication);
        await sessions.LoginAsync("user@example.com", "Password123!", CancellationToken.None);

        await Assert.ThrowsAsync<AuthApiException>(() => sessions.LogoutAsync(CancellationToken.None));

        Assert.False(sessions.IsAuthenticated);
        Assert.Null(sessions.CurrentSession);
    }

    private sealed class FakeAuthenticationService : IAuthenticationService
    {
        public bool FailLogout { get; init; }

        public string? LoggedOutToken { get; private set; }

        public Task<AuthSession> LoginAsync(string email, string password, CancellationToken cancellationToken) =>
            Task.FromResult(new AuthSession("42", email, "test-token", DateTimeOffset.UtcNow.AddHours(1)));

        public Task LogoutAsync(string accessToken, CancellationToken cancellationToken)
        {
            LoggedOutToken = accessToken;
            return FailLogout
                ? Task.FromException(new AuthApiException(AuthApiError.Unavailable, "offline"))
                : Task.CompletedTask;
        }
    }
}
