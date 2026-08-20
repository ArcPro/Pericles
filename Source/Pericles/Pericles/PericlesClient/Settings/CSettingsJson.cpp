#include "CSettingsJson.hpp"
#include "DllLauncher.hpp"

#include <algorithm>
#include <filesystem>
#include <fstream>

#include <PericlesClient/Settings/Settings.hpp>

static CSettingsJson g_CSettingsJson{};

auto CSettingsJson::LoadConfig( const std::string& JsonFile ) -> void
{
	auto ConfigLoadedIndex = 0u;
	const auto ConfigFilePath = GetDllDir() + JsonFile;

	for ( const auto& Config : m_vecConfigList )
	{
		if ( Config == JsonFile )
		{
			m_nConfigLoadedIndex = ConfigLoadedIndex;
			break;
		}

		ConfigLoadedIndex++;
	}

	std::ifstream ConfigFile( ConfigFilePath );

	rapidjson::IStreamWrapper StreamWrapper( ConfigFile );
	rapidjson::Document DocumentConfig;

	DocumentConfig.ParseStream( StreamWrapper );

	if ( !DocumentConfig.HasParseError() )
	{
		const auto& SettingsAimPreview = DocumentConfig[XorStr( "Settings" )][XorStr( "AimPreview" )];

		if ( !SettingsAimPreview.IsNull() )
		{
			GetBoolJson( SettingsAimPreview , XorStr( "Active" ) , Settings::AimPreview::Active );
			GetBoolJson( SettingsAimPreview , XorStr( "LegitMode" ) , Settings::AimPreview::LegitMode );
			GetBoolJson( SettingsAimPreview , XorStr( "ShowFovCircle" ) , Settings::AimPreview::ShowFovCircle );
			if ( SettingsAimPreview.HasMember( XorStr( "TargetBonesMask" ) ) )
			{
				GetIntJson( SettingsAimPreview , XorStr( "TargetBonesMask" ) , Settings::AimPreview::TargetBonesMask , 1 , 255 );
			}
			else
			{
				int LegacyTargetBone = 0;
				GetIntJson( SettingsAimPreview , XorStr( "TargetBone" ) , LegacyTargetBone , 0 , 7 );
				Settings::AimPreview::TargetBonesMask = 1 << LegacyTargetBone;
			}
			GetIntJson( SettingsAimPreview , XorStr( "HeadChance" ) , Settings::AimPreview::HeadChance , 0 , 100 );
			GetIntJson( SettingsAimPreview , XorStr( "TargetPriority" ) , Settings::AimPreview::TargetPriority , 0 , 1 );
			GetFloatJson( SettingsAimPreview , XorStr( "PitchSmoothing" ) , Settings::AimPreview::PitchSmoothing , 0.f , 100.f );
			GetFloatJson( SettingsAimPreview , XorStr( "YawSmoothing" ) , Settings::AimPreview::YawSmoothing , 0.f , 100.f );
			GetIntJson( SettingsAimPreview , XorStr( "HitChance" ) , Settings::AimPreview::HitChance , 0 , 100 );
			GetIntJson( SettingsAimPreview , XorStr( "FovRadius" ) , Settings::AimPreview::FovRadius , 25 , 500 );
			if ( Settings::AimPreview::LegitMode )
				Settings::AimPreview::FovRadius = Settings::AimPreview::FovRadius > 75
					? 75
					: Settings::AimPreview::FovRadius;
			GetBoolJson( SettingsAimPreview , XorStr( "OnlyVisible" ) , Settings::AimPreview::OnlyVisible );
			GetBoolJson( SettingsAimPreview , XorStr( "SoulSteal" ) , Settings::AimPreview::SoulSteal );
			GetBoolJson( SettingsAimPreview , XorStr( "AutoParry" ) , Settings::AimPreview::AutoParry );
			GetIntJson( SettingsAimPreview , XorStr( "AutoParryChance" ) , Settings::AimPreview::AutoParryChance , 0 , 100 );
			GetIntJson( SettingsAimPreview , XorStr( "AutoParryTypesMask" ) , Settings::AimPreview::AutoParryTypesMask , 1 , 15 );
			GetBoolJson( SettingsAimPreview , XorStr( "TargetHeroes" ) , Settings::AimPreview::TargetHeroes );
			GetBoolJson( SettingsAimPreview , XorStr( "TargetTroopers" ) , Settings::AimPreview::TargetTroopers );
			GetBoolJson( SettingsAimPreview , XorStr( "TargetNeutrals" ) , Settings::AimPreview::TargetNeutrals );
			GetBoolJson( SettingsAimPreview , XorStr( "TargetNpcs" ) , Settings::AimPreview::TargetNpcs );
			GetBoolJson( SettingsAimPreview , XorStr( "TargetObjectives" ) , Settings::AimPreview::TargetObjectives );
		}

		const auto& SettingsVisual = DocumentConfig[XorStr( "Settings" )][XorStr( "Visual" )];

		if ( !SettingsVisual.IsNull() )
		{
			GetBoolJson( SettingsVisual , XorStr( "Active" ) , Settings::Visual::Active );
			GetBoolJson( SettingsVisual , XorStr( "OnlyVisible" ) , Settings::Visual::OnlyVisible );
			GetBoolJson( SettingsVisual , XorStr( "HeroTeam" ) , Settings::Visual::HeroTeam );
			GetBoolJson( SettingsVisual , XorStr( "HeroEnemy" ) , Settings::Visual::HeroEnemy );
			GetBoolJson( SettingsVisual , XorStr( "HeroBox" ) , Settings::Visual::HeroBox );
			GetBoolJson( SettingsVisual , XorStr( "HeroSkeleton" ) , Settings::Visual::HeroSkeleton );
			GetBoolJson( SettingsVisual , XorStr( "HeroHealth" ) , Settings::Visual::HeroHealth );
			GetBoolJson( SettingsVisual , XorStr( "SoundStepEsp" ) , Settings::Visual::SoundStepEsp );
			GetBoolJson( SettingsVisual , XorStr( "TrooperTeam" ) , Settings::Visual::TrooperTeam );
			GetBoolJson( SettingsVisual , XorStr( "TrooperEnemy" ) , Settings::Visual::TrooperEnemy );
			GetBoolJson( SettingsVisual , XorStr( "TrooperSkeleton" ) , Settings::Visual::TrooperSkeleton );
			GetBoolJson( SettingsVisual , XorStr( "TrooperNeutral" ) , Settings::Visual::TrooperNeutral );
			GetBoolJson( SettingsVisual , XorStr( "TrooperNeutralSkeleton" ) , Settings::Visual::TrooperNeutralSkeleton );
			GetIntJson( SettingsVisual , XorStr( "HeroBoxType" ) , Settings::Visual::HeroBoxType , 0 , 3 );
			GetIntJson( SettingsVisual , XorStr( "TrooperBoxType" ) , Settings::Visual::TrooperBoxType , 0 , 3 );
			GetIntJson( SettingsVisual , XorStr( "TrooperNeutralBoxType" ) , Settings::Visual::TrooperNeutralBoxType , 0 , 3 );
		}

		const auto& SettingsMisc = DocumentConfig[XorStr( "Settings" )][XorStr( "Misc" )];

		if ( !SettingsMisc.IsNull() )
		{
			GetBoolJson( SettingsMisc , XorStr( "UnlockMiniMap" ) , Settings::Misc::UnlockMiniMap );
			GetBoolJson( SettingsMisc , XorStr( "ShowCheatOverlay" ) , Settings::Misc::ShowCheatOverlay );
			GetIntJson( SettingsMisc , XorStr( "MenuAlpha" ) , Settings::Misc::MenuAlpha , 100 , 255 );
			GetIntJson( SettingsMisc , XorStr( "MenuStyle" ) , Settings::Misc::MenuStyle , 0 , 2 );
			GetBoolJson( SettingsMisc , XorStr( "MenuSounds" ) , Settings::Misc::MenuSounds );
		}

		const auto& SettingsColors = DocumentConfig[XorStr( "Settings" )][XorStr( "Colors" )];

		if ( !SettingsColors.IsNull() )
		{
			const auto& ColorsVisual = SettingsColors[XorStr( "Visual" )];

			if ( !ColorsVisual.IsNull() )
			{
				GetColorJson( ColorsVisual , XorStr( "HeroEnemy" ) , Settings::Colors::Visual::HeroEnemy );
				GetColorJson( ColorsVisual , XorStr( "HeroEnemyVisible" ) , Settings::Colors::Visual::HeroEnemyVisible );
				GetColorJson( ColorsVisual , XorStr( "HeroTeam" ) , Settings::Colors::Visual::HeroTeam );
				GetColorJson( ColorsVisual , XorStr( "HeroTeamVisible" ) , Settings::Colors::Visual::HeroTeamVisible );
				GetColorJson( ColorsVisual , XorStr( "HeroSkeleton" ) , Settings::Colors::Visual::HeroSkeleton );
				GetColorJson( ColorsVisual , XorStr( "TrooperEnemy" ) , Settings::Colors::Visual::TrooperEnemy );
				GetColorJson( ColorsVisual , XorStr( "TrooperEnemyVisible" ) , Settings::Colors::Visual::TrooperEnemyVisible );
				GetColorJson( ColorsVisual , XorStr( "TrooperTeam" ) , Settings::Colors::Visual::TrooperTeam );
				GetColorJson( ColorsVisual , XorStr( "TrooperTeamVisible" ) , Settings::Colors::Visual::TrooperTeamVisible );
				GetColorJson( ColorsVisual , XorStr( "TrooperSkeleton" ) , Settings::Colors::Visual::TrooperSkeleton );
				GetColorJson( ColorsVisual , XorStr( "TrooperNeutral" ) , Settings::Colors::Visual::TrooperNeutral );
				GetColorJson( ColorsVisual , XorStr( "TrooperNeutralVisible" ) , Settings::Colors::Visual::TrooperNeutralVisible );
				GetColorJson( ColorsVisual , XorStr( "TrooperNeutralSkeleton" ) , Settings::Colors::Visual::TrooperNeutralSkeleton );
				GetColorJson( ColorsVisual , XorStr( "SoundStepEsp" ) , Settings::Colors::Visual::SoundStepEsp );
			}
		}
	}
	else
	{
		DEV_LOG( "[error] LoadConfig: %s -> %s , %i\n" , ConfigFilePath.c_str() , rapidjson::GetParseError_En( DocumentConfig.GetParseError() ) , DocumentConfig.GetErrorOffset() );
	}

	DocumentConfig.Clear();
	ConfigFile.close();
}

