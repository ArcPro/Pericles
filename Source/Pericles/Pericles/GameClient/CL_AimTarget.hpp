#pragma once

#include <algorithm>
#include <limits>
#include <mutex>

#include <ImGui/imgui.h>

#include <DeadLock/SDK/Math/Math.hpp>
#include <DeadLock/SDK/SDK.hpp>
#include <DeadLock/SDK/Types/CEntityData.hpp>
#include <DeadLock/SDK/Update/CGlobalVarsBase.hpp>

#include <GameClient/CEntityCache/CEntityCache.hpp>
#include <GameClient/CL_Bones.hpp>
#include <GameClient/CL_Trace.hpp>

#include <PericlesClient/Settings/Settings.hpp>

struct AimTargetResult_t
{
	Vector3 m_WorldPosition;
	ImVec2 m_ScreenPosition;
	C_BaseEntity* m_TargetEntity = nullptr;
	int m_EntityIndex = -1;
	int m_HeroControllerIndex = -1;
	int m_BoneIndex = -1;
	int m_Health = 0;
};

struct SoulTargetResult_t
{
	Vector3 m_WorldPosition;
	ImVec2 m_ScreenPosition;
	C_BaseEntity* m_TargetEntity = nullptr;
	int m_EntityIndex = -1;
};

inline constexpr const char* g_AimTargetBones[] =
{
	"head",
	"pelvis",
	"spine_3",
	"spine_0",
	"arm_lower_L",
	"arm_lower_R",
	"leg_lower_L",
	"leg_lower_R"
};

inline auto GetAimTargetBoneIndex( const char* szBoneName ) -> int
{
	if ( !szBoneName )
		return -1;

	for ( int Index = 0; Index < static_cast<int>( std::size( g_AimTargetBones ) ); ++Index )
	{
		if ( strcmp( g_AimTargetBones[Index], szBoneName ) == 0 )
			return Index;
	}

	return -1;
}

inline auto NormalizeAimBoneMask() -> int
{
	constexpr int ValidMask = ( 1 << static_cast<int>( std::size( g_AimTargetBones ) ) ) - 1;
	int Mask = Settings::AimPreview::TargetBonesMask & ValidMask;
	if ( Mask == 0 )
		Mask = 1;
	Settings::AimPreview::TargetBonesMask = Mask;
	return Mask;
}

inline auto GetFirstSelectedAimBone() -> const char*
{
	const int Mask = NormalizeAimBoneMask();
	for ( int Index = 0; Index < static_cast<int>( std::size( g_AimTargetBones ) ); ++Index )
	{
		if ( ( Mask & ( 1 << Index ) ) != 0 )
			return g_AimTargetBones[Index];
	}

	return g_AimTargetBones[0];
}

