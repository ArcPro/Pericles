using System.IO;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;
using Launcher.Devices;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace Launcher.Modules;

public sealed class ModuleApiClient(
    HttpClient httpClient,
    IOptions<ModuleOptions> options,
    ILogger<ModuleApiClient> logger) : IModuleApiClient
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);
    private readonly int _maximumPackageBytes = checked(options.Value.MaxPackageSizeMb * 1024 * 1024 + 64 * 1024);

    public async Task<ModuleTicketResponse> RequestTicketAsync(
        string gameSlug,
        string accessToken,
        CancellationToken cancellationToken)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(gameSlug);
        logger.LogInformation("Requesting module ticket for {GameSlug}.", gameSlug);
        using var request = Authenticated(HttpMethod.Post, "modules/ticket", accessToken);
        request.Content = JsonContent.Create(new TicketRequest(gameSlug), options: JsonOptions);
        using HttpResponseMessage response = await SendAsync(request, cancellationToken);
        await EnsureSuccessAsync(response, cancellationToken);
        try
        {
            ModuleTicketResponse? ticket = await response.Content.ReadFromJsonAsync<ModuleTicketResponse>(JsonOptions, cancellationToken);
            if (ticket is null || string.IsNullOrWhiteSpace(ticket.Ticket) || ticket.ExpiresIn <= 0
                || string.IsNullOrWhiteSpace(ticket.Game) || string.IsNullOrWhiteSpace(ticket.Module?.Version))
            {
                throw new JsonException("Invalid module ticket response.");
            }
            logger.LogInformation("Module ticket received for {GameSlug}.", gameSlug);
            return ticket;
        }
        catch (JsonException exception)
        {
            throw new ModuleDownloadException("The server returned an invalid module ticket.", exception);
        }
    }

    public async Task<DownloadedModulePackage> DownloadAsync(
        ModuleTicketResponse ticket,
        string accessToken,
        CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(ticket);
        logger.LogInformation("Downloading module for {GameSlug}.", ticket.Game);
        using var request = Authenticated(HttpMethod.Get, "modules/download", accessToken);
        request.Headers.Add("X-Module-Ticket", ticket.Ticket);
        using HttpResponseMessage response = await SendAsync(request, cancellationToken);
        await EnsureSuccessAsync(response, cancellationToken);

        if (response.Content.Headers.ContentLength is long contentLength && contentLength > _maximumPackageBytes)
        {
            throw new ModuleDownloadException("The module package exceeds the configured size limit.");
        }
        if (!response.Headers.TryGetValues("X-Module-Session-Key", out IEnumerable<string>? values))
        {
            throw new ModuleDownloadException("The module session key is missing.");
        }
        byte[] sessionKey;
        try
        {
            string encodedSessionKey = values.Single();
            if (encodedSessionKey.Length != 43
                || encodedSessionKey.Any(character => !char.IsAsciiLetterOrDigit(character) && character is not '-' and not '_'))
            {
                throw new FormatException("Invalid Base64URL key.");
            }
            sessionKey = Base64Url.Decode(encodedSessionKey);
        }
        catch (Exception exception) when (exception is FormatException or InvalidOperationException)
        {
            throw new ModuleDownloadException("The module session key is invalid.", exception);
        }
        if (sessionKey.Length != 32)
        {
            CryptographicOperations.ZeroMemory(sessionKey);
            throw new ModuleDownloadException("The module session key is invalid.");
        }
        try
        {
            await using Stream source = await response.Content.ReadAsStreamAsync(cancellationToken);
            using var destination = new MemoryStream(Math.Min(
                response.Content.Headers.ContentLength is long length ? checked((int) length) : 81920,
                _maximumPackageBytes));
            byte[] buffer = new byte[81920];
            int total = 0;
            while (true)
            {
                int read = await source.ReadAsync(buffer.AsMemory(), cancellationToken);
                if (read == 0) break;
                total = checked(total + read);
                if (total > _maximumPackageBytes)
                {
                    throw new ModuleDownloadException("The module package exceeds the configured size limit.");
                }
                await destination.WriteAsync(buffer.AsMemory(0, read), cancellationToken);
            }
            if (total == 0)
            {
                throw new ModuleDownloadException("The downloaded module package is empty.");
            }
            return new DownloadedModulePackage(destination.ToArray(), sessionKey, ticket);
        }
        catch
        {
            CryptographicOperations.ZeroMemory(sessionKey);
            throw;
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
            throw new ModuleDownloadException("The module service took too long to respond.", exception);
        }
        catch (HttpRequestException exception)
        {
            throw new ModuleDownloadException("Unable to contact the module service.", exception);
        }
    }

    private static async Task EnsureSuccessAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        if (response.IsSuccessStatusCode) return;
        ApiError? result = null;
        try
        {
            result = await response.Content.ReadFromJsonAsync<ApiError>(JsonOptions, cancellationToken);
        }
        catch (JsonException)
        {
        }
        string code = result?.Error ?? "module_download_failed";
        string message = result?.Message ?? "The module request failed.";
        if (code == "ticket_expired") throw new ModuleTicketExpiredException(message);
        if (code is "no_subscription" or "subscription_expired" or "subscription_suspended"
            or "subscription_not_bound" or "subscription_bound_elsewhere" or "device_not_verified"
            or "device_verification_required" or "device_revoked" or "game_not_found" or "game_disabled"
            or "module_not_found" or "module_unavailable" or "ticket_invalid" or "ticket_used"
            or "module_rate_limited")
        {
            throw new ModuleAuthorizationException(code, message);
        }
        if (response.StatusCode == HttpStatusCode.RequestTimeout)
        {
            throw new ModuleDownloadException("The module service timed out.");
        }
        throw new ModuleDownloadException(message);
    }

    private static HttpRequestMessage Authenticated(HttpMethod method, string path, string token)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(token);
        var request = new HttpRequestMessage(method, path);
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        return request;
    }

    private sealed record TicketRequest([property: JsonPropertyName("game")] string Game);
    private sealed record ApiError(
        [property: JsonPropertyName("error")] string Error,
        [property: JsonPropertyName("message")] string Message);
}
