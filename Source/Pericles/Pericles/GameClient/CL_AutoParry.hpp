#pragma once

#include <algorithm>
#include <cctype>
#include <cmath>
#include <cstdint>
#include <cstring>
#include <mutex>
#include <unordered_map>
#include <vector>

#include <Common/MemoryEngine.hpp>
#include <DeadLock/SDK/Types/CEntityData.hpp>

#include <GameClient/CL_CitadelPlayerController.hpp>
#include <GameClient/CEntityCache/CEntityCache.hpp>

#include <PericlesClient/Settings/Settings.hpp>

enum AutoParryTypeBits_t : int
{
	AUTO_PARRY_HIGH_MELEE = 1 << 0,
	AUTO_PARRY_LIGHT_MELEE = 1 << 1,
	AUTO_PARRY_BOT_MELEE = 1 << 2,
	AUTO_PARRY_GUARDIAN_MELEE = 1 << 3
};

struct AutoParryThreat_t
{
	uintptr_t m_Token = 0;
	int m_TypeBit = 0;
};

struct AutoParrySoundThreat_t
{
	Vector3 m_Position;
	uint64_t m_Token = 0;
	ULONGLONG m_Time = 0;
	int m_SourceEntityIndex = -1;
	int m_TypeBit = 0;
};

struct AutoParrySoundStore_t
{
	std::mutex m_Lock;
	AutoParrySoundThreat_t m_Threat;
	uint64_t m_NextToken = 1;
};

inline auto GetAutoParrySoundStore() -> AutoParrySoundStore_t&
{
	static AutoParrySoundStore_t Store;
	return Store;
}

inline auto AutoParryContainsInsensitive( const char* szText , const char* szNeedle ) -> bool
{
	if ( !szText || !szNeedle || !*szNeedle )
		return false;

	const auto* pTextEnd = szText + strlen( szText );
	const auto* pNeedleEnd = szNeedle + strlen( szNeedle );
	return std::search( szText , pTextEnd , szNeedle , pNeedleEnd , []( char Left , char Right )
	{
		return std::tolower( static_cast<unsigned char>( Left ) )
			== std::tolower( static_cast<unsigned char>( Right ) );
	} ) != pTextEnd;
}

inline auto RegisterAutoParrySound( const Vector3& Position , int SourceEntityIndex , const char* szSoundName ) -> void
{
	if ( !szSoundName || !*szSoundName )
		return;

	const bool bMelee = AutoParryContainsInsensitive( szSoundName , "melee" )
		|| AutoParryContainsInsensitive( szSoundName , "punch" )
		|| AutoParryContainsInsensitive( szSoundName , "guardian" );
	const bool bWindup = AutoParryContainsInsensitive( szSoundName , "swing" )
		|| AutoParryContainsInsensitive( szSoundName , "slam" )
		|| AutoParryContainsInsensitive( szSoundName , "attack" )
		|| AutoParryContainsInsensitive( szSoundName , "hold" )
		|| AutoParryContainsInsensitive( szSoundName , "windup" )
		|| AutoParryContainsInsensitive( szSoundName , "release" );
	const bool bImpact = AutoParryContainsInsensitive( szSoundName , "hit" )
		|| AutoParryContainsInsensitive( szSoundName , "damage" )
		|| AutoParryContainsInsensitive( szSoundName , "impact" );
	if ( !bMelee || !bWindup || bImpact )
		return;

	const bool bGuardian = AutoParryContainsInsensitive( szSoundName , "guardian" )
		|| AutoParryContainsInsensitive( szSoundName , "tier1" );
	const bool bBot = AutoParryContainsInsensitive( szSoundName , "trooper" )
		|| AutoParryContainsInsensitive( szSoundName , "minion" )
		|| AutoParryContainsInsensitive( szSoundName , "npc" );
	const bool bHeavy = AutoParryContainsInsensitive( szSoundName , "heavy" )
		|| AutoParryContainsInsensitive( szSoundName , "charge" )
		|| AutoParryContainsInsensitive( szSoundName , "hold" )
		|| AutoParryContainsInsensitive( szSoundName , "slam" );

	auto& Store = GetAutoParrySoundStore();
	std::scoped_lock Lock( Store.m_Lock );
	Store.m_Threat.m_Position = Position;
	Store.m_Threat.m_Time = GetTickCount64();
	Store.m_Threat.m_SourceEntityIndex = SourceEntityIndex;
	Store.m_Threat.m_TypeBit = bGuardian
		? AUTO_PARRY_GUARDIAN_MELEE
		: ( bBot ? AUTO_PARRY_BOT_MELEE : ( bHeavy ? AUTO_PARRY_HIGH_MELEE : AUTO_PARRY_LIGHT_MELEE ) );
	Store.m_Threat.m_Token = 0x8000000000000000ull | Store.m_NextToken++;
}

inline auto GetRecentAutoParrySound() -> AutoParrySoundThreat_t
{
	auto& Store = GetAutoParrySoundStore();
	std::scoped_lock Lock( Store.m_Lock );
	return Store.m_Threat;
}

inline auto AutoParrySchemaDerivesFrom( CSchemaClassBinding* pBinding , const char* szClassName ) -> bool
{
	for ( int Depth = 0; pBinding && Depth < 32; ++Depth )
	{
		const char* szBindingName = pBinding->m_bindingName();
		if ( szBindingName && strcmp( szBindingName , szClassName ) == 0 )
			return true;

		auto* pBaseClass = pBinding->m_baseClass();
		pBinding = pBaseClass ? pBaseClass->m_classInfo() : nullptr;
	}

	return false;
}

