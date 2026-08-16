using Launcher.Games;
using Launcher.Subscriptions;

namespace Launcher.Core;

public sealed record LauncherHomeData(
    IReadOnlyList<GameInfo> Games,
    IReadOnlyList<SubscriptionInfo> Subscriptions);
