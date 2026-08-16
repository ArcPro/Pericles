using System.Security.Cryptography;
using Microsoft.Extensions.Logging;

namespace Launcher.Modules;

public sealed class ModulePackageVerifier(
    IModuleSigningKeyProvider signingKeys,
    ILogger<ModulePackageVerifier> logger) : IModulePackageVerifier
{
    public void Verify(ModulePackage package)
    {
        ArgumentNullException.ThrowIfNull(package);
        using ECDsa verifier = signingKeys.CreateVerifier(package.Manifest.SigningKeyId);
        bool valid;
        try
        {
            valid = verifier.VerifyData(
                package.SignedData.Span,
                package.Signature.Span,
                HashAlgorithmName.SHA256,
                DSASignatureFormat.Rfc3279DerSequence);
        }
        catch (CryptographicException)
        {
            valid = false;
        }
        if (!valid)
        {
            throw new InvalidModuleSignatureException("The module package signature is invalid.");
        }
        logger.LogInformation("Module signature valid for key {SigningKeyId}.", package.Manifest.SigningKeyId);
    }
}
