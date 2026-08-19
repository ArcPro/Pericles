namespace Pericles.ApplicationSdk;

public interface IProcessInjector
{
    /// <summary>
    /// Injects a module into the game process.
    /// </summary>
    /// <param name="gameProcessId">PID of the game process that receives the DLL.</param>
    /// <param name="dllPath">Absolute path to the DLL to inject</param>
    /// <param name="entryPoint">Optional entry point (Cdecl function returning 0 on success)</param>
    /// <returns>Injection result with status and optional handle</returns>
    ProcessInjectionResult Inject(
        int gameProcessId,
        string dllPath,
        string? entryPoint = null);
}

public record ProcessInjectionResult(
    bool Success,
    string Code, // "loaded", "already_loaded", "invalid_path", "hash_mismatch", "load_failed", "initialization_failed"
    string? ErrorMessage = null,
    nint? RemoteModuleHandle = null,
    int? NativeError = null);
