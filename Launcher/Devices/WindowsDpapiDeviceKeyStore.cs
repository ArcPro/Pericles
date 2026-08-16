using System.IO;
using System.Security.Cryptography;
using System.Text;
using Microsoft.Extensions.Options;

namespace Launcher.Devices;

public sealed class WindowsDpapiDeviceKeyStore : IDeviceKeyStore
{
    private static readonly byte[] AdditionalEntropy = Encoding.UTF8.GetBytes("Pericles.DeviceIdentity.v1");
    private readonly string _keyPath;

    public WindowsDpapiDeviceKeyStore(IOptions<DeviceOptions> options)
    {
        ArgumentNullException.ThrowIfNull(options);
        _keyPath = Path.Combine(options.Value.ResolveStorageDirectory(), "device.key");
    }

    public Task<bool> ExistsAsync(CancellationToken cancellationToken)
    {
        cancellationToken.ThrowIfCancellationRequested();
        return Task.FromResult(File.Exists(_keyPath));
    }

    public async Task SaveAsync(byte[] privateKey, CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(privateKey);
        cancellationToken.ThrowIfCancellationRequested();
        if (privateKey.Length == 0)
        {
            throw new ArgumentException("The private key is empty.", nameof(privateKey));
        }

        byte[] protectedKey = ProtectedData.Protect(
            privateKey,
            AdditionalEntropy,
            DataProtectionScope.CurrentUser);
        try
        {
            await AtomicFile.WriteAllBytesAsync(_keyPath, protectedKey, cancellationToken);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(protectedKey);
        }
    }

    public async Task<byte[]> LoadAsync(CancellationToken cancellationToken)
    {
        cancellationToken.ThrowIfCancellationRequested();
        if (!File.Exists(_keyPath))
        {
            throw new DeviceIdentityCorruptedException("The protected device key is missing.");
        }

        byte[] protectedKey = await File.ReadAllBytesAsync(_keyPath, cancellationToken);
        try
        {
            return ProtectedData.Unprotect(
                protectedKey,
                AdditionalEntropy,
                DataProtectionScope.CurrentUser);
        }
        catch (CryptographicException exception)
        {
            throw new DeviceIdentityCorruptedException("The protected device key cannot be decrypted.", exception);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(protectedKey);
        }
    }
}
