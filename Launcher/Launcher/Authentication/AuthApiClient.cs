using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.Extensions.Logging;

namespace Launcher.Authentication;

public sealed class AuthApiClient(HttpClient httpClient, ILogger<AuthApiClient> logger) : IAuthApiClient
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);

    public async Task<LoginResponse> LoginAsync(
        string email,
        string password,
        CancellationToken cancellationToken)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(email);
        ArgumentException.ThrowIfNullOrWhiteSpace(password);

        logger.LogInformation("Login request started.");
        using var request = new HttpRequestMessage(HttpMethod.Post, "auth/login")
        {
            Content = JsonContent.Create(new LoginRequest(email, password), options: JsonOptions)
        };
        using HttpResponseMessage response = await SendAsync(request, cancellationToken);
        await EnsureSuccessAsync(response, cancellationToken);

        LoginResponse? result;
        try
        {
            result = await response.Content.ReadFromJsonAsync<LoginResponse>(JsonOptions, cancellationToken);
        }
        catch (Exception exception) when (exception is JsonException or NotSupportedException)
        {
            throw new AuthApiException(AuthApiError.ServerError, "The server returned an invalid login response.", exception);
        }
        if (result is null || string.IsNullOrWhiteSpace(result.AccessToken) || result.User is null)
        {
            throw new AuthApiException(AuthApiError.ServerError, "The server returned an invalid login response.");
        }

        logger.LogInformation("Login successful for user {UserId}.", result.User.Id);
        return result;
    }

    public async Task<UserInfo> GetCurrentUserAsync(string accessToken, CancellationToken cancellationToken)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(accessToken);
        using var request = CreateAuthenticatedRequest(HttpMethod.Get, "me", accessToken);
        using HttpResponseMessage response = await SendAsync(request, cancellationToken);
        await EnsureSuccessAsync(response, cancellationToken);

        UserInfo? result;
        try
        {
            result = await response.Content.ReadFromJsonAsync<UserInfo>(JsonOptions, cancellationToken);
        }
        catch (Exception exception) when (exception is JsonException or NotSupportedException)
        {
            throw new AuthApiException(AuthApiError.ServerError, "The server returned an invalid user response.", exception);
        }
        if (result is null)
        {
            throw new AuthApiException(AuthApiError.ServerError, "The server returned an invalid user response.");
        }

        logger.LogInformation("Current user retrieved.");
        return result;
    }

    public async Task LogoutAsync(string accessToken, CancellationToken cancellationToken)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(accessToken);
        using var request = CreateAuthenticatedRequest(HttpMethod.Post, "auth/logout", accessToken);
        using HttpResponseMessage response = await SendAsync(request, cancellationToken);
        await EnsureSuccessAsync(response, cancellationToken);
        logger.LogInformation("Logout completed.");
    }

    private static HttpRequestMessage CreateAuthenticatedRequest(HttpMethod method, string path, string token)
    {
        var request = new HttpRequestMessage(method, path);
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        return request;
    }

    private async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        try
        {
            return await httpClient.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, cancellationToken);
        }
        catch (OperationCanceledException exception) when (!cancellationToken.IsCancellationRequested)
        {
            throw new AuthApiException(AuthApiError.Timeout, "The server took too long to respond.", exception);
        }
        catch (HttpRequestException exception)
        {
            throw new AuthApiException(AuthApiError.Unavailable, "Unable to contact the server.", exception);
        }
    }

    private static async Task EnsureSuccessAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        if (response.IsSuccessStatusCode)
        {
            return;
        }

        ApiErrorResponse? apiError = null;
        try
        {
            apiError = await response.Content.ReadFromJsonAsync<ApiErrorResponse>(JsonOptions, cancellationToken);
        }
        catch (Exception exception) when (exception is JsonException or NotSupportedException)
        {
        }

        AuthApiError error = apiError?.Error switch
        {
            "invalid_credentials" => AuthApiError.InvalidCredentials,
            "account_disabled" => AuthApiError.AccountDisabled,
            "rate_limited" => AuthApiError.RateLimited,
            "invalid_token" => AuthApiError.InvalidToken,
            "token_expired" => AuthApiError.TokenExpired,
            "validation_error" => AuthApiError.Validation,
            _ when response.StatusCode == HttpStatusCode.TooManyRequests => AuthApiError.RateLimited,
            _ when response.StatusCode == HttpStatusCode.RequestTimeout => AuthApiError.Timeout,
            _ => AuthApiError.ServerError
        };

        throw new AuthApiException(error, apiError?.Message ?? "The authentication request failed.");
    }

    private sealed record LoginRequest(
        [property: JsonPropertyName("email")] string Email,
        [property: JsonPropertyName("password")] string Password);

    private sealed record ApiErrorResponse(
        [property: JsonPropertyName("error")] string Error,
        [property: JsonPropertyName("message")] string Message);
}
