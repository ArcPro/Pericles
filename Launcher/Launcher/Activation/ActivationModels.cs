using System.Text.Json.Serialization;

namespace Launcher.Activation;

public sealed record ActivationProduct(
    [property: JsonPropertyName("slug")] string Slug,
    [property: JsonPropertyName("name")] string Name);

public sealed record ActivatedSubscription(
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("expires_at")] DateTimeOffset? ExpiresAt,
    [property: JsonPropertyName("device_binding")] string DeviceBinding);

public sealed record ActivationResponse(
    [property: JsonPropertyName("success")] bool Success,
    [property: JsonPropertyName("product")] ActivationProduct Product,
    [property: JsonPropertyName("subscription")] ActivatedSubscription Subscription);
