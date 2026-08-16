namespace Launcher.Application;

public interface IGameProcessService
{
    Task<GameProcessInstance> EnsureRunningAsync(
        GameApplicationDefinition game,
        CancellationToken cancellationToken);
}