auto CSettingsJson::SaveConfig( const std::string& JsonFile ) -> void
{
	const auto ConfigFilePath = GetDllDir() + JsonFile;

	std::ofstream ConfigFile( ConfigFilePath );

	rapidjson::OStreamWrapper StreamWrapper( ConfigFile );
	rapidjson::PrettyWriter<rapidjson::OStreamWrapper> ConfigWriter( StreamWrapper );

	ConfigWriter.SetIndent( '\t' , 1 );
	ConfigWriter.SetFormatOptions( rapidjson::PrettyFormatOptions::kFormatSingleLineArray );
	ConfigWriter.SetMaxDecimalPlaces( 2 );

	ConfigWriter.StartObject();
	{
		ConfigWriter.String( XorStr( "Settings" ) );
		{
			ConfigWriter.StartObject();
			{
				ConfigWriter.String( XorStr( "AimPreview" ) );
				{
					ConfigWriter.StartObject();
					{
						AddBoolJson( ConfigWriter , XorStr( "Active" ) , Settings::AimPreview::Active );
						AddBoolJson( ConfigWriter , XorStr( "LegitMode" ) , Settings::AimPreview::LegitMode );
						AddBoolJson( ConfigWriter , XorStr( "ShowFovCircle" ) , Settings::AimPreview::ShowFovCircle );
						AddIntJson( ConfigWriter , XorStr( "TargetBonesMask" ) , Settings::AimPreview::TargetBonesMask );
						AddIntJson( ConfigWriter , XorStr( "HeadChance" ) , Settings::AimPreview::HeadChance );
						AddIntJson( ConfigWriter , XorStr( "TargetPriority" ) , Settings::AimPreview::TargetPriority );
						AddFloatJson( ConfigWriter , XorStr( "PitchSmoothing" ) , Settings::AimPreview::PitchSmoothing );
						AddFloatJson( ConfigWriter , XorStr( "YawSmoothing" ) , Settings::AimPreview::YawSmoothing );
						AddIntJson( ConfigWriter , XorStr( "HitChance" ) , Settings::AimPreview::HitChance );
						AddIntJson( ConfigWriter , XorStr( "FovRadius" ) , Settings::AimPreview::FovRadius );
						AddBoolJson( ConfigWriter , XorStr( "OnlyVisible" ) , Settings::AimPreview::OnlyVisible );
						AddBoolJson( ConfigWriter , XorStr( "SoulSteal" ) , Settings::AimPreview::SoulSteal );
						AddBoolJson( ConfigWriter , XorStr( "AutoParry" ) , Settings::AimPreview::AutoParry );
						AddIntJson( ConfigWriter , XorStr( "AutoParryChance" ) , Settings::AimPreview::AutoParryChance );
						AddIntJson( ConfigWriter , XorStr( "AutoParryTypesMask" ) , Settings::AimPreview::AutoParryTypesMask );
						AddBoolJson( ConfigWriter , XorStr( "TargetHeroes" ) , Settings::AimPreview::TargetHeroes );
						AddBoolJson( ConfigWriter , XorStr( "TargetTroopers" ) , Settings::AimPreview::TargetTroopers );
						AddBoolJson( ConfigWriter , XorStr( "TargetNeutrals" ) , Settings::AimPreview::TargetNeutrals );
						AddBoolJson( ConfigWriter , XorStr( "TargetNpcs" ) , Settings::AimPreview::TargetNpcs );
						AddBoolJson( ConfigWriter , XorStr( "TargetObjectives" ) , Settings::AimPreview::TargetObjectives );
					}
					ConfigWriter.EndObject();
				}

				ConfigWriter.String( XorStr( "Visual" ) );
				{
					ConfigWriter.StartObject();
					{
						AddBoolJson( ConfigWriter , XorStr( "Active" ) , Settings::Visual::Active );
						AddBoolJson( ConfigWriter , XorStr( "OnlyVisible" ) , Settings::Visual::OnlyVisible );
						AddBoolJson( ConfigWriter , XorStr( "HeroTeam" ) , Settings::Visual::HeroTeam );
						AddBoolJson( ConfigWriter , XorStr( "HeroEnemy" ) , Settings::Visual::HeroEnemy );
						AddBoolJson( ConfigWriter , XorStr( "HeroBox" ) , Settings::Visual::HeroBox );
						AddBoolJson( ConfigWriter , XorStr( "HeroSkeleton" ) , Settings::Visual::HeroSkeleton );
						AddBoolJson( ConfigWriter , XorStr( "HeroHealth" ) , Settings::Visual::HeroHealth );
						AddBoolJson( ConfigWriter , XorStr( "SoundStepEsp" ) , Settings::Visual::SoundStepEsp );
						AddBoolJson( ConfigWriter , XorStr( "TrooperTeam" ) , Settings::Visual::TrooperTeam );
						AddBoolJson( ConfigWriter , XorStr( "TrooperEnemy" ) , Settings::Visual::TrooperEnemy );
						AddBoolJson( ConfigWriter , XorStr( "TrooperSkeleton" ) , Settings::Visual::TrooperSkeleton );
						AddBoolJson( ConfigWriter , XorStr( "TrooperNeutral" ) , Settings::Visual::TrooperNeutral );
						AddBoolJson( ConfigWriter , XorStr( "TrooperNeutralSkeleton" ) , Settings::Visual::TrooperNeutralSkeleton );
						AddIntJson( ConfigWriter , XorStr( "HeroBoxType" ) , Settings::Visual::HeroBoxType );
						AddIntJson( ConfigWriter , XorStr( "TrooperBoxType" ) , Settings::Visual::TrooperBoxType );
						AddIntJson( ConfigWriter , XorStr( "TrooperNeutralBoxType" ) , Settings::Visual::TrooperNeutralBoxType );
					}
					ConfigWriter.EndObject();
				}

				ConfigWriter.String( XorStr( "Misc" ) );
				{
					ConfigWriter.StartObject();
					{
						AddBoolJson( ConfigWriter , XorStr( "UnlockMiniMap" ) , Settings::Misc::UnlockMiniMap );
						AddBoolJson( ConfigWriter , XorStr( "ShowCheatOverlay" ) , Settings::Misc::ShowCheatOverlay );
						AddIntJson( ConfigWriter , XorStr( "MenuAlpha" ) , Settings::Misc::MenuAlpha );
						AddIntJson( ConfigWriter , XorStr( "MenuStyle" ) , Settings::Misc::MenuStyle );
						AddBoolJson( ConfigWriter , XorStr( "MenuSounds" ) , Settings::Misc::MenuSounds );
					}
					ConfigWriter.EndObject();
				}

				ConfigWriter.String( XorStr( "Colors" ) );
				{
					ConfigWriter.StartObject();
					{
						ConfigWriter.String( XorStr( "Visual" ) );
						{
							ConfigWriter.StartObject();
							{
								AddColorJson( ConfigWriter , XorStr( "HeroEnemy" ) , Settings::Colors::Visual::HeroEnemy );
								AddColorJson( ConfigWriter , XorStr( "HeroEnemyVisible" ) , Settings::Colors::Visual::HeroEnemyVisible );
								AddColorJson( ConfigWriter , XorStr( "HeroTeam" ) , Settings::Colors::Visual::HeroTeam );
								AddColorJson( ConfigWriter , XorStr( "HeroTeamVisible" ) , Settings::Colors::Visual::HeroTeamVisible );
								AddColorJson( ConfigWriter , XorStr( "HeroSkeleton" ) , Settings::Colors::Visual::HeroSkeleton );
								AddColorJson( ConfigWriter , XorStr( "TrooperEnemy" ) , Settings::Colors::Visual::TrooperEnemy );
								AddColorJson( ConfigWriter , XorStr( "TrooperEnemyVisible" ) , Settings::Colors::Visual::TrooperEnemyVisible );
								AddColorJson( ConfigWriter , XorStr( "TrooperTeam" ) , Settings::Colors::Visual::TrooperTeam );
								AddColorJson( ConfigWriter , XorStr( "TrooperTeamVisible" ) , Settings::Colors::Visual::TrooperTeamVisible );
								AddColorJson( ConfigWriter , XorStr( "TrooperSkeleton" ) , Settings::Colors::Visual::TrooperSkeleton );
								AddColorJson( ConfigWriter , XorStr( "TrooperNeutral" ) , Settings::Colors::Visual::TrooperNeutral );
								AddColorJson( ConfigWriter , XorStr( "TrooperNeutralVisible" ) , Settings::Colors::Visual::TrooperNeutralVisible );
								AddColorJson( ConfigWriter , XorStr( "TrooperNeutralSkeleton" ) , Settings::Colors::Visual::TrooperNeutralSkeleton );
								AddColorJson( ConfigWriter , XorStr( "SoundStepEsp" ) , Settings::Colors::Visual::SoundStepEsp );
							}
							ConfigWriter.EndObject();
						}
					}
					ConfigWriter.EndObject();
				}

			}
			ConfigWriter.EndObject();
		}
	}
	ConfigWriter.EndObject();

	ConfigFile.close();
}

