#include <Windows.h>
#include <TlHelp32.h>
#include <iostream>
#include <string>
#include <vector>
#include <chrono>
#include <thread>

static std::string GetExeDirectory()
{
    char buffer[MAX_PATH]{};
    GetModuleFileNameA(NULL, buffer, MAX_PATH);
    std::string path(buffer);
    auto pos = path.find_last_of("\\/");
    if (pos != std::string::npos)
        path.resize(pos + 1);
    return path;
}

static std::string ReadCommandLineArg(int index)
{
    int argc = 0;
    LPWSTR* argv = CommandLineToArgvW(GetCommandLineW(), &argc);
    if (!argv)
        return {};

    std::string result;
    if (index < argc)
    {
        int size = WideCharToMultiByte(CP_UTF8, 0, argv[index], -1, nullptr, 0, nullptr, nullptr);
        if (size > 0)
        {
            result.resize(size - 1);
            WideCharToMultiByte(CP_UTF8, 0, argv[index], -1, &result[0], size, nullptr, nullptr);
        }
    }
    LocalFree(argv);
    return result;
}

static DWORD FindProcessId(const std::wstring& processName)
{
    PROCESSENTRY32W entry{};
    entry.dwSize = sizeof(entry);

    HANDLE snapshot = CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0);
    if (snapshot == INVALID_HANDLE_VALUE)
        return 0;

    if (Process32FirstW(snapshot, &entry))
    {
        do
        {
            if (_wcsicmp(entry.szExeFile, processName.c_str()) == 0)
            {
                CloseHandle(snapshot);
                return entry.th32ProcessID;
            }
        } while (Process32NextW(snapshot, &entry));
    }

    CloseHandle(snapshot);
    return 0;
}

static std::wstring ToWideString(const std::string& str)
{
    if (str.empty())
        return {};

    int size = MultiByteToWideChar(CP_UTF8, 0, str.c_str(), -1, nullptr, 0);
    if (size == 0)
        return {};

    std::wstring wide(size, 0);
    MultiByteToWideChar(CP_UTF8, 0, str.c_str(), -1, &wide[0], size);
    if (!wide.empty() && wide.back() == L'\0')
        wide.pop_back();

    return wide;
}

static bool FileExists(const std::string& path)
{
    DWORD attributes = GetFileAttributesA(path.c_str());
    return (attributes != INVALID_FILE_ATTRIBUTES) && !(attributes & FILE_ATTRIBUTE_DIRECTORY);
}

static bool LaunchProcess(const std::string& targetPath, const std::string& workingDir, PROCESS_INFORMATION& pi)
{
    STARTUPINFOA si{};
    si.cb = sizeof(si);

    std::string commandLine = '"' + targetPath + '"';

    if (!CreateProcessA(nullptr, commandLine.data(), nullptr, nullptr, FALSE, CREATE_SUSPENDED, nullptr, workingDir.c_str(), &si, &pi))
        return false;

    return true;
}

static bool InjectLibrary(DWORD processId, const std::string& dllPath)
{
    HANDLE hProcess = OpenProcess(PROCESS_CREATE_THREAD | PROCESS_QUERY_INFORMATION | PROCESS_VM_OPERATION | PROCESS_VM_WRITE | PROCESS_VM_READ, FALSE, processId);
    if (!hProcess)
        return false;

    LPVOID alloc = VirtualAllocEx(hProcess, nullptr, dllPath.size() + 1, MEM_COMMIT | MEM_RESERVE, PAGE_READWRITE);
    if (!alloc)
    {
        CloseHandle(hProcess);
        return false;
    }

    if (!WriteProcessMemory(hProcess, alloc, dllPath.c_str(), dllPath.size() + 1, nullptr))
    {
        VirtualFreeEx(hProcess, alloc, 0, MEM_RELEASE);
        CloseHandle(hProcess);
        return false;
    }

    HMODULE hKernel32 = GetModuleHandleA("kernel32.dll");
    if (!hKernel32)
    {
        VirtualFreeEx(hProcess, alloc, 0, MEM_RELEASE);
        CloseHandle(hProcess);
        return false;
    }

    FARPROC loadLibrary = GetProcAddress(hKernel32, "LoadLibraryA");
    if (!loadLibrary)
    {
        VirtualFreeEx(hProcess, alloc, 0, MEM_RELEASE);
        CloseHandle(hProcess);
        return false;
    }

    HANDLE hThread = CreateRemoteThread(hProcess, nullptr, 0, reinterpret_cast<LPTHREAD_START_ROUTINE>(loadLibrary), alloc, 0, nullptr);
    if (!hThread)
    {
        VirtualFreeEx(hProcess, alloc, 0, MEM_RELEASE);
        CloseHandle(hProcess);
        return false;
    }

    WaitForSingleObject(hThread, INFINITE);
    CloseHandle(hThread);
    VirtualFreeEx(hProcess, alloc, 0, MEM_RELEASE);
    CloseHandle(hProcess);
    return true;
}

int main()
{
    const std::string exeDir = GetExeDirectory();
    const std::string dllPath = exeDir + "Pericles.dll";
    const std::wstring targetProcessName = L"deadlock.exe";

    if (!FileExists(dllPath))
    {
        printf("Pericles.dll not found in %s\n", exeDir.c_str());
        return 1;
    }

    printf("Waiting for %ls to start...\n", targetProcessName.c_str());
    DWORD processId = 0;
    while (processId == 0)
    {
        processId = FindProcessId(targetProcessName);
        if (processId == 0)
        {
            std::this_thread::sleep_for(std::chrono::milliseconds(500));
        }
    }

    printf("Found %ls with PID %u. Injecting %s...\n", targetProcessName.c_str(), processId, dllPath.c_str());

    if (!InjectLibrary(processId, dllPath))
    {
        printf("DLL injection failed\n");
        return 1;
    }

    printf("Injected %s into %ls (PID %u)\n", dllPath.c_str(), targetProcessName.c_str(), processId);
    return 0;
}