inline auto GetClientRttiTypeName( const void* pObject ) -> const char*
{
	if ( !pObject )
		return nullptr;

	struct CompleteObjectLocator64_t
	{
		uint32_t m_Signature;
		uint32_t m_Offset;
		uint32_t m_ConstructorDisplacementOffset;
		int32_t m_TypeDescriptorRva;
		int32_t m_ClassDescriptorRva;
		int32_t m_SelfRva;
	};

	__try
	{
		const uintptr_t ModuleBase = reinterpret_cast<uintptr_t>( GetModuleHandleA( "client.dll" ) );
		if ( !ModuleBase )
			return nullptr;

		const auto* pDosHeader = reinterpret_cast<const IMAGE_DOS_HEADER*>( ModuleBase );
		const auto* pNtHeaders = reinterpret_cast<const IMAGE_NT_HEADERS64*>( ModuleBase + pDosHeader->e_lfanew );
		const uintptr_t ModuleEnd = ModuleBase + pNtHeaders->OptionalHeader.SizeOfImage;
		const uintptr_t VTable = *reinterpret_cast<const uintptr_t*>( pObject );
		if ( VTable < ModuleBase + sizeof( uintptr_t ) || VTable >= ModuleEnd )
			return nullptr;

		const uintptr_t LocatorAddress = *reinterpret_cast<const uintptr_t*>( VTable - sizeof( uintptr_t ) );
		if ( LocatorAddress < ModuleBase || LocatorAddress + sizeof( CompleteObjectLocator64_t ) >= ModuleEnd )
			return nullptr;

		const auto* pLocator = reinterpret_cast<const CompleteObjectLocator64_t*>( LocatorAddress );
		const uintptr_t RttiImageBase = pLocator->m_Signature == 1
			? LocatorAddress - static_cast<uint32_t>( pLocator->m_SelfRva )
			: ModuleBase;
		const uintptr_t TypeDescriptorAddress = RttiImageBase + static_cast<uint32_t>( pLocator->m_TypeDescriptorRva );
		const uintptr_t TypeNameAddress = TypeDescriptorAddress + sizeof( uintptr_t ) * 2;
		if ( TypeNameAddress < ModuleBase || TypeNameAddress >= ModuleEnd )
			return nullptr;

		const char* szTypeName = reinterpret_cast<const char*>( TypeNameAddress );
		return strnlen_s( szTypeName , 192 ) < 192 ? szTypeName : nullptr;
	}
	__except ( EXCEPTION_EXECUTE_HANDLER )
	{
		return nullptr;
	}
}

enum class AutoParryParticleKind_t : uint8_t
{
	HeroLight,
	HeroHeavy,
	NpcActivate,
	NpcSwing
};

struct AutoParryParticleSignal_t
{
	uintptr_t m_Effect = 0;
	uintptr_t m_Owner = 0;
	Vector3 m_Origin;
	AutoParryParticleKind_t m_Kind = AutoParryParticleKind_t::HeroLight;
};

struct AutoParryRawParticle_t
{
	uintptr_t m_Next = 0;
	uintptr_t m_Owner = 0;
	Vector3 m_Origin;
	char m_Name[192]{};
};

inline auto TryReadAutoParryParticle( uintptr_t Effect , AutoParryRawParticle_t& OutParticle ) -> bool
{
	OutParticle = {};
	if ( Effect < 0x10000ull )
		return false;

	__try
	{
		OutParticle.m_Next = *reinterpret_cast<const uintptr_t*>( Effect + 0x10 );
		OutParticle.m_Origin = *reinterpret_cast<const Vector3*>( Effect + 0x40 );
		OutParticle.m_Owner = *reinterpret_cast<const uintptr_t*>( Effect + 0x50 );

		const char* szDebugName = *reinterpret_cast<const char* const*>( Effect + 0x28 );
		if ( !szDebugName )
			return false;

		for ( size_t Index = 0; Index < sizeof( OutParticle.m_Name ) - 1; ++Index )
		{
			const char Character = szDebugName[Index];
			OutParticle.m_Name[Index] = Character;
			if ( Character == '\0' )
				return Index != 0;
		}

		OutParticle.m_Name[sizeof( OutParticle.m_Name ) - 1] = '\0';
		return true;
	}
	__except ( EXCEPTION_EXECUTE_HANDLER )
	{
		return false;
	}
}

inline auto TryReadAutoParryParticleOwnerHandle( uintptr_t Owner , uint32_t& OutHandle ) -> bool
{
	OutHandle = INVALID_EHANDLE_INDEX;
	if ( Owner < 0x10000ull || Owner >= 0x0000800000000000ull )
		return false;

	__try
	{
		OutHandle = *reinterpret_cast<const uint32_t*>( Owner );
		return true;
	}
	__except ( EXCEPTION_EXECUTE_HANDLER )
	{
		return false;
	}
}

inline auto GetAutoParryParticleManager() -> uintptr_t
{
	static uintptr_t ParticleManager = []() -> uintptr_t
	{
		const uintptr_t ModuleBase = reinterpret_cast<uintptr_t>( GetModuleHandleA( CLIENT_DLL ) );
		if ( !ModuleBase )
			return 0;

		const auto* pDosHeader = reinterpret_cast<const IMAGE_DOS_HEADER*>( ModuleBase );
		const auto* pNtHeaders = reinterpret_cast<const IMAGE_NT_HEADERS64*>( ModuleBase + pDosHeader->e_lfanew );
		const uintptr_t CodeStart = ModuleBase + pNtHeaders->OptionalHeader.BaseOfCode;
		const uintptr_t CodeEnd = CodeStart + pNtHeaders->OptionalHeader.SizeOfCode;
		constexpr const char* ManagerInitializerPattern =
			"48 8D 1D ? ? ? ? 48 8B CB E8 ? ? ? ? 48 8D 0D ? ? ? ? E8 ? ? ? ? "
			"48 8D 0D ? ? ? ? E8 ? ? ? ? 48 8B C3";

		uintptr_t SearchStart = CodeStart;
		for ( int MatchIndex = 0; MatchIndex < 32 && SearchStart < CodeEnd; ++MatchIndex )
		{
			const uintptr_t Match = reinterpret_cast<uintptr_t>(
				FindPattern( ManagerInitializerPattern , SearchStart , CodeEnd ) );
			if ( !Match )
				break;

			const uintptr_t Candidate = GetPtrAddress<uintptr_t>( Match );
			const char* szTypeName = GetClientRttiTypeName( reinterpret_cast<const void*>( Candidate ) );
			if ( szTypeName && strstr( szTypeName , "CParticleMgr" ) )
				return Candidate;

			SearchStart = Match + 1;
		}

		return 0;
	}();

	return ParticleManager;
}

