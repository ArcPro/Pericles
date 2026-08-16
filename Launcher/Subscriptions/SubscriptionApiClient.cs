using System.Net.Http;
using Launcher.Entitlements;

namespace Launcher.Subscriptions;

public sealed class SubscriptionApiClient(HttpClient httpClient) : ISubscriptionApiClient
{
    public async Task<IReadOnlyList<SubscriptionInfo>> GetSubscriptionsAsync(
        string accessToken,
        CancellationToken cancellationToken)
    {
        using var request = EntitlementHttp.Authenticated(HttpMethod.Get, "subscriptions", accessToken);
        SubscriptionListResponse response = await EntitlementHttp.SendAsync<SubscriptionListResponse>(httpClient, request, cancellationToken);
        return response.Subscriptions ?? [];
    }
}