inline auto GetAutomaticSelectedAimBoneIndex() -> int
{
	const int Mask = NormalizeAimBoneMask();
	constexpr int HeadIndex = 0;
	int OtherSelectedIndices[std::size( g_AimTargetBones )]{};
	int OtherSelectedCount = 0;

	for ( int Index = 1; Index < static_cast<int>( std::size( g_AimTargetBones ) ); ++Index )
	{
		if ( ( Mask & ( 1 << Index ) ) != 0 )
			OtherSelectedIndices[OtherSelectedCount++] = Index;
	}

	static uint32_t RandomState = ( static_cast<uint32_t>( GetTickCount64() ) ^ 0x9E3779B9u ) | 1u;
	static int CurrentBoneIndex = -1;
	static int HeadQuota = 0;
	static int LastMask = -1;
	static int LastHeadChance = -1;
	static ULONGLONG NextBoneSelectionAt = 0;
	const auto NextRandom = []() -> uint32_t
	{
		RandomState ^= RandomState << 13;
		RandomState ^= RandomState >> 17;
		RandomState ^= RandomState << 5;
		return RandomState;
	};

	const int HeadChance = std::clamp( Settings::AimPreview::HeadChance , 0 , 100 );
	const ULONGLONG Now = GetTickCount64();
	const bool bSettingsChanged = LastMask != Mask || LastHeadChance != HeadChance;

	// An os selection is kept for a fixed slot. Head probability is consumed
	// once per slot, rather than once per rendered/CreateMove frame; transition
	// frames therefore cannot inflate or reduce the configured percentage.
	constexpr ULONGLONG BoneSelectionIntervalMs = 500;
	if ( CurrentBoneIndex < 0 || bSettingsChanged || Now >= NextBoneSelectionAt )
	{
		if ( bSettingsChanged )
		{
			HeadQuota = 0;
			LastMask = Mask;
			LastHeadChance = HeadChance;
		}

		const bool bHeadSelected = ( Mask & ( 1 << HeadIndex ) ) != 0;
		bool bChooseHead = bHeadSelected && OtherSelectedCount == 0;
		if ( bHeadSelected && OtherSelectedCount > 0 )
		{
			HeadQuota += HeadChance;
			if ( HeadQuota >= 100 )
			{
				HeadQuota -= 100;
				bChooseHead = true;
			}
		}

		if ( bChooseHead )
		{
			CurrentBoneIndex = HeadIndex;
		}
		else if ( OtherSelectedCount > 0 )
		{
			int OtherSlot = static_cast<int>( NextRandom() % static_cast<uint32_t>( OtherSelectedCount ) );
			if ( OtherSelectedCount > 1 && OtherSelectedIndices[OtherSlot] == CurrentBoneIndex )
				OtherSlot = ( OtherSlot + 1 ) % OtherSelectedCount;
			CurrentBoneIndex = OtherSelectedIndices[OtherSlot];
		}
		else
		{
			CurrentBoneIndex = HeadIndex;
		}

		NextBoneSelectionAt = Now + BoneSelectionIntervalMs;
	}

	return CurrentBoneIndex;
}

inline auto GetAutomaticSelectedAimBone() -> const char*
{
	return g_AimTargetBones[GetAutomaticSelectedAimBoneIndex()];
}

inline auto ApplyAutomaticBoneTransition( AimTargetResult_t& Target , const int SelectedBoneIndex ) -> void
{
	if ( !Target.m_TargetEntity || Target.m_HeroControllerIndex < 0 || Target.m_WorldPosition.IsZero() )
		return;

	struct TransitionState_t
	{
		int TargetEntityIndex = -1;
		int DestinationBoneIndex = -1;
		Vector3 SourcePosition;
		Vector3 LastOutputPosition;
		ULONGLONG TransitionStartedAt = 0;
		ULONGLONG LastUpdateAt = 0;
	};

	static TransitionState_t State;
	const ULONGLONG Now = GetTickCount64();
	constexpr ULONGLONG BoneTransitionDurationMs = 180;
	constexpr ULONGLONG TransitionResetGapMs = 250;

	const bool bReset = State.TargetEntityIndex != Target.m_EntityIndex
		|| State.LastUpdateAt == 0
		|| Now - State.LastUpdateAt > TransitionResetGapMs;

	if ( bReset )
	{
		State.TargetEntityIndex = Target.m_EntityIndex;
		State.DestinationBoneIndex = SelectedBoneIndex;
		State.SourcePosition = Target.m_WorldPosition;
		State.LastOutputPosition = Target.m_WorldPosition;
		State.TransitionStartedAt = 0;
	}
	else if ( State.DestinationBoneIndex != SelectedBoneIndex )
	{
		State.DestinationBoneIndex = SelectedBoneIndex;
		State.SourcePosition = State.LastOutputPosition;
		State.TransitionStartedAt = Now;
	}

	if ( State.TransitionStartedAt != 0 )
	{
		const float LinearProgress = std::clamp(
			static_cast<float>( Now - State.TransitionStartedAt )
				/ static_cast<float>( BoneTransitionDurationMs ), 0.f, 1.f );
		const float SmoothProgress = LinearProgress * LinearProgress * ( 3.f - 2.f * LinearProgress );
		Target.m_WorldPosition = State.SourcePosition * ( 1.f - SmoothProgress )
			+ Target.m_WorldPosition * SmoothProgress;

		if ( LinearProgress >= 1.f )
			State.TransitionStartedAt = 0;
	}

	State.LastOutputPosition = Target.m_WorldPosition;
	State.LastUpdateAt = Now;
	Math::WorldToScreen( Target.m_WorldPosition, Target.m_ScreenPosition );
}

