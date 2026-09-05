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

// ============================================================================
// 1. POINTEUR VERS LA FONCTION ORIGINALE
// ============================================================================
void (*CreateMove_o)(CCitadelInput*, uint32_t, char) = nullptr;

// ============================================================================
// Command helpers.
// ============================================================================
static auto PassHitChance() -> bool
{
    const int HitChance = std::clamp(Settings::AimPreview::HitChance, 0, 100);
    if (HitChance <= 0) return false;
    if (HitChance >= 100) return true;

    static uint32_t RandomState = (static_cast<uint32_t>(GetTickCount64()) ^ GetCurrentThreadId()) | 1u;
    RandomState ^= RandomState << 13;
    RandomState ^= RandomState >> 17;
    RandomState ^= RandomState << 5;
    return static_cast<int>(RandomState % 100u) < HitChance;
}

static auto PassAutoParryChance() -> bool
{
    const int ParryChance = std::clamp(Settings::AimPreview::AutoParryChance, 0, 100);
    if (ParryChance <= 0) return false;
    if (ParryChance >= 100) return true;

    static uint32_t RandomState = (static_cast<uint32_t>(GetTickCount64()) ^ GetCurrentThreadId() ^ 0x9E3779B9u) | 1u;
    RandomState ^= RandomState << 13;
    RandomState ^= RandomState >> 17;
    RandomState ^= RandomState << 5;
    return static_cast<int>(RandomState % 100u) < ParryChance;
}

static auto SyncMessageAngles(CMsgQAngle* pDestination, const CMsgQAngle& Source) -> void
{
    if (!pDestination) return;
    if (Source.has_x()) pDestination->set_x(Source.x()); else pDestination->clear_x();
    if (Source.has_y()) pDestination->set_y(Source.y()); else pDestination->clear_y();
    if (Source.has_z()) pDestination->set_z(Source.z()); else pDestination->clear_z();
}

static auto SyncButtonState(CInButtonStatePB* pDestination, const CInButtonStatePB& Source) -> void
{
    if (!pDestination) return;
    if (Source.has_buttonstate1()) pDestination->set_buttonstate1(Source.buttonstate1()); else pDestination->clear_buttonstate1();
    if (Source.has_buttonstate2()) pDestination->set_buttonstate2(Source.buttonstate2()); else pDestination->clear_buttonstate2();
    if (Source.has_buttonstate3()) pDestination->set_buttonstate3(Source.buttonstate3()); else pDestination->clear_buttonstate3();
}

static auto SyncSubtickMove(CSubtickMoveStep* pDestination, const CSubtickMoveStep& Source) -> void
{
    if (!pDestination) return;
    if (Source.has_button()) pDestination->set_button(Source.button()); else pDestination->clear_button();
    if (Source.has_pressed()) pDestination->set_pressed(Source.pressed()); else pDestination->clear_pressed();
    if (Source.has_when()) pDestination->set_when(Source.when()); else pDestination->clear_when();
    if (Source.has_analog_forward_delta()) pDestination->set_analog_forward_delta(Source.analog_forward_delta()); else pDestination->clear_analog_forward_delta();
    if (Source.has_analog_left_delta()) pDestination->set_analog_left_delta(Source.analog_left_delta()); else pDestination->clear_analog_left_delta();
    if (Source.has_pitch_delta()) pDestination->set_pitch_delta(Source.pitch_delta()); else pDestination->clear_pitch_delta();
    if (Source.has_yaw_delta()) pDestination->set_yaw_delta(Source.yaw_delta()); else pDestination->clear_yaw_delta();
}