auto CSettingsJson::DeleteConfig( const std::string& JsonFile ) -> void
{
	const auto ConfigFilePath = GetDllDir() + JsonFile;

	DeleteFileA( ConfigFilePath.c_str() );
}

auto CSettingsJson::UpdateConfigList() -> void
{
	m_vecConfigList.clear();

	for ( const auto& Entry : std::filesystem::directory_iterator( GetDllDir().c_str() ) )
	{
		if ( Entry.is_regular_file() )
		{
			if ( Entry.path().extension().string() == XorStr( ".json" ) )
				m_vecConfigList.emplace_back( Entry.path().filename().string() );
		}
	}
}

auto CSettingsJson::GetIntJson( const rapidjson::Value& JsonValue , const char* Name , int& Output , const int Min , const int Max ) -> void
{
	if ( !JsonValue.IsNull() && JsonValue.HasMember( Name ) )
	{
		auto& Value = JsonValue[Name];

		if ( !Value.IsNull() && Value.IsInt() )
		{
			const auto IntValue = Value.GetInt();

			if ( IntValue < Min )
				Output = Min;
			else if ( IntValue > Max )
				Output = Max;
			else
				Output = IntValue;
		}
	}
}

auto CSettingsJson::GetBoolJson( const rapidjson::Value& JsonValue , const char* Name , bool& Output ) -> void
{
	if ( !JsonValue.IsNull() && JsonValue.HasMember( Name ) )
	{
		auto& Value = JsonValue[Name];

		if ( !Value.IsNull() && Value.IsBool() )
			Output = Value.GetBool();
	}
}

