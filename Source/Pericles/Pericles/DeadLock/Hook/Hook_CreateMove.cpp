#include "Hook_CreateMove.hpp"

#include <DeadLock/SDK/Update/CCitadelInput.hpp>

#include <GameClient/CL_CitadelPlayerController.hpp>
#include <GameClient/CEntityCache/CEntityCache.hpp>
#include <GameClient/CL_AimTarget.hpp>
#include <GameClient/CL_AutoParry.hpp>

#include <PericlesClient/CPericlesClient.hpp>
#include <PericlesClient/Features/CVisual/CVisual.hpp>
#include <PericlesClient/Settings/Settings.hpp>

#include <DeadLock/SDK/Interface/CGameEntitySystem.hpp>
#include <DeadLock/SDK/Types/CEntityData.hpp>
#include <DeadLock/SDK/Math/Math.hpp>
#include <GameClient/CL_Bones.hpp>

#include <algorithm>
#include <cmath>

static auto PassHitChance() -> bool
{
	const int HitChance = std::clamp( Settings::AimPreview::HitChance , 0 , 100 );

	if ( HitChance <= 0 )
		return false;

	if ( HitChance >= 100 )
		return true;

	// One roll is kept for the whole CreateMove invocation through bHaveFinal.
	static uint32_t RandomState = ( static_cast<uint32_t>( GetTickCount64() ) ^ GetCurrentThreadId() ) | 1u;
	RandomState ^= RandomState << 13;
	RandomState ^= RandomState >> 17;
	RandomState ^= RandomState << 5;

	return static_cast<int>( RandomState % 100u ) < HitChance;
}

static auto PassAutoParryChance() -> bool
{
	const int ParryChance = std::clamp( Settings::AimPreview::AutoParryChance , 0 , 100 );

	if ( ParryChance <= 0 )
		return false;

	if ( ParryChance >= 100 )
		return true;

	static uint32_t RandomState = ( static_cast<uint32_t>( GetTickCount64() )
		^ GetCurrentThreadId() ^ 0x9E3779B9u ) | 1u;
	RandomState ^= RandomState << 13;
	RandomState ^= RandomState >> 17;
	RandomState ^= RandomState << 5;

	return static_cast<int>( RandomState % 100u ) < ParryChance;
}

static auto WriteMessageAngles( CMsgQAngle* pMessageAngles , const QAngle& Angles ) -> void
{
	if ( !pMessageAngles )
		return;

	pMessageAngles->set_x( Angles.m_x );
	pMessageAngles->set_y( Angles.m_y );
	pMessageAngles->set_z( Angles.m_z );
}

static auto SyncMessageAngles( CMsgQAngle* pDestination , const CMsgQAngle& Source ) -> void
{
	if ( !pDestination )
		return;

	if ( Source.has_x() ) pDestination->set_x( Source.x() );
	else pDestination->clear_x();

	if ( Source.has_y() ) pDestination->set_y( Source.y() );
	else pDestination->clear_y();

	if ( Source.has_z() ) pDestination->set_z( Source.z() );
	else pDestination->clear_z();
}

static auto SyncButtonState( CInButtonStatePB* pDestination , const CInButtonStatePB& Source ) -> void
{
	if ( !pDestination )
		return;

	if ( Source.has_buttonstate1() ) pDestination->set_buttonstate1( Source.buttonstate1() );
	else pDestination->clear_buttonstate1();

	if ( Source.has_buttonstate2() ) pDestination->set_buttonstate2( Source.buttonstate2() );
	else pDestination->clear_buttonstate2();

	if ( Source.has_buttonstate3() ) pDestination->set_buttonstate3( Source.buttonstate3() );
	else pDestination->clear_buttonstate3();
}

