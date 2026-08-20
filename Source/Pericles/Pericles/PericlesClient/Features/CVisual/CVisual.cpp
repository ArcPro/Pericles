#include "CVisual.hpp"

#include <algorithm>

#include <DeadLock/SDK/SDK.hpp>
#include <DeadLock/SDK/Math/Math.hpp>
#include <DeadLock/SDK/Update/CUserCmd.hpp>
#include <DeadLock/SDK/Types/CEntityData.hpp>

#include <DeadLock/SDK/Interface/CGameEntitySystem.hpp>
#include <DeadLock/SDK/Interface/IEngineToClient.hpp>

#include <GameClient/CEntityCache/CEntityCache.hpp>

#include <GameClient/CL_CitadelPlayerPawn.hpp>
#include <GameClient/CL_CitadelPlayerController.hpp>
#include <GameClient/CL_Bones.hpp>
#include <GameClient/CL_VisibleCheck.hpp>
#include <GameClient/CL_AimTarget.hpp>

#include <PericlesClient/Settings/Settings.hpp>
#include <PericlesClient/Fonts/CFontManager.hpp>
#include <PericlesClient/Render/CRenderStackSystem.hpp>

static CVisual g_CVisual{};

auto CVisual::OnRender() -> void
{
	CEntityCache::CachedEntityVec_t CachedSnapshot;
	{
		std::scoped_lock Lock( GetEntityCache()->GetLock() );
		CachedSnapshot = *GetEntityCache()->GetCachedEntity();
	}

	for ( const auto& CachedEntity : CachedSnapshot )
	{
		auto pEntity = CachedEntity.m_Handle.Get();

		if ( !pEntity )
			continue;

		auto hEntity = pEntity->pEntityIdentity()->Handle();

		if ( hEntity != CachedEntity.m_Handle )
			continue;

		switch ( CachedEntity.m_Type )
		{
			case CachedEntity_t::CITADEL_PLAYER_CONTROLLER:
			{
				auto* pCCitadelPlayerController = reinterpret_cast<CCitadelPlayerController*>( pEntity );

				if ( pCCitadelPlayerController != GetCL_CitadelPlayerController()->GetLocal() )
					OnRenderPlayerEsp( pCCitadelPlayerController , CachedEntity.m_bVisible );
			}
			break;
			case CachedEntity_t::NPC_TROOPER:
			{
				auto* pC_NPC_Trooper = reinterpret_cast<C_NPC_Trooper*>( pEntity );

				if ( CachedEntity.m_bDraw )
					OnRenderTrooperEsp( pC_NPC_Trooper , CachedEntity.m_Bbox , CachedEntity.m_bVisible );
			}
			break;
			case CachedEntity_t::NPC_TROOPER_NEUTRAL:
			{
				auto* pC_NPC_TrooperNeutral = reinterpret_cast<C_NPC_TrooperNeutral*>( pEntity );

				// Neutral ESP computes its screen bounds from the prepared skeleton.
				// It must not depend on the lane-trooper bounding-box cache.
				if ( Settings::Visual::TrooperNeutral || Settings::Visual::TrooperNeutralSkeleton )
					OnRenderTrooperNeutralEsp( pC_NPC_TrooperNeutral , CachedEntity.m_bVisible );
			}
			break;
			default:
				break;
		}
	}

	if ( Settings::Visual::SoundStepEsp )
		OnRenderSound();

	if ( Settings::AimPreview::Active || Settings::AimPreview::SoulSteal )
		OnRenderAimPreview();
}