inline auto ClassifyAutoParryParticle( const char* szName , AutoParryParticleKind_t& OutKind ,
	ULONGLONG& OutDelay ) -> bool
{
	OutDelay = 0;
	if ( !szName || AutoParryContainsInsensitive( szName , "impact" )
		|| AutoParryContainsInsensitive( szName , "parry" ) )
	{
		return false;
	}

	if ( !AutoParryContainsInsensitive( szName , "activate_charge" )
		&& ( AutoParryContainsInsensitive( szName , "melee_swing_heavy.vpcf" )
			|| AutoParryContainsInsensitive( szName , "melee_heavy_activate.vpcf" ) ) )
	{
		OutKind = AutoParryParticleKind_t::HeroHeavy;
		// Hero-heavy timing is applied once at command emission so modifier, sound
		// and particle detections all use the same delay.
		return true;
	}

	// Current game builds use the generic melee_activate/melee_swing resources
	// for player light attacks. Older builds and a few heroes still expose a
	// melee_quick variant, so retain it as an alias. Match the generic resources
	// only in the player ability directory to avoid classifying NPC swings as
	// light hero melee.
	const bool bPlayerLightMelee = AutoParryContainsInsensitive( szName , "melee_quick" )
		|| AutoParryContainsInsensitive( szName , "particles/abilities/melee/melee_activate.vpcf" )
		|| AutoParryContainsInsensitive( szName , "particles/abilities/melee/melee_swing.vpcf" );
	if ( bPlayerLightMelee )
	{
		OutKind = AutoParryParticleKind_t::HeroLight;
		return true;
	}

	if ( AutoParryContainsInsensitive( szName , "npc_melee_activate.vpcf" ) )
	{
		OutKind = AutoParryParticleKind_t::NpcActivate;
		OutDelay = 45;
		return true;
	}

	if ( AutoParryContainsInsensitive( szName , "npc_melee_swing.vpcf" ) )
	{
		OutKind = AutoParryParticleKind_t::NpcSwing;
		return true;
	}

	return false;
}

inline auto TryReadAutoParryParticleListHead( uintptr_t ParticleManager , uintptr_t& OutEffect ) -> bool
{
	OutEffect = 0;
	__try
	{
		// CParticleMgr::m_NewEffects. The current engine inserts new effects at
		// the front and links CNewParticleEffect through +0x10/+0x18.
		OutEffect = *reinterpret_cast<const uintptr_t*>( ParticleManager + 0x70 );
		return true;
	}
	__except ( EXCEPTION_EXECUTE_HANDLER )
	{
		return false;
	}
}

inline auto CollectAutoParryParticleSignals() -> std::vector<AutoParryParticleSignal_t>
{
	std::vector<AutoParryParticleSignal_t> Signals;
	const uintptr_t ParticleManager = GetAutoParryParticleManager();
	if ( !ParticleManager )
		return Signals;

	uintptr_t Effect = 0;
	if ( !TryReadAutoParryParticleListHead( ParticleManager , Effect ) )
		return Signals;

	struct ParticleAge_t
	{
		ULONGLONG m_FirstSeen = 0;
		ULONGLONG m_LastSeen = 0;
	};
	static std::unordered_map<uintptr_t, ParticleAge_t> ParticleAges;
	const ULONGLONG Now = GetTickCount64();

	for ( int EffectIndex = 0; Effect && EffectIndex < 2048; ++EffectIndex )
	{
		AutoParryRawParticle_t RawParticle;
		if ( !TryReadAutoParryParticle( Effect , RawParticle ) )
		{
			// A null debug name is valid during asynchronous creation; keep walking
			// as long as the link itself was readable.
			if ( RawParticle.m_Next == Effect )
				break;
			Effect = RawParticle.m_Next;
			continue;
		}

		AutoParryParticleKind_t Kind;
		ULONGLONG Delay = 0;
		if ( ClassifyAutoParryParticle( RawParticle.m_Name , Kind , Delay ) )
		{
			auto& Age = ParticleAges[Effect];
			if ( Age.m_FirstSeen == 0 )
				Age.m_FirstSeen = Now;
			Age.m_LastSeen = Now;

			const bool bFiniteOrigin = std::isfinite( RawParticle.m_Origin.m_x )
				&& std::isfinite( RawParticle.m_Origin.m_y )
				&& std::isfinite( RawParticle.m_Origin.m_z );
			if ( bFiniteOrigin && Now - Age.m_FirstSeen >= Delay )
				Signals.push_back( { Effect , RawParticle.m_Owner , RawParticle.m_Origin , Kind } );
		}

		if ( RawParticle.m_Next == Effect )
			break;
		Effect = RawParticle.m_Next;
	}

	for ( auto Iterator = ParticleAges.begin(); Iterator != ParticleAges.end(); )
	{
		if ( Now - Iterator->second.m_LastSeen > 2000ull )
			Iterator = ParticleAges.erase( Iterator );
		else
			++Iterator;
	}

	return Signals;
}

inline auto AutoParryParticleMatchesAttacker( const AutoParryParticleSignal_t& Signal ,
	C_BaseEntity* pAttacker ) -> bool
{
	if ( !pAttacker )
		return false;

	const CHandle AttackerHandle = pAttacker->pEntityIdentity()->Handle();
	const uint32_t AttackerEntry = static_cast<uint32_t>( AttackerHandle.GetEntryIndex() );
	const uint32_t InlineOwner = static_cast<uint32_t>( Signal.m_Owner );
	if ( InlineOwner != INVALID_EHANDLE_INDEX && ( InlineOwner & ENT_ENTRY_MASK ) == AttackerEntry )
		return true;

	uint32_t PointedOwner = INVALID_EHANDLE_INDEX;
	if ( TryReadAutoParryParticleOwnerHandle( Signal.m_Owner , PointedOwner )
		&& PointedOwner != INVALID_EHANDLE_INDEX
		&& ( PointedOwner & ENT_ENTRY_MASK ) == AttackerEntry )
	{
		return true;
	}

	if ( Signal.m_Origin.IsZero() )
		return false;

	constexpr float MaxParticleOwnerDistance = 240.f;
	return Signal.m_Origin.DistanceSquared( pAttacker->GetOrigin() )
		<= MaxParticleOwnerDistance * MaxParticleOwnerDistance;
}

