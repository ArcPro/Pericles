#include "CPericlesMenu.hpp"

#include <algorithm>
#include <string>

#include <PericlesClient/CPericlesGUI.hpp>
#include <PericlesClient/Settings/Settings.hpp>
#include <PericlesClient/Settings/CSettingsJson.hpp>
#include <PericlesClient/Helpers/CPlayUISound.hpp>
#include <PericlesClient/Fonts/FontAwesomeIcon.hpp>

static CPericlesMenu g_CPericlesMenu{};

namespace
{
	constexpr ImVec2 MainWindowSize( 1120.f, 720.f );
	constexpr float HeaderHeight = 76.f;
	constexpr float FooterHeight = 64.f;
	constexpr float SidebarWidth = 230.f;

	constexpr ImU32 AccentColor = IM_COL32( 166, 48, 255, 255 );
	constexpr ImU32 AccentHoverColor = IM_COL32( 194, 86, 255, 255 );
	constexpr ImU32 AccentSoftColor = IM_COL32( 125, 32, 198, 82 );
	constexpr ImU32 SidebarColor = IM_COL32( 8, 8, 13, 252 );
	constexpr ImU32 HeaderColor = IM_COL32( 9, 9, 15, 252 );
	constexpr ImU32 HeaderLineColor = IM_COL32( 43, 39, 52, 235 );
	constexpr ImU32 CardColor = IM_COL32( 13, 13, 20, 252 );
	constexpr ImU32 CardBorderColor = IM_COL32( 43, 38, 52, 245 );
	constexpr ImU32 MutedTextColor = IM_COL32( 139, 135, 150, 255 );
}

auto CPericlesMenu::InitColors() -> void
{
	// The color editor reads the settings arrays directly; no mirrored list is
	// required anymore.
}

auto CPericlesMenu::OnRenderMenu() -> void
{
	const float MenuAlpha = static_cast<float>( Settings::Misc::MenuAlpha ) / 255.f;
	const ImGuiWindowFlags WindowFlags = ImGuiWindowFlags_NoTitleBar
		| ImGuiWindowFlags_NoResize
		| ImGuiWindowFlags_NoCollapse
		| ImGuiWindowFlags_NoScrollbar
		| ImGuiWindowFlags_NoScrollWithMouse;
	const ImVec2 DisplaySize = ImGui::GetIO().DisplaySize;
	ImGui::GetBackgroundDrawList()->AddRectFilled( ImVec2( 0.f, 0.f ), DisplaySize, IM_COL32( 3, 2, 7, 168 ) );

	ImGui::SetNextWindowSize( MainWindowSize, ImGuiCond_Always );
	ImGui::SetNextWindowPos( ImGui::GetMainViewport()->GetCenter(), ImGuiCond_FirstUseEver, ImVec2( 0.5f, 0.5f ) );
	ImGui::PushStyleVar( ImGuiStyleVar_Alpha, MenuAlpha );
	ImGui::PushStyleVar( ImGuiStyleVar_WindowPadding, ImVec2( 0.f, 0.f ) );

	if ( ImGui::Begin( XorStr( "Pericles##PericlesMainMenu" ), nullptr, WindowFlags ) )
	{
		auto* DrawList = ImGui::GetWindowDrawList();
		const ImVec2 WindowPos = ImGui::GetWindowPos();
		const ImVec2 WindowSize = ImGui::GetWindowSize();
		DrawList->AddRectFilled( WindowPos, ImVec2( WindowPos.x + WindowSize.x, WindowPos.y + HeaderHeight ), HeaderColor, 5.f, ImDrawFlags_RoundCornersTop );

		DrawList->AddLine(
			ImVec2( WindowPos.x, WindowPos.y + HeaderHeight ),
			ImVec2( WindowPos.x + WindowSize.x, WindowPos.y + HeaderHeight ),
			HeaderLineColor );

		if ( GetPericlesGUI()->GetLogoTexture() )
		{
			DrawList->AddImageRounded(
				reinterpret_cast<ImTextureID>( GetPericlesGUI()->GetLogoTexture() ),
				ImVec2( WindowPos.x + 14.f, WindowPos.y + 12.f ),
				ImVec2( WindowPos.x + 66.f, WindowPos.y + 64.f ),
				ImVec2( 0.f, 0.f ), ImVec2( 1.f, 1.f ), IM_COL32_WHITE, 3.f );
		}

		ImGui::SetCursorPos( ImVec2( 75.f, 13.f ) );
		if ( GetPericlesGUI()->m_pTitleFont )
			ImGui::PushFont( GetPericlesGUI()->m_pTitleFont );
		ImGui::TextUnformatted( XorStr( "PERICLES" ) );
		if ( GetPericlesGUI()->m_pTitleFont )
			ImGui::PopFont();

		ImGui::SetCursorPos( ImVec2( 77.f, 43.f ) );
		ImGui::PushStyleColor( ImGuiCol_Text, ImVec4( 0.51f, 0.49f, 0.56f, 1.f ) );
		ImGui::TextUnformatted( XorStr( "CONFIGURATION PANEL" ) );
		ImGui::PopStyleColor();

		DrawList->AddRectFilled(
			ImVec2( WindowPos.x + 306.f, WindowPos.y + 22.f ),
			ImVec2( WindowPos.x + 392.f, WindowPos.y + 54.f ),
			IM_COL32( 75, 26, 108, 92 ), 3.f );
		DrawList->AddRect(
			ImVec2( WindowPos.x + 306.f, WindowPos.y + 22.f ),
			ImVec2( WindowPos.x + 392.f, WindowPos.y + 54.f ),
			IM_COL32( 106, 43, 143, 190 ), 3.f );
		DrawList->AddCircleFilled( ImVec2( WindowPos.x + 321.f, WindowPos.y + 38.f ), 4.f, AccentHoverColor );
		ImGui::SetCursorPos( ImVec2( 331.f, 30.f ) );
		ImGui::PushStyleColor( ImGuiCol_Text, ImGui::ColorConvertU32ToFloat4( AccentHoverColor ) );
		ImGui::TextUnformatted( XorStr( "ACTIVE" ) );
		ImGui::PopStyleColor();

		ImGui::SetCursorPos( ImVec2( WindowSize.x - 54.f, 20.f ) );
		ImGui::PushStyleColor( ImGuiCol_Button, ImVec4( 0.f, 0.f, 0.f, 0.f ) );
		ImGui::PushStyleColor( ImGuiCol_ButtonHovered, ImVec4( 0.22f, 0.11f, 0.29f, 0.95f ) );
		ImGui::PushStyleColor( ImGuiCol_ButtonActive, ImVec4( 0.46f, 0.12f, 0.68f, 1.f ) );
		if ( ImGui::Button( XorStr( "X##ClosePericles" ), ImVec2( 34.f, 34.f ) ) )
			GetPericlesGUI()->OnReopenGUI();
		ImGui::PopStyleColor( 3 );

		ImGui::SetCursorPos( ImVec2( 0.f, HeaderHeight ) );
		ImGui::BeginChild( XorStr( "##PericlesBody" ), ImVec2( WindowSize.x, WindowSize.y - HeaderHeight - FooterHeight ), false, ImGuiWindowFlags_NoScrollbar );
		RenderSidebar();
		ImGui::SameLine( 0.f, 0.f );
		RenderContent();
		ImGui::EndChild();

		ImGui::SetCursorPos( ImVec2( 0.f, WindowSize.y - FooterHeight ) );
		RenderFooter();
	}

	ImGui::End();
	ImGui::PopStyleVar( 2 );

	if ( ImGui::IsMouseClicked( ImGuiMouseButton_Left ) )
		PlayClick();
}