auto CVisual::OnRenderAimPreview() -> void
{
	const ImVec2 ScreenCenter = ImGui::GetIO().DisplaySize * 0.5f;
	const int MaxFovRadius = Settings::AimPreview::LegitMode ? 75 : 500;
	const float FovRadius = static_cast<float>( std::clamp( Settings::AimPreview::FovRadius , 25 , MaxFovRadius ) );

	if ( Settings::AimPreview::ShowFovCircle )
		GetRenderStackSystem()->DrawCircle( ScreenCenter , FovRadius , ImColor( 1.f , 1.f , 1.f , 0.65f ) );

	ImVec2 TargetScreen;
	bool bHaveTarget = false;
	bool bIsSoul = false;
	{
		std::scoped_lock Lock( m_AimPreviewLock );
		bHaveTarget = m_bAimPreviewHasTarget && GetTickCount64() - m_AimPreviewUpdateTime <= 150;
		bIsSoul = m_bAimPreviewIsSoul;
		TargetScreen = m_AimPreviewScreen;
	}

	if ( !bHaveTarget )
		return;

	const ImColor TargetColor = bIsSoul
		? ImColor( 0.2f , 0.85f , 1.f , 1.f )
		: ImColor( 1.f , 0.75f , 0.f , 1.f );
	GetRenderStackSystem()->DrawCircle( TargetScreen , bIsSoul ? 10.f : 9.f , TargetColor );
	GetRenderStackSystem()->DrawCircleFilled( TargetScreen , 3.f , TargetColor );
}

auto CVisual::UpdateAimPreviewTarget( bool bHaveSoul , const ImVec2& SoulScreen , bool bHaveAim , const ImVec2& AimScreen ) -> void
{
	std::scoped_lock Lock( m_AimPreviewLock );
	m_bAimPreviewHasTarget = bHaveSoul || bHaveAim;
	m_bAimPreviewIsSoul = bHaveSoul;
	m_AimPreviewScreen = bHaveSoul ? SoulScreen : AimScreen;
	m_AimPreviewUpdateTime = GetTickCount64();
}

auto CVisual::OnStartSound( const Vector3& Pos , const int SourceEntityIndex , const char* szSoundName ) -> void
{
	if ( strstr( szSoundName , XorStr( "Footstep" ) ) )
	{
		if ( auto* pBaseEntity = SDK::Interfaces::GameEntitySystem()->GetBaseEntity( SourceEntityIndex ); pBaseEntity )
		{
			if ( auto* pLocalCitadelPlayerController = GetCL_CitadelPlayerController()->GetLocal(); pLocalCitadelPlayerController )
			{
				if ( pLocalCitadelPlayerController->m_iTeamNum() != pBaseEntity->m_iTeamNum() )
				{
					if ( pBaseEntity->IsCitadelPlayerPawn() )
					{
						std::scoped_lock m_Lock( m_SoundLock );

						m_SoundList.emplace_back( GetTickCount64() , Pos );
					}
				}
			}
		}
	}
}

auto CVisual::OnClientOutput() -> void
{
	if ( Settings::Visual::Active )
	{
		OnRender();
		return;
	}

	// The aim overlay is independent from ESP. Disabling visual entities must
	// not hide the FOV circle or the current target indicator.
	if ( Settings::AimPreview::Active || Settings::AimPreview::SoulSteal )
		OnRenderAimPreview();
}

