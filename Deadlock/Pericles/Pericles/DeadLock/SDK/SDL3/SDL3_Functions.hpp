#pragma once

#include <Common/Common.hpp>

class CSDL3Functions final
{
public:
	auto OnInit() -> bool;
	auto SetCursorVisible( bool Visible ) -> void;

public:
	using SDL_WarpMouseInWindow_t = int( __stdcall* )( void* , float , float );
	using SDL_ShowCursor_t = bool( __cdecl* )();
	using SDL_HideCursor_t = bool( __cdecl* )();
	using SDL_CursorVisible_t = bool( __cdecl* )();

public:
	SDL_WarpMouseInWindow_t SDL_WarpMouseInWindow_o = nullptr;
	SDL_ShowCursor_t SDL_ShowCursor_o = nullptr;
	SDL_HideCursor_t SDL_HideCursor_o = nullptr;
	SDL_CursorVisible_t SDL_CursorVisible_o = nullptr;
};

auto GetSDL3Functions() -> CSDL3Functions*;