static auto RefreshMoveCrc(CBaseUserCmdPB* pBaseCmd) -> void
{
    if (!pBaseCmd || !pBaseCmd->has_move_crc()) return;
    CBaseUserCmdPB MoveSnapshot;
    if (!MoveSnapshot.ParseFromString(pBaseCmd->move_crc())) return;
    if (pBaseCmd->has_viewangles()) SyncMessageAngles(MoveSnapshot.mutable_viewangles(), pBaseCmd->viewangles()); else MoveSnapshot.clear_viewangles();
    if (pBaseCmd->has_buttons_pb()) SyncButtonState(MoveSnapshot.mutable_buttons_pb(), pBaseCmd->buttons_pb()); else MoveSnapshot.clear_buttons_pb();
    if (pBaseCmd->has_forwardmove()) MoveSnapshot.set_forwardmove(pBaseCmd->forwardmove());
    if (pBaseCmd->has_leftmove()) MoveSnapshot.set_leftmove(pBaseCmd->leftmove());
    if (pBaseCmd->has_upmove()) MoveSnapshot.set_upmove(pBaseCmd->upmove());
    while (MoveSnapshot.subtick_moves_size() > pBaseCmd->subtick_moves_size()) MoveSnapshot.mutable_subtick_moves()->RemoveLast();
    for (int Index = 0; Index < pBaseCmd->subtick_moves_size(); ++Index)
    {
        auto* pDestination = (Index < MoveSnapshot.subtick_moves_size()) ? MoveSnapshot.mutable_subtick_moves(Index) : MoveSnapshot.add_subtick_moves();
        SyncSubtickMove(pDestination, pBaseCmd->subtick_moves(Index));
    }
    std::string SerializedSnapshot;
    if (MoveSnapshot.SerializeToString(&SerializedSnapshot)) pBaseCmd->set_move_crc(SerializedSnapshot);
}

static auto ForceCommandButton(CUserCmd* pUserCmd, const uint64_t ButtonMask) -> bool
{
    if (!pUserCmd || !pUserCmd->cmd.has_base()) return false;
    auto* pBaseCmd = pUserCmd->cmd.mutable_base();
    if (!pBaseCmd->has_buttons_pb()) return false;
    pUserCmd->button_states.buttonstate1 |= ButtonMask;
    pUserCmd->button_states.buttonstate2 |= ButtonMask;
    auto* pButtons = pBaseCmd->mutable_buttons_pb();
    pButtons->set_buttonstate1(pButtons->buttonstate1() | ButtonMask);
    pButtons->set_buttonstate2(pButtons->buttonstate2() | ButtonMask);
    RefreshMoveCrc(pBaseCmd);
    return true;
}

static auto ForcePrimaryAttack(CUserCmd* pUserCmd) -> bool
{
    return ForceCommandButton(pUserCmd, static_cast<uint64_t>(IN_ATTACK));
}

static auto HasPrimaryAttackInCommand(const CUserCmd* pUserCmd) -> bool
{
    if (!pUserCmd) return false;
    constexpr uint64_t AttackMask = static_cast<uint64_t>(IN_ATTACK);
    if ((pUserCmd->button_states.buttonstate1 & AttackMask) != 0) return true;
    if (!pUserCmd->cmd.has_base()) return false;
    const auto& BaseCmd = pUserCmd->cmd.base();
    if (BaseCmd.has_buttons_pb() && (BaseCmd.buttons_pb().buttonstate1() & AttackMask) != 0) return true;
    for (int i = 0; i < BaseCmd.subtick_moves_size(); ++i)
    {
        const auto& Step = BaseCmd.subtick_moves(i);
        if (Step.has_button() && (Step.button() & AttackMask) != 0 && Step.has_pressed() && Step.pressed()) return true;
    }
    return false;
}

namespace
{
    struct RawMsgVector
    {
        uint8_t pad00[0x10];
        uint32_t hasBits;
        uint8_t pad14[0x04];
        float x;
        float y;
        float z;
        uint8_t pad24[0x04];
    };

    struct RawCitadelUserCmd
    {
        uint8_t pad00[0x10];
        uint32_t hasBits;
        uint8_t pad14[0x24];
        void* base;
        RawMsgVector* cameraPosition;
        void* cameraAngles;
    };

    static_assert(offsetof(RawMsgVector, x) == 0x18);
    static_assert(offsetof(RawCitadelUserCmd, cameraPosition) == 0x40);

    auto IsFiniteVector(const Vector3& value) -> bool
    {
        return std::isfinite(value.m_x) && std::isfinite(value.m_y) && std::isfinite(value.m_z);
    }

