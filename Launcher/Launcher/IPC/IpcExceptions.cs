namespace Launcher.IPC;

public abstract class ApplicationChannelException(string message, Exception? innerException = null)
    : Exception(message, innerException);

public sealed class ApplicationConnectionException(string message, Exception? innerException = null)
    : ApplicationChannelException(message, innerException);

public sealed class ApplicationHandshakeException(string message)
    : ApplicationChannelException(message);

public sealed class ApplicationIdentityMismatchException()
    : ApplicationChannelException("The named-pipe server is not the configured application process.");

public sealed class ModuleLoadFailedException(string code)
    : ApplicationChannelException("The owned application rejected the module load request.")
{
    public string Code { get; } = code;
}

public sealed class ModuleRestartRequiredException()
    : ApplicationChannelException("A different module version is already loaded.");
