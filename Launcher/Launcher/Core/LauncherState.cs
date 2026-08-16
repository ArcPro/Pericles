namespace Launcher.Core;

public enum LauncherState
{
    Initializing,
    CheckingDeviceIdentity,
    Authenticating,
    CheckingLicense,
    PreparingModule,
    StartingApplication,
    Connecting,
    Ready,
    Failed,
    Stopped
}
