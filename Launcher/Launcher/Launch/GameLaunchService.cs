using Launcher.Application;
using Launcher.Authentication;
using Launcher.Games;
using Launcher.IPC;
using Launcher.Modules;
using Microsoft.Extensions.Logging;
using System.Security.Cryptography;

namespace Launcher.Launch;

public sealed class GameLaunchService(
    IGameCatalogApiClient games,
    IModuleService modules,
    IRuntimeModuleStore runtimeModules,
    IApplicationConfigurationService applications,
    IGameProcessService gameProcesses,
    ITargetApplicationService targetApplications,
    IApplicationChannel channel,
    ILogger<GameLaunchService> logger) : IGameLaunchService
{
    public async Task<GameLaunchResult> LaunchAsync(
        AuthSession session,
        string gameSlug,
        IProgress<GameLaunchStage>? progress,
        CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(session);
        ArgumentException.ThrowIfNullOrWhiteSpace(gameSlug);
        progress?.Report(GameLaunchStage.RefreshingAuthorization);
        IReadOnlyList<GameInfo> catalog = await games.GetGamesAsync(session.AccessToken, cancellationToken);
        GameInfo game = catalog.FirstOrDefault(item => string.Equals(item.Slug, gameSlug, StringComparison.Ordinal))
            ?? throw new GameAuthorizationChangedException(GameAccessState.Locked);
        if (game.Access.State != GameAccessState.Available)
        {
            throw new GameAuthorizationChangedException(game.Access.State);
        }

        GameApplicationDefinition application = applications.GetRequired(gameSlug);
        progress?.Report(GameLaunchStage.PreparingModule);
        using PreparedModule prepared = await modules.PrepareModuleAsync(session, gameSlug, cancellationToken);
        progress?.Report(GameLaunchStage.WritingRuntimeModule);
        await using RuntimeModuleHandle runtime = await runtimeModules.WriteAsync(prepared, cancellationToken);
        progress?.Report(GameLaunchStage.LocatingGame);
        progress?.Report(GameLaunchStage.StartingGame);
        GameProcessInstance gameInstance = await gameProcesses.EnsureRunningAsync(application, cancellationToken);
        progress?.Report(GameLaunchStage.GameRunning);
        progress?.Report(GameLaunchStage.StartingApplication);
        ApplicationInstance instance = await targetApplications.EnsureRunningAsync(application, cancellationToken);
        ModuleLoadResult load;
        try
        {
            await runtimeModules.MarkInUseAsync(runtime, instance, cancellationToken);
            progress?.Report(GameLaunchStage.ConnectingApplication);
            load = await channel.LoadModuleAsync(
                instance,
                application,
                runtime,
                gameInstance.ProcessId,
                progress,
                cancellationToken);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(instance.IpcSessionSecret);
        }
        if (!load.Succeeded)
        {
            throw new ModuleLoadFailedException(load.Code);
        }
        progress?.Report(GameLaunchStage.Active);
        logger.LogInformation(
            "Cooperative launch completed for {GameSlug} version {Version}; game process {GameProcessId}, owned module host {ModuleHostProcessId}.",
            gameSlug,
            load.Version,
            gameInstance.ProcessId,
            instance.ProcessId);
        return new GameLaunchResult(gameSlug, load.Version, gameInstance.ProcessId, instance.ProcessId, load.Code);
    }
}