    auto CalculateRedirectedCameraPosition(const Vector3& cameraPosition,
                                           const QAngle& commandAngles,
                                           const Vector3& targetPosition,
                                           Vector3& redirectedPosition) -> bool
    {
        if (!IsFiniteVector(cameraPosition) || !IsFiniteVector(targetPosition))
            return false;

        Vector3 forward;
        Math::AngleVectors(commandAngles, forward);
        if (!IsFiniteVector(forward) || forward.LengthSquared() < 1e-6f)
            return false;

        forward.Normalize();

        const Vector3 toTarget = targetPosition - cameraPosition;
        const float distanceAlongRay = toTarget.Dot(forward);
        if (!std::isfinite(distanceAlongRay) || distanceAlongRay <= 0.f)
            return false;

        redirectedPosition = targetPosition - forward * distanceAlongRay;
        if (!IsFiniteVector(redirectedPosition))
            return false;

        return true;
    }

    auto WriteCameraPosition(CCitadelUserCmdPB& command, const Vector3& position) -> bool
    {
        if (!command.has_vec_camera_position())
            return false;

        auto* cameraMessage = command.mutable_vec_camera_position();
        auto* rawCamera = reinterpret_cast<RawMsgVector*>(cameraMessage);
        auto* rawCommand = reinterpret_cast<RawCitadelUserCmd*>(&command);

        rawCamera->x = position.m_x;
        rawCamera->y = position.m_y;
        rawCamera->z = position.m_z;
        rawCamera->hasBits |= 0x7;
        rawCommand->hasBits |= 0x2;
        return true;
    }

    auto WriteCameraAngles(CCitadelUserCmdPB& command, const QAngle& angles) -> bool
    {
        if (!command.has_ang_camera_angles()
            || !std::isfinite(angles.m_x) || !std::isfinite(angles.m_y) || !std::isfinite(angles.m_z))
        {
            return false;
        }

        auto* angleMessage = command.mutable_ang_camera_angles();
        auto* rawAngles = reinterpret_cast<RawMsgVector*>(angleMessage);
        auto* rawCommand = reinterpret_cast<RawCitadelUserCmd*>(&command);

        rawAngles->x = angles.m_x;
        rawAngles->y = angles.m_y;
        rawAngles->z = angles.m_z;
        rawAngles->hasBits |= 0x7;
        rawCommand->hasBits |= 0x4;

        // Subtick mouse deltas would otherwise be applied after the absolute
        // angle and introduce a left/right-dependent error on the server.
        command.clear_view_delta_x();
        command.clear_view_delta_y();
        return true;
    }
}

