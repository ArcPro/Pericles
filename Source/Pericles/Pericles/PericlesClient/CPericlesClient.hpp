#pragma once

#include <Common/Common.hpp>

#include <atomic>

#include <DeadLock/SDK/Types/CEntityData.hpp>

class IGameEvent;
class CCitadelUserMessage_Damage;
class CCitadelInput;
class CUserCmd;

class IPericlesClient
{
public:
	virtual void OnFireEventClientSide( IGameEvent* pGameEvent ) = 0;
	virtual void OnAddEntity( CEntityInstance* pInst , CHandle handle ) = 0;
	virtual void OnRemoveEntity( CEntityInstance* pInst , CHandle handle ) = 0;
	virtual void OnStartSound( const Vector3& Pos , const int SourceEntityIndex , const char* szSoundName ) = 0;
	virtual void OnCreateMove( CCitadelInput* pCitadelInput , CUserCmd* pUserCmd ) = 0;
	virtual void OnClientOutput() = 0;

public:
	virtual void OnRender() = 0;
};

class CPericlesClient final : public IPericlesClient
{
public:
	auto OnInit() -> void;
	auto OnDamageMessage( const CCitadelUserMessage_Damage* pDamageMessage ) -> void;

public:
	virtual void OnFireEventClientSide( IGameEvent* pGameEvent ) override;
	virtual void OnAddEntity( CEntityInstance* pInst , CHandle handle ) override;
	virtual void OnRemoveEntity( CEntityInstance* pInst , CHandle handle ) override;
	virtual void OnStartSound( const Vector3& Pos , const int SourceEntityIndex , const char* szSoundName ) override;
	virtual void OnCreateMove( CCitadelInput* pCitadelInput , CUserCmd* pUserCmd ) override;
	virtual void OnClientOutput() override;

public:
	virtual void OnRender() override;

private:
	auto ResetHeadshotStats() -> void;
	auto GetHeadshotPercentage() const -> float;

private:
	std::atomic<uint64_t> m_PlayerBulletHits{ 0 };
	std::atomic<uint64_t> m_PlayerBulletHeadshots{ 0 };
	bool m_bWasInGame = false;
};

auto GetPericlesClient() -> CPericlesClient*;