static auto SyncSubtickMove( CSubtickMoveStep* pDestination , const CSubtickMoveStep& Source ) -> void
{
	if ( !pDestination )
		return;

	if ( Source.has_button() ) pDestination->set_button( Source.button() );
	else pDestination->clear_button();

	if ( Source.has_pressed() ) pDestination->set_pressed( Source.pressed() );
	else pDestination->clear_pressed();

	if ( Source.has_when() ) pDestination->set_when( Source.when() );
	else pDestination->clear_when();

	if ( Source.has_analog_forward_delta() ) pDestination->set_analog_forward_delta( Source.analog_forward_delta() );
	else pDestination->clear_analog_forward_delta();

	if ( Source.has_analog_left_delta() ) pDestination->set_analog_left_delta( Source.analog_left_delta() );
	else pDestination->clear_analog_left_delta();

	if ( Source.has_pitch_delta() ) pDestination->set_pitch_delta( Source.pitch_delta() );
	else pDestination->clear_pitch_delta();

	if ( Source.has_yaw_delta() ) pDestination->set_yaw_delta( Source.yaw_delta() );
	else pDestination->clear_yaw_delta();
}

static auto RefreshMoveCrc( CBaseUserCmdPB* pBaseCmd ) -> void
{
	if ( !pBaseCmd || !pBaseCmd->has_move_crc() )
		return;

	// move_crc is a serialized movement snapshot. Parse the engine-generated
	// payload so fields added by an update are preserved, then synchronize the
	// fields this hook may have changed in the real base command.
	CBaseUserCmdPB MoveSnapshot;
	if ( !MoveSnapshot.ParseFromString( pBaseCmd->move_crc() ) )
		return;

	// The command object belongs to client.dll while MoveSnapshot belongs to this
	// module. Protobuf CopyFrom compares descriptor addresses and fatally aborts
	// when identically named generated messages come from those two modules.
	// Synchronize scalar fields instead, which is safe across that boundary.
	if ( pBaseCmd->has_viewangles() )
		SyncMessageAngles( MoveSnapshot.mutable_viewangles() , pBaseCmd->viewangles() );
	else
		MoveSnapshot.clear_viewangles();

	if ( pBaseCmd->has_buttons_pb() )
		SyncButtonState( MoveSnapshot.mutable_buttons_pb() , pBaseCmd->buttons_pb() );
	else
		MoveSnapshot.clear_buttons_pb();

	if ( pBaseCmd->has_forwardmove() )
		MoveSnapshot.set_forwardmove( pBaseCmd->forwardmove() );

	if ( pBaseCmd->has_leftmove() )
		MoveSnapshot.set_leftmove( pBaseCmd->leftmove() );

	if ( pBaseCmd->has_upmove() )
		MoveSnapshot.set_upmove( pBaseCmd->upmove() );

	while ( MoveSnapshot.subtick_moves_size() > pBaseCmd->subtick_moves_size() )
		MoveSnapshot.mutable_subtick_moves()->RemoveLast();

	for ( int Index = 0; Index < pBaseCmd->subtick_moves_size(); ++Index )
	{
		auto* pDestination = Index < MoveSnapshot.subtick_moves_size()
			? MoveSnapshot.mutable_subtick_moves( Index )
			: MoveSnapshot.add_subtick_moves();

		SyncSubtickMove( pDestination , pBaseCmd->subtick_moves( Index ) );
	}

	std::string SerializedSnapshot;
	if ( MoveSnapshot.SerializeToString( &SerializedSnapshot ) )
		pBaseCmd->set_move_crc( SerializedSnapshot );
}

static auto ForceCommandButton( CUserCmd* pUserCmd , const uint64_t ButtonMask ) -> bool
{
	if ( !pUserCmd || !pUserCmd->cmd.has_base() )
		return false;

	auto* pBaseCmd = pUserCmd->cmd.mutable_base();
	// Never allocate a nested protobuf message inside a command owned by the
	// game. An object allocated by this DLL carries this module's descriptor and
	// client.dll will fatally abort when it later copies the command.
	if ( !pBaseCmd->has_buttons_pb() )
		return false;

	pUserCmd->button_states.buttonstate1 |= ButtonMask;
	pUserCmd->button_states.buttonstate2 |= ButtonMask;

	auto* pButtons = pBaseCmd->mutable_buttons_pb();
	pButtons->set_buttonstate1( pButtons->buttonstate1() | ButtonMask );
	pButtons->set_buttonstate2( pButtons->buttonstate2() | ButtonMask );

	RefreshMoveCrc( pBaseCmd );
	return true;
}

