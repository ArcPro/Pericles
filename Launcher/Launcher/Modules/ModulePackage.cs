using System.Text.Json.Serialization;

namespace Launcher.Modules;

public sealed record ModuleManifest(
    [property: JsonPropertyName("package_version")] int PackageVersion,
    [property: JsonPropertyName("game_slug")] string GameSlug,
    [property: JsonPropertyName("module_slug")] string ModuleSlug,
    [property: JsonPropertyName("module_version")] string ModuleVersion,
    [property: JsonPropertyName("payload_sha256")] string PayloadSha256,
    [property: JsonPropertyName("payload_size")] int PayloadSize,
    [property: JsonPropertyName("created_at")] DateTimeOffset CreatedAt,
    [property: JsonPropertyName("signing_key_id")] string SigningKeyId);

public sealed record ModulePackage(
    ushort PackageVersion,
    ModuleManifest Manifest,
    ReadOnlyMemory<byte> ManifestBytes,
    ReadOnlyMemory<byte> Nonce,
    ReadOnlyMemory<byte> AuthenticationTag,
    ReadOnlyMemory<byte> EncryptedPayload,
    ReadOnlyMemory<byte> Signature,
    ReadOnlyMemory<byte> SignedData);
