using System.Net.Http;
using System.IO;
using System.Text;
using Launcher.Application;
using Launcher.Authentication;
using Launcher.Configuration;
using Launcher.Core;
using Launcher.Devices;
using Launcher.IPC;
using Launcher.Launch;
using Launcher.Activation;
using Launcher.Games;
using Launcher.Modules;
using Launcher.Subscriptions;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Options;

namespace Launcher;

public partial class App : System.Windows.Application
{
    private IHost? _host;

    protected override async void OnStartup(System.Windows.StartupEventArgs e)
    {
        base.OnStartup(e);

        if (ModuleHostMode.IsRequested(e.Args))
        {
            ShutdownMode = System.Windows.ShutdownMode.OnExplicitShutdown;
            int exitCode = await ModuleHostMode.RunAsync(e.Args);
            Shutdown(exitCode);
            return;
        }

        DispatcherUnhandledException += OnDispatcherUnhandledException;

        var settings = new HostApplicationBuilderSettings
        {
            Args = e.Args,
            ContentRootPath = AppContext.BaseDirectory
        };
        HostApplicationBuilder builder = Host.CreateApplicationBuilder(settings);
        AddEmbeddedConfiguration(builder.Configuration);

        builder.Services
            .AddOptions<ApiOptions>()
            .Bind(builder.Configuration.GetRequiredSection(ApiOptions.SectionName))
            .Validate(ApiOptions.UsesAllowedTransport, "Api:BaseUrl must use HTTPS, except explicitly allowed loopback development URLs.")
            .Validate(options => options.TimeoutSeconds > 0, "Api:TimeoutSeconds must be greater than zero.")
            .ValidateOnStart();

        builder.Services
            .AddOptions<DeviceOptions>()
            .Bind(builder.Configuration.GetSection(DeviceOptions.SectionName))
            .Validate(options => options.ChallengeTimeoutSeconds is >= 30 and <= 60,
                "Device:ChallengeTimeoutSeconds must be between 30 and 60.")
            .Validate(options => !string.IsNullOrWhiteSpace(options.MutexName),
                "Device:MutexName cannot be empty.")
            .ValidateOnStart();

        builder.Services
            .AddOptions<ModuleOptions>()
            .Bind(builder.Configuration.GetSection(ModuleOptions.SectionName))
            .Validate(options => options.MaxPackageSizeMb is >= 1 and <= 100,
                "Modules:MaxPackageSizeMb must be between 1 and 100.")
            .Validate(options => options.TrustedSigningKeys.Count > 0,
                "At least one embedded module signing key must be trusted.")
            .ValidateOnStart();

        builder.Services
            .AddOptions<LaunchOptions>()
            .Bind(builder.Configuration.GetSection(LaunchOptions.SectionName))
            .Validate(options => options.GameStartTimeoutSeconds is >= 5 and <= 180,
                "Launch:GameStartTimeoutSeconds must be between 5 and 180.")
            .Validate(options => options.ApplicationStartTimeoutSeconds is >= 1 and <= 60,
                "Launch:ApplicationStartTimeoutSeconds must be between 1 and 60.")
            .Validate(options => options.IpcConnectTimeoutSeconds is >= 1 and <= 60,
                "Launch:IpcConnectTimeoutSeconds must be between 1 and 60.")
            .Validate(options => options.ModuleLoadTimeoutSeconds is >= 1 and <= 60,
                "Launch:ModuleLoadTimeoutSeconds must be between 1 and 60.")
            .Validate(options => options.RuntimeCleanupAgeHours is >= 1 and <= 168,
                "Launch:RuntimeCleanupAgeHours must be between 1 and 168.")
            .ValidateOnStart();

        builder.Services.AddHttpClient<IAuthApiClient, AuthApiClient>((services, client) =>
        {
            ApiOptions options = services.GetRequiredService<IOptions<ApiOptions>>().Value;
            client.BaseAddress = new Uri(options.BaseUrl.TrimEnd('/') + '/');
            client.Timeout = TimeSpan.FromSeconds(options.TimeoutSeconds);
            client.DefaultRequestHeaders.Accept.ParseAdd("application/json");
            client.DefaultRequestHeaders.UserAgent.ParseAdd("PericlesLauncher/0.3");
        });
        builder.Services.AddHttpClient<IDeviceApiClient, DeviceApiClient>((services, client) =>
        {
            ApiOptions options = services.GetRequiredService<IOptions<ApiOptions>>().Value;
            client.BaseAddress = new Uri(options.BaseUrl.TrimEnd('/') + '/');
            client.Timeout = TimeSpan.FromSeconds(options.TimeoutSeconds);
            client.DefaultRequestHeaders.Accept.ParseAdd("application/json");
            client.DefaultRequestHeaders.UserAgent.ParseAdd("PericlesLauncher/0.3");
        });
        builder.Services.AddHttpClient<IGameCatalogApiClient, GameCatalogApiClient>(ConfigureApiClient);
        builder.Services.AddHttpClient<IActivationApiClient, ActivationApiClient>(ConfigureApiClient);
        builder.Services.AddHttpClient<ISubscriptionApiClient, SubscriptionApiClient>(ConfigureApiClient);
        builder.Services.AddHttpClient<IModuleApiClient, ModuleApiClient>(ConfigureApiClient);
        builder.Services.AddHttpClient<IGameImageService, RemoteGameImageService>(client =>
        {
            client.Timeout = TimeSpan.FromSeconds(10);
            client.DefaultRequestHeaders.UserAgent.ParseAdd("PericlesLauncher/0.4");
        });

        builder.Services.AddTransient<IAuthenticationService, ApiAuthenticationService>();
        builder.Services.AddSingleton<ISessionService, SessionService>();
        builder.Services.AddSingleton<IDeviceKeyStore, WindowsDpapiDeviceKeyStore>();
        builder.Services.AddSingleton<IHardwareFingerprintProvider, WindowsHardwareFingerprintProvider>();
        builder.Services.AddSingleton<IDeviceIdentityService, DeviceIdentityService>();
        builder.Services.AddSingleton<IDeviceSignatureService, DeviceSignatureService>();
        builder.Services.AddSingleton<DeviceVerificationState>();
        builder.Services.AddSingleton<IDeviceVerificationService, DeviceVerificationService>();
        builder.Services.AddSingleton<IModulePackageParser, ModulePackageParser>();
        builder.Services.AddSingleton<IModuleSigningKeyProvider, EmbeddedModuleSigningKeyProvider>();
        builder.Services.AddSingleton<IModulePackageVerifier, ModulePackageVerifier>();
        builder.Services.AddSingleton<IModuleDecryptor, ModuleDecryptor>();
        builder.Services.AddSingleton<IModuleService, ModuleService>();
        builder.Services.AddSingleton<IApplicationConfigurationService, ApplicationConfigurationService>();
        builder.Services.AddSingleton<IGameProcessService, SteamGameProcessService>();
        builder.Services.AddSingleton<ITargetApplicationService, TargetApplicationService>();
        builder.Services.AddSingleton<IRuntimeModuleStore, RuntimeModuleStore>();
        builder.Services.AddSingleton<IApplicationChannel, NamedPipeApplicationChannel>();
        builder.Services.AddSingleton<IGameLaunchService, GameLaunchService>();
        builder.Services.AddSingleton<LauncherService>();
        builder.Services.AddSingleton<MainWindow>();

        _host = builder.Build();
        await _host.StartAsync();
        await _host.Services.GetRequiredService<IRuntimeModuleStore>().CleanupStaleAsync(CancellationToken.None);

        MainWindow window = _host.Services.GetRequiredService<MainWindow>();
        MainWindow = window;
        window.Show();

        void ConfigureApiClient(IServiceProvider services, HttpClient client)
        {
            ApiOptions options = services.GetRequiredService<IOptions<ApiOptions>>().Value;
            client.BaseAddress = new Uri(options.BaseUrl.TrimEnd('/') + '/');
            client.Timeout = TimeSpan.FromSeconds(options.TimeoutSeconds);
            client.DefaultRequestHeaders.Accept.ParseAdd("application/json");
            client.DefaultRequestHeaders.UserAgent.ParseAdd("PericlesLauncher/0.4");
        }
    }

