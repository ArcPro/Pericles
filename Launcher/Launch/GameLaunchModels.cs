using Launcher.Games;

namespace Launcher.Launch;

public enum GameLaunchStage
{
    RefreshingAuthorization,
    PreparingModule,
    WritingRuntimeModule,
    LocatingGame,
    StartingGame,
    GameRunning,
    StartingApplication,
    ConnectingApplication,
    LoadingModule,
    Active
}

public sealed record GameLaunchResult(
    string GameSlug,
    string Version,
    int GameProcessId,
    int ModuleHostProcessId,
    string Code);

public sealed class GameAuthorizationChangedException(GameAccessState state)
    : Exception("The game authorization is no longer available.")
{
    public GameAccessState State { get; } = state;
}
