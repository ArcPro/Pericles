#include "CPericlesGUI.hpp"
#include "DllLauncher.hpp"

#include <Common/Helpers/StringHelper.hpp>

#include <ShlObj_core.h>

#include <ImGui/imgui_impl_win32.h>
#include <ImGui/imgui_impl_dx11.h>
#include <lodepng/lodepng.h>

#include <DeadLock/SDK/SDK.hpp>
#include <DeadLock/SDK/Interface/IEngineToClient.hpp>
#include <DeadLock/SDK/SDL3/SDL3_Functions.hpp>
#include <DeadLock/Hook/Hook_IsRelativeMouseMode.hpp>

#include <PericlesClient/CPericlesClient.hpp>
#include <PericlesClient/Fonts/FontAwesomeIcon.hpp>
#include <PericlesClient/Settings/Settings.hpp>

static CPericlesGUI g_CPericlesGUI{};

IMGUI_IMPL_API LRESULT ImGui_ImplWin32_WndProcHandler( HWND hwnd, UINT msg, WPARAM wParam, LPARAM lParam );

auto CPericlesGUI::OnInit( IDXGISwapChain* pSwapChain ) -> void
{
	DXGI_SWAP_CHAIN_DESC SwapChainDesc{};

	if ( FAILED( pSwapChain->GetDevice( IID_PPV_ARGS( &m_pDevice ) ) ) )
	{
		DEV_LOG( "[error] CPericlesGUI::OnInit: #1\n" );
		return;
	}

	m_pDevice->GetImmediateContext( &m_pDeviceContext );

	if ( FAILED( pSwapChain->GetDesc( &SwapChainDesc ) ) )
	{
		DEV_LOG( "[error] CPericlesGUI::OnInit: #2\n" );
		return;
	}

	m_hDeadLockWindow = SwapChainDesc.OutputWindow;
	m_pImGuiContext = ImGui::CreateContext();
	m_GuiFile = GetDllDir() + XorStr( GUI_FILE );

	if ( !m_pFreeType_Font )
		m_pFreeType_Font = new FreeTypeBuild();

	ImGui::SetCurrentContext( m_pImGuiContext );
	ImGui::GetIO().IniFilename = m_GuiFile.c_str();
	ImGui::GetIO().LogFilename = "";
	ImGui::GetIO().ConfigFlags |= ImGuiConfigFlags_NoMouseCursorChange;
	ImGui::GetIO().BackendFlags |= ImGuiBackendFlags_HasSetMousePos;

	ImGui_ImplWin32_Init( m_hDeadLockWindow );
	ImGui_ImplDX11_Init( m_pDevice, m_pDeviceContext );

	InitLogoTexture();
	InitFont();
	InitPericlesStyle();

	m_WndProc_o = reinterpret_cast<WNDPROC>( SetWindowLongPtrA( m_hDeadLockWindow, GWLP_WNDPROC, reinterpret_cast<LONG_PTR>( GUI_WndProc ) ) );
	m_bInit = true;
}

auto CPericlesGUI::OnDestroy() -> void
{
	if ( m_bVisible )
	{
		m_bVisible = false;
		IsRelativeMouseMode_o( SDK::Interfaces::InputSystem() , m_bMainActive );
		GetSDL3Functions()->SetCursorVisible( !m_bMainActive );
	}

	if ( m_hDeadLockWindow && m_WndProc_o )
		SetWindowLongPtrA( m_hDeadLockWindow, GWLP_WNDPROC, reinterpret_cast<LONG_PTR>( m_WndProc_o ) );

	m_bVisible = false;

	if ( m_pFreeType_Font )
	{
		delete m_pFreeType_Font;
		m_pFreeType_Font = nullptr;
	}

	if ( m_pLogoTexture )
	{
		m_pLogoTexture->Release();
		m_pLogoTexture = nullptr;
	}

	ClearRenderTargetView();
	ImGui_ImplDX11_Shutdown();
	ImGui_ImplWin32_Shutdown();
	ImGui::DestroyContext();

	m_pImGuiContext = nullptr;
	m_bInit = false;
}

