#include "Hook_IsRelativeMouseMode.hpp"

#include <DeadLock/SDK/SDL3/SDL3_Functions.hpp>
#include <PericlesClient/CPericlesGUI.hpp>

auto Hook_IsRelativeMouseMode( CInputSystem* pInputSystem , bool Active ) -> void
{
	GetPericlesGUI()->m_bMainActive = Active;

	if ( GetPericlesGUI()->IsVisible() )
	{
		GetSDL3Functions()->SetCursorVisible( false );
		return IsRelativeMouseMode_o( pInputSystem , false );
	}

	IsRelativeMouseMode_o( pInputSystem , Active );
	GetSDL3Functions()->SetCursorVisible( !Active );
}
