using System.Net.Http;
using Launcher.Entitlements;

namespace Launcher.Games;

public sealed class GameCatalogApiClient(HttpClient httpClient) : IGameCatalogApiClient
{
    public async Task<IReadOnlyList<GameInfo>> GetGamesAsync(string accessToken, CancellationToken cancellationToken)
    {
        using var request = EntitlementHttp.Authenticated(HttpMethod.Get, "games", accessToken);
        GameCatalogResponse response = await EntitlementHttp.SendAsync<GameCatalogResponse>(httpClient, request, cancellationToken);
        return response.Games ?? [];
    }

    public async Task BindGameToCurrentDeviceAsync(
        string slug,
        string accessToken,
        CancellationToken cancellationToken)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(slug);
        using var request = EntitlementHttp.Authenticated(
            HttpMethod.Post,
            "games/" + Uri.EscapeDataString(slug) + "/bind-device",
            accessToken);
        _ = await EntitlementHttp.SendAsync<BindGameResponse>(httpClient, request, cancellationToken);
    }
}
