#include "Hook_Present.hpp"

#include <PericlesClient/CPericlesGUI.hpp>

auto Hook_Present( IDXGISwapChain* pSwapChain , UINT SyncInterval , UINT Flags ) -> HRESULT
{
	GetPericlesGUI()->OnPresent( pSwapChain );

	return Present_o( pSwapChain , SyncInterval , Flags );
}