auto CPericlesMenu::RenderSidebar() -> void
{
	ImGui::PushStyleColor( ImGuiCol_ChildBg, ImGui::ColorConvertU32ToFloat4( SidebarColor ) );
	ImGui::BeginChild( XorStr( "##PericlesSidebar" ), ImVec2( SidebarWidth, 0.f ), false, ImGuiWindowFlags_NoScrollbar );
	const ImVec2 SidebarPosition = ImGui::GetWindowPos();
	ImGui::GetWindowDrawList()->AddLine(
		ImVec2( SidebarPosition.x + SidebarWidth - 1.f, SidebarPosition.y ),
		ImVec2( SidebarPosition.x + SidebarWidth - 1.f, SidebarPosition.y + ImGui::GetWindowHeight() ),
		HeaderLineColor );
	ImGui::SetCursorPos( ImVec2( 18.f, 24.f ) );
	ImGui::BeginGroup();

	CategoryHeading( XorStr( "Combat" ) );
	NavigationItem( XorStr( "Aim" ), Page::Aimbot );
	NavigationItem( XorStr( "Automation" ), Page::Automation );

	CategoryHeading( XorStr( "Visuals" ) );
	NavigationItem( XorStr( "General" ), Page::VisualGeneral );
	NavigationItem( XorStr( "Hero ESSP" ), Page::HeroEsp );
	NavigationItem( XorStr( "Unit ESP" ), Page::UnitEsp );
	NavigationItem( XorStr( "Colors" ), Page::Colors );

	CategoryHeading( XorStr( "Other" ) );
	NavigationItem( XorStr( "Game" ), Page::Misc );
	NavigationItem( XorStr( "Interface" ), Page::Interface );
	NavigationItem( XorStr( "Profiles" ), Page::Profiles );

	ImGui::EndGroup();
	ImGui::SetCursorPos( ImVec2( 20.f, ImGui::GetWindowHeight() - 54.f ) );
	ImGui::PushStyleColor( ImGuiCol_Text, ImVec4( 0.43f, 0.41f, 0.47f, 1.f ) );
	ImGui::Text( XorStr( "Version %s" ), CHEAT_VERSION );
	ImGui::PopStyleColor();
	ImGui::EndChild();
	ImGui::PopStyleColor();
}

auto CPericlesMenu::RenderContent() -> void
{
	ImGui::PushStyleColor( ImGuiCol_ChildBg, ImVec4( 0.034f, 0.034f, 0.049f, 1.f ) );
	ImGui::BeginChild( XorStr( "##PericlesContent" ), ImVec2( 0.f, 0.f ), false, ImGuiWindowFlags_NoScrollbar );
	ImGui::SetCursorPos( ImVec2( 30.f, 24.f ) );
	ImGui::BeginChild( XorStr( "##PericlesContentScroll" ), ImVec2( -30.f, -20.f ), false );

	switch ( m_Page )
	{
		case Page::Aimbot: RenderAimbotPage(); break;
		case Page::Automation: RenderAutomationPage(); break;
		case Page::VisualGeneral: RenderVisualGeneralPage(); break;
		case Page::HeroEsp: RenderHeroEspPage(); break;
		case Page::UnitEsp: RenderUnitEspPage(); break;
		case Page::Colors: RenderColorsPage(); break;
		case Page::Misc: RenderMiscPage(); break;
		case Page::Interface: RenderInterfacePage(); break;
		case Page::Profiles: RenderProfilesPage(); break;
	}

	ImGui::EndChild();
	ImGui::EndChild();
	ImGui::PopStyleColor();
}

auto CPericlesMenu::RenderFooter() -> void
{
	const ImVec2 FooterPos = ImGui::GetCursorScreenPos();
	const float FooterWidth = ImGui::GetWindowWidth();
	ImGui::GetWindowDrawList()->AddRectFilled(
		FooterPos,
		ImVec2( FooterPos.x + FooterWidth, FooterPos.y + FooterHeight ),
		HeaderColor, 5.f, ImDrawFlags_RoundCornersBottom );
	ImGui::GetWindowDrawList()->AddLine( FooterPos, ImVec2( FooterPos.x + FooterWidth, FooterPos.y ), HeaderLineColor );
	ImGui::SetCursorPos( ImVec2( 24.f, ImGui::GetWindowHeight() - FooterHeight + 14.f ) );
	ImGui::PushStyleColor( ImGuiCol_Text, ImVec4( 0.50f, 0.47f, 0.55f, 1.f ) );
	ImGui::TextUnformatted( XorStr( "ACTIVE PROFILE" ) );
	ImGui::PopStyleColor();
	ImGui::SameLine();
	ImGui::PushStyleColor( ImGuiCol_Text, ImGui::ColorConvertU32ToFloat4( AccentHoverColor ) );
	ImGui::TextUnformatted( XorStr( "CURRENT CONFIGURATION" ) );
	ImGui::PopStyleColor();

	ImGui::SetCursorPos( ImVec2( FooterWidth - 442.f, ImGui::GetWindowHeight() - FooterHeight + 13.f ) );
	if ( ActionButton( XorStr( "Apply" ), ImVec2( 132.f, 38.f ), true ) )
		SaveSelectedProfile();
	ImGui::SameLine();
	if ( ActionButton( XorStr( "Reload" ), ImVec2( 132.f, 38.f ) ) )
		ReloadSelectedProfile();
	ImGui::SameLine();
	if ( ActionButton( XorStr( "Close" ), ImVec2( 132.f, 38.f ) ) )
		GetPericlesGUI()->OnReopenGUI();
}

