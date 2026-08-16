using Launcher.Application;
using Launcher.Modules;
using Launcher.Launch;

namespace Launcher.IPC;

public interface IApplicationChannel
{
    Task<ModuleLoadResult> LoadModuleAsync(
        ApplicationInstance application,
        GameApplicationDefinition game,
        RuntimeModuleHandle module,
        int gameProcessId,
        IProgress<GameLaunchStage>? progress,
        CancellationToken cancellationToken);
}