auto CPericlesGUI::InitFont() -> void
{
	ImGuiIO& Io = ImGui::GetIO();
	ImFontConfig BodyConfig{};
	ImFontConfig SectionConfig{};
	ImFontConfig TitleConfig{};
	ImFontConfig IconConfig{};

	BodyConfig.OversampleH = 3;
	BodyConfig.OversampleV = 2;
	SectionConfig.OversampleH = 3;
	TitleConfig.OversampleH = 3;

	static const ImWchar TextRanges[] = { 0x0020, 0x00FF, 0x0100, 0x024F, 0 };
	static const ImWchar IconRanges[] = { ICON_MIN_FA, ICON_MAX_FA, 0 };

	wchar_t* WindowsFontPath = nullptr;
	if ( SHGetKnownFolderPath( FOLDERID_Fonts, 0, nullptr, &WindowsFontPath ) == S_OK )
	{
		const std::wstring BodyFontPath = std::wstring( WindowsFontPath ) + L"\\segoeui.ttf";
		const std::wstring HeadingFontPath = std::wstring( WindowsFontPath ) + L"\\seguisb.ttf";
		const std::string BodyFont = unicode_to_utf8( BodyFontPath );
		const std::string HeadingFont = unicode_to_utf8( HeadingFontPath );

		m_pBodyFont = Io.Fonts->AddFontFromFileTTF( BodyFont.c_str(), 16.f, &BodyConfig, TextRanges );
		m_pSectionFont = Io.Fonts->AddFontFromFileTTF( HeadingFont.c_str(), 18.f, &SectionConfig, TextRanges );
		m_pTitleFont = Io.Fonts->AddFontFromFileTTF( HeadingFont.c_str(), 25.f, &TitleConfig, TextRanges );
	}

	m_pFontAwesomeIcons = Io.Fonts->AddFontFromMemoryCompressedTTF(
		FontAwesomeIcon_compressed_data,
		FontAwesomeIcon_compressed_size,
		20.f,
		&IconConfig,
		IconRanges );

	CoTaskMemFree( WindowsFontPath );
}

auto CPericlesGUI::InitLogoTexture() -> void
{
	if ( !m_pDevice || m_pLogoTexture )
		return;

	std::vector<unsigned char> Pixels;
	unsigned Width = 0;
	unsigned Height = 0;
	const std::string LogoPath = GetDllDir() + XorStr( "logo.png" );
	if ( lodepng::decode( Pixels, Width, Height, LogoPath ) != 0 || Pixels.empty() || Width == 0 || Height == 0 )
		return;

	D3D11_TEXTURE2D_DESC TextureDescription{};
	TextureDescription.Width = Width;
	TextureDescription.Height = Height;
	TextureDescription.MipLevels = 1;
	TextureDescription.ArraySize = 1;
	TextureDescription.Format = DXGI_FORMAT_R8G8B8A8_UNORM;
	TextureDescription.SampleDesc.Count = 1;
	TextureDescription.Usage = D3D11_USAGE_DEFAULT;
	TextureDescription.BindFlags = D3D11_BIND_SHADER_RESOURCE;

	D3D11_SUBRESOURCE_DATA TextureData{};
	TextureData.pSysMem = Pixels.data();
	TextureData.SysMemPitch = Width * 4;

	ID3D11Texture2D* Texture = nullptr;
	if ( FAILED( m_pDevice->CreateTexture2D( &TextureDescription, &TextureData, &Texture ) ) )
		return;

	D3D11_SHADER_RESOURCE_VIEW_DESC ResourceViewDescription{};
	ResourceViewDescription.Format = TextureDescription.Format;
	ResourceViewDescription.ViewDimension = D3D11_SRV_DIMENSION_TEXTURE2D;
	ResourceViewDescription.Texture2D.MipLevels = 1;
	m_pDevice->CreateShaderResourceView( Texture, &ResourceViewDescription, &m_pLogoTexture );
	Texture->Release();
}