auto CPericlesMenu::RenderAimbotPage() -> void
{
	PageHeading( XorStr( "AIM" ), XorStr( "Target selection and field-of-view behavior." ) );

	SectionHeading( XorStr( "Activation" ) );
	if ( BeginSettingsTable( XorStr( "##AimActivationTable" ) ) )
	{
		ToggleRow( XorStr( "Enable aim" ), XorStr( "Enables target selection within the FOV." ), XorStr( "##AimActive" ), Settings::AimPreview::Active );
		ToggleRow( XorStr( "Legit mode" ), XorStr( "Uses camera-origin redirection only and limits the targeting radius to 75 px." ), XorStr( "##AimLegitMode" ), Settings::AimPreview::LegitMode );
		ToggleRow( XorStr( "Show FOV circle" ), XorStr( "Draws the selection area at the center of the screen." ), XorStr( "##AimShowFov" ), Settings::AimPreview::ShowFovCircle );
		ToggleRow( XorStr( "Visible targets only" ), XorStr( "Ignores targets when the selected point is obstructed." ), XorStr( "##AimVisibleOnly" ), Settings::AimPreview::OnlyVisible );
		EndSettingsTable();
	}

	SectionHeading( XorStr( "Targeting" ) );
	if ( BeginSettingsTable( XorStr( "##AimTargetTable" ) ) )
	{
		MultiBoneComboRow( XorStr( "Target bones" ), XorStr( "Select multiple bones; head probability is configurable and the remainder is shared between other bones." ), XorStr( "##AimTargetBones" ), Settings::AimPreview::TargetBonesMask );
		if ( ( Settings::AimPreview::TargetBonesMask & 1 ) != 0 )
		{
			SliderIntRow( XorStr( "Head probability" ), XorStr( "Percentage of shots directed at the head; the remainder is shared between selected bones." ), XorStr( "##AimHeadChance" ), Settings::AimPreview::HeadChance, 0, 100, XorStr( "%d %%" ) );
		}
		const char* TargetPriorities[] = { "Closest to crosshair", "Lowest health" };
		ComboRow( XorStr( "Priority" ), XorStr( "Selects a target inside the circle by screen distance or current health." ), XorStr( "##AimTargetPriority" ), Settings::AimPreview::TargetPriority, TargetPriorities, IM_ARRAYSIZE( TargetPriorities ) );
		SliderIntRow( XorStr( "Hit chance" ), XorStr( "Percentage of firing commands redirected toward the target." ), XorStr( "##AimHitChance" ), Settings::AimPreview::HitChance, 0, 100, XorStr( "%d %%" ) );
		if ( Settings::AimPreview::LegitMode )
			Settings::AimPreview::FovRadius = std::clamp( Settings::AimPreview::FovRadius, 25, 75 );
		SliderIntRow( XorStr( "FOV radius" ), XorStr( "Maximum targeting radius measured on screen. Legit mode is capped at 75 px." ), XorStr( "##AimFovRadius" ), Settings::AimPreview::FovRadius, 25, Settings::AimPreview::LegitMode ? 75 : 500, XorStr( "%d px" ) );
		if ( Settings::AimPreview::LegitMode )
		{
			SliderIntRow( XorStr( "Maximum origin shift" ), XorStr( "Limits the world-space projectile-origin displacement. Lower values reduce visible tracer arcs but also reduce effective long-range assistance." ), XorStr( "##AimLegitOriginShift" ), Settings::AimPreview::LegitMaxOriginShift, 5, 100, XorStr( "%d units" ) );
		}
		SliderFloatRow( XorStr( "Pitch smoothing" ), XorStr( "Smooths vertical movement for more natural behavior." ), XorStr( "##AimPitchSmoothing" ), Settings::AimPreview::PitchSmoothing, 0.f, 100.f, XorStr( "%.1f %%" ) );
		SliderFloatRow( XorStr( "Yaw smoothing" ), XorStr( "Smooths horizontal movement for more natural behavior." ), XorStr( "##AimYawSmoothing" ), Settings::AimPreview::YawSmoothing, 0.f, 100.f, XorStr( "%.1f %%" ) );
		EndSettingsTable();

		const bool bSuspiciousSmoothing = Settings::AimPreview::PitchSmoothing < 15.f || Settings::AimPreview::YawSmoothing < 15.f;
		if ( bSuspiciousSmoothing )
		{
			ImGui::PushStyleColor( ImGuiCol_Text, ImVec4( 0.98f, 0.62f, 0.24f, 1.f ) );
			ImGui::TextWrapped( XorStr( "Warning: very low smoothing can make movement look unnaturally sharp. Values between 15%% and 35%% are generally more subtle." ) );
			ImGui::PopStyleColor();
		}
		else
		{
			ImGui::PushStyleColor( ImGuiCol_Text, ImVec4( 0.35f, 0.85f, 0.45f, 1.f ) );
			ImGui::TextWrapped( XorStr( "Natural movement: the current values should remain subtle." ) );
			ImGui::PopStyleColor();
		}
	}

	SectionHeading( XorStr( "Target types" ) );
	if ( BeginSettingsTable( XorStr( "##AimEntityTypesTable" ) ) )
	{
		ToggleRow( XorStr( "Enemy heroes" ), XorStr( "Includes enemy players and hero bots." ), XorStr( "##AimHeroes" ), Settings::AimPreview::TargetHeroes );
		ToggleRow( XorStr( "Enemy troopers" ), XorStr( "Includes enemy lane troopers." ), XorStr( "##AimTroopers" ), Settings::AimPreview::TargetTroopers );
		ToggleRow( XorStr( "Neutral units" ), XorStr( "Includes neutral camps and creatures." ), XorStr( "##AimNeutrals" ), Settings::AimPreview::TargetNeutrals );
		ToggleRow( XorStr( "Other NPCs" ), XorStr( "Includes drones and other hostile NPCs." ), XorStr( "##AimNpcs" ), Settings::AimPreview::TargetNpcs );
		ToggleRow( XorStr( "Towers and objectives" ), XorStr( "Includes sentries, guardians, walkers and bosses." ), XorStr( "##AimObjectives" ), Settings::AimPreview::TargetObjectives );
		EndSettingsTable();
	}

}

auto CPericlesMenu::RenderAutomationPage() -> void
{
	PageHeading( XorStr( "AUTOMATION" ), XorStr( "Combat actions executed automatically when the situation requires it." ) );

	SectionHeading( XorStr( "Automatic actions" ) );
	if ( BeginSettingsTable( XorStr( "##CombatAutomationTable" ) ) )
	{
		ToggleRow( XorStr( "Soul steal" ), XorStr( "Automatically fires until any active soul inside the circle is collected." ), XorStr( "##AimSoulSteal" ), Settings::AimPreview::SoulSteal );
		ToggleRow( XorStr( "Auto parry" ), XorStr( "Triggers a parry when a parryable attack threatens the character." ), XorStr( "##AimAutoParry" ), Settings::AimPreview::AutoParry );
		SliderIntRow( XorStr( "Parry chance" ), XorStr( "Probability that each newly detected attack triggers auto parry." ), XorStr( "##AimAutoParryChance" ), Settings::AimPreview::AutoParryChance, 0, 100, XorStr( "%d %%" ) );
		MultiParryComboRow( XorStr( "Damage types to parry" ), XorStr( "Select the attack types allowed to trigger auto parry." ), XorStr( "##AimAutoParryTypes" ), Settings::AimPreview::AutoParryTypesMask );
		EndSettingsTable();
	}
}