inline auto AimEntityHasSkeleton( C_BaseEntity* pEntity ) -> bool
{
	auto* pBinding = pEntity ? pEntity->GetSchemaClassBinding() : nullptr;

	for ( int Depth = 0; pBinding && Depth < 32; ++Depth )
	{
		const char* szBindingName = pBinding->m_bindingName();
		if ( szBindingName && strcmp( szBindingName , "CBaseAnimGraph" ) == 0 )
			return true;

		auto* pBaseClass = pBinding->m_baseClass();
		pBinding = pBaseClass ? pBaseClass->m_classInfo() : nullptr;
	}

	return false;
}

inline auto ResolveAimTargetPoint( C_BaseEntity* pEntity , const CachedEntity_t::Type Type , const char* szSelectedBone ) -> Vector3
{
	if ( !pEntity )
		return {};

	const bool bHero = Type == CachedEntity_t::CITADEL_PLAYER_CONTROLLER;
	const bool bObjective = Type == CachedEntity_t::NPC_OBJECTIVE;
	const char* NpcHeadBoneNames[] =
	{
		"head",
		"head_end",
		"neck_0",
		"eye_0",
		"spine_1",
		"chest"
	};

	if ( !bObjective && AimEntityHasSkeleton( pEntity ) )
	{
		if ( GetCL_Bones()->PrepareEntityBones( pEntity ) )
		{
			// A hero selection must resolve to the requested bone. Falling back to
			// head/chest here made the UI report one bone while the shot used another.
			if ( bHero )
			{
				if ( !szSelectedBone )
					return {};

				return GetCL_Bones()->GetPreparedBonePositionByName( pEntity , szSelectedBone );
			}

			for ( int BoneIndex = 0; BoneIndex < 6; ++BoneIndex )
			{
				const char* szBoneName = NpcHeadBoneNames[BoneIndex];
				if ( !szBoneName )
					continue;

				const Vector3 BonePosition = GetCL_Bones()->GetPreparedBonePositionByName( pEntity , szBoneName );
				if ( !BonePosition.IsZero() )
					return BonePosition;
			}
		}
	}

	// Static objectives do not always expose a humanoid skeleton. Their
	// collision center is a stable target point and remains projected by the
	// same screen-space FOV test as bones.
	auto* pModelEntity = reinterpret_cast<C_BaseModelEntity*>( pEntity );
	const Vector3 Mins = pModelEntity->m_Collision().m_vecMins();
	const Vector3 Maxs = pModelEntity->m_Collision().m_vecMaxs();
	return pEntity->GetOrigin() + ( Mins + Maxs ) * 0.5f;
}