// ============================================================================
// HOOK PRINCIPAL
// ============================================================================
auto Hook_CreateMove(CCitadelInput* pCitadelInput, uint32_t split_screen_index, char a3) -> void
{
    // ------------------------------------------------------------------
    // Read local state and select the target before command creation.
    // ------------------------------------------------------------------
    CCitadelPlayerController* pLocalController = GetCL_CitadelPlayerController()->GetLocal();
    C_CitadelPlayerPawn* pLocalPawn = pLocalController ? pLocalController->m_hHeroPawn().Get<C_CitadelPlayerPawn>() : nullptr;

    Vector3 finalTargetPos;
    C_BaseEntity* pFinalTargetEntity = nullptr;
    int finalTargetEntityIndex = -1;
    int finalHeroControllerIndex = -1;
    bool bHaveTarget = false;
    bool bHitChanceEvaluated = false;
    bool bHitChancePassed = false;
    bool bAutoSoulShot = false;

    if ((Settings::AimPreview::Active || Settings::AimPreview::SoulSteal) && pLocalController && pCitadelInput)
    {
        Vector3 cameraPos;
        if (pLocalPawn) cameraPos = pLocalPawn->GetEyeOrigin();

        auto* pPreviousCmd = pCitadelInput->GetUserCmd(pLocalController);
        if (pPreviousCmd && pPreviousCmd->cmd.has_vec_camera_position())
        {
            const auto& PreviousCamera = pPreviousCmd->cmd.vec_camera_position();
            const Vector3 PreviousCameraPos(PreviousCamera.x(), PreviousCamera.y(), PreviousCamera.z());
            if (!PreviousCameraPos.IsZero()) cameraPos = PreviousCameraPos;
        }

        AimTargetResult_t BestTarget;
        SoulTargetResult_t BestSoul;
        const Vector3 SoulShotOrigin = pLocalPawn ? pLocalPawn->GetEyeOrigin() : Vector3{};
        const int SelectedAimBoneIndex = GetAutomaticSelectedAimBoneIndex();
        const bool bHaveSoulTarget = Settings::AimPreview::SoulSteal && FindBestSoulTarget(SoulShotOrigin, BestSoul);
        const bool bHaveAimTarget = !bHaveSoulTarget && Settings::AimPreview::Active
            && FindBestAimTargetWithFallback(pLocalController, g_AimTargetBones[SelectedAimBoneIndex], BestTarget);

        if (bHaveAimTarget)
        {
            const int EffectiveBoneIndex = BestTarget.m_BoneIndex >= 0
                ? BestTarget.m_BoneIndex
                : SelectedAimBoneIndex;
            ApplyAutomaticBoneTransition(BestTarget, EffectiveBoneIndex);
        }

        GetVisual()->UpdateAimPreviewTarget(bHaveSoulTarget, BestSoul.m_ScreenPosition, bHaveAimTarget, BestTarget.m_ScreenPosition);

        if (!cameraPos.IsZero() && (bHaveSoulTarget || bHaveAimTarget))
        {
            if (bHaveSoulTarget)
            {
                finalTargetPos = BestSoul.m_WorldPosition;
                pFinalTargetEntity = BestSoul.m_TargetEntity;
                finalTargetEntityIndex = BestSoul.m_EntityIndex;
                bAutoSoulShot = true;
                bHitChanceEvaluated = true;
                bHitChancePassed = true;
            }
            else
            {
                finalTargetPos = BestTarget.m_WorldPosition;
                pFinalTargetEntity = BestTarget.m_TargetEntity;
                finalTargetEntityIndex = BestTarget.m_EntityIndex;
                finalHeroControllerIndex = BestTarget.m_HeroControllerIndex;
            }
            bHaveTarget = true;

            const bool bAttackHeld = bAutoSoulShot || (GetAsyncKeyState(VK_LBUTTON) & 0x8000) || HasPrimaryAttackInCommand(pPreviousCmd);

            if (bAttackHeld && !bAutoSoulShot)
            {
                bHitChanceEvaluated = true;
                bHitChancePassed = PassHitChance();
            }

        }
    }

    // ------------------------------------------------------------------
    // Let the game create the command with the player's native shot direction.
    // ------------------------------------------------------------------
    CreateMove_o(pCitadelInput, split_screen_index, a3);

    // ------------------------------------------------------------------
    // Read the command generated by the game.
    // ------------------------------------------------------------------
    CUserCmd* pUserCmd = (pLocalController && pCitadelInput) ? pCitadelInput->GetUserCmd(pLocalController) : nullptr;

    // ------------------------------------------------------------------
    // 4. AUTO-SOUL
    // ------------------------------------------------------------------
    if (bAutoSoulShot) ForcePrimaryAttack(pUserCmd);

    // ------------------------------------------------------------------
    // Auto parry.
    // ------------------------------------------------------------------
    static bool bHighMeleePending = false;
    static ULONGLONG HighMeleeObservedAt = 0;
    static bool bParryThreatDecisionActive = false;
    static uintptr_t EvaluatedParryThreatToken = 0;
    static int EvaluatedParryThreatType = 0;
    static int EvaluatedParryChance = -1;
    static bool bParryChancePassed = false;
    AutoParryThreat_t ParryThreat;
    if (FindAutoParryThreat(pLocalController, pLocalPawn, ParryThreat))
    {
        const int CurrentParryChance = std::clamp(Settings::AimPreview::AutoParryChance, 0, 100);
        const bool bNewThreat = !bParryThreatDecisionActive || EvaluatedParryThreatToken != ParryThreat.m_Token || EvaluatedParryThreatType != ParryThreat.m_TypeBit || EvaluatedParryChance != CurrentParryChance;
        if (bNewThreat)
        {
            bParryThreatDecisionActive = true;
            EvaluatedParryThreatToken = ParryThreat.m_Token;
            EvaluatedParryThreatType = ParryThreat.m_TypeBit;
            EvaluatedParryChance = CurrentParryChance;
            bParryChancePassed = PassAutoParryChance();
            bHighMeleePending = false;
            HighMeleeObservedAt = 0;
        }
        if (bParryChancePassed)
        {
            bool bDelayElapsed = true;
            if (ParryThreat.m_TypeBit == AUTO_PARRY_HIGH_MELEE)
            {
                const ULONGLONG Now = GetTickCount64();
                if (!bHighMeleePending) { bHighMeleePending = true; HighMeleeObservedAt = Now; }
                constexpr ULONGLONG HighMeleeParryDelayMs = 70;
                bDelayElapsed = (Now - HighMeleeObservedAt >= HighMeleeParryDelayMs);
            }
            else
            {
                bHighMeleePending = false;
                HighMeleeObservedAt = 0;
            }
            if (bDelayElapsed) ForceCommandButton(pUserCmd, static_cast<uint64_t>(IN_ABILITY_HELD));
        }
        else { bHighMeleePending = false; HighMeleeObservedAt = 0; }
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

    // ------------------------------------------------------------------
    // Detect the effective firing command.
    // ------------------------------------------------------------------
    const bool bActualAttack = HasPrimaryAttackInCommand(pUserCmd);
    if (bHaveTarget && bActualAttack && !bHitChanceEvaluated)
    {
        bHitChanceEvaluated = true;
        bHitChancePassed = PassHitChance();
    }

    // Preserve the native command angle and redirect the ray by translating its
    // origin. The target selection FOV remains independent from this world-space
    // offset and failed validations leave the original command untouched.
    if (bHaveTarget && pUserCmd && bActualAttack && bHitChancePassed
        && pUserCmd->cmd.has_base() && pUserCmd->cmd.has_vec_camera_position())
    {
        const auto& camera = pUserCmd->cmd.vec_camera_position();
        const Vector3 originalCameraPosition(camera.x(), camera.y(), camera.z());
        const auto& baseCommand = pUserCmd->cmd.base();

        auto* pTargetEntity = pFinalTargetEntity;

        QAngle commandAngles;
        QAngle cameraAngles;
        bool bHaveCommandAngles = false;
        bool bHaveCameraAngles = false;
        // The camera-origin redirection must use the direction paired with that
        // origin. base.viewangles can contain a transient/subtick weapon angle;
        // logs showed discontinuities above 50 degrees while the camera stayed
        // stable, which translated the shot to the wrong side of the target.
        if (pUserCmd->cmd.has_ang_camera_angles())
        {
            const auto& messageAngles = pUserCmd->cmd.ang_camera_angles();
            cameraAngles = QAngle(messageAngles.x(), messageAngles.y(), messageAngles.z());
            bHaveCameraAngles = true;
            commandAngles = cameraAngles;
            bHaveCommandAngles = true;
        }
        else if (baseCommand.has_viewangles())
        {
            const auto& messageAngles = baseCommand.viewangles();
            commandAngles = QAngle(messageAngles.x(), messageAngles.y(), messageAngles.z());
            bHaveCommandAngles = true;
        }

        if (!originalCameraPosition.IsZero() && pTargetEntity && bHaveCommandAngles)
        {
            commandAngles.Normalize();
            commandAngles.Clamp();

            Vector3 redirectedCameraPosition;
            if (CalculateRedirectedCameraPosition(originalCameraPosition, commandAngles,
                                                   finalTargetPos, redirectedCameraPosition))
            {
                const float targetDistance = (finalTargetPos - originalCameraPosition).Length();
                const float originShift = (redirectedCameraPosition - originalCameraPosition).Length();
                const float sineDeviation = targetDistance > 1e-3f
                    ? std::clamp(originShift / targetDistance, 0.f, 1.f)
                    : 1.f;
                const float deviationDegrees = RAD2DEG(std::asin(sineDeviation));

                // Translating the origin is reliable only near the crosshair.
                // Past this symmetric angular limit the required world-space
                // displacement becomes very large, can cross geometry, and is
                // treated asymmetrically by the game. Redirect the outgoing
                // camera angle instead; the local/control camera is untouched.
                constexpr float MaxOriginRedirectAngle = 4.5f;
                const bool bUseAngleRedirect = !Settings::AimPreview::LegitMode
                    && deviationDegrees > MaxOriginRedirectAngle
                    && pUserCmd->cmd.has_ang_camera_angles();

                const float maxLegitOriginShift = static_cast<float>(
                    std::clamp(Settings::AimPreview::LegitMaxOriginShift, 5, 100));
                const bool bOriginShiftAllowed = !Settings::AimPreview::LegitMode
                    || originShift <= maxLegitOriginShift;

                QAngle redirectedShotAngles = Math::CalcAngle(originalCameraPosition, finalTargetPos);
                redirectedShotAngles.Normalize();
                redirectedShotAngles.Clamp();

                const Vector3& traceOrigin = bUseAngleRedirect
                    ? originalCameraPosition
                    : redirectedCameraPosition;
                const bool bShotPathClear = bOriginShiftAllowed
                    && GetCL_Trace()->IsEntityVisibleAtPoint(
                        traceOrigin, finalTargetPos, pTargetEntity);

                const bool bWritten = bShotPathClear && (bUseAngleRedirect
                    ? WriteCameraAngles(pUserCmd->cmd, redirectedShotAngles)
                    : WriteCameraPosition(pUserCmd->cmd, redirectedCameraPosition));

                // The game serializes the hero under the crosshair separately
                // from the camera ray. Keep that metadata consistent with the
                // physical pawn used by the redirected shot.
                if (bWritten && finalHeroControllerIndex >= 0)
                    pUserCmd->cmd.set_enemy_hero_aimed_at(finalTargetEntityIndex);

                static ULONGLONG LastPsilentLog = 0;
                const ULONGLONG Now = GetTickCount64();
                if (Now - LastPsilentLog >= 250)
                {
                    LastPsilentLog = Now;
                    const auto& writtenCamera = pUserCmd->cmd.vec_camera_position();
                    DEV_LOG("[psilent] target=%d controller=%d aimed=%d mode=%s deviation=%.2f shift=%.2f allowed=%d clear=%d written=%d "
                            "shotAngle=(%.2f %.2f) cameraAngle=(%.2f %.2f) "
                            "redirectAngle=(%.2f %.2f) "
                            "bone=(%.2f %.2f %.2f) orig=(%.2f %.2f %.2f) "
                            "redirect=(%.2f %.2f %.2f) read=(%.2f %.2f %.2f)\n",
                            finalTargetEntityIndex, finalHeroControllerIndex,
                            pUserCmd->cmd.has_enemy_hero_aimed_at() ? pUserCmd->cmd.enemy_hero_aimed_at() : -1,
                            bUseAngleRedirect ? "angle" : "origin", deviationDegrees,
                            originShift, bOriginShiftAllowed ? 1 : 0,
                            bShotPathClear ? 1 : 0, bWritten ? 1 : 0,
                            commandAngles.m_x, commandAngles.m_y,
                            bHaveCameraAngles ? cameraAngles.m_x : 0.f,
                            bHaveCameraAngles ? cameraAngles.m_y : 0.f,
                            redirectedShotAngles.m_x, redirectedShotAngles.m_y,
                            finalTargetPos.m_x, finalTargetPos.m_y, finalTargetPos.m_z,
                            originalCameraPosition.m_x, originalCameraPosition.m_y, originalCameraPosition.m_z,
                            redirectedCameraPosition.m_x, redirectedCameraPosition.m_y, redirectedCameraPosition.m_z,
                            writtenCamera.x(), writtenCamera.y(), writtenCamera.z());
                }
            }
        }
    }

    if (pUserCmd) GetPericlesClient()->OnCreateMove(pCitadelInput, pUserCmd);
}
