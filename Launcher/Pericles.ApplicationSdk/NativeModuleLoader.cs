using System.Runtime.InteropServices;

namespace Pericles.ApplicationSdk;

public interface INativeModuleLoader
{
    NativeModuleReference Load(string absolutePath, string? entryPoint);
}

public sealed record NativeModuleReference(nint Handle, string Path, string? EntryPoint);

public sealed class WindowsNativeModuleLoader : INativeModuleLoader
{
    private const uint LoadLibrarySearchDllLoadDir = 0x00000100;
    private const uint LoadLibrarySearchDefaultDirs = 0x00001000;

    public NativeModuleReference Load(string absolutePath, string? entryPoint)
    {
        if (!OperatingSystem.IsWindows())
        {
            throw new PlatformNotSupportedException("Native modules are supported only on Windows.");
        }
        
        //ICI
        nint handle = LoadLibraryEx(
            absolutePath,
            0,
            LoadLibrarySearchDllLoadDir | LoadLibrarySearchDefaultDirs);
        if (handle == 0)
        {
            throw new NativeModuleLoadException("The native module could not be loaded.", Marshal.GetLastWin32Error());
        }
        if (!string.IsNullOrWhiteSpace(entryPoint))
        {
            nint address = GetProcAddress(handle, entryPoint);
            if (address == 0)
            {
                FreeLibrary(handle);
                throw new NativeModuleLoadException("The configured module entry point was not found.");
            }
            var initialize = Marshal.GetDelegateForFunctionPointer<ModuleInitialize>(address);
            int result;
            try
            {
                result = initialize();
            }
            catch (Exception exception)
            {
                throw new NativeModuleInitializationException(
                    "The module initialization entry point raised an error.",
                    handle,
                    exception);
            }
            if (result != 0)
            {
                // Initialization may have created native state. Keep the module mapped until process exit.
                throw new NativeModuleInitializationException("The module initialization entry point failed.", handle);
            }
        }
        return new NativeModuleReference(handle, absolutePath, entryPoint);
    }

    [UnmanagedFunctionPointer(CallingConvention.Cdecl)]
    private delegate int ModuleInitialize();

    [DllImport("kernel32.dll", EntryPoint = "LoadLibraryExW", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern nint LoadLibraryEx(string fileName, nint file, uint flags);

    [DllImport("kernel32.dll", CharSet = CharSet.Ansi, SetLastError = true)]
    private static extern nint GetProcAddress(nint module, string procedureName);

    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool FreeLibrary(nint module);
}

public class NativeModuleLoadException : Exception
{
    public NativeModuleLoadException(
        string message,
        int? nativeError = null,
        Exception? innerException = null) : base(message, innerException)
    {
        NativeError = nativeError;
    }

    public int? NativeError { get; }
}

public sealed class NativeModuleInitializationException(
    string message,
    nint retainedHandle,
    Exception? innerException = null)
    : NativeModuleLoadException(message, null, innerException)
{
    public nint RetainedHandle { get; } = retainedHandle;
}
