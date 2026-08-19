using Launcher.Application;
using Microsoft.Extensions.Configuration;
using Xunit;

namespace Launcher.Tests.Application;

public sealed class ApplicationConfigurationServiceTests
{
    [Fact]
    public void SelfHostedApplicationResolvesToCurrentExecutable()
    {
        IConfiguration configuration = new ConfigurationBuilder()
            .AddInMemoryCollection(new Dictionary<string, string?>
            {
                ["Games:deadlock:SteamAppId"] = "1422450",
                ["Games:deadlock:GameProcessNames:0"] = "deadlock",
                ["Games:deadlock:GameExecutableRelativePaths:0"] = "game\\bin\\win64\\deadlock.exe",
                ["Games:deadlock:ApplicationPath"] = "$self",
                ["Games:deadlock:ProcessName"] = "$self",
                ["Games:deadlock:PipeName"] = "Pericles.Deadlock",
                ["Games:deadlock:ApplicationIdentity"] = "pericles-module-host"
            })
            .Build();

        var service = new ApplicationConfigurationService(configuration);

        GameApplicationDefinition game = service.GetRequired("deadlock");

        Assert.True(game.IsSelfHosted);
        Assert.Equal(Path.GetFullPath(Environment.ProcessPath!), game.ApplicationPath);
        Assert.Equal(Path.GetFileNameWithoutExtension(Environment.ProcessPath), game.ProcessName);
    }

    [Theory]
    [InlineData("Pericles.DemoApplication", "Pericles.DemoApplication")]
    [InlineData("Pericles.DemoApplication.exe", "Pericles.DemoApplication")]
    public void ProcessNameOnlyRemovesExeSuffix(string configured, string expected)
    {
        IConfiguration configuration = new ConfigurationBuilder()
            .AddInMemoryCollection(new Dictionary<string, string?>
            {
                ["Games:deadlock:SteamAppId"] = "1422450",
                ["Games:deadlock:GameProcessNames:0"] = "deadlock",
                ["Games:deadlock:GameExecutableRelativePaths:0"] = "game\\bin\\win64\\deadlock.exe",
                ["Games:deadlock:ApplicationPath"] = "E:\\Apps\\Pericles.DemoApplication.exe",
                ["Games:deadlock:ProcessName"] = configured,
                ["Games:deadlock:PipeName"] = "Pericles.Deadlock",
                ["Games:deadlock:ApplicationIdentity"] = "pericles-demo-application"
            })
            .Build();

        var service = new ApplicationConfigurationService(configuration);

        GameApplicationDefinition game = service.GetRequired("deadlock");

        Assert.Equal(expected, game.ProcessName);
    }
}
