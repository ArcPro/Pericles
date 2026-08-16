namespace Launcher.Modules;

public sealed class ModuleOptions
{
    public const string SectionName = "Modules";

    public int MaxPackageSizeMb { get; init; } = 50;

    public Dictionary<string, string> TrustedSigningKeys { get; init; } = new(StringComparer.Ordinal);
}