inline auto FindAutoParryParticleThreat( const std::vector<AutoParryParticleSignal_t>& Signals ,
	C_BaseEntity* pAttacker , bool bHero , bool bGuardian , bool bMeleeTrooper , int EnabledTypes ,
	AutoParryThreat_t& OutThreat ) -> bool
{
	for ( const auto& Signal : Signals )
	{
		int TypeBit = 0;
		if ( bHero && Signal.m_Kind == AutoParryParticleKind_t::HeroLight )
			TypeBit = AUTO_PARRY_LIGHT_MELEE;
		else if ( bHero && Signal.m_Kind == AutoParryParticleKind_t::HeroHeavy )
			TypeBit = AUTO_PARRY_HIGH_MELEE;
		else if ( bGuardian && ( Signal.m_Kind == AutoParryParticleKind_t::NpcActivate
			|| Signal.m_Kind == AutoParryParticleKind_t::NpcSwing ) )
		{
			TypeBit = AUTO_PARRY_GUARDIAN_MELEE;
		}
		else if ( bMeleeTrooper && ( Signal.m_Kind == AutoParryParticleKind_t::NpcActivate
			|| Signal.m_Kind == AutoParryParticleKind_t::NpcSwing ) )
		{
			TypeBit = AUTO_PARRY_BOT_MELEE;
		}

		if ( TypeBit == 0 || ( EnabledTypes & TypeBit ) == 0
			|| !AutoParryParticleMatchesAttacker( Signal , pAttacker ) )
		{
			continue;
		}

		OutThreat.m_Token = Signal.m_Effect;
		OutThreat.m_TypeBit = TypeBit;
		return true;
	}

	return false;
}

inline auto FindParryCheckModifier( C_BaseEntity* pEntity ) -> CBaseModifier*
{
	if ( !pEntity )
		return nullptr;

	auto* pModifierProperty = pEntity->m_pModifierProp();
	if ( !pModifierProperty )
		return nullptr;

	auto& Modifiers = pModifierProperty->m_vecModifiers();
	const int ModifierCount = Modifiers.Count();
	if ( ModifierCount <= 0 || ModifierCount > 256 || !Modifiers.Base() )
		return nullptr;

	for ( int ModifierIndex = 0; ModifierIndex < ModifierCount; ++ModifierIndex )
	{
		auto* pModifier = Modifiers[ModifierIndex];
		const char* szTypeName = GetClientRttiTypeName( pModifier );
		if ( szTypeName && strstr( szTypeName , "CCitadel_Modifier_CheckNearbyPlayerParry" ) )
			return pModifier;
	}

	return nullptr;
}

inline auto IsGuardianMeleeEntity( C_BaseEntity* pEntity ) -> bool
{
	auto* pBinding = pEntity ? pEntity->GetSchemaClassBinding() : nullptr;
	return AutoParrySchemaDerivesFrom( pBinding , "C_NPC_TrooperBoss" );
}

inline auto IsMeleeTrooperModel( C_BaseEntity* pEntity ) -> bool
{
	auto* pSceneNode = pEntity ? pEntity->m_pGameSceneNode() : nullptr;
	if ( !pSceneNode )
		return false;

	const char* szModelName = pSceneNode->GetSkeletonInstance()->m_modelState().m_ModelName().String();
	return AutoParryContainsInsensitive( szModelName , "trooper_melee" )
		|| AutoParryContainsInsensitive( szModelName , "trooper_01_melee" );
}

inline auto IsKnownTier1GuardianModel( C_BaseEntity* pEntity ) -> bool
{
	auto* pSceneNode = pEntity ? pEntity->m_pGameSceneNode() : nullptr;
	if ( !pSceneNode )
		return false;

	const char* szModelName = pSceneNode->GetSkeletonInstance()->m_modelState().m_ModelName().String();
	return AutoParryContainsInsensitive( szModelName , "boss_tier_01_brazier_guardian" );
}

struct AutoParryNpcAttackState_t
{
	bool m_WasMeleeActive = false;
	uint64_t m_ActiveToken = 0;
	ULONGLONG m_LastSeen = 0;
	uint32_t m_LastSequence = UINT32_MAX;
	uint32_t m_LastSequenceStartBits = UINT32_MAX;
	ULONGLONG m_SequenceObservedAt = 0;
};

struct AutoParryGuardianAttackState_t
{
	uint32_t m_LastSequence = UINT32_MAX;
	uint32_t m_LastSequenceStartBits = UINT32_MAX;
	uint64_t m_ActiveToken = 0;
	ULONGLONG m_AttackObservedAt = 0;
};

inline auto GetAutoParryNpcAttackStates() -> std::unordered_map<uint32_t, AutoParryNpcAttackState_t>&
{
	static std::unordered_map<uint32_t, AutoParryNpcAttackState_t> States;
	return States;
}

inline auto GetAutoParryGuardianAttackStates() -> std::unordered_map<uint32_t, AutoParryGuardianAttackState_t>&
{
	static std::unordered_map<uint32_t, AutoParryGuardianAttackState_t> States;
	return States;
}