static auto ForcePrimaryAttack( CUserCmd* pUserCmd ) -> bool
{
	return ForceCommandButton( pUserCmd , static_cast<uint64_t>( IN_ATTACK ) );
}

static auto HasPrimaryAttackInCommand( const CUserCmd* pUserCmd ) -> bool
{
	if ( !pUserCmd )
		return false;

	constexpr uint64_t AttackMask = static_cast<uint64_t>( IN_ATTACK );

	// Held attack in the native button state.
	if ( ( pUserCmd->button_states.buttonstate1 & AttackMask ) != 0 )
		return true;

	if ( !pUserCmd->cmd.has_base() )
		return false;

	const auto& BaseCmd = pUserCmd->cmd.base();

	// Held attack in the command's serialized button state.
	if ( BaseCmd.has_buttons_pb()
		&& ( BaseCmd.buttons_pb().buttonstate1() & AttackMask ) != 0 )
	{
		return true;
	}

	// A short click can be pressed and released within the same tick, leaving
	// the final held state clear. The press edge is retained in subtick_moves.
	for ( int i = 0; i < BaseCmd.subtick_moves_size(); ++i )
	{
		const auto& Step = BaseCmd.subtick_moves( i );

		if ( Step.has_button()
			&& ( Step.button() & AttackMask ) != 0
			&& Step.has_pressed()
			&& Step.pressed() )
		{
			return true;
		}
	}

	return false;
}

static auto CalculateShotAngles( const Vector3& CameraPos, const Vector3& TargetPos ) -> QAngle
{
	QAngle ShotAngles = Math::CalcAngle( CameraPos, TargetPos );

	ShotAngles.Normalize();
	ShotAngles.Clamp();
	return ShotAngles;
}

static auto ApplyAxisSmoothing( float CurrentAngle, float TargetAngle, float SmoothingPercent ) -> float
{
	// The setting is exposed as a percentage: 0% means no smoothing (the full
	// correction is applied), while 100% keeps the player's original angle.
	const float CorrectionScale = 1.f - std::clamp( SmoothingPercent, 0.f, 100.f ) / 100.f;
	const float Delta = Math::AngleNormalize( TargetAngle - CurrentAngle );
	return CurrentAngle + Delta * CorrectionScale;
}

static auto RotateMovementAxes( float Forward, float Left, float DeltaYawRadians ) -> std::pair<float, float>
{
	const float CosYaw = std::cos( DeltaYawRadians );
	const float SinYaw = std::sin( DeltaYawRadians );
	return {
		Forward * CosYaw - Left * SinYaw,
		Forward * SinYaw + Left * CosYaw
	};
}

static auto CorrectMovementForYaw( CBaseUserCmdPB* pBaseCmd, float OriginalYaw, float CommandYaw ) -> void
{
	if ( !pBaseCmd )
		return;

	const float DeltaYaw = DEG2RAD( Math::AngleNormalize( OriginalYaw - CommandYaw ) );

	if ( pBaseCmd->has_forwardmove() || pBaseCmd->has_leftmove() )
	{
		const auto [CorrectedForward, CorrectedLeft] = RotateMovementAxes(
			pBaseCmd->forwardmove(), pBaseCmd->leftmove(), DeltaYaw );
		pBaseCmd->set_forwardmove( CorrectedForward );
		pBaseCmd->set_leftmove( CorrectedLeft );
	}

	// Analog movement can change during a tick. Rotate every subtick delta as
	// well, otherwise keyboard/controller transitions still bend the path.
	for ( int Index = 0; Index < pBaseCmd->subtick_moves_size(); ++Index )
	{
		auto* pStep = pBaseCmd->mutable_subtick_moves( Index );
		if ( !pStep || ( !pStep->has_analog_forward_delta() && !pStep->has_analog_left_delta() ) )
			continue;

		const auto [CorrectedForwardDelta, CorrectedLeftDelta] = RotateMovementAxes(
			pStep->analog_forward_delta(), pStep->analog_left_delta(), DeltaYaw );
		pStep->set_analog_forward_delta( CorrectedForwardDelta );
		pStep->set_analog_left_delta( CorrectedLeftDelta );
	}
}

