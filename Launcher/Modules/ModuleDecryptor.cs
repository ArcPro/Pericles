using System.Security.Cryptography;

namespace Launcher.Modules;

public sealed class ModuleDecryptor : IModuleDecryptor
{
    public byte[] Decrypt(ModulePackage package, ReadOnlySpan<byte> sessionKey)
    {
        ArgumentNullException.ThrowIfNull(package);
        if (sessionKey.Length != 32)
        {
            throw new ModuleDecryptionFailedException("The module session key is invalid.");
        }
        byte[] plaintext = new byte[package.EncryptedPayload.Length];
        try
        {
            using var aes = new AesGcm(sessionKey, 16);
            aes.Decrypt(
                package.Nonce.Span,
                package.EncryptedPayload.Span,
                package.AuthenticationTag.Span,
                plaintext,
                package.ManifestBytes.Span);
            return plaintext;
        }
        catch (CryptographicException exception)
        {
            CryptographicOperations.ZeroMemory(plaintext);
            throw new ModuleDecryptionFailedException("The module package authentication tag is invalid.", exception);
        }
    }
}
