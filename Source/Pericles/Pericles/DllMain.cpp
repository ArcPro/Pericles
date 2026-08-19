// DllMain.cpp
#include "DllMain.hpp"
#include "DllLauncher.hpp"

#include <fstream>
#include <ctime>

BOOL WINAPI DllMain(HINSTANCE hInstace, DWORD dwReason, LPVOID lpReserved)
{
    // Écrire dans un fichier immédiatement
    std::ofstream log("E:\\Pericles\\Pericles_Load.log", std::ios::app);
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
                std::ofstream log2("E:\\Pericles\\Pericles_Load.log", std::ios::app);
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
                std::ofstream log3("E:\\Pericles\\Pericles_Load.log", std::ios::app);
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
