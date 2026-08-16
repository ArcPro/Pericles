namespace Launcher.Games;

public interface IGameCatalogApiClient
{
    Task<IReadOnlyList<GameInfo>> GetGamesAsync(string accessToken, CancellationToken cancellationToken);
    Task BindGameToCurrentDeviceAsync(string slug, string accessToken, CancellationToken cancellationToken);
}
