using System.Text.Json.Serialization;

namespace Launcher.Devices;

public sealed record RegisterDeviceRequest(
    [property: JsonPropertyName("device_id")] string DeviceId,
    [property: JsonPropertyName("public_key")] string PublicKey,
    [property: JsonPropertyName("key_algorithm")] string KeyAlgorithm,
    [property: JsonPropertyName("display_name")] string DisplayName);

public sealed record DeviceInfo(
    [property: JsonPropertyName("device_id")] string DeviceId,
    [property: JsonPropertyName("display_name")] string DisplayName,
    [property: JsonPropertyName("created_at")] DateTimeOffset CreatedAt,
    [property: JsonPropertyName("last_seen_at")] DateTimeOffset? LastSeenAt,
    [property: JsonPropertyName("verified_at")] DateTimeOffset? VerifiedAt,
    [property: JsonPropertyName("is_current")] bool IsCurrent);

public sealed record DeviceRegistrationResponse(
    [property: JsonPropertyName("device")] DeviceInfo Device);

public sealed record DeviceChallengeResponse(
    [property: JsonPropertyName("challenge_id")] string ChallengeId,
    [property: JsonPropertyName("challenge")] string Challenge,
    [property: JsonPropertyName("expires_at")] DateTimeOffset ExpiresAt);

public sealed record VerifyDeviceRequest(
    [property: JsonPropertyName("device_id")] string DeviceId,
    [property: JsonPropertyName("challenge_id")] string ChallengeId,
    [property: JsonPropertyName("signature")] string Signature);

public sealed record DeviceVerificationResponse(
    [property: JsonPropertyName("device_verified")] bool DeviceVerified,
    [property: JsonPropertyName("verified_at")] DateTimeOffset VerifiedAt);

public sealed record DeviceListResponse(
    [property: JsonPropertyName("devices")] IReadOnlyList<DeviceInfo> Devices);
