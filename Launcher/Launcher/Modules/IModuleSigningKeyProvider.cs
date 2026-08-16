using System.Security.Cryptography;

namespace Launcher.Modules;

public interface IModuleSigningKeyProvider
{
    ECDsa CreateVerifier(string signingKeyId);
}
