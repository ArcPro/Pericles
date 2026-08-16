namespace Launcher.Application;

public abstract class ApplicationLaunchException(string message, Exception? innerException = null)
    : Exception(message, innerException);

public sealed class ApplicationConfigurationException(string gameSlug)
    : ApplicationLaunchException($"No valid owned application is configured for game '{gameSlug}'.");

public sealed class ApplicationNotFoundException()
    : ApplicationLaunchException("The configured application was not found.");

public sealed class ApplicationStartException(string message, Exception? innerException = null)
    : ApplicationLaunchException(message, innerException);

public sealed class ApplicationCrashedException()
    : ApplicationLaunchException("The target application exited unexpectedly.");

public sealed class ApplicationSessionUnavailableException()
    : ApplicationLaunchException("The application is running without a launcher-authenticated IPC session.");

public abstract class GameProcessException(string message, Exception? innerException = null)
    : Exception(message, innerException);

public sealed class GameInstallationNotFoundException()
    : GameProcessException("The Steam game installation was not found.");

public sealed class GameStartException(string message, Exception? innerException = null)
    : GameProcessException(message, innerException);

public sealed class GameStartTimeoutException()
    : GameProcessException("The game process did not start in time.");
