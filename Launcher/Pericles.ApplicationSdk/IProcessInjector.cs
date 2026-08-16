namespace Pericles.ApplicationSdk;

public interface IProcessInjector
{
    /// <summary>
    /// Injects a module into a target process
    /// </summary>
    /// <param name="targetProcessId">PID of the owned Pericles application that receives the DLL</param>
    /// <param name="dllPath">Absolute path to the DLL to inject</param>
    /// <param name="gameProcessId">
    /// Deadlock PID forwarded as opaque metadata only. It must never be used
    /// to select, open, inspect, or modify a process.
    /// </param>
    /// <param name="entryPoint">Optional entry point (Cdecl function returning 0 on success)</param>
    /// <returns>Injection result with status and optional handle</returns>
    ProcessInjectionResult Inject(
        int targetProcessId,
        string dllPath,
        int gameProcessId,
        string? entryPoint = null);
}

public record ProcessInjectionResult(
    bool Success,
    string Code, // "loaded", "already_loaded", "invalid_path", "hash_mismatch", "load_failed", "initialization_failed"
    string? ErrorMessage = null,
    nint? RemoteModuleHandle = null,
    int? NativeError = null);
