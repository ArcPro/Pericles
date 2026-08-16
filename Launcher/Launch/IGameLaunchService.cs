using Launcher.Authentication;

namespace Launcher.Launch;

public interface IGameLaunchService
{
    Task<GameLaunchResult> LaunchAsync(
        AuthSession session,
        string gameSlug,
        IProgress<GameLaunchStage>? progress,
        CancellationToken cancellationToken);
}
