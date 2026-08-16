using System.Runtime.InteropServices;
using System.Text;

namespace Pericles.ApplicationSdk;

public sealed class WindowsProcessInjector : IProcessInjector, IDisposable
{
    // Process access rights
    private const uint PROCESS_CREATE_THREAD = 0x0002;
    private const uint PROCESS_QUERY_INFORMATION = 0x0400;
    private const uint PROCESS_VM_OPERATION = 0x0008;
    private const uint PROCESS_VM_WRITE = 0x0020;
    private const uint PROCESS_VM_READ = 0x0010;

    // Memory allocation flags
    private const uint MEM_COMMIT = 0x00001000;
    private const uint MEM_RESERVE = 0x00002000;
    private const uint MEM_RELEASE = 0x8000;

    // Page protection flags
    private const uint PAGE_READWRITE = 0x04;

    // Thread wait timeout (10 seconds)
    private const uint WAIT_TIMEOUT = 10000;

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern IntPtr OpenProcess(uint dwDesiredAccess, bool bInheritHandle, int dwProcessId);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool CloseHandle(IntPtr hObject);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern IntPtr VirtualAllocEx(IntPtr hProcess, IntPtr lpAddress, uint dwSize,
        uint flAllocationType, uint flProtect);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool VirtualFreeEx(IntPtr hProcess, IntPtr lpAddress, uint dwSize, uint dwFreeType);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool WriteProcessMemory(IntPtr hProcess, IntPtr lpBaseAddress,
        byte[] lpBuffer, uint nSize, out IntPtr lpNumberOfBytesWritten);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern IntPtr GetProcAddress(IntPtr hModule, string lpProcName);

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern IntPtr GetModuleHandle(string lpModuleName);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern IntPtr CreateRemoteThread(IntPtr hProcess, IntPtr lpThreadAttributes,
        uint dwStackSize, IntPtr lpStartAddress, IntPtr lpParameter, uint dwCreationFlags,
        IntPtr lpThreadId);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern IntPtr CreateToolhelp32Snapshot(uint dwFlags, uint th32ProcessID);

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Ansi)]
    private struct ModuleEntry32
    {
        public uint dwSize;
        public uint th32ModuleID;
        public uint th32ProcessID;
        public uint GlblcntUsage;
        public uint ProccntUsage;
        public IntPtr modBaseAddr;
        public uint modBaseSize;
        public IntPtr hModule;
        [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 256)]
        public string szModule;
        [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 260)]
        public string szExePath;
    }

    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Ansi)]
    private static extern bool Module32First(IntPtr hSnapshot, ref ModuleEntry32 lpme);

    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Ansi)]
    private static extern bool Module32Next(IntPtr hSnapshot, ref ModuleEntry32 lpme);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern uint WaitForSingleObject(IntPtr hHandle, uint dwMilliseconds);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool GetExitCodeThread(IntPtr hThread, out IntPtr lpExitCode);

    private readonly Dictionary<int, HashSet<string>> _injectedModules = new();
    private readonly object _lock = new();

    private static IntPtr GetRemoteKernel32Base(int processId)
    {
        const uint TH32CS_SNAPMODULE = 0x00000008;
        const uint TH32CS_SNAPMODULE32 = 0x00000010;

        IntPtr snapshot = CreateToolhelp32Snapshot(TH32CS_SNAPMODULE | TH32CS_SNAPMODULE32, (uint)processId);
        if (snapshot == IntPtr.Zero || snapshot == new IntPtr(-1))
        {
            return IntPtr.Zero;
        }

        try
        {
            var module = new ModuleEntry32 { dwSize = (uint)Marshal.SizeOf<ModuleEntry32>() };

            if (Module32First(snapshot, ref module))
            {
                do
                {
                    if (string.Equals(Path.GetFileName(module.szModule), "kernel32.dll", StringComparison.OrdinalIgnoreCase))
                    {
                        return module.modBaseAddr;
                    }
                } while (Module32Next(snapshot, ref module));
            }
        }
        finally
        {
            CloseHandle(snapshot);
        }

        return IntPtr.Zero;
    }

    private static IntPtr GetRemoteLoadLibraryAddress(int processId, string procName = "LoadLibraryW")
    {
        IntPtr localKernel32 = GetModuleHandle("kernel32.dll");
        IntPtr localProcAddress = GetProcAddress(localKernel32, procName);
        if (localKernel32 == IntPtr.Zero || localProcAddress == IntPtr.Zero)
        {
            return IntPtr.Zero;
        }

        IntPtr remoteKernel32Base = GetRemoteKernel32Base(processId);
        if (remoteKernel32Base == IntPtr.Zero)
        {
            return IntPtr.Zero;
        }

        long delta = localProcAddress.ToInt64() - localKernel32.ToInt64();
        return new IntPtr(remoteKernel32Base.ToInt64() + delta);
    }

    /// <summary>
    /// Injects a module into the target game process
    /// </summary>
    public ProcessInjectionResult Inject(
        int targetProcessId,
        string dllPath,
        int gameProcessId,
        string? entryPoint = null)
    {
        // targetProcessId est UNIQUEMENT une métadonnée opaque - NE PAS UTILISER
        // Le PID cible pour l'injection EST gameProcessId

        Console.WriteLine($"[injector] Launching injection. targetProcessId={targetProcessId}, gameProcessId={gameProcessId}, dllPath={dllPath}");

        if (!OperatingSystem.IsWindows())
            throw new PlatformNotSupportedException("Process injection is supported only on Windows.");

        if (gameProcessId <= 0)
            return new ProcessInjectionResult(false, "load_failed",
                $"Invalid game process ID: {gameProcessId}");

        if (string.IsNullOrWhiteSpace(dllPath))
            return new ProcessInjectionResult(false, "invalid_path", "DLL path is empty");

        if (!File.Exists(dllPath))
            return new ProcessInjectionResult(false, "invalid_path",
                $"DLL not found: {dllPath}");

        Console.WriteLine($"[injector] Stage 1: validating DLL and game PID. currentPID={Environment.ProcessId}");

        // Vérifier si déjà injecté dans le jeu (gameProcessId)
        lock (_lock)
        {
            if (_injectedModules.TryGetValue(gameProcessId, out var modules) && modules.Contains(dllPath))
            {
                return new ProcessInjectionResult(true, "already_loaded");
            }
        }

        Console.WriteLine($"[injector] Stage 2: opening target process PID={gameProcessId}");
        // Ouvrir le processus du jeu - UTILISER gameProcessId
        IntPtr hProcess = OpenProcess(
            PROCESS_CREATE_THREAD | PROCESS_QUERY_INFORMATION | PROCESS_VM_OPERATION |
            PROCESS_VM_WRITE | PROCESS_VM_READ,
            false, gameProcessId);  // ← Injection DANS le jeu

        if (hProcess == IntPtr.Zero)
        {
            int error = Marshal.GetLastWin32Error();
            return new ProcessInjectionResult(false, "load_failed",
                $"Failed to open game process (PID: {gameProcessId}, Error: {error})",
                NativeError: error);
        }

        try
        {
            Console.WriteLine($"[injector] Stage 3: allocating remote memory in PID={gameProcessId}");
            // Allouer la mémoire dans le processus du jeu pour le chemin de la DLL
            byte[] dllPathBytes = Encoding.ASCII.GetBytes(dllPath);
            uint dllPathSize = (uint)dllPathBytes.Length + 1;

            IntPtr remoteDllPath = VirtualAllocEx(hProcess, IntPtr.Zero, dllPathSize,
                MEM_COMMIT | MEM_RESERVE, PAGE_READWRITE);

            if (remoteDllPath == IntPtr.Zero)
            {
                int error = Marshal.GetLastWin32Error();
                return new ProcessInjectionResult(false, "load_failed",
                    $"Failed to allocate memory in game process (Error: {error})",
                    NativeError: error);
            }

            try
            {
                // Écrire le chemin de la DLL dans la mémoire distante
                IntPtr bytesWritten;
                if (!WriteProcessMemory(hProcess, remoteDllPath, dllPathBytes, dllPathSize, out bytesWritten))
                {
                    int error = Marshal.GetLastWin32Error();
                    return new ProcessInjectionResult(false, "load_failed",
                        $"Failed to write DLL path to game process (Error: {error})",
                        NativeError: error);
                }

                Console.WriteLine($"[injector] Stage 4: resolving remote LoadLibraryW for PID={gameProcessId}");
                IntPtr loadLibraryAddr = GetRemoteLoadLibraryAddress(gameProcessId, "LoadLibraryW");
                if (loadLibraryAddr == IntPtr.Zero)
                {
                    int error = Marshal.GetLastWin32Error();
                    return new ProcessInjectionResult(false, "load_failed",
                        $"Failed to resolve remote LoadLibraryW for PID {gameProcessId} (Error: {error})",
                        NativeError: error);
                }

                Console.WriteLine($"[injector] Stage 5: creating remote thread in PID={gameProcessId} with LoadLibraryW={loadLibraryAddr}");
                // Créer un thread distant dans le jeu pour charger la DLL
                IntPtr hThread = CreateRemoteThread(hProcess, IntPtr.Zero, 0, loadLibraryAddr,
                    remoteDllPath, 0, IntPtr.Zero);

                if (hThread == IntPtr.Zero)
                {
                    int error = Marshal.GetLastWin32Error();
                    return new ProcessInjectionResult(false, "load_failed",
                        $"Failed to create remote thread (Error: {error})",
                        NativeError: error);
                }

                // Attendre la fin du thread (avec timeout)
                uint waitResult = WaitForSingleObject(hThread, WAIT_TIMEOUT);
                if (waitResult != 0)
                {
                    CloseHandle(hThread);
                    return new ProcessInjectionResult(false, "load_failed",
                        $"LoadLibrary thread timed out (Result: {waitResult})");
                }

                // Récupérer le code de retour (handle de la DLL)
                if (!GetExitCodeThread(hThread, out IntPtr moduleHandle))
                {
                    int error = Marshal.GetLastWin32Error();
                    CloseHandle(hThread);
                    return new ProcessInjectionResult(false, "load_failed",
                        $"Failed to get thread exit code (Error: {error})",
                        NativeError: error);
                }

                CloseHandle(hThread);

                // Si le handle est 0, le chargement a échoué
                if (moduleHandle == IntPtr.Zero)
                {
                    return new ProcessInjectionResult(false, "load_failed",
                        "LoadLibrary returned NULL - module load failed");
                }

                // Si un point d'entrée est spécifié, l'appeler
                if (!string.IsNullOrWhiteSpace(entryPoint))
                {
                    try
                    {
                        // Obtenir l'adresse du point d'entrée dans le module chargé
                        IntPtr entryPointAddr = GetProcAddress(moduleHandle, entryPoint);
                        if (entryPointAddr == IntPtr.Zero)
                        {
                            return new ProcessInjectionResult(false, "load_failed",
                                $"Entry point '{entryPoint}' not found in module");
                        }

                        // Créer un thread pour exécuter le point d'entrée
                        IntPtr entryThread = CreateRemoteThread(hProcess, IntPtr.Zero, 0,
                            entryPointAddr, IntPtr.Zero, 0, IntPtr.Zero);

                        if (entryThread == IntPtr.Zero)
                        {
                            int error = Marshal.GetLastWin32Error();
                            return new ProcessInjectionResult(false, "initialization_failed",
                                $"Failed to create entry point thread (Error: {error})",
                                NativeError: error);
                        }

                        // Attendre la fin de l'exécution du point d'entrée
                        waitResult = WaitForSingleObject(entryThread, WAIT_TIMEOUT);
                        if (waitResult != 0)
                        {
                            CloseHandle(entryThread);
                            return new ProcessInjectionResult(false, "initialization_failed",
                                $"Entry point thread timed out (Result: {waitResult})");
                        }

                        // Vérifier le code de retour
                        if (GetExitCodeThread(entryThread, out IntPtr entryExitCode))
                        {
                            CloseHandle(entryThread);
                            if (entryExitCode != IntPtr.Zero)
                            {
                                return new ProcessInjectionResult(false, "initialization_failed",
                                    $"Entry point returned error code: 0x{entryExitCode.ToInt64():X8}");
                            }
                        }
                        else
                        {
                            CloseHandle(entryThread);
                            return new ProcessInjectionResult(false, "initialization_failed",
                                "Failed to get entry point exit code");
                        }
                    }
                    catch (Exception ex)
                    {
                        return new ProcessInjectionResult(false, "initialization_failed",
                            $"Entry point execution failed: {ex.Message}");
                    }
                }

                // Enregistrer l'injection réussie dans gameProcessId
                lock (_lock)
                {
                    if (!_injectedModules.TryGetValue(gameProcessId, out var moduleSet))
                    {
                        moduleSet = new HashSet<string>();
                        _injectedModules[gameProcessId] = moduleSet;
                    }
                    moduleSet.Add(dllPath);
                }

                return new ProcessInjectionResult(true, "loaded",
                    RemoteModuleHandle: moduleHandle);
            }
            finally
            {
                // Libérer la mémoire distante
                if (remoteDllPath != IntPtr.Zero)
                {
                    VirtualFreeEx(hProcess, remoteDllPath, 0, MEM_RELEASE);
                }
            }
        }
        finally
        {
            CloseHandle(hProcess);
        }
    }

    /// <summary>
    /// Vérifie si un module est déjà injecté dans un processus
    /// </summary>
    public bool IsModuleInjected(int processId, string dllPath)
    {
        lock (_lock)
        {
            return _injectedModules.TryGetValue(processId, out var modules) && modules.Contains(dllPath);
        }
    }

    /// <summary>
    /// Récupère la liste des modules injectés pour un processus
    /// </summary>
    public IReadOnlySet<string> GetInjectedModules(int processId)
    {
        lock (_lock)
        {
            return _injectedModules.TryGetValue(processId, out var modules)
                ? modules.ToHashSet()
                : new HashSet<string>();
        }
    }

    /// <summary>
    /// Nettoie les structures de tracking
    /// </summary>
    public void Dispose()
    {
        lock (_lock)
        {
            _injectedModules.Clear();
        }
        GC.SuppressFinalize(this);
    }
}