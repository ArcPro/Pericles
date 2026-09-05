#include "SDL3_Functions.hpp"

static CSDL3Functions g_CSDL3Functions{};

auto CSDL3Functions::OnInit() -> bool
{
	auto hSDL3Module = GetModuleHandleA( XorStr( "SDL3.dll" ) );

	if ( !hSDL3Module )
	{
		DEV_LOG( "[error] SDL3.dll Module\n" );
		return false;
	}

	SDL_WarpMouseInWindow_o = (SDL_WarpMouseInWindow_t)GetProcAddress( hSDL3Module , XorStr( "SDL_WarpMouseInWindow" ) );
	SDL_ShowCursor_o = (SDL_ShowCursor_t)GetProcAddress( hSDL3Module , XorStr( "SDL_ShowCursor" ) );
	SDL_HideCursor_o = (SDL_HideCursor_t)GetProcAddress( hSDL3Module , XorStr( "SDL_HideCursor" ) );
	SDL_CursorVisible_o = (SDL_CursorVisible_t)GetProcAddress( hSDL3Module , XorStr( "SDL_CursorVisible" ) );

	if ( !SDL_WarpMouseInWindow_o )
	{
		DEV_LOG( "[error] SDL3 SDL_WarpMouseInWindow\n" );
		return false;
	}

	return true;
}

auto CSDL3Functions::SetCursorVisible( bool Visible ) -> void
{
	// SDL owns the game's cursor. Avoid feeding Win32's ShowCursor counter on
	// every menu toggle: an unbalanced counter is what made the cursor vanish
	// intermittently in Panorama menus and the shop.
	if ( SDL_CursorVisible_o && SDL_ShowCursor_o && SDL_HideCursor_o )
	{
		if ( SDL_CursorVisible_o() != Visible )
		{
			if ( Visible )
				SDL_ShowCursor_o();
			else
				SDL_HideCursor_o();
		}

		if ( Visible )
			SetCursor( LoadCursorA( nullptr , IDC_ARROW ) );

		return;
	}

	// Fallback for an SDL build without the cursor exports. Normalize the
	// process-local Win32 display counter instead of treating ShowCursor as a
	// boolean setter.
	if ( Visible )
	{
		while ( ShowCursor( TRUE ) < 0 ) {}
		SetCursor( LoadCursorA( nullptr , IDC_ARROW ) );
	}
	else
	{
		while ( ShowCursor( FALSE ) >= 0 ) {}
	}
}

auto GetSDL3Functions() -> CSDL3Functions*
{
	return &g_CSDL3Functions;
}
