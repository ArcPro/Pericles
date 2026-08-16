namespace Launcher.Core;

public sealed class LauncherException : Exception
{
    public LauncherException(LauncherError error, string message)
        : base(message)
    {
        Error = error;
    }

    public LauncherException(LauncherError error, string message, Exception innerException)
        : base(message, innerException)
    {
        Error = error;
    }

    public LauncherError Error { get; }
}
