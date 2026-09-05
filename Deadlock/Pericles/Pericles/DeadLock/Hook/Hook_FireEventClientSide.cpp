#include "Hook_FireEventClientSide.hpp"

#include <PericlesClient/CPericlesClient.hpp>

auto Hook_FireEventClientSide( IGameEventManager2* pGameEventManager2 , IGameEvent* pGameEvent ) -> bool
{
	GetPericlesClient()->OnFireEventClientSide( pGameEvent );

	return FireEventClientSide_o( pGameEventManager2 , pGameEvent );
}
