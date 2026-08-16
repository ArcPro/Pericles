#include "CPericlesClient.hpp"
#include "CPericlesGUI.hpp"

#include <algorithm>

#include "Fonts/CFontManager.hpp"

#include <DeadLock/SDK/SDK.hpp>
#include <DeadLock/SDK/Interface/IEngineToClient.hpp>
#include <DeadLock/SDK/Interface/CGameEntitySystem.hpp>
#include <DeadLock/SDK/Types/CEntityData.hpp>
#include <DeadLock/Protobuf/citadel_usermessages.pb.h>

#include <GameClient/CEntityCache/CEntityCache.hpp>
#include <GameClient/CL_AutoParry.hpp>
#include <GameClient/CL_CitadelPlayerController.hpp>

#include <PericlesClient/Features/CVisual/CVisual.hpp>
#include <PericlesClient/GUI/CPericlesMenu.hpp>
#include <PericlesClient/Settings/CSettingsJson.hpp>
#include <PericlesClient/Settings/Settings.hpp>
#include <PericlesClient/Render/CRenderStackSystem.hpp>

static CPericlesClient g_CPericlesClient{};

auto CPericlesClient::OnInit() -> void
{
	ResetHeadshotStats();
	GetPericlesMenu()->InitColors();
	GetPericlesMenu()->SetConfigSelected( GetSettingsJson()->GetConfigLoadedIndex() );
}

auto CPericlesClient::OnDamageMessage( const CCitadelUserMessage_Damage* pDamageMessage ) -> void
{
	if ( !pDamageMessage || !pDamageMessage->has_entindex_attacker()
		|| !pDamageMessage->has_entindex_victim() || !pDamageMessage->has_type()
		|| !pDamageMessage->has_hitgroup_id() )
	{
		return;
	}

	auto* pLocalController = GetCL_CitadelPlayerController()->GetLocal();
	if ( !pLocalController )
		return;

	const CHandle LocalPawnHandle = pLocalController->m_hHeroPawn();
	auto* pLocalControllerIdentity = pLocalController->pEntityIdentity();
	if ( !LocalPawnHandle.IsValid() || !pLocalControllerIdentity )
		return;

	const int LocalPawnIndex = LocalPawnHandle.GetEntryIndex();
	const int LocalControllerIndex = pLocalControllerIdentity->Handle().GetEntryIndex();
	const int AttackerIndex = pDamageMessage->entindex_attacker();
	if ( AttackerIndex != LocalPawnIndex && AttackerIndex != LocalControllerIndex )
		return;

	const int VictimIndex = pDamageMessage->entindex_victim();
	if ( VictimIndex <= 0 || VictimIndex == LocalPawnIndex )
		return;

	auto* pEntitySystem = SDK::Interfaces::GameEntitySystem();
	auto* pVictim = pEntitySystem ? pEntitySystem->GetBaseEntity( VictimIndex ) : nullptr;
	if ( !pVictim || !pVictim->IsCitadelPlayerPawn() )
		return;

	// DamageTypes_t from the current client schema. Count only confirmed bullet
	// or buckshot damage; abilities, melee hits and NPC damage are excluded.
	constexpr uint32_t DamageBullet = 0x00000002u;
	constexpr uint32_t DamageBuckshot = 0x00000800u;
	constexpr uint32_t DamageHeadshot = 0x00080000u;
	const uint32_t DamageType = static_cast<uint32_t>( pDamageMessage->type() );
	if ( ( DamageType & ( DamageBullet | DamageBuckshot ) ) == 0 )
		return;

	uint64_t HitCount = 1;
	if ( pDamageMessage->has_hits() && pDamageMessage->hits() > 0 )
		HitCount = static_cast<uint64_t>( ( std::min )( pDamageMessage->hits() , 64 ) );

	m_PlayerBulletHits.fetch_add( HitCount , std::memory_order_relaxed );

	constexpr int HitGroupHead = 1;
	constexpr int HitGroupHeadNoResist = 19;
	const int HitGroup = pDamageMessage->hitgroup_id();
	if ( ( DamageType & DamageHeadshot ) != 0 || HitGroup == HitGroupHead || HitGroup == HitGroupHeadNoResist )
		m_PlayerBulletHeadshots.fetch_add( HitCount , std::memory_order_relaxed );
}

auto CPericlesClient::OnFireEventClientSide( IGameEvent* pGameEvent ) -> void
{

}