auto CPericlesGUI::InitPericlesStyle() -> void
{
	ImGuiStyle& Style = ImGui::GetStyle();
	Style.Alpha = 1.f;
	Style.DisabledAlpha = 0.55f;
	Style.WindowPadding = ImVec2( 12.f, 12.f );
	Style.WindowRounding = 5.f;
	Style.WindowBorderSize = 1.f;
	Style.WindowMinSize = ImVec2( 140.f, 100.f );
	Style.WindowTitleAlign = ImVec2( 0.f, 0.5f );
	Style.ChildRounding = 3.f;
	Style.ChildBorderSize = 0.f;
	Style.PopupRounding = 4.f;
	Style.PopupBorderSize = 1.f;
	Style.FramePadding = ImVec2( 10.f, 7.f );
	Style.FrameRounding = 3.f;
	Style.FrameBorderSize = 1.f;
	Style.ItemSpacing = ImVec2( 10.f, 8.f );
	Style.ItemInnerSpacing = ImVec2( 8.f, 6.f );
	Style.CellPadding = ImVec2( 10.f, 6.f );
	Style.IndentSpacing = 20.f;
	Style.ScrollbarSize = 10.f;
	Style.ScrollbarRounding = 3.f;
	Style.GrabMinSize = 10.f;
	Style.GrabRounding = 3.f;
	Style.TabRounding = 3.f;
	Style.ButtonTextAlign = ImVec2( 0.5f, 0.5f );
	Style.SelectableTextAlign = ImVec2( 0.f, 0.5f );

	auto& Colors = Style.Colors;
	Colors[ImGuiCol_Text] = ImVec4( 0.91f, 0.90f, 0.94f, 1.f );
	Colors[ImGuiCol_TextDisabled] = ImVec4( 0.53f, 0.51f, 0.58f, 1.f );
	Colors[ImGuiCol_WindowBg] = ImVec4( 0.032f, 0.034f, 0.050f, 0.99f );
	Colors[ImGuiCol_ChildBg] = ImVec4( 0.039f, 0.040f, 0.058f, 0.99f );
	Colors[ImGuiCol_PopupBg] = ImVec4( 0.050f, 0.047f, 0.070f, 0.995f );
	Colors[ImGuiCol_Border] = ImVec4( 0.16f, 0.14f, 0.20f, 0.95f );
	Colors[ImGuiCol_BorderShadow] = ImVec4( 0.f, 0.f, 0.f, 0.f );
	Colors[ImGuiCol_FrameBg] = ImVec4( 0.064f, 0.060f, 0.086f, 1.f );
	Colors[ImGuiCol_FrameBgHovered] = ImVec4( 0.105f, 0.080f, 0.145f, 1.f );
	Colors[ImGuiCol_FrameBgActive] = ImVec4( 0.145f, 0.085f, 0.205f, 1.f );
	Colors[ImGuiCol_TitleBg] = ImVec4( 0.032f, 0.034f, 0.050f, 1.f );
	Colors[ImGuiCol_TitleBgActive] = ImVec4( 0.045f, 0.040f, 0.064f, 1.f );
	Colors[ImGuiCol_TitleBgCollapsed] = ImVec4( 0.025f, 0.024f, 0.036f, 0.94f );
	Colors[ImGuiCol_MenuBarBg] = ImVec4( 0.032f, 0.034f, 0.050f, 1.f );
	Colors[ImGuiCol_ScrollbarBg] = ImVec4( 0.020f, 0.020f, 0.030f, 0.72f );
	Colors[ImGuiCol_ScrollbarGrab] = ImVec4( 0.25f, 0.17f, 0.32f, 0.90f );
	Colors[ImGuiCol_ScrollbarGrabHovered] = ImVec4( 0.48f, 0.19f, 0.68f, 1.f );
	Colors[ImGuiCol_ScrollbarGrabActive] = ImVec4( 0.66f, 0.22f, 0.94f, 1.f );
	Colors[ImGuiCol_CheckMark] = ImVec4( 0.68f, 0.22f, 1.f, 1.f );
	Colors[ImGuiCol_SliderGrab] = ImVec4( 0.61f, 0.18f, 0.92f, 1.f );
	Colors[ImGuiCol_SliderGrabActive] = ImVec4( 0.77f, 0.36f, 1.f, 1.f );
	Colors[ImGuiCol_Button] = ImVec4( 0.075f, 0.071f, 0.10f, 1.f );
	Colors[ImGuiCol_ButtonHovered] = ImVec4( 0.14f, 0.095f, 0.19f, 1.f );
	Colors[ImGuiCol_ButtonActive] = ImVec4( 0.50f, 0.12f, 0.75f, 1.f );
	Colors[ImGuiCol_Header] = ImVec4( 0.47f, 0.12f, 0.72f, 0.62f );
	Colors[ImGuiCol_HeaderHovered] = ImVec4( 0.61f, 0.18f, 0.90f, 0.76f );
	Colors[ImGuiCol_HeaderActive] = ImVec4( 0.68f, 0.22f, 1.f, 0.90f );
	Colors[ImGuiCol_Separator] = ImVec4( 0.15f, 0.14f, 0.19f, 0.92f );
	Colors[ImGuiCol_SeparatorHovered] = ImVec4( 0.61f, 0.18f, 0.92f, 0.88f );
	Colors[ImGuiCol_SeparatorActive] = ImVec4( 0.70f, 0.25f, 1.f, 1.f );
	Colors[ImGuiCol_ResizeGrip] = ImVec4( 0.50f, 0.14f, 0.74f, 0.25f );
	Colors[ImGuiCol_ResizeGripHovered] = ImVec4( 0.63f, 0.20f, 0.94f, 0.70f );
	Colors[ImGuiCol_ResizeGripActive] = ImVec4( 0.72f, 0.27f, 1.f, 0.94f );
	Colors[ImGuiCol_Tab] = ImVec4( 0.060f, 0.055f, 0.080f, 1.f );
	Colors[ImGuiCol_TabHovered] = ImVec4( 0.51f, 0.14f, 0.77f, 0.82f );
	Colors[ImGuiCol_TabActive] = ImVec4( 0.43f, 0.10f, 0.67f, 1.f );
	Colors[ImGuiCol_TabUnfocused] = ImVec4( 0.040f, 0.038f, 0.055f, 1.f );
	Colors[ImGuiCol_TabUnfocusedActive] = ImVec4( 0.19f, 0.09f, 0.27f, 1.f );
	Colors[ImGuiCol_TableHeaderBg] = ImVec4( 0.065f, 0.060f, 0.085f, 1.f );
	Colors[ImGuiCol_TableBorderStrong] = ImVec4( 0.18f, 0.15f, 0.22f, 0.96f );
	Colors[ImGuiCol_TableBorderLight] = ImVec4( 0.13f, 0.115f, 0.16f, 0.82f );
	Colors[ImGuiCol_TableRowBg] = ImVec4( 0.f, 0.f, 0.f, 0.f );
	Colors[ImGuiCol_TableRowBgAlt] = ImVec4( 1.f, 1.f, 1.f, 0.018f );
	Colors[ImGuiCol_TextSelectedBg] = ImVec4( 0.55f, 0.14f, 0.84f, 0.42f );
	Colors[ImGuiCol_DragDropTarget] = ImVec4( 0.70f, 0.28f, 1.f, 0.94f );
	Colors[ImGuiCol_NavHighlight] = ImVec4( 0.65f, 0.20f, 0.98f, 0.90f );
	Colors[ImGuiCol_NavWindowingHighlight] = ImVec4( 0.82f, 0.90f, 1.f, 0.74f );
	Colors[ImGuiCol_NavWindowingDimBg] = ImVec4( 0.02f, 0.03f, 0.05f, 0.72f );
	Colors[ImGuiCol_ModalWindowDimBg] = ImVec4( 0.015f, 0.02f, 0.03f, 0.78f );
}

