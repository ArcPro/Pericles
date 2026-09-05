// DllMain.cpp
#include "DllMain.hpp"
#include "DllLauncher.hpp"

#include <fstream>
#include <ctime>
#include <string>

namespace
{
    auto GetLoadLogPath(HINSTANCE instance) -> std::string
    {
        char modulePath[MAX_PATH] = {};
        if (GetModuleFileNameA(instance, modulePath, MAX_PATH) == 0)
            return "Pericles_Load.log";

        std::string path(modulePath);
        const auto separator = path.find_last_of("\\/");
        if (separator == std::string::npos)
            return "Pericles_Load.log";

        path.resize(separator + 1);
        return path + "Pericles_Load.log";
    }
}

BOOL WINAPI DllMain(HINSTANCE hInstace, DWORD dwReason, LPVOID lpReserved)
{
    // Keep diagnostics beside the loaded DLL instead of in the repository root.
    const auto loadLogPath = GetLoadLogPath(hInstace);
    std::ofstream log(loadLogPath, std::ios::app);
    if (log.is_open()) {
        time_t now = time(nullptr);
        char timestamp[26] = {};
        ctime_s(timestamp, sizeof(timestamp), &now);
        log << timestamp << " - DllMain called, reason=" << dwReason << "\n";
        log.close();
    }

    switch (dwReason)
    {
        case DLL_PROCESS_ATTACH:
            // Log avant tout
            {
                std::ofstream log2(loadLogPath, std::ios::app);
                if (log2.is_open()) {
                    log2 << "DLL_PROCESS_ATTACH - calling OnDllMain\n";
                    log2.close();
                }
            }
            DisableThreadLibraryCalls(hInstace);
            GetDllLauncher()->OnDllMain(lpReserved, hInstace);
            return TRUE;
        case DLL_PROCESS_DETACH:
            {
                std::ofstream log3(loadLogPath, std::ios::app);
                if (log3.is_open()) {
                    log3 << "DLL_PROCESS_DETACH\n";
                    log3.close();
                }
            }
            GetDllLauncher()->OnDestroy();
            return TRUE;
    }
    return TRUE;
}