auto CPericlesMenu::RenderVisualGeneralPage() -> void
{
	PageHeading( XorStr( "GENERAL VISUALS" ), XorStr( "Global activation, visibility filtering and sound events." ) );

	SectionHeading( XorStr( "General display" ) );
	if ( BeginSettingsTable( XorStr( "##VisualGeneralTable" ) ) )
	{
		ToggleRow( XorStr( "Enable visuals" ), XorStr( "Controls all ESP elements." ), XorStr( "##VisualActive" ), Settings::Visual::Active );
		ToggleRow( XorStr( "Visible entities only" ), XorStr( "Hides boxes and skeletons without line of sight; health remains visible." ), XorStr( "##VisualVisibleOnly" ), Settings::Visual::OnlyVisible );
		ToggleRow( XorStr( "Sound indicators" ), XorStr( "Displays detected footsteps in the scene." ), XorStr( "##VisualSound" ), Settings::Visual::SoundStepEsp );
		EndSettingsTable();
	}
}

auto CPericlesMenu::RenderHeroEspPage() -> void
{
	PageHeading( XorStr( "HERO ESP" ), XorStr( "Visual information displayed around allied and enemy heroes." ) );

	SectionHeading( XorStr( "Heroes" ) );
	if ( BeginSettingsTable( XorStr( "##VisualHeroTable" ) ) )
	{
		ToggleRow( XorStr( "Allies" ), XorStr( "Displays heroes on your team." ), XorStr( "##VisualHeroTeam" ), Settings::Visual::HeroTeam );
		ToggleRow( XorStr( "Enemies" ), XorStr( "Displays enemy heroes." ), XorStr( "##VisualHeroEnemy" ), Settings::Visual::HeroEnemy );
		ToggleRow( XorStr( "Boxes" ), XorStr( "Draws a frame around heroes." ), XorStr( "##VisualHeroBox" ), Settings::Visual::HeroBox );
		ToggleRow( XorStr( "Skeletons" ), XorStr( "Draws the main joints." ), XorStr( "##VisualHeroSkeleton" ), Settings::Visual::HeroSkeleton );
		ToggleRow( XorStr( "Show health" ), XorStr( "Remains visible even when the hero is behind a wall." ), XorStr( "##VisualHeroHealth" ), Settings::Visual::HeroHealth );

		const char* BoxTypes[] = { "Box", "Outlined box", "Corners", "Outlined corners" };
		ComboRow( XorStr( "Box style" ), XorStr( "Shape used around heroes." ), XorStr( "##VisualHeroBoxType" ), Settings::Visual::HeroBoxType, BoxTypes, IM_ARRAYSIZE( BoxTypes ) );
		EndSettingsTable();
	}
}

auto CPericlesMenu::RenderUnitEspPage() -> void
{
	PageHeading( XorStr( "UNIT ESP" ), XorStr( "Display options for lane troopers and neutral creatures." ) );

	SectionHeading( XorStr( "Units" ) );
	if ( BeginSettingsTable( XorStr( "##VisualTrooperTable" ) ) )
	{
		ToggleRow( XorStr( "Allied troopers" ), XorStr( "Displays troopers on your team." ), XorStr( "##VisualTrooperTeam" ), Settings::Visual::TrooperTeam );
		ToggleRow( XorStr( "Enemy troopers" ), XorStr( "Displays enemy troopers." ), XorStr( "##VisualTrooperEnemy" ), Settings::Visual::TrooperEnemy );
		ToggleRow( XorStr( "Trooper skeletons" ), XorStr( "Draws their joints." ), XorStr( "##VisualTrooperSkeleton" ), Settings::Visual::TrooperSkeleton );
		ToggleRow( XorStr( "Neutral units" ), XorStr( "Displays neutral camps." ), XorStr( "##VisualNeutral" ), Settings::Visual::TrooperNeutral );
		ToggleRow( XorStr( "Neutral skeletons" ), XorStr( "Draws the joints of neutral units." ), XorStr( "##VisualNeutralSkeleton" ), Settings::Visual::TrooperNeutralSkeleton );

		const char* BoxTypes[] = { "Box", "Outlined box", "Corners", "Outlined corners" };
		ComboRow( XorStr( "Trooper box style" ), XorStr( "Shape used around troopers." ), XorStr( "##VisualTrooperBoxType" ), Settings::Visual::TrooperBoxType, BoxTypes, IM_ARRAYSIZE( BoxTypes ) );
		ComboRow( XorStr( "Neutral box style" ), XorStr( "Shape used around neutral units." ), XorStr( "##VisualNeutralBoxType" ), Settings::Visual::TrooperNeutralBoxType, BoxTypes, IM_ARRAYSIZE( BoxTypes ) );
		EndSettingsTable();
	}
}

