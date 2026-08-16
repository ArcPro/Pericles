using System.Text.Json.Serialization;

namespace Launcher.Games;

public enum GameAccessState
{
    Locked,
    ReadyToBind,
    Available,
    BoundElsewhere,
    Expired,
    Suspended
}

public sealed record GameAccess(
    [property: JsonPropertyName("state")] GameAccessState State,
    [property: JsonPropertyName("expires_at")] DateTimeOffset? ExpiresAt);

public sealed record GameInfo(
    [property: JsonPropertyName("slug")] string Slug,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("short_description")] string ShortDescription,
    [property: JsonPropertyName("image_url")] string ImageUrl,
    [property: JsonPropertyName("sort_order")] int SortOrder,
    [property: JsonPropertyName("access")] GameAccess Access);

public sealed record GameCatalogResponse(
    [property: JsonPropertyName("games")] IReadOnlyList<GameInfo> Games);

public sealed record BindGameResponse(
    [property: JsonPropertyName("success")] bool Success,
    [property: JsonPropertyName("state")] string State);