auto CVisual::OnCreateMove( CUserCmd* pCUserCmd ) -> void
{
	if ( !Settings::Visual::Active || !pCUserCmd )
		return;

	const auto CachedVec = GetEntityCache()->GetCachedEntity();
	++m_VisibilityUpdateCounter;
	const uint32_t CurrentFrame = m_VisibilityUpdateCounter;

	Vector3 CameraPos = Vector3( pCUserCmd->cmd.vec_camera_position().x() ,
									 pCUserCmd->cmd.vec_camera_position().y() ,
									 pCUserCmd->cmd.vec_camera_position().z() );

	std::scoped_lock CacheLock( GetEntityCache()->GetLock() );
	for ( auto& CachedEntity : *CachedVec )
	{
		uint32_t UpdateInterval = 0;
		if ( CachedEntity.m_Type == CachedEntity_t::CITADEL_PLAYER_CONTROLLER )
			UpdateInterval = g_HeroVisibilityUpdateInterval;
		else if ( CachedEntity.m_Type == CachedEntity_t::NPC_TROOPER
			|| CachedEntity.m_Type == CachedEntity_t::NPC_TROOPER_NEUTRAL )
			UpdateInterval = g_NpcVisibilityUpdateInterval;
		else
			continue;

		const uint32_t EntityShard = static_cast<uint32_t>( CachedEntity.m_Handle.GetEntryIndex() );
		if ( ( CurrentFrame + EntityShard ) % UpdateInterval != 0
			|| CurrentFrame - CachedEntity.m_LastVisibilityCheckFrame < UpdateInterval )
			continue;

		CachedEntity.m_LastVisibilityCheckFrame = CurrentFrame;

		auto pEntity = CachedEntity.m_Handle.Get();
		if ( !pEntity )
			continue;

		auto hEntity = pEntity->pEntityIdentity()->Handle();
		if ( hEntity != CachedEntity.m_Handle )
			continue;

		switch ( CachedEntity.m_Type )
		{
			case CachedEntity_t::CITADEL_PLAYER_CONTROLLER:
			{
				auto* pCCitadelPlayerController = reinterpret_cast<CCitadelPlayerController*>( pEntity );

				if ( pCCitadelPlayerController != GetCL_CitadelPlayerController()->GetLocal() )
					CachedEntity.m_bVisible = GetCL_VisibleCheck()->IsPlayerControllerVisible( CameraPos , pCCitadelPlayerController );
			}
			break;
			case CachedEntity_t::NPC_TROOPER:
			{
				auto* pC_NPC_Trooper = reinterpret_cast<C_NPC_Trooper*>( pEntity );
				CachedEntity.m_bVisible = GetCL_VisibleCheck()->IsTropperVisible( CameraPos , pC_NPC_Trooper );
			}
			break;
			case CachedEntity_t::NPC_TROOPER_NEUTRAL:
			{
				auto* pC_NPC_TrooperNeutral = reinterpret_cast<C_NPC_TrooperNeutral*>( pEntity );
				CachedEntity.m_bVisible = GetCL_VisibleCheck()->IsTropperNeutralVisible( CameraPos , pC_NPC_TrooperNeutral );
			}
			break;
			default:
				break;
		}
	}
}

auto CVisual::OnRenderSound() -> void
{
	std::scoped_lock m_Lock( m_SoundLock );

	auto NewEnd = std::remove_if( m_SoundList.begin() , m_SoundList.end() , []( const SoundData_t& Sound )
	{
		return GetTickCount64() - Sound.dwTime >= g_SoundShowTime;
	} );

	m_SoundList.erase( NewEnd , m_SoundList.end() );

	for ( const auto& Sound : m_SoundList )
	{
		auto Ratio = static_cast<float>( GetTickCount64() - Sound.dwTime ) / static_cast<float>( g_SoundShowTime );
		auto Alpha = std::lerp( 1.f , 0.f , Ratio );

		ImVec2 Screen;

		if ( Math::WorldToScreen( Sound.Pos , Screen ) )
		{
			constexpr static auto SoundSize = 20.f;
			auto Radius = std::lerp( SoundSize , 0.f , Ratio );

			GetRenderStackSystem()->DrawCircle3D( Sound.Pos , Radius , ImColor( 1.f , 1.f , 0.f , Alpha ) );
		}
	}
}