inline auto TryReadMeleeTrooperAttackState( C_BaseEntity* pAttacker , bool& bMeleeActive ) -> bool
{
	bMeleeActive = false;
	if ( !pAttacker || !IsMeleeTrooperModel( pAttacker ) )
		return false;

	// The shipped lane-melee AnimGraph 2 resource starts its networked bools
	// with in_air, has_target, action_melee (bound as b_Melee by the model
	// subgraph). Read that third bool instead of guessing from distance/movement.
	constexpr int MeleeBoolIndex = 2;

	__try
	{
		auto* pGraphController = reinterpret_cast<CBaseAnimGraph*>( pAttacker )->m_pMainGraphController();
		if ( !pGraphController )
			return false;

		auto& NetworkedVars = pGraphController->m_animGraphNetworkedVars();
		const int BoolCount = NetworkedVars.m_nBoolVariablesCount();
		if ( BoolCount <= MeleeBoolIndex || BoolCount > 256 )
			return false;

		auto& BoolWords = NetworkedVars.m_PredNetBoolVariables();
		const int RequiredWordCount = ( BoolCount + 31 ) / 32;
		if ( BoolWords.Count() < RequiredWordCount || BoolWords.Count() > 8 || !BoolWords.Base() )
			return false;

		bMeleeActive = ( BoolWords[MeleeBoolIndex / 32] & ( 1u << ( MeleeBoolIndex % 32 ) ) ) != 0;
		return true;
	}
	__except ( EXCEPTION_EXECUTE_HANDLER )
	{
		return false;
	}
}

inline auto BuildGuardianAnimationThreat( C_BaseEntity* pGuardian , const Vector3& LocalOrigin ,
	bool bAimsAtLocal , bool& bSignalReadable , AutoParryThreat_t& OutThreat ) -> bool
{
	bSignalReadable = false;
	if ( !pGuardian || !IsKnownTier1GuardianModel( pGuardian ) )
		return false;

	uint32_t Sequence = UINT32_MAX;
	uint32_t SequenceStartBits = UINT32_MAX;
	__try
	{
		auto* pGraphController = reinterpret_cast<CBaseAnimGraph*>( pGuardian )->m_pMainGraphController();
		if ( !pGraphController )
			return false;

		Sequence = pGraphController->m_hSequence();
		const float SequenceStartTime = pGraphController->m_flSeqStartTime();
		memcpy( &SequenceStartBits , &SequenceStartTime , sizeof( SequenceStartBits ) );
		bSignalReadable = true;
	}
	__except ( EXCEPTION_EXECUTE_HANDLER )
	{
		return false;
	}
	if ( Sequence > 4096 )
	{
		bSignalReadable = false;
		return false;
	}

	// The current Tier-1 Guardian AG1 graph is server-authoritative and binds
	// close swing/slam to the embedded model sequences 81 and 82. Tracking both
	// sequence and start time also catches two consecutive uses of the same clip.
	constexpr uint32_t GuardianSwingSequence = 81;
	constexpr uint32_t GuardianSlamSequence = 82;
	const bool bMeleeSequence = Sequence == GuardianSwingSequence || Sequence == GuardianSlamSequence;

	const CHandle GuardianHandle = pGuardian->pEntityIdentity()->Handle();
	const ULONGLONG Now = GetTickCount64();
	auto& State = GetAutoParryGuardianAttackStates()[GuardianHandle.m_Index];
	if ( Sequence != State.m_LastSequence || SequenceStartBits != State.m_LastSequenceStartBits )
	{
		State.m_LastSequence = Sequence;
		State.m_LastSequenceStartBits = SequenceStartBits;
		State.m_ActiveToken = 0;
		State.m_AttackObservedAt = Now;

		if ( bMeleeSequence )
		{
			static uint64_t NextGuardianAttackToken = 1;
			State.m_ActiveToken = 0x1000000000000000ull | NextGuardianAttackToken++;
		}
	}

	if ( !bMeleeSequence || State.m_ActiveToken == 0 || !bAimsAtLocal
		|| Now - State.m_AttackObservedAt > 750ull )
	{
		return false;
	}

	constexpr float MaxGuardianMeleeReach = 330.f;
	if ( LocalOrigin.DistanceSquared( pGuardian->GetOrigin() ) > MaxGuardianMeleeReach * MaxGuardianMeleeReach )
		return false;

	OutThreat.m_Token = static_cast<uintptr_t>( State.m_ActiveToken );
	OutThreat.m_TypeBit = AUTO_PARRY_GUARDIAN_MELEE;
	return true;
}

inline auto IsAutoParryEntityFacingLocal( C_BaseEntity* pAttacker , const Vector3& LocalOrigin ) -> bool
{
	auto* pSceneNode = pAttacker ? pAttacker->m_pGameSceneNode() : nullptr;
	if ( !pSceneNode )
		return false;

	Vector3 ToLocal = LocalOrigin - pAttacker->GetOrigin();
	ToLocal.m_z = 0.f;
	if ( ToLocal.Normalize() <= 0.001f )
		return true;

	constexpr float Pi = 3.14159265358979323846f;
	const float YawRadians = pSceneNode->m_angAbsRotation().m_y * ( Pi / 180.f );
	const Vector3 Forward( cosf( YawRadians ) , sinf( YawRadians ) , 0.f );
	return Forward.Dot2D( ToLocal ) >= 0.35f;
}

inline auto TryGetAutoParryEntityTarget( C_BaseEntity* pAttacker , CHandle& OutTarget ) -> bool
{
	OutTarget.m_Index = INVALID_EHANDLE_INDEX;
	auto* pBinding = pAttacker ? pAttacker->GetSchemaClassBinding() : nullptr;
	if ( AutoParrySchemaDerivesFrom( pBinding , "C_NPC_Trooper" ) )
	{
		const CHandle Target = reinterpret_cast<C_NPC_Trooper*>( pAttacker )->m_hTargetedEnemy();
		if ( Target.IsValid() )
		{
			OutTarget = Target;
			return true;
		}
	}
	else if ( AutoParrySchemaDerivesFrom( pBinding , "C_NPC_Boss_Tier2" ) )
	{
		const CHandle Target = reinterpret_cast<C_NPC_Boss_Tier2*>( pAttacker )->m_hTargetedEnemy();
		if ( Target.IsValid() )
		{
			OutTarget = Target;
			return true;
		}
	}

	if ( AutoParrySchemaDerivesFrom( pBinding , "C_AI_CitadelNPC" ) )
	{
		const CHandle LookTarget = reinterpret_cast<C_AI_CitadelNPC*>( pAttacker )->m_hLookTarget();
		if ( LookTarget.IsValid() )
		{
			OutTarget = LookTarget;
			return true;
		}
	}

	return false;
}