inline auto FindBestAimTarget( CCitadelPlayerController* pLocalController , const char* szSelectedBone , AimTargetResult_t& OutTarget ) -> bool
{
	OutTarget = {};

	if ( !pLocalController || !ImGui::GetCurrentContext() )
		return false;

	const ImVec2 DisplaySize = ImGui::GetIO().DisplaySize;
	if ( DisplaySize.x <= 0.f || DisplaySize.y <= 0.f )
		return false;

	const ImVec2 ScreenCenter = DisplaySize * 0.5f;
	const int MaxFovRadius = Settings::AimPreview::LegitMode ? 75 : 500;
	const float FovRadius = static_cast<float>( std::clamp( Settings::AimPreview::FovRadius , 25 , MaxFovRadius ) );
	const float MaxDistanceSquared = FovRadius * FovRadius;
	const uint8 LocalTeam = pLocalController->m_iTeamNum();
	const int Priority = std::clamp( Settings::AimPreview::TargetPriority , 0 , 1 );
	auto* pLocalPawn = pLocalController->m_hHeroPawn().Get<C_CitadelPlayerPawn>();
	const Vector3 CameraPosition = pLocalPawn ? pLocalPawn->GetEyeOrigin() : Vector3{};

	float BestDistanceSquared = std::numeric_limits<float>::max();
	int BestHealth = std::numeric_limits<int>::max();
	bool bFoundTarget = false;

	const auto* pCachedEntities = GetEntityCache()->GetCachedEntity();
	std::scoped_lock CacheLock( GetEntityCache()->GetLock() );

	for ( const auto& CachedEntity : *pCachedEntities )
	{
		C_BaseEntity* pTargetEntity = nullptr;
		int Health = 0;

		switch ( CachedEntity.m_Type )
		{
			case CachedEntity_t::CITADEL_PLAYER_CONTROLLER:
			{
				if ( !Settings::AimPreview::TargetHeroes )
					continue;

				auto* pController = CachedEntity.m_Handle.Get<CCitadelPlayerController>();
				if ( !pController || pController == pLocalController || !pController->IsAlive()
					|| pController->m_iTeamNum() == LocalTeam )
				{
					continue;
				}

				pTargetEntity = pController->m_hHeroPawn().Get<C_CitadelPlayerPawn>();
				Health = pController->m_PlayerDataGlobal().m_iHealth();
				break;
			}
			case CachedEntity_t::NPC_TROOPER:
				if ( !Settings::AimPreview::TargetTroopers )
					continue;
				pTargetEntity = CachedEntity.m_Handle.Get();
				if ( !pTargetEntity || pTargetEntity->m_iTeamNum() == LocalTeam )
					continue;
				Health = pTargetEntity->m_iHealth();
				break;
			case CachedEntity_t::NPC_TROOPER_NEUTRAL:
				if ( !Settings::AimPreview::TargetNeutrals )
					continue;
				pTargetEntity = CachedEntity.m_Handle.Get();
				if ( !pTargetEntity )
					continue;
				Health = pTargetEntity->m_iHealth();
				break;
			case CachedEntity_t::NPC_CITADEL:
				if ( !Settings::AimPreview::TargetNpcs )
					continue;
				pTargetEntity = CachedEntity.m_Handle.Get();
				if ( !pTargetEntity || pTargetEntity->m_iTeamNum() == LocalTeam )
					continue;
				Health = pTargetEntity->m_iHealth();
				break;
			case CachedEntity_t::NPC_OBJECTIVE:
				if ( !Settings::AimPreview::TargetObjectives )
					continue;
				pTargetEntity = CachedEntity.m_Handle.Get();
				if ( !pTargetEntity || pTargetEntity->m_iTeamNum() == LocalTeam )
					continue;
				Health = pTargetEntity->m_iHealth();
				break;
			default:
				continue;
		}

		if ( !pTargetEntity || Health <= 0 )
			continue;

		auto* pSceneNode = pTargetEntity->m_pGameSceneNode();
		if ( !pSceneNode || pSceneNode->m_bDormant() )
			continue;

		const Vector3 TargetPosition = ResolveAimTargetPoint( pTargetEntity , CachedEntity.m_Type , szSelectedBone );
		ImVec2 TargetScreen;
		if ( TargetPosition.IsZero() || !Math::WorldToScreen( TargetPosition , TargetScreen ) )
			continue;

		const float DeltaX = TargetScreen.x - ScreenCenter.x;
		const float DeltaY = TargetScreen.y - ScreenCenter.y;
		const float DistanceSquared = DeltaX * DeltaX + DeltaY * DeltaY;
		if ( DistanceSquared > MaxDistanceSquared )
			continue;

		if ( Settings::AimPreview::OnlyVisible
			&& ( CameraPosition.IsZero()
				|| !GetCL_Trace()->IsEntityVisibleAtPoint( CameraPosition , TargetPosition , pTargetEntity ) ) )
		{
			continue;
		}

		const bool bIsBetter = !bFoundTarget
			|| ( Priority == 0 && DistanceSquared < BestDistanceSquared )
			|| ( Priority == 1 && ( Health < BestHealth
				|| ( Health == BestHealth && DistanceSquared < BestDistanceSquared ) ) );

		if ( !bIsBetter )
			continue;

		bFoundTarget = true;
		BestDistanceSquared = DistanceSquared;
		BestHealth = Health;
		OutTarget.m_WorldPosition = TargetPosition;
		OutTarget.m_ScreenPosition = TargetScreen;
		OutTarget.m_TargetEntity = pTargetEntity;
		OutTarget.m_EntityIndex = CachedEntity.m_Type == CachedEntity_t::CITADEL_PLAYER_CONTROLLER
			? static_cast<CCitadelPlayerController*>( CachedEntity.m_Handle.Get() )->m_hHeroPawn().GetEntryIndex()
			: CachedEntity.m_Handle.GetEntryIndex();
		OutTarget.m_HeroControllerIndex = CachedEntity.m_Type == CachedEntity_t::CITADEL_PLAYER_CONTROLLER
			? CachedEntity.m_Handle.GetEntryIndex()
			: -1;
		OutTarget.m_BoneIndex = GetAimTargetBoneIndex( szSelectedBone );
		OutTarget.m_Health = Health;
	}

	return bFoundTarget;
}

