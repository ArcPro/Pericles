#pragma once

#include <Common/Common.hpp>

class CUserCmd;
class Vector3;
class C_BaseEntity;

class CL_Trace final
{
public:
	auto TraceToBoneEntity( CUserCmd* CUserCmd ) -> std::pair<uint64_t , C_BaseEntity*>;
	auto TraceToEntityEndPos( const Vector3& CameraPos , Vector3* vEnd ) -> C_BaseEntity*;
	auto IsEntityVisibleAtPoint( const Vector3& CameraPos , const Vector3& EndPos , C_BaseEntity* pTargetEntity ) -> bool;
	auto PathCheck( const Vector3& CameraPos , Vector3& vTargetPos ) -> bool;
};

auto GetCL_Trace() -> CL_Trace*;
