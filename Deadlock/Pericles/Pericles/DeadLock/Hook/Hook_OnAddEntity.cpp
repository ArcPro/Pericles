#include "Hook_OnAddEntity.hpp"

#include <PericlesClient/CPericlesClient.hpp>

auto Hook_OnAddEntity( CGameEntitySystem* pCGameEntitySystem , CEntityInstance* pInst , CHandle handle ) -> void
{
	GetPericlesClient()->OnAddEntity( pInst , handle );

	return OnAddEntity_o( pCGameEntitySystem , pInst , handle );
}
