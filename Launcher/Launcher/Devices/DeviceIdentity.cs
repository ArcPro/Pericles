namespace Launcher.Devices;

public sealed class DeviceIdentity
{
    public const int CurrentSchemaVersion = 1;
    public const string EcdsaP256Algorithm = "ECDSA-P256";

    public required int SchemaVersion { get; init; }

    public required string DeviceId { get; init; }

    public required string PublicKey { get; init; }

    public required string KeyAlgorithm { get; init; }

    public required DateTimeOffset CreatedAt { get; init; }

    public int? HardwareIdVersion { get; init; }

    public string? PreviousDeviceId { get; init; }
}
