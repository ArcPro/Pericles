using System.Text.Json.Serialization;

namespace Launcher.Subscriptions;

public sealed record SubscriptionProduct(
    [property: JsonPropertyName("slug")] string Slug,
    [property: JsonPropertyName("name")] string Name);

public sealed record SubscriptionDeviceBinding(
    [property: JsonPropertyName("state")] string State);

public sealed record SubscriptionInfo(
    [property: JsonPropertyName("product")] SubscriptionProduct Product,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("started_at")] DateTimeOffset StartedAt,
    [property: JsonPropertyName("expires_at")] DateTimeOffset? ExpiresAt,
    [property: JsonPropertyName("device_binding")] SubscriptionDeviceBinding DeviceBinding);

public sealed record SubscriptionListResponse(
    [property: JsonPropertyName("subscriptions")] IReadOnlyList<SubscriptionInfo> Subscriptions);
