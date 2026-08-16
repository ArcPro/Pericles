using Launcher.Authentication;

namespace Launcher.Modules;

public interface IModuleService
{
    Task<PreparedModule> PrepareModuleAsync(
        AuthSession session,
        string gameSlug,
        CancellationToken cancellationToken);
}