auto CVisual::OnRenderPlayerEsp( CCitadelPlayerController* pCCitadelPlayerController , const bool bVisible ) -> void
{
	if ( !pCCitadelPlayerController->IsAlive() )
		return;

	auto* pC_CitadelPlayerPawn = pCCitadelPlayerController->m_hHeroPawn().Get<C_CitadelPlayerPawn>();
	if ( !pC_CitadelPlayerPawn )
		return;

	auto* pSceneNode = pC_CitadelPlayerPawn->m_pGameSceneNode();
	if ( !pSceneNode || pSceneNode->m_bDormant() )
		return;

	auto* pLocalController = GetCL_CitadelPlayerController()->GetLocal();
	const bool bForceDraw = pLocalController == nullptr;
	const uint8 LocalTeam = bForceDraw ? 0 : pLocalController->m_iTeamNum();
	const bool bIsEnemy = pCCitadelPlayerController->m_iTeamNum() != LocalTeam;
	const bool bHeroEnabled = bForceDraw
		|| ( bIsEnemy && Settings::Visual::HeroEnemy )
		|| ( !bIsEnemy && Settings::Visual::HeroTeam );
	const bool bDrawEsp = bHeroEnabled && ( !Settings::Visual::OnlyVisible || bVisible );
	const bool bDrawHealth = bHeroEnabled && Settings::Visual::HeroHealth;

	if ( !bDrawEsp && !bDrawHealth )
		return;

	Vector3 vOrigin = pC_CitadelPlayerPawn->m_vOldOrigin();
	if ( vOrigin.IsZero() )
		vOrigin = pC_CitadelPlayerPawn->GetOrigin();

	ImVec2 OriginScreen;
	if ( vOrigin.IsZero() || !Math::WorldToScreen( vOrigin , OriginScreen ) )
		return;

	if ( !GetCL_Bones()->PrepareEntityBones( pC_CitadelPlayerPawn ) )
		return;

	const Vector3 vHeadPos = GetCL_Bones()->GetPreparedBonePositionByName( pC_CitadelPlayerPawn , XorStr( "head" ) );
	ImVec2 HeadScreen;
	if ( vHeadPos.IsZero() || !Math::WorldToScreen( vHeadPos , HeadScreen ) )
	{
		return;
	}

	const float BoxHeight = ( std::max )( 1.f , fabsf( OriginScreen.y - HeadScreen.y ) );
	const float BoxWidth = BoxHeight * 0.5f;
	const float Top = ( std::min )( OriginScreen.y , HeadScreen.y );
	const float Bottom = ( std::max )( OriginScreen.y , HeadScreen.y );
	const ImVec2 Min = { floorf( HeadScreen.x - BoxWidth * 0.5f ), floorf( Top ) };
	const ImVec2 Max = { floorf( HeadScreen.x + BoxWidth * 0.5f ), floorf( Bottom ) };

	auto PlayerColor = ImColor( 255 , 255 , 255 );
	if ( bIsEnemy )
	{
		PlayerColor = ImColor( Settings::Colors::Visual::HeroEnemy[0] , Settings::Colors::Visual::HeroEnemy[1] , Settings::Colors::Visual::HeroEnemy[2] );
		if ( bVisible )
			PlayerColor = ImColor( Settings::Colors::Visual::HeroEnemyVisible[0] , Settings::Colors::Visual::HeroEnemyVisible[1] , Settings::Colors::Visual::HeroEnemyVisible[2] );
	}
	else
	{
		PlayerColor = ImColor( Settings::Colors::Visual::HeroTeam[0] , Settings::Colors::Visual::HeroTeam[1] , Settings::Colors::Visual::HeroTeam[2] );
		if ( bVisible )
			PlayerColor = ImColor( Settings::Colors::Visual::HeroTeamVisible[0] , Settings::Colors::Visual::HeroTeamVisible[1] , Settings::Colors::Visual::HeroTeamVisible[2] );
	}

	if ( bDrawEsp && Settings::Visual::HeroBox )
	{
		if ( Settings::Visual::HeroBoxType == EVisualBoxType_t::BOX )
			GetRenderStackSystem()->DrawBox( Min , Max , PlayerColor );
		else if ( Settings::Visual::HeroBoxType == EVisualBoxType_t::OUTLINE_BOX )
			GetRenderStackSystem()->DrawOutlineBox( Min , Max , PlayerColor );
		else if ( Settings::Visual::HeroBoxType == EVisualBoxType_t::COAL_BOX )
			GetRenderStackSystem()->DrawCoalBox( Min , Max , PlayerColor );
		else if ( Settings::Visual::HeroBoxType == EVisualBoxType_t::OUTLINE_COAL_BOX )
			GetRenderStackSystem()->DrawOutlineCoalBox( Min , Max , PlayerColor );
	}

	if ( bDrawHealth )
	{
		const int CurrentHealth = ( std::max )( 0 , pCCitadelPlayerController->m_PlayerDataGlobal().m_iHealth() );
		const int MaxHealth = ( std::max )( 1 , pCCitadelPlayerController->m_PlayerDataGlobal().m_iHealthMax() );
		const float HealthRatio = std::clamp( static_cast<float>( CurrentHealth ) / static_cast<float>( MaxHealth ) , 0.f , 1.f );
		const float BarLeft = Min.x - 8.f;
		const float BarRight = Min.x - 3.f;
		const float FillTop = Max.y - ( Max.y - Min.y ) * HealthRatio;
		const ImColor HealthColor( 1.f - HealthRatio , HealthRatio , 0.12f , 1.f );

		GetRenderStackSystem()->DrawFillBox( ImVec2( BarLeft - 1.f , Min.y - 1.f ) , ImVec2( BarRight + 1.f , Max.y + 1.f ) , ImColor( 0.f , 0.f , 0.f , 0.8f ) );
		GetRenderStackSystem()->DrawFillBox( ImVec2( BarLeft , FillTop ) , ImVec2( BarRight , Max.y ) , HealthColor );

		const int TextX = static_cast<int>( ( Min.x + Max.x ) * 0.5f );
		const int TextY = static_cast<int>( Min.y ) - 15;
		GetRenderStackSystem()->DrawString( &GetFontManager()->m_VerdanaFont , TextX + 1 , TextY + 1 , FW1_CENTER , ImColor( 0.f , 0.f , 0.f , 1.f ) , "%d / %d" , CurrentHealth , MaxHealth );
		GetRenderStackSystem()->DrawString( &GetFontManager()->m_VerdanaFont , TextX , TextY , FW1_CENTER , ImColor( 1.f , 1.f , 1.f , 1.f ) , "%d / %d" , CurrentHealth , MaxHealth );
	}

	if ( bDrawEsp && Settings::Visual::HeroSkeleton )
		OnRenderHeroSkeleton( pC_CitadelPlayerPawn );
}

