namespace Launcher.Subscriptions;

public interface ISubscriptionApiClient
{
    Task<IReadOnlyList<SubscriptionInfo>> GetSubscriptionsAsync(string accessToken, CancellationToken cancellationToken);
}