auto Hook_CreateMove( CCitadelInput* pCitadelInput , uint32_t split_screen_index , char a3 ) -> void
{
	// PRE: select a target from the player's real camera angle.
	CCitadelPlayerController* pLocalController = GetCL_CitadelPlayerController()->GetLocal();
	C_CitadelPlayerPawn* pLocalPawn = pLocalController
		? pLocalController->m_hHeroPawn().Get<C_CitadelPlayerPawn>()
		: nullptr;

	Vector3 finalTargetPos;
	bool bHaveTarget = false;
	bool bHitChanceEvaluated = false;
	bool bHitChancePassed = false;
	bool bAutoSoulShot = false;

	QAngle* pInputViewAngles = nullptr;
	QAngle originalInputAngles;
	QAngle originalClientCamera;
	QAngle originalEyeAngles;
	bool bHaveOriginalInputAngles = false;
	bool bHavePawnAngleSnapshot = false;
	bool bTemporaryRedirect = false;

	if ( ( Settings::AimPreview::Active || Settings::AimPreview::SoulSteal ) && pLocalController && pCitadelInput )
	{
		Vector3 cameraPos;
		if ( pLocalPawn )
			cameraPos = pLocalPawn->GetEyeOrigin();

		// Prefer the engine-provided camera origin from the previous complete
		// command. It is more accurate in third-person than the pawn eye origin.
		auto* pPreviousCmd = pCitadelInput->GetUserCmd( pLocalController );
		if ( pPreviousCmd && pPreviousCmd->cmd.has_vec_camera_position() )
		{
			const auto& PreviousCamera = pPreviousCmd->cmd.vec_camera_position();
			const Vector3 PreviousCameraPos( PreviousCamera.x() , PreviousCamera.y() , PreviousCamera.z() );

			if ( !PreviousCameraPos.IsZero() )
				cameraPos = PreviousCameraPos;
		}

		pInputViewAngles = CCitadelInput_GetViewAngles( pCitadelInput, 0 );
		if ( pInputViewAngles )
		{
			originalInputAngles = *pInputViewAngles;
			bHaveOriginalInputAngles = true;
		}

		AimTargetResult_t BestTarget;
		SoulTargetResult_t BestSoul;
		const Vector3 SoulShotOrigin = pLocalPawn ? pLocalPawn->GetEyeOrigin() : Vector3{};
		const bool bHaveSoulTarget = Settings::AimPreview::SoulSteal
			&& FindBestSoulTarget( SoulShotOrigin , BestSoul );
		const bool bHaveAimTarget = !bHaveSoulTarget
			&& Settings::AimPreview::Active
			&& FindBestAimTargetWithFallback( pLocalController , GetRandomSelectedAimBone() , BestTarget );

		GetVisual()->UpdateAimPreviewTarget( bHaveSoulTarget , BestSoul.m_ScreenPosition ,
			bHaveAimTarget , BestTarget.m_ScreenPosition );

		if ( bHaveOriginalInputAngles && !cameraPos.IsZero() && ( bHaveSoulTarget || bHaveAimTarget ) )
		{
			if ( bHaveSoulTarget )
			{
				finalTargetPos = BestSoul.m_WorldPosition;
				bAutoSoulShot = true;
				bHitChanceEvaluated = true;
				bHitChancePassed = true;
			}
			else
			{
				finalTargetPos = BestTarget.m_WorldPosition;
			}

			bHaveTarget = true;

			// CreateMove must see the shot angle while it constructs the firing
			// command. This is temporary: every local angle is restored below.
			const bool bAttackHeld = bAutoSoulShot
				|| ( GetAsyncKeyState( VK_LBUTTON ) & 0x8000 ) != 0
				|| HasPrimaryAttackInCommand( pPreviousCmd );

			if ( bAttackHeld && !bAutoSoulShot )
			{
				bHitChanceEvaluated = true;
				bHitChancePassed = PassHitChance();
			}

			if ( bAttackHeld && bHitChancePassed )
			{
				if ( pLocalPawn )
				{
					originalClientCamera = pLocalPawn->m_angClientCamera();
					originalEyeAngles = pLocalPawn->m_angEyeAngles();
					bHavePawnAngleSnapshot = true;
				}

				*pInputViewAngles = CalculateShotAngles( cameraPos, finalTargetPos );
				bTemporaryRedirect = true;
			}
		}
	}

	// Build the command, then immediately restore the real local camera state.
	CreateMove_o( pCitadelInput , split_screen_index , a3 );

	if ( bTemporaryRedirect )
	{
		*pInputViewAngles = originalInputAngles;

		if ( bHavePawnAngleSnapshot && pLocalPawn )
		{
			pLocalPawn->m_angClientCamera() = originalClientCamera;
			pLocalPawn->m_angEyeAngles() = originalEyeAngles;
		}
	}

	CUserCmd* pUserCmd = pLocalController && pCitadelInput
		? pCitadelInput->GetUserCmd( pLocalController )
		: nullptr;

	if ( bAutoSoulShot )
		ForcePrimaryAttack( pUserCmd );

	// The game exposes the temporary CheckNearbyPlayerParry modifier only while
	// an incoming melee is parable.
	static bool bHighMeleePending = false;
	static ULONGLONG HighMeleeObservedAt = 0;
	static bool bParryThreatDecisionActive = false;
	static uintptr_t EvaluatedParryThreatToken = 0;
	static int EvaluatedParryThreatType = 0;
	static int EvaluatedParryChance = -1;
	static bool bParryChancePassed = false;
	AutoParryThreat_t ParryThreat;
	if ( FindAutoParryThreat( pLocalController , pLocalPawn , ParryThreat ) )
	{
		const int CurrentParryChance = std::clamp( Settings::AimPreview::AutoParryChance , 0 , 100 );
		const bool bNewThreat = !bParryThreatDecisionActive
			|| EvaluatedParryThreatToken != ParryThreat.m_Token
			|| EvaluatedParryThreatType != ParryThreat.m_TypeBit
			|| EvaluatedParryChance != CurrentParryChance;
		if ( bNewThreat )
		{
			bParryThreatDecisionActive = true;
			EvaluatedParryThreatToken = ParryThreat.m_Token;
			EvaluatedParryThreatType = ParryThreat.m_TypeBit;
			EvaluatedParryChance = CurrentParryChance;
			bParryChancePassed = PassAutoParryChance();
			bHighMeleePending = false;
			HighMeleeObservedAt = 0;
		}

		if ( bParryChancePassed )
		{
			bool bDelayElapsed = true;
			if ( ParryThreat.m_TypeBit == AUTO_PARRY_HIGH_MELEE )
			{
				const ULONGLONG Now = GetTickCount64();
				if ( !bHighMeleePending )
				{
					bHighMeleePending = true;
					HighMeleeObservedAt = Now;
				}

				// A heavy melee winds up before its parryable impact. Waiting a few
				// frames avoids raising parry as soon as the attacker starts the swing.
				constexpr ULONGLONG HighMeleeParryDelayMs = 70;
				bDelayElapsed = Now - HighMeleeObservedAt >= HighMeleeParryDelayMs;
			}
			else
			{
				bHighMeleePending = false;
				HighMeleeObservedAt = 0;
			}

			// buttonstate1 carries the held state and buttonstate2 carries the press
			// transition. Do not append a protobuf subtick object here: it would be
			// allocated by this DLL and crash client.dll when the command is copied.
			if ( bDelayElapsed )
				ForceCommandButton( pUserCmd , static_cast<uint64_t>( IN_ABILITY_HELD ) );
		}
		else
		{
			bHighMeleePending = false;
			HighMeleeObservedAt = 0;
		}
	}
	else
	{
		bParryThreatDecisionActive = false;
		EvaluatedParryThreatToken = 0;
		EvaluatedParryThreatType = 0;
		EvaluatedParryChance = -1;
		bParryChancePassed = false;
		bHighMeleePending = false;
		HighMeleeObservedAt = 0;
	}

	const bool bActualAttack = HasPrimaryAttackInCommand( pUserCmd );
	if ( bHaveTarget && bActualAttack && !bHitChanceEvaluated )
	{
		bHitChanceEvaluated = true;
		bHitChancePassed = PassHitChance();
	}

	// POST: keep only the simulation shot angle in the outgoing command. The
	// Citadel camera angle remains the player's original visual orientation.
	if ( bHaveTarget && pUserCmd && bActualAttack && bHitChancePassed )
	{
		Vector3 commandCameraPos;

		if ( pUserCmd->cmd.has_vec_camera_position() )
		{
			const auto& CommandCamera = pUserCmd->cmd.vec_camera_position();
			commandCameraPos = Vector3( CommandCamera.x() , CommandCamera.y() , CommandCamera.z() );
		}

		if ( commandCameraPos.IsZero() && pLocalController )
		{
			if ( auto* pLocalPawn = pLocalController->m_hHeroPawn().Get<C_CitadelPlayerPawn>(); pLocalPawn )
				commandCameraPos = pLocalPawn->GetEyeOrigin();
		}

		if ( !commandCameraPos.IsZero() )
		{
			const QAngle shotAngles = CalculateShotAngles( commandCameraPos , finalTargetPos );
			QAngle smoothedShotAngles = shotAngles;

			// Souls have a small hit volume and the shot is triggered automatically.
			// Keeping any fraction of the player's angle (often aimed at the hero
			// behind the soul) leaves the ray below/aside the orb. Aim exactly at the
			// soul; smoothing remains exclusive to player-triggered aim assistance.
			if ( !bAutoSoulShot )
			{
				smoothedShotAngles.m_x = ApplyAxisSmoothing( originalInputAngles.m_x, shotAngles.m_x, Settings::AimPreview::PitchSmoothing );
				smoothedShotAngles.m_y = ApplyAxisSmoothing( originalInputAngles.m_y, shotAngles.m_y, Settings::AimPreview::YawSmoothing );
			}
			smoothedShotAngles.m_z = 0.f;
			smoothedShotAngles.Normalize();
			smoothedShotAngles.Clamp();

			auto* pBaseCmd = pUserCmd->cmd.mutable_base();
			WriteMessageAngles( pBaseCmd->mutable_viewangles() , smoothedShotAngles );
			CorrectMovementForYaw( pBaseCmd, originalInputAngles.m_y, smoothedShotAngles.m_y );

			if ( bTemporaryRedirect && bHaveOriginalInputAngles )
				WriteMessageAngles( pUserCmd->cmd.mutable_ang_camera_angles(), originalInputAngles );

			RefreshMoveCrc( pBaseCmd );
		}
	}
	else if ( bTemporaryRedirect && pUserCmd && bHaveOriginalInputAngles )
	{
		// A Windows mouse edge can be consumed by the UI/game before it becomes
		// IN_ATTACK. In that case restore the command as well as the local state.
		auto* pBaseCmd = pUserCmd->cmd.mutable_base();
		WriteMessageAngles( pBaseCmd->mutable_viewangles(), originalInputAngles );
		WriteMessageAngles( pUserCmd->cmd.mutable_ang_camera_angles(), originalInputAngles );
		RefreshMoveCrc( pBaseCmd );
	}

	// Lastly, call client side OnCreateMove handlers (GUI, visual features)
	if ( pUserCmd )
		GetPericlesClient()->OnCreateMove( pCitadelInput , pUserCmd );
}