auto CVisual::OnRenderHeroSkeleton( C_CitadelPlayerPawn* pC_CitadelPlayerPawn ) -> void
{
	Vector3 BonePosStart , BonePosEnd;
	std::vector<RenderLine_t> Lines;
	Lines.reserve( g_AllSkeletonHeroPairBones.size() );
	const ImColor Color( Settings::Colors::Visual::HeroSkeleton[0] ,
		Settings::Colors::Visual::HeroSkeleton[1] , Settings::Colors::Visual::HeroSkeleton[2] );

	for ( const auto& Bones : g_AllSkeletonHeroPairBones )
	{
		const auto& [Start , End] = Bones;

		BonePosStart = GetCL_Bones()->GetPreparedBonePositionByName( pC_CitadelPlayerPawn , Start.c_str() );
		BonePosEnd = GetCL_Bones()->GetPreparedBonePositionByName( pC_CitadelPlayerPawn , End.c_str() );

		ImVec2 ScreenStart , ScreenEnd;

		if ( !BonePosStart.IsZero() && !BonePosEnd.IsZero() &&
			 Math::WorldToScreen( BonePosStart , ScreenStart ) &&
			 Math::WorldToScreen( BonePosEnd , ScreenEnd ) )
		{
			if ( !BonePosStart.IsZero() && !BonePosEnd.IsZero() )
			{
				Lines.push_back( { ScreenStart , ScreenEnd , Color , 2.f } );
			}
		}
	}

	GetRenderStackSystem()->DrawLineBatch( std::move( Lines ) );
}

