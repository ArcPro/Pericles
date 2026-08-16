namespace Launcher.Modules;

public interface IModuleDecryptor
{
    byte[] Decrypt(ModulePackage package, ReadOnlySpan<byte> sessionKey);
}
