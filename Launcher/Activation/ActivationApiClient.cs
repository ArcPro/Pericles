using System.Net.Http;
using System.Net.Http.Json;
using System.Text.Json.Serialization;
using Launcher.Entitlements;

namespace Launcher.Activation;

public sealed class ActivationApiClient(HttpClient httpClient) : IActivationApiClient
{
    public async Task<ActivationResponse> RedeemKeyAsync(
        string key,
        string accessToken,
        CancellationToken cancellationToken)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(key);
        using var request = EntitlementHttp.Authenticated(HttpMethod.Post, "activation/redeem", accessToken);
        request.Content = JsonContent.Create(new ActivationRequest(key), options: EntitlementHttp.JsonOptions);
        return await EntitlementHttp.SendAsync<ActivationResponse>(httpClient, request, cancellationToken);
    }

    private sealed record ActivationRequest([property: JsonPropertyName("key")] string Key);
}