auto CVisual::OnRenderTrooperEsp( C_NPC_Trooper* pC_NPC_Trooper , const Rect_t& bBox , const bool bVisible ) -> void
{
	if ( pC_NPC_Trooper->m_NPCState() == NPC_STATE_INIT ||
		 pC_NPC_Trooper->m_NPCState() == NPC_STATE_IDLE ||
		 pC_NPC_Trooper->m_NPCState() == NPC_STATE_ALERT ||
		 pC_NPC_Trooper->m_NPCState() == NPC_STATE_COMBAT )
	{
		const ImVec2 min = { floor( bBox.x ), floor( bBox.y ) };
		const ImVec2 max = { floor( bBox.w ), floor( bBox.h ) };

		auto Draw = false;
		auto ForceDraw = false;

		if ( !GetCL_CitadelPlayerController()->GetLocal() )
			ForceDraw = true;

		const auto LocalPlayerControllerTeamNum = ForceDraw ? 0 : GetCL_CitadelPlayerController()->GetLocal()->m_iTeamNum();
		const auto IsEnemy = pC_NPC_Trooper->m_iTeamNum() != LocalPlayerControllerTeamNum;

		if ( Settings::Visual::TrooperEnemy && IsEnemy )
			Draw = true;

		if ( Settings::Visual::TrooperTeam && !IsEnemy )
			Draw = true;

		if ( Settings::Visual::OnlyVisible && !bVisible && Draw )
			Draw = false;

		if ( ForceDraw && !Draw )
			Draw = true;

		if ( Draw )
		{
			auto TrooperColor = ImColor( 255 , 255 , 255 );

			if ( IsEnemy )
			{
				TrooperColor = ImColor( Settings::Colors::Visual::TrooperEnemy[0] , Settings::Colors::Visual::TrooperEnemy[1] , Settings::Colors::Visual::TrooperEnemy[2] );

				if ( bVisible )
					TrooperColor = ImColor( Settings::Colors::Visual::TrooperEnemyVisible[0] , Settings::Colors::Visual::TrooperEnemyVisible[1] , Settings::Colors::Visual::TrooperEnemyVisible[2] );
			}
			else
			{
				TrooperColor = ImColor( Settings::Colors::Visual::TrooperTeam[0] , Settings::Colors::Visual::TrooperTeam[1] , Settings::Colors::Visual::TrooperTeam[2] );

				if ( bVisible )
					TrooperColor = ImColor( Settings::Colors::Visual::TrooperTeamVisible[0] , Settings::Colors::Visual::TrooperTeamVisible[1] , Settings::Colors::Visual::TrooperTeamVisible[2] );
			}

			if ( Settings::Visual::TrooperBoxType == EVisualBoxType_t::BOX )
				GetRenderStackSystem()->DrawBox( min , max , TrooperColor );
			else if ( Settings::Visual::TrooperBoxType == EVisualBoxType_t::OUTLINE_BOX )
				GetRenderStackSystem()->DrawOutlineBox( min , max , TrooperColor );
			else if ( Settings::Visual::TrooperBoxType == EVisualBoxType_t::COAL_BOX )
				GetRenderStackSystem()->DrawCoalBox( min , max , TrooperColor );
			else if ( Settings::Visual::TrooperBoxType == EVisualBoxType_t::OUTLINE_COAL_BOX )
				GetRenderStackSystem()->DrawOutlineCoalBox( min , max , TrooperColor );

			if ( Settings::Visual::TrooperSkeleton )
				OnRenderTrooperSkeleton( pC_NPC_Trooper );
		}
	}
}

auto CVisual::OnRenderTrooperSkeleton( C_NPC_Trooper* pC_NPC_Trooper ) -> void
{
	if ( !GetCL_Bones()->PrepareEntityBones( pC_NPC_Trooper ) )
		return;

	Vector3 BonePosStart , BonePosEnd;
	std::vector<RenderLine_t> Lines;
	Lines.reserve( g_AllSkeletonTrooperPairBones.size() );
	const ImColor Color( Settings::Colors::Visual::TrooperSkeleton[0] ,
		Settings::Colors::Visual::TrooperSkeleton[1] , Settings::Colors::Visual::TrooperSkeleton[2] );

	for ( const auto& Bones : g_AllSkeletonTrooperPairBones )
	{
		const auto& [Start , End] = Bones;

		BonePosStart = GetCL_Bones()->GetPreparedBonePositionByName( pC_NPC_Trooper , Start.c_str() );
		BonePosEnd = GetCL_Bones()->GetPreparedBonePositionByName( pC_NPC_Trooper , End.c_str() );

		ImVec2 ScreenStart , ScreenEnd;

		if ( !BonePosStart.IsZero() && !BonePosEnd.IsZero() &&
				Math::WorldToScreen( BonePosStart , ScreenStart ) &&
				Math::WorldToScreen( BonePosEnd , ScreenEnd ) )
		{
			if ( !BonePosStart.IsZero() && !BonePosEnd.IsZero() )
			{
				Lines.push_back( { ScreenStart , ScreenEnd , Color , 2.f } );
			}
		}
	}

	GetRenderStackSystem()->DrawLineBatch( std::move( Lines ) );
}

