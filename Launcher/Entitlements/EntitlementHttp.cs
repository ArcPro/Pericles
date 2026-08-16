using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Launcher.Entitlements;

internal static class EntitlementHttp
{
    internal static readonly JsonSerializerOptions JsonOptions = CreateOptions();

    internal static HttpRequestMessage Authenticated(HttpMethod method, string path, string accessToken)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(accessToken);
        var request = new HttpRequestMessage(method, path);
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", accessToken);
        return request;
    }

    internal static async Task<T> SendAsync<T>(
        HttpClient client,
        HttpRequestMessage request,
        CancellationToken cancellationToken)
    {
        try
        {
            using HttpResponseMessage response = await client.SendAsync(
                request,
                HttpCompletionOption.ResponseHeadersRead,
                cancellationToken);
            if (!response.IsSuccessStatusCode)
            {
                await ThrowApiErrorAsync(response, cancellationToken);
            }
            return await response.Content.ReadFromJsonAsync<T>(JsonOptions, cancellationToken)
                ?? throw new EntitlementApiException(EntitlementApiError.ServerError, "Réponse serveur vide.");
        }
        catch (OperationCanceledException exception) when (!cancellationToken.IsCancellationRequested)
        {
            throw new EntitlementApiException(EntitlementApiError.Timeout, "Le serveur met trop de temps à répondre.", exception);
        }
        catch (HttpRequestException exception)
        {
            throw new EntitlementApiException(EntitlementApiError.Unavailable, "Impossible de contacter le serveur.", exception);
        }
        catch (JsonException exception)
        {
            throw new EntitlementApiException(EntitlementApiError.ServerError, "Réponse serveur invalide.", exception);
        }
    }

    private static async Task ThrowApiErrorAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        ApiErrorResponse? body = null;
        try
        {
            body = await response.Content.ReadFromJsonAsync<ApiErrorResponse>(JsonOptions, cancellationToken);
        }
        catch (JsonException)
        {
        }
        EntitlementApiError error = body?.Error switch
        {
            "invalid_activation_key" => EntitlementApiError.InvalidKey,
            "activation_key_used" => EntitlementApiError.KeyUsed,
            "activation_key_expired" => EntitlementApiError.KeyExpired,
            "activation_key_revoked" => EntitlementApiError.KeyRevoked,
            "subscription_already_lifetime" => EntitlementApiError.AlreadyLifetime,
            "activation_rate_limited" => EntitlementApiError.RateLimited,
            "subscription_required" => EntitlementApiError.SubscriptionRequired,
            "subscription_bound_to_another_device" => EntitlementApiError.BoundElsewhere,
            "device_verification_required" => EntitlementApiError.DeviceVerificationRequired,
            "invalid_token" or "token_expired" => EntitlementApiError.InvalidToken,
            "validation_error" => EntitlementApiError.Validation,
            _ when response.StatusCode == HttpStatusCode.TooManyRequests => EntitlementApiError.RateLimited,
            _ when response.StatusCode == HttpStatusCode.RequestTimeout => EntitlementApiError.Timeout,
            _ => EntitlementApiError.ServerError
        };
        throw new EntitlementApiException(error, body?.Message ?? "La requête a échoué.");
    }

    private static JsonSerializerOptions CreateOptions()
    {
        var options = new JsonSerializerOptions(JsonSerializerDefaults.Web);
        options.Converters.Add(new JsonStringEnumConverter(JsonNamingPolicy.SnakeCaseLower));
        return options;
    }

    private sealed record ApiErrorResponse(
        [property: JsonPropertyName("error")] string Error,
        [property: JsonPropertyName("message")] string Message);
}
