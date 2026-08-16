using System.Diagnostics;
using System.Security.Cryptography;
using System.Text.Json;
using Launcher.Devices;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using Xunit;

namespace Launcher.Tests.Devices;

public sealed class DeviceIdentityServiceTests : IDisposable
{
    private readonly string _directory = Path.Combine(Path.GetTempPath(), "Pericles.Tests", Guid.NewGuid().ToString("N"));
    private readonly DeviceOptions _options;

    public DeviceIdentityServiceTests()
    {
        _options = new DeviceOptions
        {
            StorageDirectory = _directory,
            MutexName = @"Local\Pericles.Tests." + Guid.NewGuid().ToString("N")
        };
    }

    [Fact]
    public async Task FirstRunCreatesIdentity()
    {
        DeviceIdentity identity = await CreateIdentityService().GetOrCreateAsync(CancellationToken.None);

        Assert.True(Guid.TryParseExact(identity.DeviceId, "N", out _));
        Assert.Equal(DeviceIdentity.EcdsaP256Algorithm, identity.KeyAlgorithm);
        Assert.True(File.Exists(Path.Combine(_directory, "device.json")));
        Assert.True(File.Exists(Path.Combine(_directory, "device.key")));
    }

    [Fact]
    public async Task SecondRunLoadsSameIdentity()
    {
        DeviceIdentity first = await CreateIdentityService().GetOrCreateAsync(CancellationToken.None);
        DeviceIdentity second = await CreateIdentityService().GetOrCreateAsync(CancellationToken.None);

        Assert.Equal(first.DeviceId, second.DeviceId);
        Assert.Equal(first.PublicKey, second.PublicKey);
        Assert.Equal(first.CreatedAt, second.CreatedAt);
    }

    [Fact]
    public async Task ConcurrentFirstRunCreatesSingleIdentity()
    {
        DeviceIdentityService firstService = CreateIdentityService();
        DeviceIdentityService secondService = CreateIdentityService();

        DeviceIdentity[] identities = await Task.WhenAll(
            firstService.GetOrCreateAsync(CancellationToken.None),
            secondService.GetOrCreateAsync(CancellationToken.None));

        Assert.Equal(identities[0].DeviceId, identities[1].DeviceId);
        Assert.Equal(identities[0].PublicKey, identities[1].PublicKey);
    }

    [Fact]
    public async Task PrivateKeyNotStoredPlaintext()
    {
        var keyStore = CreateKeyStore();
        await CreateIdentityService(keyStore).GetOrCreateAsync(CancellationToken.None);
        byte[] plaintext = await keyStore.LoadAsync(CancellationToken.None);
        byte[] stored = await File.ReadAllBytesAsync(Path.Combine(_directory, "device.key"));

        try
        {
            Assert.NotEqual(Convert.ToBase64String(plaintext), Convert.ToBase64String(stored));
            Assert.DoesNotContain(Convert.ToBase64String(plaintext), Convert.ToBase64String(stored), StringComparison.Ordinal);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(plaintext);
            CryptographicOperations.ZeroMemory(stored);
        }
    }

    [Fact]
    public async Task CanSignChallenge()
    {
        var keyStore = CreateKeyStore();
        DeviceIdentity identity = await CreateIdentityService(keyStore).GetOrCreateAsync(CancellationToken.None);
        var signatures = new DeviceSignatureService(keyStore);
        byte[] challenge = RandomNumberGenerator.GetBytes(32);
        string encodedSignature = await signatures.SignAsync(challenge, CancellationToken.None);

        using ECDsa verifier = ECDsa.Create();
        verifier.ImportFromPem(identity.PublicKey);
        Assert.True(verifier.VerifyData(
            challenge,
            Base64Url.Decode(encodedSignature),
            HashAlgorithmName.SHA256,
            DSASignatureFormat.Rfc3279DerSequence));
    }