auto CVisual::OnRenderTrooperNeutralEsp( C_NPC_TrooperNeutral* pC_NPC_TrooperNeutral , const bool bVisible ) -> void
{
	auto* pSceneNode = pC_NPC_TrooperNeutral ? pC_NPC_TrooperNeutral->m_pGameSceneNode() : nullptr;
	if ( !pSceneNode || pSceneNode->m_bDormant() )
		return;

	if ( pC_NPC_TrooperNeutral->m_NPCState() == NPC_STATE_INIT ||
		 pC_NPC_TrooperNeutral->m_NPCState() == NPC_STATE_IDLE ||
		 pC_NPC_TrooperNeutral->m_NPCState() == NPC_STATE_ALERT ||
		 pC_NPC_TrooperNeutral->m_NPCState() == NPC_STATE_COMBAT )
	{
		ImVec2 OriginScreen;
		const Vector3 Origin = pC_NPC_TrooperNeutral->GetOrigin();
		if ( Origin.IsZero() || !Math::WorldToScreen( Origin , OriginScreen ) )
			return;

		if ( GetCL_Bones()->PrepareEntityBones( pC_NPC_TrooperNeutral ) )
		{
			Rect_t bBox;
			const Vector3 vNeckBonePos = GetCL_Bones()->GetPreparedBonePositionByName( pC_NPC_TrooperNeutral , XorStr( "neck_0" ) );

			if ( !vNeckBonePos.IsZero() )
			{
				ImVec2 NeckScreen;
				auto W2SHead = Math::WorldToScreen( vNeckBonePos , NeckScreen );

				if ( W2SHead && Settings::Visual::TrooperNeutral )
				{
					const auto BoxHeight = floor( OriginScreen.y - NeckScreen.y );
					const auto BoxWidth = floor( BoxHeight / 1.5f );

					bBox.x = floor( NeckScreen.x - BoxWidth );
					bBox.y = floor( NeckScreen.y );
					bBox.w = floor( NeckScreen.x + BoxWidth );
					bBox.h = floor( OriginScreen.y );

					const ImVec2 min = { bBox.x, bBox.y };
					const ImVec2 max = { bBox.w, bBox.h };

					auto TrooperNeutralColor = ImColor( Settings::Colors::Visual::TrooperNeutral[0] , Settings::Colors::Visual::TrooperNeutral[1] , Settings::Colors::Visual::TrooperNeutral[2] );

					if ( bVisible )
						TrooperNeutralColor = ImColor( Settings::Colors::Visual::TrooperNeutralVisible[0] , Settings::Colors::Visual::TrooperNeutralVisible[1] , Settings::Colors::Visual::TrooperNeutralVisible[2] );

					if ( Settings::Visual::TrooperNeutralBoxType == EVisualBoxType_t::BOX )
						GetRenderStackSystem()->DrawBox( min , max , TrooperNeutralColor );
					else if ( Settings::Visual::TrooperNeutralBoxType == EVisualBoxType_t::OUTLINE_BOX )
						GetRenderStackSystem()->DrawOutlineBox( min , max , TrooperNeutralColor );
					else if ( Settings::Visual::TrooperNeutralBoxType == EVisualBoxType_t::COAL_BOX )
						GetRenderStackSystem()->DrawCoalBox( min , max , TrooperNeutralColor );
					else if ( Settings::Visual::TrooperNeutralBoxType == EVisualBoxType_t::OUTLINE_COAL_BOX )
						GetRenderStackSystem()->DrawOutlineCoalBox( min , max , TrooperNeutralColor );
				}
			}

			if ( Settings::Visual::TrooperNeutralSkeleton )
				OnRenderTrooperNeutralSkeleton( pC_NPC_TrooperNeutral );
		}
	}
}