inline auto FindBestAimTargetWithFallback( CCitadelPlayerController* pLocalController , const char* szPreferredBone , AimTargetResult_t& OutTarget ) -> bool
{
	if ( !pLocalController || !szPreferredBone || !ImGui::GetCurrentContext() )
	{
		OutTarget = {};
		return false;
	}

	// Preserve the weighted/random choice whenever that bone has a valid target.
	if ( FindBestAimTarget( pLocalController , szPreferredBone , OutTarget ) )
		return true;

	// The preferred bone may be outside the FOV, hidden or unavailable on the
	// model. In that case compare every other selected bone instead of leaving
	// the shot at the edge of the FOV.
	const int Mask = NormalizeAimBoneMask();
	const ImVec2 ScreenCenter = ImGui::GetIO().DisplaySize * 0.5f;
	const int Priority = std::clamp( Settings::AimPreview::TargetPriority , 0 , 1 );
	float BestDistanceSquared = std::numeric_limits<float>::max();
	int BestHealth = std::numeric_limits<int>::max();
	bool bFoundFallback = false;

	for ( int Index = 0; Index < static_cast<int>( std::size( g_AimTargetBones ) ); ++Index )
	{
		if ( ( Mask & ( 1 << Index ) ) == 0 || strcmp( g_AimTargetBones[Index] , szPreferredBone ) == 0 )
			continue;

		AimTargetResult_t Candidate;
		if ( !FindBestAimTarget( pLocalController , g_AimTargetBones[Index] , Candidate ) )
			continue;

		const float DeltaX = Candidate.m_ScreenPosition.x - ScreenCenter.x;
		const float DeltaY = Candidate.m_ScreenPosition.y - ScreenCenter.y;
		const float DistanceSquared = DeltaX * DeltaX + DeltaY * DeltaY;
		const bool bIsBetter = !bFoundFallback
			|| ( Priority == 0 && DistanceSquared < BestDistanceSquared )
			|| ( Priority == 1 && ( Candidate.m_Health < BestHealth
				|| ( Candidate.m_Health == BestHealth && DistanceSquared < BestDistanceSquared ) ) );

		if ( !bIsBetter )
			continue;

		bFoundFallback = true;
		BestDistanceSquared = DistanceSquared;
		BestHealth = Candidate.m_Health;
		OutTarget = Candidate;
	}

	return bFoundFallback;
}

