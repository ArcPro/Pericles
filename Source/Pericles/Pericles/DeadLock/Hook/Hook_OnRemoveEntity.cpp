#include "Hook_OnRemoveEntity.hpp"

#include <PericlesClient/CPericlesClient.hpp>

auto Hook_OnRemoveEntity( CGameEntitySystem* pCGameEntitySystem , CEntityInstance* pInst , CHandle handle ) -> void
{
	GetPericlesClient()->OnRemoveEntity( pInst , handle );

	return OnRemoveEntity_o( pCGameEntitySystem , pInst , handle );
}
