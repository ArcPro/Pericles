// DllLauncher.cpp
#include "DllMain.hpp"
#include "DllLauncher.hpp"
#include "Common/CrashLog.hpp"
#include "Common/Helpers/StringHelper.hpp"
#include "DeadLock/CHook_Loader.hpp"
#include "DeadLock/CSDK_Loader.hpp"
#include "DeadLock/SDK/CFunctionList.hpp"
#include "PericlesClient/CPericlesClient.hpp"
#include "PericlesClient/CPericlesGUI.hpp"
#include "PericlesClient/Settings/CSettingsJson.hpp"

#include <string>
#include <winternl.h>
#include <fstream>
#include <chrono>
#include <ctime>

static CDllLauncher g_DllLauncher{};

auto GetDllDir() -> std::string&
{
    return g_DllLauncher.m_DllDir;
}

auto GetDeadLockDir() -> std::string
{
    return g_DllLauncher.m_DeadLockDir;
}

auto GetDllLauncher() -> CDllLauncher*
{
    return &g_DllLauncher;
}

static void WriteDebugLog(const char* format, ...)
{
    char buffer[1024];
    va_list args;
    va_start(args, format);
    vsnprintf(buffer, sizeof(buffer), format, args);
    va_end(args);

    // Écrit dans E:\Pericles\Pericles_Debug.log
    std::ofstream log("E:\\Pericles\\Pericles_Debug.log", std::ios::app);
    if (log.is_open()) {
        auto now = std::chrono::system_clock::now();
        auto time = std::chrono::system_clock::to_time_t(now);
        char timestamp[26] = {};
        ctime_s(timestamp, sizeof(timestamp), &time);
        log << timestamp << " - " << buffer << std::endl;
        log.close();
    }

    // OutputDebugString
    OutputDebugStringA("[Pericles] ");
    OutputDebugStringA(buffer);
    OutputDebugStringA("\n");
}

auto CDllLauncher::OnDllMain(LPVOID lpReserved, HINSTANCE hInstace) -> void
{
    WriteDebugLog("OnDllMain - Start, lpReserved=%p", lpReserved);

    if (lpReserved)
    {
        ManualMapParam_t* pParam = reinterpret_cast<ManualMapParam_t*>(lpReserved);
        if (pParam)
        {
            m_DllDir = pParam->m_DllDir;
            m_DllDir = m_DllDir.substr(0, m_DllDir.find_last_of('\\') + 1);
            WriteDebugLog("OnDllMain - Param DllDir: %s", m_DllDir.c_str());
        }
    }
    else
    {
        char szDllDir[MAX_PATH];
        GetModuleFileNameA(hInstace, szDllDir, MAX_PATH);
        WriteDebugLog("OnDllMain - ModuleFileName: %s", szDllDir);
        m_DllDir = szDllDir;
        m_DllDir = m_DllDir.substr(0, m_DllDir.find_last_of('\\'));
        m_DllDir += '\\';
    }

    WriteDebugLog("OnDllMain - Final DllDir: %s", m_DllDir.c_str());

    m_hDllImage = hInstace;
    m_SizeofImage = GetSizeOfImageInternal();
    m_BaseOfCode = GetBaseOfCodeInternal();

    char szGameFile[MAX_PATH] = {0};
    GetModuleFileNameA(0, szGameFile, MAX_PATH);
    m_DeadLockDir = szGameFile;
    m_DeadLockDir = m_DeadLockDir.substr(0, m_DeadLockDir.find_last_of("\\/"));
    m_DeadLockDir += '\\';
    WriteDebugLog("OnDllMain - Final DllDir: %s", m_DllDir.c_str());

    WriteDebugLog("OnDllMain - Creating StartCheatThread");
    HANDLE hThread = CreateThread(0, 0, StartCheatTheard, lpReserved, 0, 0);
    if (hThread == NULL) {
        WriteDebugLog("OnDllMain - CreateThread failed, error=%d", GetLastError());
    } else {
        WriteDebugLog("OnDllMain - CreateThread succeeded, handle=%p", hThread);
        CloseHandle(hThread);
    }
}

