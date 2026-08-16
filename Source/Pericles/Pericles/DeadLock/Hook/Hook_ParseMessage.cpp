#include "Hook_ParseMessage.hpp"

#include <DeadLock/SDK/SDK.hpp>
#include <DeadLock/SDK/Math/Vector3.hpp>
#include <DeadLock/SDK/Update/Offsets.hpp>

#include <DeadLock/SDK/Network/CNetworkMessages.hpp>
#include <DeadLock/SDK/Interface/CSoundOpSystem.hpp>

#include <DeadLock/Protobuf/gameevents.pb.h>
#include <DeadLock/Protobuf/citadel_usermessages.pb.h>

#include <PericlesClient/CPericlesClient.hpp>

#include <cstring>

auto Hook_ParseMessage( CDemoRecorder* pDemoRecorder , CNetworkSerializerPB* pSerializer , CNetMessagePB* pNetMessage ) -> bool
{
	if ( pSerializer->messageID == k_EUserMsg_Damage )
	{
		auto* pDamageMessage = reinterpret_cast<CCitadelUserMessage_Damage*>(
			reinterpret_cast<PBYTE>( pNetMessage ) + g_OFFSET_CDemoRecorder_ParseMessage_pProtobuf );

		if ( pDamageMessage )
			GetPericlesClient()->OnDamageMessage( pDamageMessage );
	}

	if ( pSerializer->messageID == GE_SosStartSoundEvent )
	{
		CMsgSosStartSoundEvent* pMessage = reinterpret_cast<CMsgSosStartSoundEvent*>( (PBYTE)pNetMessage + g_OFFSET_CDemoRecorder_ParseMessage_pProtobuf );

		if ( pMessage )
		{
			std::string SoundName = "null";
			Vector3 SoundPos{};
			auto SourceEntityIndex = 0;

			if ( pMessage->has_source_entity_index() )
				SourceEntityIndex = pMessage->source_entity_index();

			const auto& PackedParams = pMessage->packed_params();
			if ( pMessage->has_packed_params()
				&& PackedParams.size() >= g_OFFSET_CMsgSosStartSoundEvent_SoundPos + sizeof( Vector3 ) )
			{
				memcpy( &SoundPos , PackedParams.data() + g_OFFSET_CMsgSosStartSoundEvent_SoundPos , sizeof( SoundPos ) );
			}

			const char* szSoundEventName = SDK::Interfaces::SoundOpSystem()->GetCSoundEventManager()->GetSoundEventName( pMessage->soundevent_hash() );

			if ( szSoundEventName )
				SoundName = szSoundEventName;

			// The source index and event hash are sufficient for attack cues. Some
			// Guardian events omit packed spatial parameters entirely.
			GetPericlesClient()->OnStartSound( SoundPos , SourceEntityIndex , SoundName.c_str() );
		}
	}

	return ParseMessage_o( pDemoRecorder , pSerializer , pNetMessage );
}