auto CVisual::OnRenderTrooperNeutralSkeleton( C_NPC_TrooperNeutral* pC_NPC_TrooperNeutral ) -> void
{
	// OnRenderTrooperNeutralEsp already prepared this entity's skeleton once.
	Vector3 BonePosStart , BonePosEnd;
	std::vector<RenderLine_t> Lines;
	Lines.reserve( g_AllSkeletonTrooperNeutralPairBones.size() );
	const ImColor Color( Settings::Colors::Visual::TrooperNeutralSkeleton[0] ,
		Settings::Colors::Visual::TrooperNeutralSkeleton[1] , Settings::Colors::Visual::TrooperNeutralSkeleton[2] );

	for ( const auto& Bones : g_AllSkeletonTrooperNeutralPairBones )
	{
		const auto& [Start , End] = Bones;

		BonePosStart = GetCL_Bones()->GetPreparedBonePositionByName( pC_NPC_TrooperNeutral , Start.c_str() );
		BonePosEnd = GetCL_Bones()->GetPreparedBonePositionByName( pC_NPC_TrooperNeutral , End.c_str() );

		ImVec2 ScreenStart , ScreenEnd;

		if ( !BonePosStart.IsZero() && !BonePosEnd.IsZero() &&
				Math::WorldToScreen( BonePosStart , ScreenStart ) &&
				Math::WorldToScreen( BonePosEnd , ScreenEnd ) )
		{
			if ( !BonePosStart.IsZero() && !BonePosEnd.IsZero() )
			{
				Lines.push_back( { ScreenStart , ScreenEnd , Color , 2.f } );
			}
		}
	}

	GetRenderStackSystem()->DrawLineBatch( std::move( Lines ) );
}

auto CVisual::CalculateBoundingBoxes() -> void
{
	if ( !SDK::Interfaces::EngineToClient()->IsInGame() )
		return;

	const ULONGLONG Now = GetTickCount64();
	if ( Now - m_LastBoundingBoxUpdateTime < g_BoundingBoxUpdateIntervalMs )
		return;
	m_LastBoundingBoxUpdateTime = Now;

	const auto& CachedVec = GetEntityCache()->GetCachedEntity();
	++m_BoundingBoxUpdateCounter;
	const uint32_t CurrentFrame = m_BoundingBoxUpdateCounter;

	std::scoped_lock Lock( GetEntityCache()->GetLock() );

	for ( auto& it : *CachedVec )
	{
		// Only lane troopers consume the cached bounding box. Souls use their
		// collision center for targeting and neutrals build their box from bones.
		if ( it.m_Type != CachedEntity_t::NPC_TROOPER )
			continue;

		const uint32_t EntityShard = static_cast<uint32_t>( it.m_Handle.GetEntryIndex() );
		if ( ( CurrentFrame + EntityShard ) % g_BoundingBoxShardCount != 0 )
			continue;

		it.m_LastBoundingBoxUpdateFrame = CurrentFrame;

		auto pEntity = it.m_Handle.Get();

		if ( !pEntity )
			continue;

		auto hEntity = pEntity->pEntityIdentity()->Handle();

		if ( hEntity != it.m_Handle )
			continue;

		auto* pC_NPC_Trooper = reinterpret_cast<C_NPC_Trooper*>( pEntity );
		auto* pSceneNode = pC_NPC_Trooper->m_pGameSceneNode();
		if ( !pSceneNode || pSceneNode->m_bDormant() )
		{
			it.m_bDraw = false;
			continue;
		}

		it.m_bDraw = pC_NPC_Trooper->GetBoundingBox( it.m_Bbox );
	}
}

auto GetVisual() -> CVisual*
{
	return &g_CVisual;
}