auto CPericlesGUI::UpdateStyle() -> void
{
	ImGui::SetCurrentContext( m_pImGuiContext );
	InitPericlesStyle();
}

void CPericlesGUI::OnPresent( IDXGISwapChain* pSwapChain )
{
	if ( !m_bInit )
		OnInit( pSwapChain );
	else
		OnRender( pSwapChain );
}

void CPericlesGUI::OnResizeBuffers( IDXGISwapChain* pSwapChain )
{
	OnDestroy();
}

void CPericlesGUI::OnRender( IDXGISwapChain* pSwapChain )
{
	if ( m_pFreeType_Font && m_pFreeType_Font->PreNewFrame() )
	{
		ImGui_ImplDX11_InvalidateDeviceObjects();
		ImGui_ImplDX11_CreateDeviceObjects();
		return;
	}

	if ( !m_pRenderTargetView )
	{
		ID3D11Texture2D* pBackBuffer = nullptr;
		if ( FAILED( pSwapChain->GetBuffer( 0, IID_PPV_ARGS( &pBackBuffer ) ) ) )
		{
			DEV_LOG( "[error] CPericlesGUI::OnRender: #1\n" );
			return;
		}

		D3D11_RENDER_TARGET_VIEW_DESC RenderTargetDesc{};
		RenderTargetDesc.Format = DXGI_FORMAT_R8G8B8A8_UNORM;
		RenderTargetDesc.ViewDimension = D3D11_RTV_DIMENSION_TEXTURE2DMS;
		m_pDevice->CreateRenderTargetView( pBackBuffer, &RenderTargetDesc, &m_pRenderTargetView );
		pBackBuffer->Release();
	}

	ImGui::SetCurrentContext( m_pImGuiContext );
	m_pDeviceContext->OMGetRenderTargets( 1, &m_pMainRenderTarget, nullptr );
	m_pDeviceContext->OMSetRenderTargets( 1, &m_pRenderTargetView, nullptr );

	ImGui_ImplDX11_NewFrame();
	ImGui_ImplWin32_NewFrame();
	ImGui::NewFrame();
	GetPericlesClient()->OnRender();
	ImGui::EndFrame();
	ImGui::Render();
	ImGui_ImplDX11_RenderDrawData( ImGui::GetDrawData() );

	m_pDeviceContext->OMSetRenderTargets( 1, &m_pMainRenderTarget, nullptr );
	if ( m_pMainRenderTarget )
	{
		m_pMainRenderTarget->Release();
		m_pMainRenderTarget = nullptr;
	}
}