    [Fact]
    public async Task ModifiedChallengeFails()
    {
        var keyStore = CreateKeyStore();
        DeviceIdentity identity = await CreateIdentityService(keyStore).GetOrCreateAsync(CancellationToken.None);
        byte[] originalChallenge = RandomNumberGenerator.GetBytes(32);
        string encodedSignature = await new DeviceSignatureService(keyStore)
            .SignAsync(originalChallenge, CancellationToken.None);
        byte[] modifiedChallenge = originalChallenge.ToArray();
        modifiedChallenge[0] ^= 0xff;

        using ECDsa verifier = ECDsa.Create();
        verifier.ImportFromPem(identity.PublicKey);
        Assert.False(verifier.VerifyData(
            modifiedChallenge,
            Base64Url.Decode(encodedSignature),
            HashAlgorithmName.SHA256,
            DSASignatureFormat.Rfc3279DerSequence));
    }

    [Fact]
    public async Task CorruptedPrivateKeyRejected()
    {
        await CreateIdentityService().GetOrCreateAsync(CancellationToken.None);
        await File.WriteAllBytesAsync(Path.Combine(_directory, "device.key"), RandomNumberGenerator.GetBytes(96));

        await Assert.ThrowsAsync<DeviceIdentityCorruptedException>(() =>
            CreateIdentityService().GetOrCreateAsync(CancellationToken.None));
    }

    [Fact]
    public async Task ModifiedPublicKeyRejected()
    {
        await CreateIdentityService().GetOrCreateAsync(CancellationToken.None);
        using ECDsa replacement = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        string jsonPath = Path.Combine(_directory, "device.json");
        DeviceIdentity original = JsonSerializer.Deserialize<DeviceIdentity>(
            await File.ReadAllTextAsync(jsonPath),
            new JsonSerializerOptions(JsonSerializerDefaults.Web))!;
        var modified = new DeviceIdentity
        {
            SchemaVersion = original.SchemaVersion,
            DeviceId = original.DeviceId,
            PublicKey = replacement.ExportSubjectPublicKeyInfoPem(),
            KeyAlgorithm = original.KeyAlgorithm,
            CreatedAt = original.CreatedAt
        };
        await File.WriteAllTextAsync(jsonPath, JsonSerializer.Serialize(modified, new JsonSerializerOptions(JsonSerializerDefaults.Web)));

        await Assert.ThrowsAsync<DeviceIdentityCorruptedException>(() =>
            CreateIdentityService().GetOrCreateAsync(CancellationToken.None));
    }

    [Fact]
    public async Task DotNetSignatureIsVerifiedByPhpOpenSsl()
    {
        var keyStore = CreateKeyStore();
        DeviceIdentity identity = await CreateIdentityService(keyStore).GetOrCreateAsync(CancellationToken.None);
        byte[] challenge = RandomNumberGenerator.GetBytes(32);
        string signature = await new DeviceSignatureService(keyStore).SignAsync(challenge, CancellationToken.None);
        string script = FindRepositoryFile(Path.Combine("Site", "tests", "verify_dotnet_signature.php"));
        var startInfo = new ProcessStartInfo("php", $"\"{script}\"")
        {
            RedirectStandardInput = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
            CreateNoWindow = true
        };
        using Process process = Process.Start(startInfo)!;
        await process.StandardInput.WriteAsync(JsonSerializer.Serialize(new
        {
            public_key = identity.PublicKey,
            data = Convert.ToBase64String(challenge),
            signature
        }));
        process.StandardInput.Close();
        string output = await process.StandardOutput.ReadToEndAsync();
        string error = await process.StandardError.ReadToEndAsync();
        await process.WaitForExitAsync();

        Assert.True(process.ExitCode == 0, error);
        Assert.Equal("VALID", output.Trim());
    }

    public void Dispose()
    {
        if (Directory.Exists(_directory))
        {
            Directory.Delete(_directory, recursive: true);
        }
    }

    private WindowsDpapiDeviceKeyStore CreateKeyStore() => new(Options.Create(_options));

    private DeviceIdentityService CreateIdentityService(IDeviceKeyStore? keyStore = null) => new(
        keyStore ?? CreateKeyStore(),
        Options.Create(_options),
        NullLogger<DeviceIdentityService>.Instance);

    private static string FindRepositoryFile(string relativePath)
    {
        DirectoryInfo? directory = new(AppContext.BaseDirectory);
        while (directory is not null)
        {
            string candidate = Path.Combine(directory.FullName, relativePath);
            if (File.Exists(candidate))
            {
                return candidate;
            }
            directory = directory.Parent;
        }
        throw new FileNotFoundException("Could not locate repository test helper.", relativePath);
    }
}
