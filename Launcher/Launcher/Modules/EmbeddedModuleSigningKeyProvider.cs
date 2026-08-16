using System.IO;
using System.Reflection;
using System.Security.Cryptography;
using Microsoft.Extensions.Options;

namespace Launcher.Modules;

public sealed class EmbeddedModuleSigningKeyProvider(IOptions<ModuleOptions> options) : IModuleSigningKeyProvider
{
    public ECDsa CreateVerifier(string signingKeyId)
    {
        if (!options.Value.TrustedSigningKeys.TryGetValue(signingKeyId, out string? resourceName)
            || string.IsNullOrWhiteSpace(resourceName))
        {
            throw new InvalidModuleSignatureException("The module signing key is not trusted.");
        }
        Assembly assembly = typeof(EmbeddedModuleSigningKeyProvider).Assembly;
        using Stream? stream = assembly.GetManifestResourceStream(resourceName);
        if (stream is null)
        {
            throw new InvalidModuleSignatureException("The pinned module signing key is unavailable.");
        }
        using var reader = new StreamReader(stream);
        string pem = reader.ReadToEnd();
        try
        {
            ECDsa verifier = ECDsa.Create();
            verifier.ImportFromPem(pem);
            if (verifier.KeySize != 256)
            {
                verifier.Dispose();
                throw new InvalidModuleSignatureException("The pinned module signing key is invalid.");
            }
            return verifier;
        }
        catch (CryptographicException)
        {
            throw new InvalidModuleSignatureException("The pinned module signing key is invalid.");
        }
    }
}