auto CSettingsJson::GetFloatJson( const rapidjson::Value& JsonValue , const char* Name , float& Output , const float Min , const float Max ) -> void
{
	if ( !JsonValue.IsNull() && JsonValue.HasMember( Name ) )
	{
		auto& Value = JsonValue[Name];

		if ( !Value.IsNull() && Value.IsFloat() )
		{
			Output = std::clamp( Value.GetFloat() , Min , Max );
		}
	}
}

auto CSettingsJson::GetColorJson( const rapidjson::Value& JsonValue , const char* Name , float* Output ) -> void
{
	if ( !JsonValue.IsNull() && JsonValue.HasMember( Name ) )
	{
		auto& Value = JsonValue[Name];

		if ( !Value.IsNull() && Value.IsArray() && Value.GetArray().Size() == 3 )
		{
			Output[0] = Value.GetArray()[0].GetFloat();
			Output[1] = Value.GetArray()[1].GetFloat();
			Output[2] = Value.GetArray()[2].GetFloat();

			Output[0] = std::clamp( Output[0] , 0.f , 1.f );
			Output[1] = std::clamp( Output[1] , 0.f , 1.f );
			Output[2] = std::clamp( Output[2] , 0.f , 1.f );
		}
	}
}

auto CSettingsJson::AddIntJson( rapidjson::PrettyWriter<rapidjson::OStreamWrapper>& Writer , const char* Name , int& Output ) -> void
{
	Writer.String( Name );
	Writer.Int( Output );
}

