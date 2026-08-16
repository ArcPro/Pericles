namespace Launcher.Modules;

public sealed class RuntimeModuleIntegrityException(string message, Exception? innerException = null)
    : ModuleException(message, innerException);