auto CPericlesGUI::OnReopenGUI() -> void
{
	m_bVisible = !m_bVisible;
	ImGui::GetIO().MouseDrawCursor = m_bVisible;
	IsRelativeMouseMode_o( SDK::Interfaces::InputSystem(), m_bVisible ? false : m_bMainActive );
	GetSDL3Functions()->SetCursorVisible( !m_bVisible && !m_bMainActive );

	if ( m_bVisible )
	{
		if ( m_vecMousePosSave.x == 0.f && m_vecMousePosSave.y == 0.f )
			m_vecMousePosSave = ImGui::GetIO().DisplaySize / 2.f;

		ImGui::GetIO().MousePos = m_vecMousePosSave;
		if ( SDK::Interfaces::EngineToClient()->IsInGame() )
			GetSDL3Functions()->SDL_WarpMouseInWindow_o( nullptr, ImGui::GetIO().MousePos.x, ImGui::GetIO().MousePos.y );
	}
	else
	{
		m_vecMousePosSave = ImGui::GetIO().MousePos;
	}
}

auto CPericlesGUI::OnDisableCheat() -> void
{
	if ( !m_bCheatDisabledByDelete )
	{
		m_SavedCheatState.bPreviousGuiVisible = m_bVisible;
		m_SavedCheatState.AimActive = Settings::AimPreview::Active;
		m_SavedCheatState.SoulSteal = Settings::AimPreview::SoulSteal;
		m_SavedCheatState.AutoParry = Settings::AimPreview::AutoParry;

		m_SavedCheatState.VisualActive = Settings::Visual::Active;
		m_SavedCheatState.OnlyVisible = Settings::Visual::OnlyVisible;
		m_SavedCheatState.HeroTeam = Settings::Visual::HeroTeam;
		m_SavedCheatState.HeroEnemy = Settings::Visual::HeroEnemy;
		m_SavedCheatState.HeroBox = Settings::Visual::HeroBox;
		m_SavedCheatState.HeroSkeleton = Settings::Visual::HeroSkeleton;
		m_SavedCheatState.HeroHealth = Settings::Visual::HeroHealth;
		m_SavedCheatState.SoundStepEsp = Settings::Visual::SoundStepEsp;
		m_SavedCheatState.TrooperTeam = Settings::Visual::TrooperTeam;
		m_SavedCheatState.TrooperEnemy = Settings::Visual::TrooperEnemy;
		m_SavedCheatState.TrooperSkeleton = Settings::Visual::TrooperSkeleton;
		m_SavedCheatState.TrooperNeutral = Settings::Visual::TrooperNeutral;
		m_SavedCheatState.TrooperNeutralSkeleton = Settings::Visual::TrooperNeutralSkeleton;

		m_SavedCheatState.UnlockMiniMap = Settings::Misc::UnlockMiniMap;
		m_SavedCheatState.ShowCheatOverlay = Settings::Misc::ShowCheatOverlay;

		Settings::AimPreview::Active = false;
		Settings::AimPreview::SoulSteal = false;
		Settings::AimPreview::AutoParry = false;

		Settings::Visual::Active = false;
		Settings::Visual::OnlyVisible = false;
		Settings::Visual::HeroTeam = false;
		Settings::Visual::HeroEnemy = false;
		Settings::Visual::HeroBox = false;
		Settings::Visual::HeroSkeleton = false;
		Settings::Visual::HeroHealth = false;
		Settings::Visual::SoundStepEsp = false;
		Settings::Visual::TrooperTeam = false;
		Settings::Visual::TrooperEnemy = false;
		Settings::Visual::TrooperSkeleton = false;
		Settings::Visual::TrooperNeutral = false;
		Settings::Visual::TrooperNeutralSkeleton = false;

		Settings::Misc::UnlockMiniMap = false;
		Settings::Misc::ShowCheatOverlay = false;

		if ( m_bVisible )
			OnReopenGUI();

		m_bCheatDisabledByDelete = true;
	}
	else
	{
		Settings::AimPreview::Active = m_SavedCheatState.AimActive;
		Settings::AimPreview::SoulSteal = m_SavedCheatState.SoulSteal;
		Settings::AimPreview::AutoParry = m_SavedCheatState.AutoParry;

		Settings::Visual::Active = m_SavedCheatState.VisualActive;
		Settings::Visual::OnlyVisible = m_SavedCheatState.OnlyVisible;
		Settings::Visual::HeroTeam = m_SavedCheatState.HeroTeam;
		Settings::Visual::HeroEnemy = m_SavedCheatState.HeroEnemy;
		Settings::Visual::HeroBox = m_SavedCheatState.HeroBox;
		Settings::Visual::HeroSkeleton = m_SavedCheatState.HeroSkeleton;
		Settings::Visual::HeroHealth = m_SavedCheatState.HeroHealth;
		Settings::Visual::SoundStepEsp = m_SavedCheatState.SoundStepEsp;
		Settings::Visual::TrooperTeam = m_SavedCheatState.TrooperTeam;
		Settings::Visual::TrooperEnemy = m_SavedCheatState.TrooperEnemy;
		Settings::Visual::TrooperSkeleton = m_SavedCheatState.TrooperSkeleton;
		Settings::Visual::TrooperNeutral = m_SavedCheatState.TrooperNeutral;
		Settings::Visual::TrooperNeutralSkeleton = m_SavedCheatState.TrooperNeutralSkeleton;

		Settings::Misc::UnlockMiniMap = m_SavedCheatState.UnlockMiniMap;
		Settings::Misc::ShowCheatOverlay = m_SavedCheatState.ShowCheatOverlay;

		if ( m_SavedCheatState.bPreviousGuiVisible && !m_bVisible )
			OnReopenGUI();

		m_bCheatDisabledByDelete = false;
	}
}

