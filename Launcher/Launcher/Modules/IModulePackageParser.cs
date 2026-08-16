namespace Launcher.Modules;

public interface IModulePackageParser
{
    ModulePackage Parse(ReadOnlyMemory<byte> packageBytes);
}