    private static void AddEmbeddedConfiguration(ConfigurationManager configuration)
    {
        using Stream stream = typeof(App).Assembly.GetManifestResourceStream("Launcher.appsettings.json")
            ?? throw new InvalidOperationException("The embedded launcher configuration is missing.");
        configuration.AddJsonStream(stream);
    }

    private void OnDispatcherUnhandledException(
        object sender,
        System.Windows.Threading.DispatcherUnhandledExceptionEventArgs e)
    {
        string logPath = WriteStartupFailure(e.Exception);
        System.Windows.MessageBox.Show(
            $"Pericles n'a pas pu démarrer. Le diagnostic a été enregistré dans :\n{logPath}",
            "Erreur de démarrage Pericles",
            System.Windows.MessageBoxButton.OK,
            System.Windows.MessageBoxImage.Error);
        e.Handled = true;
        Shutdown(1);
    }

    private static string WriteStartupFailure(Exception exception)
    {
        string directory = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "Pericles",
            "Logs");
        string path = Path.Combine(directory, "launcher-startup.log");
        try
        {
            Directory.CreateDirectory(directory);
            string entry = $"[{DateTimeOffset.Now:O}] {exception}{Environment.NewLine}";
            File.AppendAllText(path, entry, Encoding.UTF8);
        }
        catch
        {
            // Reporting a startup failure must not hide the original error dialog.
        }
        return path;
    }

    protected override void OnExit(System.Windows.ExitEventArgs e)
    {
        if (_host is not null)
        {
            _host.StopAsync().GetAwaiter().GetResult();
            _host.Dispose();
        }

        base.OnExit(e);
    }
}
