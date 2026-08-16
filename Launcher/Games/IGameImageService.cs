namespace Launcher.Games;

public interface IGameImageService
{
    Task<byte[]?> DownloadAsync(string imageUrl, CancellationToken cancellationToken);
}