auto CPericlesClient::OnAddEntity( CEntityInstance* pInst , CHandle handle ) -> void
{
	GetEntityCache()->OnAddEntity( pInst , handle );
}

auto CPericlesClient::OnRemoveEntity( CEntityInstance* pInst , CHandle handle ) -> void
{
	GetEntityCache()->OnRemoveEntity( pInst , handle );
}

auto CPericlesClient::OnStartSound( const Vector3& Pos , const int SourceEntityIndex , const char* szSoundName ) -> void
{
	RegisterAutoParrySound( Pos , SourceEntityIndex , szSoundName );
	GetVisual()->OnStartSound( Pos , SourceEntityIndex , szSoundName );
}

auto CPericlesClient::OnClientOutput() -> void
{
	if ( SDK::Interfaces::EngineToClient()->IsInGame() )
		GetVisual()->OnClientOutput();
}

auto CPericlesClient::OnRender() -> void
{
	auto* pEngineToClient = SDK::Interfaces::EngineToClient();
	const bool bInGame = pEngineToClient && pEngineToClient->IsInGame();
	if ( !bInGame && m_bWasInGame )
		ResetHeadshotStats();
	m_bWasInGame = bInGame;

	if ( GetPericlesGUI()->IsVisible() )
		GetPericlesMenu()->OnRenderMenu();

	if ( Settings::Misc::ShowCheatOverlay )
	{
		GetFontManager()->FirstInitFonts();
		GetFontManager()->m_VerdanaFont.DrawString( 1 , 1 , ImColor( 1.f , 1.f , 0.f ) , FW1_LEFT ,
			XorStr( "%s | HS %.1f%%" ) , XorStr( CHEAT_NAME ) , GetHeadshotPercentage() );
	}

	GetRenderStackSystem()->OnRenderStack();
}

auto CPericlesClient::ResetHeadshotStats() -> void
{
	m_PlayerBulletHits.store( 0 , std::memory_order_relaxed );
	m_PlayerBulletHeadshots.store( 0 , std::memory_order_relaxed );
}

auto CPericlesClient::GetHeadshotPercentage() const -> float
{
	const uint64_t TotalHits = m_PlayerBulletHits.load( std::memory_order_relaxed );
	if ( TotalHits == 0 )
		return 0.f;

	const uint64_t Headshots = m_PlayerBulletHeadshots.load( std::memory_order_relaxed );
	return static_cast<float>( Headshots ) * 100.f / static_cast<float>( TotalHits );
}

auto CPericlesClient::OnCreateMove( CCitadelInput* pCitadelInput , CUserCmd* pUserCmd ) -> void
{
	GetVisual()->OnCreateMove( pUserCmd );

	if ( Settings::Misc::UnlockMiniMap )
	{
		static uint32_t MinimapRefreshCounter = 0;
		++MinimapRefreshCounter;
		const bool bRefreshFoW = ( MinimapRefreshCounter % 120 ) == 0;

		if ( bRefreshFoW )
		{
			FOR_EACH_ENTITY( idx )
			{
				auto* pC_BaseEntity = SDK::Interfaces::GameEntitySystem()->GetBaseEntity( idx );
				auto* pBinding = pC_BaseEntity ? pC_BaseEntity->GetSchemaClassBinding() : nullptr;

				if ( pBinding && strcmp( pBinding->m_bindingName() , XorStr( "C_CitadelTeam" ) ) == 0 )
				{
					auto* pC_CitadelTeam = reinterpret_cast<C_CitadelTeam*>( pC_BaseEntity );
					for ( auto& FOWEntity : pC_CitadelTeam->m_vecFOWEntities() )
					{
						// Neutral-camp markers are availability indicators, not ordinary
						// fog-of-war entities. Their networked visibility is authoritative:
						// false while cleared, true again after the camp respawns.
						constexpr uint32_t FirstNeutralCampClass = 0x22; // CLASS_WEAK_NEUTRAL_CAMP
						constexpr uint32_t LastNeutralCampClass = 0x26;  // CLASS_SUPER_NEUTRAL_CAMP
						const uint32_t EntityClass = FOWEntity.m_eClass();
						const bool IsNeutralCamp = EntityClass >= FirstNeutralCampClass && EntityClass <= LastNeutralCampClass;

						if ( !IsNeutralCamp )
							FOWEntity.m_bVisibleOnMap() = true;
					}
				}
			}
		}
	}
}

auto GetPericlesClient() -> CPericlesClient*
{
	return &g_CPericlesClient;
}