inline auto IsAutoParryEntityTargetingLocal( C_BaseEntity* pAttacker , CHandle LocalPawnHandle ,
	CHandle LocalControllerHandle ) -> bool
{
	CHandle Target;
	return TryGetAutoParryEntityTarget( pAttacker , Target )
		&& ( Target == LocalPawnHandle || Target == LocalControllerHandle );
}

inline auto BuildMeleeTrooperAttackThreat( C_BaseEntity* pAttacker , CHandle LocalPawnHandle ,
	CHandle LocalControllerHandle , const Vector3& LocalOrigin , AutoParryThreat_t& OutThreat ) -> bool
{
	if ( !pAttacker || !IsMeleeTrooperModel( pAttacker ) )
		return false;

	const CHandle AttackerHandle = pAttacker->pEntityIdentity()->Handle();
	auto& State = GetAutoParryNpcAttackStates()[AttackerHandle.m_Index];
	const ULONGLONG Now = GetTickCount64();
	State.m_LastSeen = Now;

	CHandle Target;
	const bool bHasTarget = TryGetAutoParryEntityTarget( pAttacker , Target );
	const bool bTargetsLocal = bHasTarget
		&& ( Target == LocalPawnHandle || Target == LocalControllerHandle );
	const bool bFacesLocal = IsAutoParryEntityFacingLocal( pAttacker , LocalOrigin );
	if ( bHasTarget ? !bTargetsLocal : !bFacesLocal )
		return false;

	constexpr float MaxTrooperMeleeReach = 270.f;
	if ( LocalOrigin.DistanceSquared( pAttacker->GetOrigin() ) > MaxTrooperMeleeReach * MaxTrooperMeleeReach )
		return false;

	bool bMeleeActive = false;
	const bool bMeleeSignalReadable = TryReadMeleeTrooperAttackState( pAttacker , bMeleeActive );

	if ( bMeleeSignalReadable && bMeleeActive )
	{
		if ( !State.m_WasMeleeActive )
		{
			static uint64_t NextAttackToken = 1;
			State.m_ActiveToken = 0x2000000000000000ull | NextAttackToken++;
			State.m_WasMeleeActive = true;
		}

		OutThreat.m_Token = static_cast<uintptr_t>( State.m_ActiveToken );
		OutThreat.m_TypeBit = AUTO_PARRY_BOT_MELEE;
		return true;
	}

	State.m_WasMeleeActive = false;
	State.m_ActiveToken = 0;

	// AnimGraph layouts can change between game updates. When the named melee
	// bool is missing or no longer occupies its historical slot, use a new
	// sequence/start-time pair as a guarded fallback. Target, facing and reach
	// checks above prevent unrelated distant animations from becoming threats.
	uint32_t Sequence = UINT32_MAX;
	uint32_t SequenceStartBits = UINT32_MAX;
	__try
	{
		auto* pGraphController = reinterpret_cast<CBaseAnimGraph*>( pAttacker )->m_pMainGraphController();
		if ( pGraphController )
		{
			Sequence = pGraphController->m_hSequence();
			const float SequenceStartTime = pGraphController->m_flSeqStartTime();
			memcpy( &SequenceStartBits , &SequenceStartTime , sizeof( SequenceStartBits ) );
		}
	}
	__except ( EXCEPTION_EXECUTE_HANDLER )
	{
		return false;
	}

	if ( Sequence == UINT32_MAX || Sequence > 4096 )
		return false;

	const bool bHadSequence = State.m_LastSequence != UINT32_MAX;
	const bool bSequenceChanged = Sequence != State.m_LastSequence
		|| SequenceStartBits != State.m_LastSequenceStartBits;
	if ( bSequenceChanged )
	{
		State.m_LastSequence = Sequence;
		State.m_LastSequenceStartBits = SequenceStartBits;
		State.m_SequenceObservedAt = Now;
		static uint64_t NextSequenceToken = 1;
		State.m_ActiveToken = 0x3000000000000000ull | NextSequenceToken++;
	}

	if ( ( bHadSequence || LocalOrigin.DistanceSquared( pAttacker->GetOrigin() ) <= 190.f * 190.f )
		&& State.m_ActiveToken != 0 && Now - State.m_SequenceObservedAt <= 320ull )
	{
		OutThreat.m_Token = static_cast<uintptr_t>( State.m_ActiveToken );
		OutThreat.m_TypeBit = AUTO_PARRY_BOT_MELEE;
		return true;
	}

	return false;
}

inline auto GetHeroMeleeTypeBit( CBaseModifier* pModifier ) -> int
{
	if ( !pModifier )
		return 0;

	auto* pAbility = pModifier->m_hAbility().Get<C_BaseEntity>();
	if ( !pAbility || !AutoParrySchemaDerivesFrom( pAbility->GetSchemaClassBinding() , "CCitadel_Ability_HoldMelee" ) )
		return 0;

	auto* pMeleeAbility = reinterpret_cast<CCitadel_Ability_HoldMelee*>( pAbility );
	const auto AttackState = pMeleeAbility->m_eCurrentAttackState();
	if ( AttackState == EMeleeHold_AttackState::None )
		return 0;

	const auto AttackType = pMeleeAbility->m_eCurrentAttackType();
	// A light tap is already classified as Light during its brief charging
	// phase. Accepting it here gains the first few frames of reaction time. Do
	// not do the same for heavy variants; their release keeps its own delay.
	if ( AttackState == EMeleeHold_AttackState::Charging
		&& AttackType != EMeleeHold_AttackType::Light )
	{
		return 0;
	}

	switch ( AttackType )
	{
		case EMeleeHold_AttackType::Light:
			return AUTO_PARRY_LIGHT_MELEE;
		case EMeleeHold_AttackType::Heavy:
		case EMeleeHold_AttackType::HeavyAir:
		case EMeleeHold_AttackType::Slide:
			return AUTO_PARRY_HIGH_MELEE;
		default:
			return 0;
	}
}

