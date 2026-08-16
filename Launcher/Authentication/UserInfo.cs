using System.Text.Json.Serialization;

namespace Launcher.Authentication;

public sealed record UserInfo(
    [property: JsonPropertyName("id")] string Id,
    [property: JsonPropertyName("email")] string Email,
    [property: JsonPropertyName("status")] string Status);
