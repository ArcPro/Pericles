using System.Security.Cryptography;

namespace Launcher.Devices;

public sealed class DeviceSignatureService(IDeviceKeyStore keyStore) : IDeviceSignatureService
{
    public async Task<string> SignAsync(ReadOnlyMemory<byte> data, CancellationToken cancellationToken)
    {
        cancellationToken.ThrowIfCancellationRequested();
        byte[] privateKey = await keyStore.LoadAsync(cancellationToken);
        try
        {
            using ECDsa signer = ECDsa.Create();
            signer.ImportPkcs8PrivateKey(privateKey, out int bytesRead);
            if (bytesRead != privateKey.Length || signer.KeySize != 256)
            {
                throw new DeviceIdentityCorruptedException("The device private key has an invalid format.");
            }

            byte[] signature = signer.SignData(
                data.Span,
                HashAlgorithmName.SHA256,
                DSASignatureFormat.Rfc3279DerSequence);
            return Base64Url.Encode(signature);
        }
        catch (CryptographicException exception)
        {
            throw new DeviceIdentityCorruptedException("The device private key cannot sign data.", exception);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(privateKey);
        }
    }
}
