using System.Net;
using System.Text;
using System.Text.Json;
using Launcher.Authentication;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Logging.Abstractions;
using Xunit;

namespace Launcher.Tests.Authentication;

public sealed class AuthApiClientTests
{
    private const string SecretToken = "secret-token-that-must-not-be-logged";

    [Fact]
    public async Task LoginRequestSerialization()
    {
        string? requestJson = null;
        var client = CreateClient(async request =>
        {
            requestJson = await request.Content!.ReadAsStringAsync();
            return JsonResponse(HttpStatusCode.OK, LoginJson());
        });

        await client.LoginAsync("user@example.com", "Password123!", CancellationToken.None);

        using JsonDocument document = JsonDocument.Parse(requestJson!);
        Assert.Equal("user@example.com", document.RootElement.GetProperty("email").GetString());
        Assert.Equal("Password123!", document.RootElement.GetProperty("password").GetString());
    }

    [Fact]
    public async Task LoginSuccessBuildsAuthenticatedSessionWithoutASecondRequest()
    {
        var handler = new StubHttpMessageHandler(request =>
        {
            string path = request.RequestUri!.AbsolutePath;
            return Task.FromResult(path.EndsWith("/auth/login", StringComparison.Ordinal)
                ? JsonResponse(HttpStatusCode.OK, LoginJson())
                : JsonResponse(HttpStatusCode.OK, UserJson()));
        });
        var apiClient = new AuthApiClient(CreateHttpClient(handler), NullLogger<AuthApiClient>.Instance);
        var authentication = new ApiAuthenticationService(apiClient, NullLogger<ApiAuthenticationService>.Instance);

        AuthSession session = await authentication.LoginAsync("user@example.com", "Password123!", CancellationToken.None);

        Assert.Equal("42", session.UserId);
        Assert.Equal("user@example.com", session.Email);
        Assert.Equal(SecretToken, session.AccessToken);
        Assert.True(session.ExpiresAt > DateTimeOffset.UtcNow);
        Assert.Equal(1, handler.CallCount);
    }

    [Fact]
    public async Task InvalidCredentialsReturnsTypedError()
    {
        var client = CreateClient(_ => Task.FromResult(JsonResponse(
            HttpStatusCode.Unauthorized,
            "{\"error\":\"invalid_credentials\",\"message\":\"Invalid email or password.\"}")));

        AuthApiException exception = await Assert.ThrowsAsync<AuthApiException>(() =>
            client.LoginAsync("user@example.com", "wrong", CancellationToken.None));

        Assert.Equal(AuthApiError.InvalidCredentials, exception.Error);
    }

    [Fact]
    public async Task ServerUnavailableReturnsTypedError()
    {
        var handler = new StubHttpMessageHandler(_ => throw new HttpRequestException("offline"));
        var client = new AuthApiClient(CreateHttpClient(handler), NullLogger<AuthApiClient>.Instance);

        AuthApiException exception = await Assert.ThrowsAsync<AuthApiException>(() =>
            client.LoginAsync("user@example.com", "Password123!", CancellationToken.None));

        Assert.Equal(AuthApiError.Unavailable, exception.Error);
    }

    [Fact]
    public async Task HostingHtmlRateLimitReturnsTypedError()
    {
        var client = CreateClient(_ => Task.FromResult(new HttpResponseMessage(HttpStatusCode.TooManyRequests)
        {
            Content = new StringContent("<html><body>Rate limited by hosting security.</body></html>", Encoding.UTF8, "text/html")
        }));

        AuthApiException exception = await Assert.ThrowsAsync<AuthApiException>(() =>
            client.LoginAsync("user@example.com", "Password123!", CancellationToken.None));

        Assert.Equal(AuthApiError.RateLimited, exception.Error);
    }

    [Fact]
    public async Task MeWithValidTokenSendsBearerHeader()
    {
        string? authorization = null;
        var client = CreateClient(request =>
        {
            authorization = request.Headers.Authorization?.ToString();
            return Task.FromResult(JsonResponse(HttpStatusCode.OK, UserJson()));
        });

        UserInfo user = await client.GetCurrentUserAsync(SecretToken, CancellationToken.None);

        Assert.Equal("Bearer " + SecretToken, authorization);
        Assert.Equal("user@example.com", user.Email);
    }

    [Fact]
    public async Task AccessTokenIsNotLogged()
    {
        var logger = new CollectingLogger<AuthApiClient>();
        var client = CreateClient(_ => Task.FromResult(JsonResponse(HttpStatusCode.OK, LoginJson())), logger);

        await client.LoginAsync("user@example.com", "Password123!", CancellationToken.None);

        Assert.DoesNotContain(logger.Messages, message => message.Contains(SecretToken, StringComparison.Ordinal));
        Assert.DoesNotContain(logger.Messages, message => message.Contains("Password123!", StringComparison.Ordinal));
    }

    private static AuthApiClient CreateClient(
        Func<HttpRequestMessage, Task<HttpResponseMessage>> responder,
        ILogger<AuthApiClient>? logger = null) =>
        new(CreateHttpClient(new StubHttpMessageHandler(responder)), logger ?? NullLogger<AuthApiClient>.Instance);

    private static HttpClient CreateHttpClient(HttpMessageHandler handler) => new(handler)
    {
        BaseAddress = new Uri("https://events.example.test/public/api/v1/"),
        Timeout = TimeSpan.FromSeconds(5)
    };

    private static HttpResponseMessage JsonResponse(HttpStatusCode status, string json) => new(status)
    {
        Content = new StringContent(json, Encoding.UTF8, "application/json")
    };

    private static string LoginJson() =>
        $"{{\"access_token\":\"{SecretToken}\",\"token_type\":\"Bearer\",\"expires_in\":3600,\"user\":{UserJson()}}}";

    private static string UserJson() =>
        "{\"id\":\"42\",\"email\":\"user@example.com\",\"status\":\"active\"}";
}

internal sealed class StubHttpMessageHandler(
    Func<HttpRequestMessage, Task<HttpResponseMessage>> responder) : HttpMessageHandler
{
    public int CallCount { get; private set; }

    protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        CallCount++;
        return responder(request);
    }
}

internal sealed class CollectingLogger<T> : ILogger<T>
{
    public List<string> Messages { get; } = [];

    public IDisposable? BeginScope<TState>(TState state) where TState : notnull => null;

    public bool IsEnabled(LogLevel logLevel) => true;

    public void Log<TState>(
        LogLevel logLevel,
        EventId eventId,
        TState state,
        Exception? exception,
        Func<TState, Exception?, string> formatter) => Messages.Add(formatter(state, exception));
}
