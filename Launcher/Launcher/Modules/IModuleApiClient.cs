namespace Launcher.Modules;

public interface IModuleApiClient
{
    Task<ModuleTicketResponse> RequestTicketAsync(
        string gameSlug,
        string accessToken,
        CancellationToken cancellationToken);

    Task<DownloadedModulePackage> DownloadAsync(
        ModuleTicketResponse ticket,
        string accessToken,
        CancellationToken cancellationToken);
}
