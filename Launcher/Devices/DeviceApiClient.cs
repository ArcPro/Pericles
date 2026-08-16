using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.Extensions.Logging;

namespace Launcher.Devices;

public sealed class DeviceApiClient(HttpClient httpClient, ILogger<DeviceApiClient> logger) : IDeviceApiClient
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);

    public async Task<DeviceRegistrationResponse> RegisterAsync(
        RegisterDeviceRequest request,
        string accessToken,
        CancellationToken cancellationToken)
    {
        using var message = CreateAuthenticatedRequest(HttpMethod.Post, "devices/register", accessToken);
        message.Content = JsonContent.Create(request, options: JsonOptions);
        DeviceRegistrationResponse response = await SendAndReadAsync<DeviceRegistrationResponse>(message, cancellationToken);
        logger.LogInformation("Device registration successful.");
        return response;
    }

    public async Task<DeviceChallengeResponse> RequestChallengeAsync(
        string deviceId,
        string accessToken,
        CancellationToken cancellationToken)
    {
        using var message = CreateAuthenticatedRequest(HttpMethod.Post, "devices/challenge", accessToken);
        message.Content = JsonContent.Create(new DeviceIdRequest(deviceId), options: JsonOptions);
        DeviceChallengeResponse response = await SendAndReadAsync<DeviceChallengeResponse>(message, cancellationToken);
        logger.LogInformation("Device challenge requested.");
        return response;
    }

    public async Task<DeviceVerificationResponse> VerifyAsync(
        VerifyDeviceRequest request,
        string accessToken,
        CancellationToken cancellationToken)
    {
        using var message = CreateAuthenticatedRequest(HttpMethod.Post, "devices/verify", accessToken);
        message.Content = JsonContent.Create(request, options: JsonOptions);
        DeviceVerificationResponse response = await SendAndReadAsync<DeviceVerificationResponse>(message, cancellationToken);
        logger.LogInformation("Device verification successful.");
        return response;
    }

    public async Task<IReadOnlyList<DeviceInfo>> GetDevicesAsync(
        string currentDeviceId,
        string accessToken,
        CancellationToken cancellationToken)
    {
        using var message = CreateAuthenticatedRequest(HttpMethod.Get, "devices", accessToken);
        message.Headers.Add("X-Device-Id", currentDeviceId);
        DeviceListResponse response = await SendAndReadAsync<DeviceListResponse>(message, cancellationToken);
        return response.Devices ?? [];
    }

    public async Task RevokeAsync(string deviceId, string accessToken, CancellationToken cancellationToken)
    {
        using var message = CreateAuthenticatedRequest(
            HttpMethod.Delete,
            "devices/" + Uri.EscapeDataString(deviceId),
            accessToken);
        using HttpResponseMessage response = await SendAsync(message, cancellationToken);
        await EnsureSuccessAsync(response, cancellationToken);
    }

    private async Task<T> SendAndReadAsync<T>(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        using HttpResponseMessage response = await SendAsync(request, cancellationToken);
        await EnsureSuccessAsync(response, cancellationToken);
        try
        {
            T? result = await response.Content.ReadFromJsonAsync<T>(JsonOptions, cancellationToken);
            return result ?? throw new JsonException("Empty response.");
        }
        catch (Exception exception) when (exception is JsonException or NotSupportedException)
        {
            throw new DeviceApiException(DeviceApiError.ServerError, "The server returned an invalid device response.", exception);
        }
    }

    private async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        try
        {
            return await httpClient.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, cancellationToken);
        }
        catch (OperationCanceledException exception) when (!cancellationToken.IsCancellationRequested)
        {
            throw new DeviceApiException(DeviceApiError.Timeout, "The device service took too long to respond.", exception);
        }
        catch (HttpRequestException exception)
        {
            throw new DeviceApiException(DeviceApiError.Unavailable, "Unable to contact the device service.", exception);
        }
    }

    private static async Task EnsureSuccessAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        if (response.IsSuccessStatusCode)
        {
            return;
        }

        ApiErrorResponse? result = null;
        try
        {
            result = await response.Content.ReadFromJsonAsync<ApiErrorResponse>(JsonOptions, cancellationToken);
        }
        catch (Exception exception) when (exception is JsonException or NotSupportedException)
        {
        }

        DeviceApiError error = result?.Error switch
        {
            "device_limit_reached" => DeviceApiError.DeviceLimitReached,
            "device_revoked" => DeviceApiError.DeviceRevoked,
            "device_claimed" => DeviceApiError.DeviceClaimed,
            "device_key_mismatch" => DeviceApiError.PublicKeyMismatch,
            "challenge_expired" => DeviceApiError.ChallengeExpired,
            "challenge_used" => DeviceApiError.ChallengeUsed,
            "invalid_signature" => DeviceApiError.InvalidSignature,
            "invalid_token" or "token_expired" => DeviceApiError.InvalidToken,
            "validation_error" => DeviceApiError.Validation,
            _ when response.StatusCode == HttpStatusCode.TooManyRequests => DeviceApiError.Unavailable,
            _ when response.StatusCode == HttpStatusCode.RequestTimeout => DeviceApiError.Timeout,
            _ => DeviceApiError.ServerError
        };
        throw new DeviceApiException(error, result?.Message ?? "The device request failed.");
    }

    private static HttpRequestMessage CreateAuthenticatedRequest(HttpMethod method, string path, string token)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(token);
        var request = new HttpRequestMessage(method, path);
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        return request;
    }

    private sealed record DeviceIdRequest(
        [property: JsonPropertyName("device_id")] string DeviceId);

    private sealed record ApiErrorResponse(
        [property: JsonPropertyName("error")] string Error,
        [property: JsonPropertyName("message")] string Message);
}
