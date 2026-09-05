#pragma once

#include <Common/Common.hpp>
#include <ImGui/imgui.h>

class CPericlesMenu final
{
public:
	auto InitColors() -> void;
	auto OnRenderMenu() -> void;

	inline auto SetConfigSelected( uint32_t Index ) -> void
	{
		m_nConfigSelected = Index;
	}

private:
	enum class Page : uint8_t
	{
		Aimbot,
		Automation,
		VisualGeneral,
		HeroEsp,
		UnitEsp,
		Colors,
		Misc,
		Interface,
		Profiles
	};

	auto RenderSidebar() -> void;
	auto RenderContent() -> void;
	auto RenderFooter() -> void;

	auto RenderAimbotPage() -> void;
	auto RenderAutomationPage() -> void;
	auto RenderVisualGeneralPage() -> void;
	auto RenderHeroEspPage() -> void;
	auto RenderUnitEspPage() -> void;
	auto RenderColorsPage() -> void;
	auto RenderMiscPage() -> void;
	auto RenderInterfacePage() -> void;
	auto RenderProfilesPage() -> void;

	auto CategoryHeading( const char* Label ) -> void;
	auto NavigationItem( const char* Label, Page TargetPage ) -> bool;
	auto ToggleSwitch( const char* Id, bool& Value ) -> bool;
	auto ActionButton( const char* Label, const ImVec2& Size, bool Primary = false ) -> bool;

	auto BeginSettingsTable( const char* Id ) -> bool;
	auto EndSettingsTable() -> void;
	auto PageHeading( const char* Title, const char* Description ) -> void;
	auto SectionHeading( const char* Title ) -> void;
	auto ToggleRow( const char* Label, const char* Description, const char* Id, bool& Value ) -> void;
	auto SliderIntRow( const char* Label, const char* Description, const char* Id, int& Value, int Min, int Max, const char* Format ) -> void;
	auto SliderFloatRow( const char* Label, const char* Description, const char* Id, float& Value, float Min, float Max, const char* Format ) -> void;
	auto ComboRow( const char* Label, const char* Description, const char* Id, int& Value, const char* const* Items, int ItemCount ) -> void;
	auto MultiBoneComboRow( const char* Label, const char* Description, const char* Id, int& Mask ) -> void;
	auto MultiParryComboRow( const char* Label, const char* Description, const char* Id, int& Mask ) -> void;
	auto ColorRow( const char* Label, const char* Description, const char* Id, float* Color ) -> void;

	auto SaveSelectedProfile() -> void;
	auto ReloadSelectedProfile() -> void;
	auto PlayClick() -> void;
	auto PlayHover() -> void;

private:
	Page m_Page = Page::Aimbot;
	uint32_t m_nConfigSelected = 0;
	char m_szNewConfigFileName[32] = { 0 };
};

auto GetPericlesMenu() -> CPericlesMenu*;
