using System.IO;
using System.Security.Cryptography;
using System.Text.Json;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace Launcher.Devices;

public sealed class DeviceIdentityService : IDeviceIdentityService
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web)
    {
        WriteIndented = true
    };

    private readonly IDeviceKeyStore _keyStore;
    private readonly IHardwareFingerprintProvider _hardwareFingerprint;
    private readonly DeviceOptions _options;
    private readonly ILogger<DeviceIdentityService> _logger;
    private readonly string _identityPath;

    public DeviceIdentityService(
        IDeviceKeyStore keyStore,
        IHardwareFingerprintProvider hardwareFingerprint,
        IOptions<DeviceOptions> options,
        ILogger<DeviceIdentityService> logger)
    {
        _keyStore = keyStore;
        _hardwareFingerprint = hardwareFingerprint;
        _options = options.Value;
        _logger = logger;
        _identityPath = Path.Combine(_options.ResolveStorageDirectory(), "device.json");
    }

    public Task<DeviceIdentity> GetOrCreateAsync(CancellationToken cancellationToken) =>
        Task.Run(() => GetOrCreateSynchronized(cancellationToken), cancellationToken);

    public Task CompleteHardwareIdMigrationAsync(DeviceIdentity identity, CancellationToken cancellationToken) =>
        Task.Run(() => CompleteMigrationSynchronized(identity, cancellationToken), cancellationToken);

    private DeviceIdentity GetOrCreateSynchronized(CancellationToken cancellationToken)
    {
        using var mutex = new Mutex(initiallyOwned: false, _options.MutexName);
        bool acquired = false;
        try
        {
            while (!acquired)
            {
                cancellationToken.ThrowIfCancellationRequested();
                try
                {
                    acquired = mutex.WaitOne(TimeSpan.FromMilliseconds(250));
                }
                catch (AbandonedMutexException)
                {
                    acquired = true;
                }
            }

            string hardwareDeviceId = _hardwareFingerprint.GetDeviceId();
            bool identityExists = File.Exists(_identityPath);
            bool keyExists = _keyStore.ExistsAsync(cancellationToken).GetAwaiter().GetResult();
            if (identityExists != keyExists)
            {
                throw new DeviceIdentityCorruptedException("The local device identity is incomplete.");
            }

            if (identityExists)
            {
                DeviceIdentity identity = LoadAndValidateAsync(hardwareDeviceId, cancellationToken).GetAwaiter().GetResult();
                _logger.LogInformation("Device identity loaded ({DeviceId}).", Mask(identity.DeviceId));
                return identity;
            }

            DeviceIdentity created = CreateAsync(hardwareDeviceId, cancellationToken).GetAwaiter().GetResult();
            _logger.LogInformation("Device identity created ({DeviceId}).", Mask(created.DeviceId));
            return created;
        }
        finally
        {
            if (acquired)
            {
                mutex.ReleaseMutex();
            }
        }
    }

    private async Task<DeviceIdentity> CreateAsync(string hardwareDeviceId, CancellationToken cancellationToken)
    {
        cancellationToken.ThrowIfCancellationRequested();
        using ECDsa signer = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        byte[] privateKey = signer.ExportPkcs8PrivateKey();
        try
        {
            var identity = new DeviceIdentity
            {
                SchemaVersion = DeviceIdentity.CurrentSchemaVersion,
                DeviceId = hardwareDeviceId,
                PublicKey = signer.ExportSubjectPublicKeyInfoPem(),
                KeyAlgorithm = DeviceIdentity.EcdsaP256Algorithm,
                CreatedAt = DateTimeOffset.UtcNow,
                HardwareIdVersion = 1
            };

            // Once generation starts, finish both atomic writes even if UI cancellation arrives.
            // This prevents a normal window close from leaving a half-created identity.
            await _keyStore.SaveAsync(privateKey, CancellationToken.None);
            byte[] json = JsonSerializer.SerializeToUtf8Bytes(identity, JsonOptions);
            await AtomicFile.WriteAllBytesAsync(_identityPath, json, CancellationToken.None);
            return identity;
        }
        finally
        {
            CryptographicOperations.ZeroMemory(privateKey);
        }
    }

    private async Task<DeviceIdentity> LoadAndValidateAsync(
        string hardwareDeviceId,
        CancellationToken cancellationToken)
    {
        DeviceIdentity? identity;
        try
        {
            await using FileStream stream = File.OpenRead(_identityPath);
            identity = await JsonSerializer.DeserializeAsync<DeviceIdentity>(stream, JsonOptions, cancellationToken);
        }
        catch (Exception exception) when (exception is JsonException or IOException or UnauthorizedAccessException)
        {
            throw new DeviceIdentityCorruptedException("The device identity file is invalid.", exception);
        }

        if (identity is null
            || identity.SchemaVersion != DeviceIdentity.CurrentSchemaVersion
            || !Guid.TryParseExact(identity.DeviceId, "N", out _)
            || !string.Equals(identity.KeyAlgorithm, DeviceIdentity.EcdsaP256Algorithm, StringComparison.Ordinal)
            || identity.CreatedAt == default)
        {
            throw new DeviceIdentityCorruptedException("The device identity metadata is invalid or unsupported.");
        }

        byte[] privateKey = await _keyStore.LoadAsync(cancellationToken);
        try
        {
            using ECDsa signer = ECDsa.Create();
            signer.ImportPkcs8PrivateKey(privateKey, out int bytesRead);
            using ECDsa verifier = ECDsa.Create();
            verifier.ImportFromPem(identity.PublicKey);
            if (bytesRead != privateKey.Length || signer.KeySize != 256 || verifier.KeySize != 256)
            {
                throw new DeviceIdentityCorruptedException("The device key parameters are invalid.");
            }

            byte[] challenge = RandomNumberGenerator.GetBytes(32);
            byte[] signature = signer.SignData(
                challenge,
                HashAlgorithmName.SHA256,
                DSASignatureFormat.Rfc3279DerSequence);
            bool matches = verifier.VerifyData(
                challenge,
                signature,
                HashAlgorithmName.SHA256,
                DSASignatureFormat.Rfc3279DerSequence);
            CryptographicOperations.ZeroMemory(challenge);
            CryptographicOperations.ZeroMemory(signature);
            if (!matches)
            {
                throw new DeviceIdentityCorruptedException("The device public and private keys do not match.");
            }
        }
        catch (DeviceIdentityCorruptedException)
        {
            throw;
        }
        catch (Exception exception) when (exception is CryptographicException or ArgumentException)
        {
            throw new DeviceIdentityCorruptedException("The device key material is invalid.", exception);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(privateKey);
        }

        if (identity.HardwareIdVersion == 1)
        {
            if (!string.Equals(identity.DeviceId, hardwareDeviceId, StringComparison.Ordinal))
            {
                throw new DeviceIdentityCorruptedException("The saved identity belongs to different hardware.");
            }
            return identity;
        }

        var migrated = new DeviceIdentity
        {
            SchemaVersion = identity.SchemaVersion,
            DeviceId = hardwareDeviceId,
            PublicKey = identity.PublicKey,
            KeyAlgorithm = identity.KeyAlgorithm,
            CreatedAt = identity.CreatedAt,
            HardwareIdVersion = 1,
            PreviousDeviceId = identity.DeviceId
        };
        await WriteIdentityAsync(migrated, CancellationToken.None);
        _logger.LogInformation(
            "Legacy device identity prepared for hardware-ID migration ({DeviceId}).",
            Mask(migrated.DeviceId));
        return migrated;
    }

    private void CompleteMigrationSynchronized(DeviceIdentity identity, CancellationToken cancellationToken)
    {
        if (identity.PreviousDeviceId is null)
        {
            return;
        }
        using var mutex = new Mutex(initiallyOwned: false, _options.MutexName);
        bool acquired = false;
        try
        {
            while (!acquired)
            {
                cancellationToken.ThrowIfCancellationRequested();
                try
                {
                    acquired = mutex.WaitOne(TimeSpan.FromMilliseconds(250));
                }
                catch (AbandonedMutexException)
                {
                    acquired = true;
                }
            }

            DeviceIdentity current = LoadIdentityMetadataAsync(cancellationToken).GetAwaiter().GetResult();
            if (!string.Equals(current.DeviceId, identity.DeviceId, StringComparison.Ordinal)
                || !string.Equals(current.PreviousDeviceId, identity.PreviousDeviceId, StringComparison.Ordinal))
            {
                throw new DeviceIdentityCorruptedException("The hardware-ID migration state changed unexpectedly.");
            }
            var completed = new DeviceIdentity
            {
                SchemaVersion = current.SchemaVersion,
                DeviceId = current.DeviceId,
                PublicKey = current.PublicKey,
                KeyAlgorithm = current.KeyAlgorithm,
                CreatedAt = current.CreatedAt,
                HardwareIdVersion = current.HardwareIdVersion
            };
            WriteIdentityAsync(completed, CancellationToken.None).GetAwaiter().GetResult();
        }
        finally
        {
            if (acquired)
            {
                mutex.ReleaseMutex();
            }
        }
    }

    private async Task<DeviceIdentity> LoadIdentityMetadataAsync(CancellationToken cancellationToken)
    {
        try
        {
            await using FileStream stream = File.OpenRead(_identityPath);
            return await JsonSerializer.DeserializeAsync<DeviceIdentity>(stream, JsonOptions, cancellationToken)
                ?? throw new DeviceIdentityCorruptedException("The device identity file is empty.");
        }
        catch (Exception exception) when (exception is JsonException or IOException or UnauthorizedAccessException)
        {
            throw new DeviceIdentityCorruptedException("The device identity file is invalid.", exception);
        }
    }

    private Task WriteIdentityAsync(DeviceIdentity identity, CancellationToken cancellationToken)
    {
        byte[] json = JsonSerializer.SerializeToUtf8Bytes(identity, JsonOptions);
        return AtomicFile.WriteAllBytesAsync(_identityPath, json, cancellationToken);
    }

    private static string Mask(string deviceId) => deviceId.Length <= 8
        ? "********"
        : $"{deviceId[..4]}...{deviceId[^4..]}";
}
