#pragma once

#include <Common/Common.hpp>
#include <d3d11.h>

#include <ImGui/imgui.h>
#include <ImGui/Misc/freetype/imgui_freetype.h>

class IPericlesGUI
{
public:
	virtual bool IsVisible() = 0;
	virtual bool IsInited() = 0;

	virtual void OnPresent( IDXGISwapChain* pSwapChain ) = 0;
	virtual void OnResizeBuffers( IDXGISwapChain* pSwapChain ) = 0;
	virtual void OnRender( IDXGISwapChain* pSwapChain ) = 0;
};

class CPericlesGUI final : public IPericlesGUI
{
public:
	auto OnInit( IDXGISwapChain* pSwapChain ) -> void;
	auto OnDestroy() -> void;
	auto InitFont() -> void;
	auto UpdateStyle() -> void;

	virtual bool IsVisible() override { return m_bVisible; }
	virtual bool IsInited() override { return m_bInit; }
	virtual void OnPresent( IDXGISwapChain* pSwapChain ) override;
	virtual void OnResizeBuffers( IDXGISwapChain* pSwapChain ) override;
	virtual void OnRender( IDXGISwapChain* pSwapChain ) override;

	auto OnReopenGUI() -> void;
	auto OnDisableCheat() -> void;
	static LRESULT WINAPI GUI_WndProc( HWND hwnd, UINT uMsg, WPARAM wParam, LPARAM lParam );

	auto GetDevice() -> ID3D11Device* { return m_pDevice; }
	auto GetDeviceContext() -> ID3D11DeviceContext* { return m_pDeviceContext; }
	auto GetLogoTexture() -> ID3D11ShaderResourceView* { return m_pLogoTexture; }
	auto ClearRenderTargetView() -> void;

private:
	auto InitPericlesStyle() -> void;
	auto InitLogoTexture() -> void;

private:
	ID3D11Device* m_pDevice = nullptr;
	ID3D11DeviceContext* m_pDeviceContext = nullptr;
	ID3D11RenderTargetView* m_pRenderTargetView = nullptr;
	ID3D11RenderTargetView* m_pMainRenderTarget = nullptr;
	ID3D11ShaderResourceView* m_pLogoTexture = nullptr;
	ImGuiContext* m_pImGuiContext = nullptr;
	std::string m_GuiFile;
	HWND m_hDeadLockWindow = nullptr;

public:
	WNDPROC m_WndProc_o = nullptr;
	bool m_bInit = false;
	bool m_bVisible = false;
	bool m_bMainActive = false;

private:
	ImVec2 m_vecMousePosSave;

	struct CheatState
	{
		bool bPreviousGuiVisible = false;

		bool AimActive = false;
		bool SoulSteal = false;
		bool AutoParry = false;

		bool VisualActive = false;
		bool OnlyVisible = false;
		bool HeroTeam = false;
		bool HeroEnemy = false;
		bool HeroBox = false;
		bool HeroSkeleton = false;
		bool HeroHealth = false;
		bool SoundStepEsp = false;
		bool TrooperTeam = false;
		bool TrooperEnemy = false;
		bool TrooperSkeleton = false;
		bool TrooperNeutral = false;
		bool TrooperNeutralSkeleton = false;

		bool UnlockMiniMap = false;
		bool ShowCheatOverlay = false;
	};

	CheatState m_SavedCheatState;
	bool m_bCheatDisabledByDelete = false;

public:
	ImFont* m_pBodyFont = nullptr;
	ImFont* m_pSectionFont = nullptr;
	ImFont* m_pTitleFont = nullptr;
	ImFont* m_pFontAwesomeIcons = nullptr;

private:
	struct FreeTypeBuild
	{
		enum class FontBuildMode { FreeType };
		FontBuildMode BuildMode = FontBuildMode::FreeType;
		bool WantRebuild = true;
		float RasterizerMultiply = 1.0f;
		unsigned int FreeTypeBuilderFlags = ImGuiFreeTypeBuilderFlags_ForceAutoHint | ImGuiFreeTypeBuilderFlags_MonoHinting;

		bool PreNewFrame();
		void ResetBuildFont() { WantRebuild = true; }
	};

	FreeTypeBuild* m_pFreeType_Font = nullptr;
};

auto GetPericlesGUI() -> CPericlesGUI*;