inline auto SelectEnabledHeroMeleeType( int DetectedType , int EnabledTypes ) -> int
{
	if ( DetectedType != 0 )
		return ( EnabledTypes & DetectedType ) != 0 ? DetectedType : 0;

	// CheckNearbyPlayerParry is itself an authoritative parry window. If its
	// ability handle is unavailable on the client, preserve the threat and use
	// whichever hero-melee category the user enabled.
	if ( ( EnabledTypes & AUTO_PARRY_HIGH_MELEE ) != 0 )
		return AUTO_PARRY_HIGH_MELEE;
	if ( ( EnabledTypes & AUTO_PARRY_LIGHT_MELEE ) != 0 )
		return AUTO_PARRY_LIGHT_MELEE;
	return 0;
}

inline auto FindActiveHeroMeleeModifier( C_BaseEntity* pAttacker , int EnabledTypes ,
	CBaseModifier*& OutModifier , int& OutTypeBit ) -> bool
{
	OutModifier = nullptr;
	OutTypeBit = 0;
	if ( !pAttacker )
		return false;

	auto* pModifierProperty = pAttacker->m_pModifierProp();
	if ( !pModifierProperty )
		return false;

	auto& Modifiers = pModifierProperty->m_vecModifiers();
	const int ModifierCount = Modifiers.Count();
	if ( ModifierCount <= 0 || ModifierCount > 256 || !Modifiers.Base() )
		return false;

	for ( int ModifierIndex = 0; ModifierIndex < ModifierCount; ++ModifierIndex )
	{
		auto* pModifier = Modifiers[ModifierIndex];
		const int TypeBit = GetHeroMeleeTypeBit( pModifier );
		if ( TypeBit == 0 || ( EnabledTypes & TypeBit ) == 0 )
			continue;

		OutModifier = pModifier;
		OutTypeBit = TypeBit;
		return true;
	}

	return false;
}