LRESULT WINAPI CPericlesGUI::GUI_WndProc( HWND hwnd, UINT uMsg, WPARAM wParam, LPARAM lParam )
{
	if ( uMsg == WM_QUIT || uMsg == WM_CLOSE || uMsg == WM_DESTROY )
	{
		GetDllLauncher()->OnDestroy();
		return true;
	}

	if ( GetPericlesGUI()->m_bInit )
	{
		if ( uMsg == WM_KEYUP && wParam == VK_DELETE )
			GetPericlesGUI()->OnDisableCheat();
		else if ( uMsg == WM_KEYUP && wParam == VK_INSERT && !GetPericlesGUI()->m_bCheatDisabledByDelete )
			GetPericlesGUI()->OnReopenGUI();

		if ( GetPericlesGUI()->IsVisible() && ImGui_ImplWin32_WndProcHandler( hwnd, uMsg, wParam, lParam ) == 0 )
			return true;
	}

	return CallWindowProcA( GetPericlesGUI()->m_WndProc_o, hwnd, uMsg, wParam, lParam );
}

auto CPericlesGUI::FreeTypeBuild::PreNewFrame() -> bool
{
	if ( !WantRebuild )
		return false;

	ImFontAtlas* Atlas = ImGui::GetIO().Fonts;
	for ( int Index = 0; Index < Atlas->ConfigData.Size; ++Index )
		reinterpret_cast<ImFontConfig*>( &Atlas->ConfigData[Index] )->RasterizerMultiply = RasterizerMultiply;

#ifdef IMGUI_ENABLE_FREETYPE
	if ( BuildMode == FontBuildMode::FreeType )
	{
		Atlas->FontBuilderIO = ImGuiFreeType::GetBuilderForFreeType();
		Atlas->FontBuilderFlags = FreeTypeBuilderFlags;
	}
#endif

	Atlas->Build();
	WantRebuild = false;
	return true;
}

auto CPericlesGUI::ClearRenderTargetView() -> void
{
	if ( m_pRenderTargetView )
	{
		m_pRenderTargetView->Release();
		m_pRenderTargetView = nullptr;
	}
}

auto GetPericlesGUI() -> CPericlesGUI*
{
	return &g_CPericlesGUI;
}