auto CPericlesMenu::RenderColorsPage() -> void
{
	PageHeading( XorStr( "COLORS" ), XorStr( "Color palette used by the different visual elements." ) );

	SectionHeading( XorStr( "Heroes" ) );
	if ( BeginSettingsTable( XorStr( "##ColorHeroTable" ) ) )
	{
		ColorRow( XorStr( "Enemy" ), XorStr( "Default color." ), XorStr( "##ColorHeroEnemy" ), Settings::Colors::Visual::HeroEnemy );
		ColorRow( XorStr( "Visible enemy" ), XorStr( "Color used with line of sight." ), XorStr( "##ColorHeroEnemyVisible" ), Settings::Colors::Visual::HeroEnemyVisible );
		ColorRow( XorStr( "Ally" ), XorStr( "Default color." ), XorStr( "##ColorHeroTeam" ), Settings::Colors::Visual::HeroTeam );
		ColorRow( XorStr( "Visible ally" ), XorStr( "Color used with line of sight." ), XorStr( "##ColorHeroTeamVisible" ), Settings::Colors::Visual::HeroTeamVisible );
		ColorRow( XorStr( "Skeleton" ), XorStr( "Joint color." ), XorStr( "##ColorHeroSkeleton" ), Settings::Colors::Visual::HeroSkeleton );
		EndSettingsTable();
	}

	SectionHeading( XorStr( "Troopers and neutrals" ) );
	if ( BeginSettingsTable( XorStr( "##ColorTrooperTable" ) ) )
	{
		ColorRow( XorStr( "Enemy trooper" ), XorStr( "Default color." ), XorStr( "##ColorTrooperEnemy" ), Settings::Colors::Visual::TrooperEnemy );
		ColorRow( XorStr( "Visible enemy trooper" ), XorStr( "Color used with line of sight." ), XorStr( "##ColorTrooperEnemyVisible" ), Settings::Colors::Visual::TrooperEnemyVisible );
		ColorRow( XorStr( "Allied trooper" ), XorStr( "Default color." ), XorStr( "##ColorTrooperTeam" ), Settings::Colors::Visual::TrooperTeam );
		ColorRow( XorStr( "Visible allied trooper" ), XorStr( "Color used with line of sight." ), XorStr( "##ColorTrooperTeamVisible" ), Settings::Colors::Visual::TrooperTeamVisible );
		ColorRow( XorStr( "Trooper skeleton" ), XorStr( "Joint color." ), XorStr( "##ColorTrooperSkeleton" ), Settings::Colors::Visual::TrooperSkeleton );
		ColorRow( XorStr( "Neutral unit" ), XorStr( "Default color." ), XorStr( "##ColorNeutral" ), Settings::Colors::Visual::TrooperNeutral );
		ColorRow( XorStr( "Visible neutral unit" ), XorStr( "Color used with line of sight." ), XorStr( "##ColorNeutralVisible" ), Settings::Colors::Visual::TrooperNeutralVisible );
		ColorRow( XorStr( "Neutral skeleton" ), XorStr( "Joint color." ), XorStr( "##ColorNeutralSkeleton" ), Settings::Colors::Visual::TrooperNeutralSkeleton );
		ColorRow( XorStr( "Sound indicator" ), XorStr( "Color used for detected footsteps." ), XorStr( "##ColorSound" ), Settings::Colors::Visual::SoundStepEsp );
		EndSettingsTable();
	}
}

auto CPericlesMenu::RenderMiscPage() -> void
{
	PageHeading( XorStr( "GAME" ), XorStr( "General features applied directly to the game." ) );

	SectionHeading( XorStr( "Overlay and map" ) );
	if ( BeginSettingsTable( XorStr( "##GeneralGameTable" ) ) )
	{
		ToggleRow( XorStr( "Unlock minimap" ), XorStr( "Keeps entities visible on the map and refreshes outdated markers." ), XorStr( "##UnlockMinimap" ), Settings::Misc::UnlockMiniMap );
		ToggleRow( XorStr( "Show status overlay" ), XorStr( "Displays the product name and statistics in the top-left overlay." ), XorStr( "##ShowCheatOverlay" ), Settings::Misc::ShowCheatOverlay );
		EndSettingsTable();
	}
}

auto CPericlesMenu::RenderInterfacePage() -> void
{
	PageHeading( XorStr( "INTERFACE" ), XorStr( "Appearance and sound feedback for the configuration panel." ) );

	SectionHeading( XorStr( "Menu" ) );
	if ( BeginSettingsTable( XorStr( "##GeneralInterfaceTable" ) ) )
	{
		SliderIntRow( XorStr( "Menu opacity" ), XorStr( "Controls the overall transparency of the window." ), XorStr( "##MenuAlpha" ), Settings::Misc::MenuAlpha, 100, 255, XorStr( "%d" ) );
		ToggleRow( XorStr( "Interface sounds" ), XorStr( "Plays audio feedback on hover and click." ), XorStr( "##MenuSounds" ), Settings::Misc::MenuSounds );
		EndSettingsTable();
	}

	ImGui::Spacing();
	ImGui::PushStyleColor( ImGuiCol_Text, ImVec4( 0.46f, 0.43f, 0.50f, 1.f ) );
	ImGui::Text( XorStr( "Build: %s at %s" ), __DATE__, __TIME__ );
	ImGui::PopStyleColor();
}

auto CPericlesMenu::RenderProfilesPage() -> void
{
	PageHeading( XorStr( "PROFILES" ), XorStr( "Create, load and save your configurations." ) );

	auto& ConfigList = GetSettingsJson()->GetConfigList();
	if ( !ConfigList.empty() )
		m_nConfigSelected = std::min<uint32_t>( m_nConfigSelected, static_cast<uint32_t>( ConfigList.size() - 1 ) );
	else
		m_nConfigSelected = 0;

	SectionHeading( XorStr( "Available profiles" ) );
	ImGui::PushStyleColor( ImGuiCol_ChildBg, ImVec4( 0.047f, 0.043f, 0.064f, 1.f ) );
	if ( ImGui::BeginChild( XorStr( "##ProfileList" ), ImVec2( 0.f, 230.f ), true ) )
	{
		if ( ConfigList.empty() )
		{
			ImGui::TextDisabled( XorStr( "No saved profile." ) );
		}
		else
		{
			for ( uint32_t Index = 0; Index < ConfigList.size(); ++Index )
			{
				const bool Selected = Index == m_nConfigSelected;
				if ( ImGui::Selectable( ConfigList[Index].c_str(), Selected, 0, ImVec2( 0.f, 34.f ) ) )
					m_nConfigSelected = Index;
			}
		}
	}
	ImGui::EndChild();
	ImGui::PopStyleColor();

	SectionHeading( XorStr( "New profile" ) );
	ImGui::SetNextItemWidth( 330.f );
	ImGui::InputTextWithHint( XorStr( "##NewProfileName" ), XorStr( "Profile name" ), m_szNewConfigFileName, IM_ARRAYSIZE( m_szNewConfigFileName ) );
	ImGui::SameLine();
	if ( ActionButton( XorStr( "Create" ), ImVec2( 110.f, 34.f ), true ) )
	{
		const std::string ProfileName = m_szNewConfigFileName;
		if ( !ProfileName.empty() )
		{
			GetSettingsJson()->SaveConfig( ProfileName + XorStr( ".json" ) );
			GetSettingsJson()->UpdateConfigList();
			auto& UpdatedList = GetSettingsJson()->GetConfigList();
			if ( !UpdatedList.empty() )
				m_nConfigSelected = static_cast<uint32_t>( UpdatedList.size() - 1 );
			m_szNewConfigFileName[0] = '\0';
		}
	}

	ImGui::Spacing();
	if ( ActionButton( XorStr( "Load" ), ImVec2( 120.f, 36.f ) ) && !ConfigList.empty() )
		GetSettingsJson()->LoadConfig( ConfigList[m_nConfigSelected] );
	ImGui::SameLine();
	if ( ActionButton( XorStr( "Save" ), ImVec2( 120.f, 36.f ), true ) && !ConfigList.empty() )
		GetSettingsJson()->SaveConfig( ConfigList[m_nConfigSelected] );
	ImGui::SameLine();
	if ( ActionButton( XorStr( "Delete" ), ImVec2( 120.f, 36.f ) ) && !ConfigList.empty() )
	{
		const std::string DeletedProfile = ConfigList[m_nConfigSelected];
		GetSettingsJson()->DeleteConfig( DeletedProfile );
		GetSettingsJson()->UpdateConfigList();
	}
	ImGui::SameLine();
	if ( ActionButton( XorStr( "Refresh" ), ImVec2( 120.f, 36.f ) ) )
		GetSettingsJson()->UpdateConfigList();
}