inline auto FindAutoParryThreat( CCitadelPlayerController* pLocalController , C_CitadelPlayerPawn* pLocalPawn , AutoParryThreat_t& OutThreat ) -> bool
{
	OutThreat = {};
	if ( !pLocalController || !pLocalPawn || !Settings::AimPreview::AutoParry )
		return false;

	const int EnabledTypes = Settings::AimPreview::AutoParryTypesMask & 0x0F;
	if ( EnabledTypes == 0 )
		return false;

	const uint8 LocalTeam = pLocalController->m_iTeamNum();
	const Vector3 LocalOrigin = pLocalPawn->GetOrigin();
	const CHandle LocalPawnHandle = pLocalPawn->pEntityIdentity()->Handle();
	const CHandle LocalControllerHandle = pLocalController->pEntityIdentity()->Handle();
	const AutoParrySoundThreat_t RecentSound = GetRecentAutoParrySound();
	const auto ParticleSignals = CollectAutoParryParticleSignals();
	const ULONGLONG Now = GetTickCount64();
	const auto* pCachedEntities = GetEntityCache()->GetCachedEntity();
	std::scoped_lock CacheLock( GetEntityCache()->GetLock() );

	for ( const auto& CachedEntity : *pCachedEntities )
	{
		C_BaseEntity* pAttacker = nullptr;
		bool bHero = false;

		if ( CachedEntity.m_Type == CachedEntity_t::CITADEL_PLAYER_CONTROLLER )
		{
			auto* pController = CachedEntity.m_Handle.Get<CCitadelPlayerController>();
			if ( !pController || pController == pLocalController || !pController->IsAlive()
				|| pController->m_iTeamNum() == LocalTeam )
			{
				continue;
			}

			pAttacker = pController->m_hHeroPawn().Get<C_CitadelPlayerPawn>();
			bHero = true;
		}
		else if ( CachedEntity.m_Type == CachedEntity_t::NPC_TROOPER
			|| CachedEntity.m_Type == CachedEntity_t::NPC_OBJECTIVE )
		{
			pAttacker = CachedEntity.m_Handle.Get<C_BaseEntity>();
			if ( !pAttacker || pAttacker->m_iTeamNum() == LocalTeam )
				continue;
		}
		else
		{
			continue;
		}

		if ( !pAttacker || pAttacker == pLocalPawn || pAttacker->m_iHealth() <= 0 )
			continue;

		auto* pSceneNode = pAttacker->m_pGameSceneNode();
		if ( !pSceneNode || pSceneNode->m_bDormant() )
			continue;

		const bool bGuardian = !bHero
			&& CachedEntity.m_Type == CachedEntity_t::NPC_OBJECTIVE
			&& IsGuardianMeleeEntity( pAttacker );
		const bool bMeleeTrooper = !bHero
			&& CachedEntity.m_Type == CachedEntity_t::NPC_TROOPER
			&& IsMeleeTrooperModel( pAttacker );
		if ( !bHero && !bGuardian && !bMeleeTrooper )
			continue;

		const float MaxThreatDistance = bGuardian ? 725.f : 525.f;
		const float AttackerDistanceSquared = LocalOrigin.DistanceSquared( pAttacker->GetOrigin() );
		if ( AttackerDistanceSquared > MaxThreatDistance * MaxThreatDistance )
			continue;

		const bool bSoundFresh = RecentSound.m_Time != 0 && Now - RecentSound.m_Time <= 850ull;
		const int AttackerIndex = pAttacker->pEntityIdentity()->Handle().GetEntryIndex();
		const bool bSoundIndexMatches = RecentSound.m_SourceEntityIndex > 0
			&& RecentSound.m_SourceEntityIndex == AttackerIndex;
		const bool bSoundPositionMatches = !RecentSound.m_Position.IsZero()
			&& RecentSound.m_Position.DistanceSquared( pAttacker->GetOrigin() ) <= 280.f * 280.f;
		const bool bSoundHasNoBinding = RecentSound.m_SourceEntityIndex <= 0
			&& RecentSound.m_Position.IsZero();
		const bool bSoundSourceMatches = bSoundIndexMatches || bSoundPositionMatches || bSoundHasNoBinding;

		if ( bHero )
		{
			const bool bFacesLocal = IsAutoParryEntityFacingLocal( pAttacker , LocalOrigin );
			constexpr float MaxHeroMeleeReach = 360.f;
			if ( bFacesLocal && AttackerDistanceSquared <= MaxHeroMeleeReach * MaxHeroMeleeReach
				&& FindAutoParryParticleThreat( ParticleSignals , pAttacker , true , false , false ,
					EnabledTypes , OutThreat ) )
			{
				return true;
			}

			auto* pParryModifier = FindParryCheckModifier( pAttacker );
			const int TypeBit = SelectEnabledHeroMeleeType( GetHeroMeleeTypeBit( pParryModifier ) , EnabledTypes );
			if ( pParryModifier && TypeBit != 0 && bFacesLocal
				&& AttackerDistanceSquared <= MaxHeroMeleeReach * MaxHeroMeleeReach )
			{
				OutThreat.m_Token = reinterpret_cast<uintptr_t>( pParryModifier );
				OutThreat.m_TypeBit = TypeBit;
				return true;
			}

			// The exact parry-window modifier is not always replicated. Fall back to
			// the networked HoldMelee ability state carried by any active modifier.
			CBaseModifier* pActiveMeleeModifier = nullptr;
			int ActiveMeleeType = 0;
			if ( bFacesLocal && AttackerDistanceSquared <= MaxHeroMeleeReach * MaxHeroMeleeReach
				&& FindActiveHeroMeleeModifier( pAttacker , EnabledTypes , pActiveMeleeModifier , ActiveMeleeType ) )
			{
				OutThreat.m_Token = reinterpret_cast<uintptr_t>( pActiveMeleeModifier );
				OutThreat.m_TypeBit = ActiveMeleeType;
				return true;
			}

			const bool bHeroSoundType = RecentSound.m_TypeBit == AUTO_PARRY_HIGH_MELEE
				|| RecentSound.m_TypeBit == AUTO_PARRY_LIGHT_MELEE;
			if ( bSoundFresh && bSoundSourceMatches && bHeroSoundType
				&& ( EnabledTypes & RecentSound.m_TypeBit ) != 0 && bFacesLocal
				&& AttackerDistanceSquared <= MaxHeroMeleeReach * MaxHeroMeleeReach )
			{
				OutThreat.m_Token = static_cast<uintptr_t>( RecentSound.m_Token );
				OutThreat.m_TypeBit = RecentSound.m_TypeBit;
				return true;
			}

			continue;
		}

		const int TypeBit = bGuardian ? AUTO_PARRY_GUARDIAN_MELEE : AUTO_PARRY_BOT_MELEE;
		if ( ( EnabledTypes & TypeBit ) == 0 )
			continue;

		const bool bFacesLocal = bGuardian
			&& IsAutoParryEntityFacingLocal( pAttacker , LocalOrigin );
		CHandle NpcTarget;
		const bool bHasNpcTarget = bGuardian
			&& TryGetAutoParryEntityTarget( pAttacker , NpcTarget );
		const bool bGuardianTargetsLocal = bHasNpcTarget
			&& ( NpcTarget == LocalPawnHandle || NpcTarget == LocalControllerHandle );
		const bool bGuardianAimsAtLocal = bHasNpcTarget ? bGuardianTargetsLocal : bFacesLocal;
		const bool bTrooperAimsAtLocal = bMeleeTrooper
			&& ( IsAutoParryEntityTargetingLocal( pAttacker , LocalPawnHandle , LocalControllerHandle )
				|| IsAutoParryEntityFacingLocal( pAttacker , LocalOrigin ) );
		if ( ( ( bGuardian && bGuardianAimsAtLocal ) || bTrooperAimsAtLocal )
			&& FindAutoParryParticleThreat( ParticleSignals , pAttacker , false , bGuardian , bMeleeTrooper ,
				EnabledTypes , OutThreat ) )
		{
			return true;
		}

		bool bGuardianSequenceReadable = false;
		if ( bGuardian && BuildGuardianAnimationThreat( pAttacker , LocalOrigin , bGuardianAimsAtLocal ,
			bGuardianSequenceReadable , OutThreat ) )
		{
			return true;
		}

		if ( bMeleeTrooper && BuildMeleeTrooperAttackThreat( pAttacker , LocalPawnHandle ,
			LocalControllerHandle , LocalOrigin , OutThreat ) )
		{
			return true;
		}

		if ( bMeleeTrooper && bSoundFresh && bSoundSourceMatches
			&& RecentSound.m_TypeBit == AUTO_PARRY_BOT_MELEE
			&& AttackerDistanceSquared <= 270.f * 270.f
			&& ( IsAutoParryEntityTargetingLocal( pAttacker , LocalPawnHandle , LocalControllerHandle )
				|| IsAutoParryEntityFacingLocal( pAttacker , LocalOrigin ) ) )
		{
			OutThreat.m_Token = static_cast<uintptr_t>( RecentSound.m_Token );
			OutThreat.m_TypeBit = AUTO_PARRY_BOT_MELEE;
			return true;
		}

		// Tier-1 Guardians publish a Guardian.Tier1.Melee.Swing/Slam event at
		// attack start. Bind it to the emitting Guardian; when the event omitted
		// spatial fields, the attack cue plus facing/reach is still required.
		constexpr float MaxGuardianMeleeReach = 330.f;
		const bool bGuardianCanHitLocal = bGuardianAimsAtLocal
			&& LocalOrigin.DistanceSquared( pAttacker->GetOrigin() ) <= MaxGuardianMeleeReach * MaxGuardianMeleeReach;
		if ( bGuardian && bSoundFresh
			&& RecentSound.m_TypeBit == AUTO_PARRY_GUARDIAN_MELEE
			&& bSoundSourceMatches && bGuardianCanHitLocal )
		{
			OutThreat.m_Token = static_cast<uintptr_t>( RecentSound.m_Token );
			OutThreat.m_TypeBit = TypeBit;
			return true;
		}
	}

	return false;
}