auto CDllLauncher::OnDestroy() -> void
{
    WriteDebugLog("OnDestroy - Start");
    // ... (code existant)
    WriteDebugLog("OnDestroy - End");
}

auto WINAPI CDllLauncher::StartCheatTheard(LPVOID lpThreadParameter) -> DWORD
{
    WriteDebugLog("StartCheatTheard - BEGIN");
    WriteDebugLog("StartCheatTheard - DllDir: %s", GetDllLauncher()->m_DllDir.c_str());

    // Attendre que le jeu soit prêt (optionnel)
    Sleep(2000);
    WriteDebugLog("StartCheatTheard - After sleep");

    GetDevLog()->Init();
    WriteDebugLog("StartCheatTheard - DevLog initialized");

    GetCrashLog()->InitVectorExceptionHandler();
    WriteDebugLog("StartCheatTheard - CrashLog initialized");

    DEV_LOG("[+] StartCheatThread: %s\n", ansi_to_utf8(GetDllDir()).c_str());

    WriteDebugLog("StartCheatTheard - Step 1: InitalizeMH");
    if (!GetHook_Loader()->InitalizeMH())
    {
        WriteDebugLog("ERROR: Hook_Loader::InitalizeMH failed!");
        DEV_LOG("[error] Hook_Loader::InitalizeMH\n");
        return 0;
    }
    WriteDebugLog("Step 1: InitalizeMH OK");

    WriteDebugLog("Step 2: FunctionList::OnInit");
    if (!GetFunctionList()->OnInit())
    {
        WriteDebugLog("ERROR: FunctionList::OnInit failed!");
        DEV_LOG("[error] FunctionList::OnInit\n");
        return 0;
    }
    WriteDebugLog("Step 2: FunctionList::OnInit OK");

    WriteDebugLog("Step 3: CSDK_Loader::LoadSDK");
    if (!GetSDK_Loader()->LoadSDK())
    {
        WriteDebugLog("ERROR: CSDK_Loader::LoadSDK failed!");
        DEV_LOG("[error] CSDK_Loader::LoadSDK\n");
        return 0;
    }
    WriteDebugLog("Step 3: CSDK_Loader::LoadSDK OK");

    WriteDebugLog("Step 4: Hook_Loader::InstallSecondHook");
    if (!GetHook_Loader()->InstallSecondHook())
    {
        WriteDebugLog("ERROR: Hook_Loader::InstallSecondHook failed!");
        DEV_LOG("[error] Hook_Loader::InstallSecondHook\n");
        return 0;
    }
    WriteDebugLog("Step 4: Hook_Loader::InstallSecondHook OK");

    WriteDebugLog("Step 5: Loading settings");
    GetSettingsJson()->UpdateConfigList();
    GetSettingsJson()->LoadConfig(CONFIG_FILE);
    WriteDebugLog("Step 5: Settings loaded OK");

    WriteDebugLog("Step 6: Waiting for GUI initialization");
    while (!GetPericlesGUI()->IsInited())
    {
        Sleep(100);
        // Forcer le traitement des messages (utile si la GUI est créée sur un autre thread)
        MSG msg;
        while (PeekMessage(&msg, NULL, 0, 0, PM_REMOVE))
        {
            TranslateMessage(&msg);
            DispatchMessage(&msg);
        }
    }
    WriteDebugLog("Step 6: GUI initialized OK");

    WriteDebugLog("Step 7: PericlesClient::OnInit");
    GetPericlesClient()->OnInit();
    WriteDebugLog("Step 7: PericlesClient::OnInit OK");

    WriteDebugLog("StartCheatTheard - END SUCCESS");
    return 0;
}