inline auto FindBestSoulTarget( const Vector3& ShotOrigin , SoulTargetResult_t& OutTarget ) -> bool
{
	OutTarget = {};

	if ( ShotOrigin.IsZero() || !ImGui::GetCurrentContext() )
		return false;

	auto* pGlobalVars = SDK::Pointers::GlobalVarsBase();
	if ( !pGlobalVars )
		return false;

	const ImVec2 DisplaySize = ImGui::GetIO().DisplaySize;
	if ( DisplaySize.x <= 0.f || DisplaySize.y <= 0.f )
		return false;

	const float CurrentTime = pGlobalVars->m_flCurrentTime();
	const ImVec2 ScreenCenter = DisplaySize * 0.5f;
	const int MaxFovRadius = Settings::AimPreview::LegitMode ? 75 : 500;
	const float FovRadius = static_cast<float>( std::clamp( Settings::AimPreview::FovRadius , 25 , MaxFovRadius ) );
	const float MaxDistanceSquared = FovRadius * FovRadius;
	float BestDistanceSquared = std::numeric_limits<float>::max();
	bool bFoundTarget = false;

	const auto* pCachedEntities = GetEntityCache()->GetCachedEntity();
	std::scoped_lock CacheLock( GetEntityCache()->GetLock() );

	for ( const auto& CachedEntity : *pCachedEntities )
	{
		if ( CachedEntity.m_Type != CachedEntity_t::ITEM_XP )
			continue;

		auto* pSoul = CachedEntity.m_Handle.Get<CItemXP>();
		if ( !pSoul )
			continue;

		auto* pSceneNode = pSoul->m_pGameSceneNode();
		if ( !pSceneNode || pSceneNode->m_bDormant() )
			continue;

		const float AttackableTime = pSoul->m_flAttackableTime();
		const float EndAttackableTime = pSoul->m_flEndAttackableTime();
		if ( AttackableTime <= 0.f || EndAttackableTime <= AttackableTime
			|| CurrentTime < AttackableTime || CurrentTime > EndAttackableTime )
		{
			continue;
		}

		const Vector3 Mins = pSoul->m_Collision().m_vecMins();
		const Vector3 Maxs = pSoul->m_Collision().m_vecMaxs();
		const Vector3 SoulPosition = pSoul->GetOrigin() + ( Mins + Maxs ) * 0.5f;
		ImVec2 SoulScreen;
		if ( SoulPosition.IsZero() || !Math::WorldToScreen( SoulPosition , SoulScreen ) )
			continue;

		const float DeltaX = SoulScreen.x - ScreenCenter.x;
		const float DeltaY = SoulScreen.y - ScreenCenter.y;
		const float DistanceSquared = DeltaX * DeltaX + DeltaY * DeltaY;
		if ( DistanceSquared > MaxDistanceSquared || DistanceSquared >= BestDistanceSquared )
			continue;

		// Soul steal fires without player input, so it must always require a
		// clear shot, independently of the visibility option used by aim assist.
		if ( !GetCL_Trace()->IsEntityVisibleAtPoint( ShotOrigin , SoulPosition , pSoul ) )
			continue;

		bFoundTarget = true;
		BestDistanceSquared = DistanceSquared;
		OutTarget.m_WorldPosition = SoulPosition;
		OutTarget.m_ScreenPosition = SoulScreen;
		OutTarget.m_TargetEntity = pSoul;
		OutTarget.m_EntityIndex = CachedEntity.m_Handle.GetEntryIndex();
	}

	return bFoundTarget;
}