auto CPericlesMenu::CategoryHeading( const char* Label ) -> void
{
	ImGui::Dummy( ImVec2( 0.f, 5.f ) );
	ImGui::PushStyleColor( ImGuiCol_Text, ImGui::ColorConvertU32ToFloat4( AccentHoverColor ) );
	if ( GetPericlesGUI()->m_pSectionFont )
		ImGui::PushFont( GetPericlesGUI()->m_pSectionFont );
	ImGui::TextUnformatted( Label );
	if ( GetPericlesGUI()->m_pSectionFont )
		ImGui::PopFont();
	ImGui::PopStyleColor();
	ImGui::Dummy( ImVec2( 0.f, 1.f ) );
}

auto CPericlesMenu::NavigationItem( const char* Label, Page TargetPage ) -> bool
{
	const bool Selected = m_Page == TargetPage;
	const ImVec2 Position = ImGui::GetCursorScreenPos();
	const ImVec2 Size( SidebarWidth - 36.f, 30.f );

	ImGui::PushID( Label );
	const bool Clicked = ImGui::InvisibleButton( XorStr( "##NavigationItem" ), Size );
	const bool Hovered = ImGui::IsItemHovered();
	ImGui::PopID();

	auto* DrawList = ImGui::GetWindowDrawList();
	if ( Selected )
	{
		DrawList->AddRectFilled( Position, ImVec2( Position.x + Size.x, Position.y + Size.y ), AccentSoftColor, 5.f );
		DrawList->AddRect( Position, ImVec2( Position.x + Size.x, Position.y + Size.y ), IM_COL32( 153, 52, 228, 205 ), 5.f );
		DrawList->AddTriangleFilled(
			ImVec2( Position.x + 7.f, Position.y + 10.f ),
			ImVec2( Position.x + 7.f, Position.y + 20.f ),
			ImVec2( Position.x + 12.f, Position.y + 15.f ),
			AccentColor );
	}
	else if ( Hovered )
	{
		DrawList->AddRectFilled( Position, ImVec2( Position.x + Size.x, Position.y + Size.y ), IM_COL32( 52, 31, 67, 135 ), 5.f );
		PlayHover();
	}

	const ImU32 TextColor = Selected ? IM_COL32( 235, 243, 255, 255 ) : IM_COL32( 150, 160, 177, 255 );
	DrawList->AddText( ImVec2( Position.x + 20.f, Position.y + 7.f ), TextColor, Label );

	ImGui::SetCursorPosY( ImGui::GetCursorPosY() + 1.f );
	if ( Clicked )
		m_Page = TargetPage;

	return Clicked;
}

auto CPericlesMenu::ToggleSwitch( const char* Id, bool& Value ) -> bool
{
	constexpr float Width = 44.f;
	constexpr float Height = 22.f;
	const ImVec2 Available = ImGui::GetContentRegionAvail();
	ImVec2 Position = ImGui::GetCursorScreenPos();
	Position.x += Available.x - Width;
	ImGui::SetCursorScreenPos( Position );

	ImGui::InvisibleButton( Id, ImVec2( Width, Height ) );
	const bool Clicked = ImGui::IsItemClicked();
	if ( Clicked )
		Value = !Value;

	const bool Hovered = ImGui::IsItemHovered();
	const ImU32 Background = Value
		? ( Hovered ? AccentHoverColor : AccentColor )
		: ( Hovered ? IM_COL32( 78, 65, 88, 255 ) : IM_COL32( 48, 45, 58, 255 ) );
	const float KnobX = Value ? Position.x + Width - Height * 0.5f : Position.x + Height * 0.5f;

	auto* DrawList = ImGui::GetWindowDrawList();
	DrawList->AddRectFilled( Position, ImVec2( Position.x + Width, Position.y + Height ), Background, Height * 0.5f );
	DrawList->AddCircleFilled( ImVec2( KnobX, Position.y + Height * 0.5f ), Height * 0.5f - 3.f, IM_COL32( 244, 247, 252, 255 ) );
	return Clicked;
}

auto CPericlesMenu::ActionButton( const char* Label, const ImVec2& Size, bool Primary ) -> bool
{
	if ( Primary )
	{
		ImGui::PushStyleColor( ImGuiCol_Button, ImVec4( 0.52f, 0.12f, 0.79f, 1.f ) );
		ImGui::PushStyleColor( ImGuiCol_ButtonHovered, ImVec4( 0.66f, 0.19f, 0.94f, 1.f ) );
		ImGui::PushStyleColor( ImGuiCol_ButtonActive, ImVec4( 0.43f, 0.08f, 0.67f, 1.f ) );
	}

	const bool Result = ImGui::Button( Label, Size );
	if ( Primary )
		ImGui::PopStyleColor( 3 );
	return Result;
}

auto CPericlesMenu::BeginSettingsTable( const char* Id ) -> bool
{
	const ImGuiTableFlags Flags = ImGuiTableFlags_SizingStretchProp
		| ImGuiTableFlags_BordersInnerH
		| ImGuiTableFlags_NoSavedSettings;

	ImGui::PushStyleVar( ImGuiStyleVar_ChildRounding, 4.f );
	ImGui::PushStyleVar( ImGuiStyleVar_ChildBorderSize, 1.f );
	ImGui::PushStyleVar( ImGuiStyleVar_WindowPadding, ImVec2( 18.f, 12.f ) );
	ImGui::PushStyleColor( ImGuiCol_ChildBg, ImGui::ColorConvertU32ToFloat4( CardColor ) );
	ImGui::PushStyleColor( ImGuiCol_Border, ImGui::ColorConvertU32ToFloat4( CardBorderColor ) );
	const ImGuiChildFlags ChildFlags = ImGuiChildFlags_Borders
		| ImGuiChildFlags_AutoResizeY
		| ImGuiChildFlags_AlwaysUseWindowPadding;

	if ( !ImGui::BeginChild( Id, ImVec2( 0.f, 0.f ), ChildFlags, ImGuiWindowFlags_NoScrollbar | ImGuiWindowFlags_NoScrollWithMouse ) )
	{
		ImGui::EndChild();
		ImGui::PopStyleColor( 2 );
		ImGui::PopStyleVar( 3 );
		return false;
	}

	if ( !ImGui::BeginTable( XorStr( "##SettingsTable" ), 2, Flags ) )
	{
		ImGui::EndChild();
		ImGui::PopStyleColor( 2 );
		ImGui::PopStyleVar( 3 );
		return false;
	}

	ImGui::TableSetupColumn( XorStr( "##Description" ), ImGuiTableColumnFlags_WidthStretch, 1.45f );
	ImGui::TableSetupColumn( XorStr( "##Control" ), ImGuiTableColumnFlags_WidthStretch, 1.f );
	return true;
}

