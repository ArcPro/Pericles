#include "Hook_MouseInputEnabled.hpp"

#include <PericlesClient/CPericlesGUI.hpp>

auto Hook_MouseInputEnabled( CCitadelInput* pCCitadelInput ) -> bool
{
	if ( GetPericlesGUI()->IsVisible() )
		return false;

	return MouseInputEnabled_o( pCCitadelInput );
}
