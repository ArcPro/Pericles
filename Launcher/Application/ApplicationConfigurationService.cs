using System.IO;
using System.Text.RegularExpressions;
using Microsoft.Extensions.Configuration;

namespace Launcher.Application;

public sealed class ApplicationConfigurationService(IConfiguration configuration) : IApplicationConfigurationService
{
    public GameApplicationDefinition GetRequired(string gameSlug)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(gameSlug);
        IConfigurationSection section = configuration.GetSection("Games").GetSection(gameSlug);
        string path = Environment.ExpandEnvironmentVariables(section["ApplicationPath"] ?? "").Trim();
        string processName = (section["ProcessName"] ?? "").Trim();
        string pipeName = (section["PipeName"] ?? "").Trim();
        string identity = (section["ApplicationIdentity"] ?? "").Trim();
        string? entryPoint = string.IsNullOrWhiteSpace(section["EntryPoint"]) ? null : section["EntryPoint"]!.Trim();
        bool validSteamAppId = uint.TryParse(section["SteamAppId"], out uint steamAppId) && steamAppId > 0;
        string[] gameProcessNames = section.GetSection("GameProcessNames")
            .GetChildren()
            .Select(item => Path.GetFileNameWithoutExtension((item.Value ?? "").Trim()))
            .Where(value => value.Length > 0)
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToArray();
        string[] gameExecutableRelativePaths = section.GetSection("GameExecutableRelativePaths")
            .GetChildren()
            .Select(item => (item.Value ?? "").Trim())
            .Where(value => value.Length > 0)
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToArray();
        if (!Path.IsPathFullyQualified(path) && path.Length > 0)
        {
            path = Path.GetFullPath(Path.Combine(AppContext.BaseDirectory, path));
        }
        if (!validSteamAppId
            || gameProcessNames.Length == 0
            || gameProcessNames.Any(value => !Regex.IsMatch(value, "^[A-Za-z0-9._-]{1,120}$"))
            || gameExecutableRelativePaths.Length == 0
            || gameExecutableRelativePaths.Any(value => Path.IsPathFullyQualified(value) || value.Split(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar).Contains(".."))
            || path.Length == 0
            || !Regex.IsMatch(processName, "^[A-Za-z0-9._-]{1,120}$")
            || !Regex.IsMatch(pipeName, "^[A-Za-z0-9._-]{1,120}$")
            || !Regex.IsMatch(identity, "^[A-Za-z0-9._-]{1,80}$")
            || (entryPoint is not null && !Regex.IsMatch(entryPoint, "^[A-Za-z_][A-Za-z0-9_]{0,127}$")))
        {
            throw new ApplicationConfigurationException(gameSlug);
        }
        return new GameApplicationDefinition(
            gameSlug,
            steamAppId,
            gameProcessNames,
            gameExecutableRelativePaths,
            identity,
            Path.GetFullPath(path),
            Path.GetFileNameWithoutExtension(processName),
            pipeName,
            entryPoint);
    }
}