auto CPericlesMenu::EndSettingsTable() -> void
{
	ImGui::EndTable();
	ImGui::EndChild();
	ImGui::PopStyleColor( 2 );
	ImGui::PopStyleVar( 3 );
	ImGui::Spacing();
}

auto CPericlesMenu::PageHeading( const char* Title, const char* Description ) -> void
{
	const ImVec2 HeadingPosition = ImGui::GetCursorScreenPos();
	ImGui::GetWindowDrawList()->AddRectFilled(
		ImVec2( HeadingPosition.x, HeadingPosition.y + 3.f ),
		ImVec2( HeadingPosition.x + 3.f, HeadingPosition.y + 30.f ),
		AccentColor, 1.f );
	ImGui::SetCursorScreenPos( ImVec2( HeadingPosition.x + 13.f, HeadingPosition.y ) );
	if ( GetPericlesGUI()->m_pTitleFont )
		ImGui::PushFont( GetPericlesGUI()->m_pTitleFont );
	ImGui::TextUnformatted( Title );
	if ( GetPericlesGUI()->m_pTitleFont )
		ImGui::PopFont();

	ImGui::SetCursorScreenPos( ImVec2( HeadingPosition.x + 13.f, ImGui::GetCursorScreenPos().y ) );
	ImGui::PushStyleColor( ImGuiCol_Text, ImGui::ColorConvertU32ToFloat4( MutedTextColor ) );
	ImGui::TextWrapped( XorStr( "%s" ), Description );
	ImGui::PopStyleColor();
	ImGui::SetCursorScreenPos( ImVec2( HeadingPosition.x, ImGui::GetCursorScreenPos().y ) );
	ImGui::Dummy( ImVec2( 0.f, 12.f ) );
}

auto CPericlesMenu::SectionHeading( const char* Title ) -> void
{
	ImGui::Dummy( ImVec2( 0.f, 5.f ) );
	const ImVec2 HeadingPosition = ImGui::GetCursorScreenPos();
	ImGui::GetWindowDrawList()->AddCircleFilled(
		ImVec2( HeadingPosition.x + 4.f, HeadingPosition.y + 10.f ),
		3.f, AccentColor );
	ImGui::SetCursorScreenPos( ImVec2( HeadingPosition.x + 15.f, HeadingPosition.y ) );
	if ( GetPericlesGUI()->m_pSectionFont )
		ImGui::PushFont( GetPericlesGUI()->m_pSectionFont );
	ImGui::TextUnformatted( Title );
	if ( GetPericlesGUI()->m_pSectionFont )
		ImGui::PopFont();
	ImGui::SetCursorScreenPos( ImVec2( HeadingPosition.x, ImGui::GetCursorScreenPos().y ) );
	ImGui::Dummy( ImVec2( 0.f, 4.f ) );
}

auto CPericlesMenu::ToggleRow( const char* Label, const char* Description, const char* Id, bool& Value ) -> void
{
	ImGui::TableNextRow( ImGuiTableRowFlags_None, 50.f );
	ImGui::TableSetColumnIndex( 0 );
	ImGui::SetCursorPosY( ImGui::GetCursorPosY() + 6.f );
	ImGui::TextUnformatted( Label );
	ImGui::TextDisabled( XorStr( "%s" ), Description );
	ImGui::TableSetColumnIndex( 1 );
	ImGui::SetCursorPosY( ImGui::GetCursorPosY() + 13.f );
	ToggleSwitch( Id, Value );
}

auto CPericlesMenu::SliderIntRow( const char* Label, const char* Description, const char* Id, int& Value, int Min, int Max, const char* Format ) -> void
{
	ImGui::TableNextRow( ImGuiTableRowFlags_None, 55.f );
	ImGui::TableSetColumnIndex( 0 );
	ImGui::SetCursorPosY( ImGui::GetCursorPosY() + 7.f );
	ImGui::TextUnformatted( Label );
	ImGui::TextDisabled( XorStr( "%s" ), Description );
	ImGui::TableSetColumnIndex( 1 );
	ImGui::SetCursorPosY( ImGui::GetCursorPosY() + 10.f );
	ImGui::SetNextItemWidth( -1.f );
	ImGui::SliderInt( Id, &Value, Min, Max, Format, ImGuiSliderFlags_AlwaysClamp );
}

auto CPericlesMenu::SliderFloatRow( const char* Label, const char* Description, const char* Id, float& Value, float Min, float Max, const char* Format ) -> void
{
	ImGui::TableNextRow( ImGuiTableRowFlags_None, 55.f );
	ImGui::TableSetColumnIndex( 0 );
	ImGui::SetCursorPosY( ImGui::GetCursorPosY() + 7.f );
	ImGui::TextUnformatted( Label );
	ImGui::TextDisabled( XorStr( "%s" ), Description );
	ImGui::TableSetColumnIndex( 1 );
	ImGui::SetCursorPosY( ImGui::GetCursorPosY() + 10.f );
	ImGui::SetNextItemWidth( -1.f );
	ImGui::SliderFloat( Id, &Value, Min, Max, Format, ImGuiSliderFlags_AlwaysClamp );
}

auto CPericlesMenu::ComboRow( const char* Label, const char* Description, const char* Id, int& Value, const char* const* Items, int ItemCount ) -> void
{
	Value = std::clamp( Value, 0, ItemCount - 1 );
	ImGui::TableNextRow( ImGuiTableRowFlags_None, 55.f );
	ImGui::TableSetColumnIndex( 0 );
	ImGui::SetCursorPosY( ImGui::GetCursorPosY() + 7.f );
	ImGui::TextUnformatted( Label );
	ImGui::TextDisabled( XorStr( "%s" ), Description );
	ImGui::TableSetColumnIndex( 1 );
	ImGui::SetCursorPosY( ImGui::GetCursorPosY() + 9.f );
	ImGui::SetNextItemWidth( -1.f );
	if ( ImGui::BeginCombo( Id, Items[Value] ) )
	{
		for ( int Index = 0; Index < ItemCount; ++Index )
		{
			const bool Selected = Index == Value;
			if ( ImGui::Selectable( Items[Index], Selected ) )
				Value = Index;
			if ( Selected )
				ImGui::SetItemDefaultFocus();
		}
		ImGui::EndCombo();
	}
}