auto CSettingsJson::AddUInt64Json( rapidjson::PrettyWriter<rapidjson::OStreamWrapper>& Writer , const char* Name , uint64_t& Output ) -> void
{
	Writer.String( Name );
	Writer.Uint64( Output );
}

auto CSettingsJson::AddBoolJson( rapidjson::PrettyWriter<rapidjson::OStreamWrapper>& Writer , const char* Name , bool& Output ) -> void
{
	Writer.String( Name );
	Writer.Bool( Output );
}

auto CSettingsJson::AddStringJson( rapidjson::PrettyWriter<rapidjson::OStreamWrapper>& Writer , const char* Name , std::string& Output ) -> void
{
	Writer.String( Name );
	Writer.String( Output.c_str() );
}

auto CSettingsJson::AddFloatJson( rapidjson::PrettyWriter<rapidjson::OStreamWrapper>& Writer , const char* Name , float& Output ) -> void
{
	Writer.String( Name );
	Writer.Double( static_cast<double>( Output ) );
}

auto CSettingsJson::AddColorJson( rapidjson::PrettyWriter<rapidjson::OStreamWrapper>& Writer , const char* Name , float* Output ) -> void
{
	Writer.String( Name );
	Writer.StartArray();

	Writer.Double( static_cast<double>( Output[0] ) );
	Writer.Double( static_cast<double>( Output[1] ) );
	Writer.Double( static_cast<double>( Output[2] ) );

	Writer.EndArray();
}

auto GetSettingsJson() -> CSettingsJson*
{
	return &g_CSettingsJson;
}
