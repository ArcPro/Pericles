using Launcher.Authentication;
using Launcher.Activation;
using Launcher.Games;
using Launcher.Subscriptions;
using Launcher.Modules;
using Microsoft.Extensions.Logging;

namespace Launcher.Core;

public sealed class LauncherService(
    IGameCatalogApiClient gameCatalog,
    IActivationApiClient activation,
    ISubscriptionApiClient subscriptions,
    IModuleService modules,
    ILogger<LauncherService> logger)
{
    public LauncherState State { get; private set; } = LauncherState.Stopped;

    public event EventHandler<LauncherState>? StateChanged;

    public async Task<LauncherHomeData> PrepareAuthenticatedHomeAsync(
        AuthSession session,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(session);
        SetState(LauncherState.CheckingLicense);
        Task<IReadOnlyList<GameInfo>> gamesTask = gameCatalog.GetGamesAsync(session.AccessToken, cancellationToken);
        Task<IReadOnlyList<SubscriptionInfo>> subscriptionsTask = subscriptions.GetSubscriptionsAsync(
            session.AccessToken,
            cancellationToken);
        await Task.WhenAll(gamesTask, subscriptionsTask);

        SetState(LauncherState.Ready);
        logger.LogInformation("Authenticated launcher home prepared for user {UserId}.", session.UserId);
        return new LauncherHomeData(await gamesTask, await subscriptionsTask);
    }

    public async Task<LauncherHomeData> RedeemAndRefreshAsync(
        AuthSession session,
        string key,
        CancellationToken cancellationToken = default)
    {
        _ = await activation.RedeemKeyAsync(key, session.AccessToken, cancellationToken);
        return await PrepareAuthenticatedHomeAsync(session, cancellationToken);
    }

    public async Task<LauncherHomeData> BindAndRefreshAsync(
        AuthSession session,
        string gameSlug,
        CancellationToken cancellationToken = default)
    {
        await gameCatalog.BindGameToCurrentDeviceAsync(gameSlug, session.AccessToken, cancellationToken);
        return await PrepareAuthenticatedHomeAsync(session, cancellationToken);
    }

    public Task<PreparedModule> PrepareModuleAsync(
        AuthSession session,
        string gameSlug,
        CancellationToken cancellationToken = default)
    {
        SetState(LauncherState.PreparingModule);
        return PrepareModuleCoreAsync(session, gameSlug, cancellationToken);
    }

    private async Task<PreparedModule> PrepareModuleCoreAsync(
        AuthSession session,
        string gameSlug,
        CancellationToken cancellationToken)
    {
        try
        {
            PreparedModule module = await modules.PrepareModuleAsync(session, gameSlug, cancellationToken);
            SetState(LauncherState.Ready);
            return module;
        }
        catch
        {
            SetState(LauncherState.Failed);
            throw;
        }
    }

    private void SetState(LauncherState state)
    {
        State = state;
        StateChanged?.Invoke(this, state);
        logger.LogDebug("Launcher state changed to {LauncherState}.", state);
    }
}
