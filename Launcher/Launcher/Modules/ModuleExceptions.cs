namespace Launcher.Modules;

public abstract class ModuleException(string message, Exception? innerException = null) : Exception(message, innerException);

public sealed class ModuleAuthorizationException(string errorCode, string message)
    : ModuleException(message)
{
    public string ErrorCode { get; } = errorCode;
}

public sealed class ModuleTicketExpiredException(string message) : ModuleException(message);

public sealed class ModuleDownloadException(string message, Exception? innerException = null)
    : ModuleException(message, innerException);

public sealed class InvalidModulePackageException(string message, Exception? innerException = null)
    : ModuleException(message, innerException);

public sealed class InvalidModuleSignatureException(string message) : ModuleException(message);

public sealed class ModuleDecryptionFailedException(string message, Exception? innerException = null)
    : ModuleException(message, innerException);

public sealed class ModuleHashMismatchException(string message) : ModuleException(message);

public sealed class ModuleGameMismatchException(string message) : ModuleException(message);
