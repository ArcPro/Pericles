namespace Launcher.Application;

public interface ITargetApplicationService
{
    Task<ApplicationInstance> EnsureRunningAsync(
        GameApplicationDefinition game,
        CancellationToken cancellationToken);
}