auto CPericlesMenu::MultiBoneComboRow( const char* Label, const char* Description, const char* Id, int& Mask ) -> void
{
	static constexpr const char* BoneNames[] =
	{
		"head", "pelvis", "spine_3", "spine_0",
		"arm_lower_L", "arm_lower_R", "leg_lower_L", "leg_lower_R"
	};
	constexpr int ValidMask = ( 1 << IM_ARRAYSIZE( BoneNames ) ) - 1;
	Mask &= ValidMask;
	if ( Mask == 0 )
		Mask = 1;

	std::string Preview;
	int EnabledCount = 0;
	for ( int BoneIndex = 0; BoneIndex < IM_ARRAYSIZE( BoneNames ); ++BoneIndex )
	{
		if ( ( Mask & ( 1 << BoneIndex ) ) == 0 )
			continue;

		if ( !Preview.empty() )
			Preview += ", ";
		Preview += BoneNames[BoneIndex];
		++EnabledCount;
	}

	ImGui::TableNextRow( ImGuiTableRowFlags_None, 55.f );
	ImGui::TableSetColumnIndex( 0 );
	ImGui::SetCursorPosY( ImGui::GetCursorPosY() + 7.f );
	ImGui::TextUnformatted( Label );
	ImGui::TextDisabled( XorStr( "%s" ), Description );
	ImGui::TableSetColumnIndex( 1 );
	ImGui::SetCursorPosY( ImGui::GetCursorPosY() + 9.f );
	ImGui::SetNextItemWidth( -1.f );

	if ( ImGui::BeginCombo( Id, Preview.c_str() ) )
	{
		for ( int BoneIndex = 0; BoneIndex < IM_ARRAYSIZE( BoneNames ); ++BoneIndex )
		{
			const int BoneBit = 1 << BoneIndex;
			bool Selected = ( Mask & BoneBit ) != 0;
			if ( ImGui::Checkbox( BoneNames[BoneIndex], &Selected ) )
			{
				if ( Selected )
				{
					Mask |= BoneBit;
					++EnabledCount;
				}
				else if ( EnabledCount > 1 )
				{
					Mask &= ~BoneBit;
					--EnabledCount;
				}
			}
		}

		ImGui::Separator();
		ImGui::TextDisabled( XorStr( "%.1f %% per active bone" ), 100.f / static_cast<float>( EnabledCount ) );
		ImGui::EndCombo();
	}
}

auto CPericlesMenu::MultiParryComboRow( const char* Label, const char* Description, const char* Id, int& Mask ) -> void
{
	static constexpr const char* ParryNames[] =
	{
		"high melee", "light melee", "bot melee", "guardian melee"
	};
	constexpr int ValidMask = ( 1 << IM_ARRAYSIZE( ParryNames ) ) - 1;
	Mask &= ValidMask;
	if ( Mask == 0 )
		Mask = ValidMask;

	std::string Preview;
	int EnabledCount = 0;
	for ( int TypeIndex = 0; TypeIndex < IM_ARRAYSIZE( ParryNames ); ++TypeIndex )
	{
		if ( ( Mask & ( 1 << TypeIndex ) ) == 0 )
			continue;

		if ( !Preview.empty() )
			Preview += ", ";
		Preview += ParryNames[TypeIndex];
		++EnabledCount;
	}

	ImGui::TableNextRow( ImGuiTableRowFlags_None, 55.f );
	ImGui::TableSetColumnIndex( 0 );
	ImGui::SetCursorPosY( ImGui::GetCursorPosY() + 7.f );
	ImGui::TextUnformatted( Label );
	ImGui::TextDisabled( XorStr( "%s" ), Description );
	ImGui::TableSetColumnIndex( 1 );
	ImGui::SetCursorPosY( ImGui::GetCursorPosY() + 9.f );
	ImGui::SetNextItemWidth( -1.f );

	if ( ImGui::BeginCombo( Id, Preview.c_str() ) )
	{
		for ( int TypeIndex = 0; TypeIndex < IM_ARRAYSIZE( ParryNames ); ++TypeIndex )
		{
			const int TypeBit = 1 << TypeIndex;
			bool Selected = ( Mask & TypeBit ) != 0;
			if ( ImGui::Checkbox( ParryNames[TypeIndex], &Selected ) )
			{
				if ( Selected )
				{
					Mask |= TypeBit;
					++EnabledCount;
				}
				else if ( EnabledCount > 1 )
				{
					Mask &= ~TypeBit;
					--EnabledCount;
				}
			}
		}

		ImGui::EndCombo();
	}
}

auto CPericlesMenu::ColorRow( const char* Label, const char* Description, const char* Id, float* Color ) -> void
{
	ImGui::TableNextRow( ImGuiTableRowFlags_None, 55.f );
	ImGui::TableSetColumnIndex( 0 );
	ImGui::SetCursorPosY( ImGui::GetCursorPosY() + 7.f );
	ImGui::TextUnformatted( Label );
	ImGui::TextDisabled( XorStr( "%s" ), Description );
	ImGui::TableSetColumnIndex( 1 );
	ImGui::SetCursorPosY( ImGui::GetCursorPosY() + 9.f );
	ImGui::SetNextItemWidth( -1.f );
	ImGui::ColorEdit3( Id, Color, ImGuiColorEditFlags_NoInputs | ImGuiColorEditFlags_NoLabel | ImGuiColorEditFlags_PickerHueBar );
}

auto CPericlesMenu::SaveSelectedProfile() -> void
{
	auto& ConfigList = GetSettingsJson()->GetConfigList();
	if ( ConfigList.empty() )
	{
		GetSettingsJson()->SaveConfig( XorStr( "default.json" ) );
		GetSettingsJson()->UpdateConfigList();
		m_nConfigSelected = 0;
		return;
	}

	m_nConfigSelected = std::min<uint32_t>( m_nConfigSelected, static_cast<uint32_t>( ConfigList.size() - 1 ) );
	GetSettingsJson()->SaveConfig( ConfigList[m_nConfigSelected] );
}

auto CPericlesMenu::ReloadSelectedProfile() -> void
{
	auto& ConfigList = GetSettingsJson()->GetConfigList();
	if ( ConfigList.empty() )
		return;

	m_nConfigSelected = std::min<uint32_t>( m_nConfigSelected, static_cast<uint32_t>( ConfigList.size() - 1 ) );
	GetSettingsJson()->LoadConfig( ConfigList[m_nConfigSelected] );
}

auto CPericlesMenu::PlayClick() -> void
{
	if ( Settings::Misc::MenuSounds )
		GetPlayUISound()->PlayUISound( UISound::UI_Click );
}

auto CPericlesMenu::PlayHover() -> void
{
	if ( Settings::Misc::MenuSounds && ImGui::IsItemHovered( ImGuiHoveredFlags_DelayShort ) )
		GetPlayUISound()->PlayUISound( UISound::UI_Hover );
}

auto GetPericlesMenu() -> CPericlesMenu*
{
	return &g_CPericlesMenu;
}
