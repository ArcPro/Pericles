using Launcher.Application;

namespace Launcher.Modules;

public interface IRuntimeModuleStore
{
    Task<RuntimeModuleHandle> WriteAsync(PreparedModule module, CancellationToken cancellationToken);
    Task MarkInUseAsync(RuntimeModuleHandle module, ApplicationInstance application, CancellationToken cancellationToken);
    Task CleanupStaleAsync(CancellationToken cancellationToken);
}
