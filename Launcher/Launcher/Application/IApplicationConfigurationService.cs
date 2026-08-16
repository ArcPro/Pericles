namespace Launcher.Application;

public interface IApplicationConfigurationService
{
    GameApplicationDefinition GetRequired(string gameSlug);
}
