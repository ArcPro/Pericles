using System.Windows;
using System.Windows.Input;
using Launcher.Authentication;
using Launcher.Application;
using Launcher.Core;
using Launcher.Devices;
using Launcher.Entitlements;
using Launcher.Games;
using Launcher.IPC;
using Launcher.Launch;
using Launcher.Modules;
using Microsoft.Extensions.Logging;

namespace Launcher;

public partial class MainWindow : Window
{
    private readonly ISessionService _sessionService;
    private readonly LauncherService _launcherService;
    private readonly IDeviceVerificationService _deviceVerificationService;
    private readonly IGameLaunchService _gameLaunchService;
    private readonly DeviceVerificationState _deviceState;
    private readonly ILogger<MainWindow> _logger;
    private CancellationTokenSource? _operation;

    public MainWindow(
        ISessionService sessionService,
        LauncherService launcherService,
        IDeviceVerificationService deviceVerificationService,
        DeviceVerificationState deviceState,
        IGameLaunchService gameLaunchService,
        IGameImageService imageService,
        ILogger<MainWindow> logger)
    {
        InitializeComponent();
        _sessionService = sessionService;
        _launcherService = launcherService;
        _deviceVerificationService = deviceVerificationService;
        _deviceState = deviceState;
        _gameLaunchService = gameLaunchService;
        _logger = logger;
        AuthenticatedPanel.SetImageService(imageService);
    }

    private async void LoginPanel_LoginRequested(object sender, Views.LoginRequestedEventArgs e)
    {
        if (_operation is not null)
        {
            return;
        }

        _operation = new CancellationTokenSource();
        LoginPanel.SetError(null);
        LoginPanel.SetBusy(true);

        try
        {
            AuthSession session = await _sessionService.LoginAsync(e.Email, e.Password, _operation.Token);
            LoginPanel.ClearPassword();
            DeviceSession deviceSession = await _deviceVerificationService.VerifyCurrentDeviceAsync(session, _operation.Token);
            LauncherHomeData home = await _launcherService.PrepareAuthenticatedHomeAsync(session, _operation.Token);
            AuthenticatedPanel.ShowSession(session, home, deviceSession);
            ExpandForDashboard();
            LoginPanel.Visibility = Visibility.Collapsed;
            AuthenticatedPanel.Visibility = Visibility.Visible;
        }
        catch (AuthApiException exception)
        {
            LoginPanel.SetError(exception.Error switch
            {
                AuthApiError.InvalidCredentials => "Email ou mot de passe incorrect.",
                AuthApiError.AccountDisabled => "Ce compte est actuellement indisponible.",
                AuthApiError.RateLimited => "Trop de tentatives. Réessayez dans quelques minutes.",
                AuthApiError.Timeout => "Le serveur met trop de temps à répondre.",
                AuthApiError.Unavailable => "Impossible de contacter le serveur.",
                _ => "Une erreur est survenue pendant la connexion."
            });
        }
        catch (DeviceIdentityCorruptedException)
        {
            _sessionService.ClearLocalSession();
            _deviceState.Clear();
            LoginPanel.SetError("L’identité locale de cet appareil est invalide.");
        }
        catch (DeviceApiException exception)
        {
            _sessionService.ClearLocalSession();
            _deviceState.Clear();
            LoginPanel.SetError(DeviceErrorMessage(exception.Error));
        }
        catch (EntitlementApiException)
        {
            _sessionService.ClearLocalSession();
            _deviceState.Clear();
            LoginPanel.SetError("Impossible de charger le catalogue.");
        }
        catch (OperationCanceledException)
        {
        }
        catch (Exception exception)
        {
            _sessionService.ClearLocalSession();
            _deviceState.Clear();
            _logger.LogError("Device verification failed with {ExceptionType}.", exception.GetType().Name);
            LoginPanel.SetError("Impossible de vérifier cet appareil.");
        }
        finally
        {
            LoginPanel.ClearPassword();
            LoginPanel.SetBusy(false);
            _operation.Dispose();
            _operation = null;
        }
    }

    private async void AuthenticatedPanel_ActivationRequested(object sender, Views.ActivationRequestedEventArgs e)
    {
        if (_operation is not null || _sessionService.CurrentSession is not AuthSession session)
        {
            return;
        }
        _operation = new CancellationTokenSource(TimeSpan.FromSeconds(20));
        AuthenticatedPanel.SetEntitlementBusy(true);
        AuthenticatedPanel.SetActivationFeedback(null);
        try
        {
            LauncherHomeData home = await _launcherService.RedeemAndRefreshAsync(session, e.Key, _operation.Token);
            AuthenticatedPanel.UpdateHome(home);
            AuthenticatedPanel.CloseActivation();
            AuthenticatedPanel.SetGameFeedback("Clé activée. Le catalogue a été actualisé.");
        }
        catch (EntitlementApiException exception)
        {
            AuthenticatedPanel.SetActivationFeedback(EntitlementErrorMessage(exception.Error));
        }
        catch (OperationCanceledException)
        {
        }
        catch (Exception exception)
        {
            _logger.LogError("Activation failed with {ExceptionType}.", exception.GetType().Name);
            AuthenticatedPanel.SetActivationFeedback("Impossible d’activer cette clé.");
        }
        finally
        {
            AuthenticatedPanel.SetEntitlementBusy(false);
            _operation.Dispose();
            _operation = null;
        }
    }

    private async void AuthenticatedPanel_GameActionRequested(object sender, Views.GameActionRequestedEventArgs e)
    {
        if (_operation is not null || _sessionService.CurrentSession is not AuthSession session)
        {
            return;
        }
        _operation = new CancellationTokenSource(TimeSpan.FromSeconds(150));
        AuthenticatedPanel.SetEntitlementBusy(true);
        AuthenticatedPanel.SetModulePreparationBusy(e.Slug, true);
        try
        {
            if (e.State == GameAccessState.ReadyToBind)
            {
                LauncherHomeData home = await _launcherService.BindAndRefreshAsync(session, e.Slug, _operation.Token);
                AuthenticatedPanel.UpdateHome(home);
                AuthenticatedPanel.SetGameFeedback("Abonnement activé sur cet appareil.");
            }
            else if (e.State == GameAccessState.Available)
            {
                var progress = new Progress<GameLaunchStage>(stage =>
                    AuthenticatedPanel.SetGameLaunchStage(e.Slug, stage));
                GameLaunchResult result = await _gameLaunchService.LaunchAsync(
                    session,
                    e.Slug,
                    progress,
                    _operation.Token);
                AuthenticatedPanel.SetGameLaunchStage(e.Slug, GameLaunchStage.Active);
                AuthenticatedPanel.SetGameFeedback(
                    $"Deadlock démarré (PID {result.GameProcessId}). Module {result.Version} actif dans l’application Pericles (PID {result.ModuleHostProcessId}).");
            }
        }
        catch (EntitlementApiException exception)
        {
            AuthenticatedPanel.SetGameFeedback(EntitlementErrorMessage(exception.Error), error: true);
        }
        catch (ModuleException exception)
        {
            _logger.LogWarning("Module preparation failed with {ExceptionType}.", exception.GetType().Name);
            AuthenticatedPanel.SetGameFeedback(ModuleErrorMessage(exception), error: true);
        }
        catch (GameAuthorizationChangedException exception)
        {
            AuthenticatedPanel.SetGameFeedback(LaunchAuthorizationMessage(exception.State), error: true);
        }
        catch (GameProcessException exception)
        {
            _logger.LogWarning("Game process launch failed with {ExceptionType}.", exception.GetType().Name);
            AuthenticatedPanel.SetGameFeedback(GameProcessErrorMessage(exception), error: true);
        }
        catch (ApplicationLaunchException exception)
        {
            _logger.LogWarning("Owned application launch failed with {ExceptionType}.", exception.GetType().Name);
            AuthenticatedPanel.SetGameFeedback(ApplicationErrorMessage(exception), error: true);
        }
        catch (ApplicationChannelException exception)
        {
            _logger.LogWarning("Application IPC failed with {ExceptionType}.", exception.GetType().Name);
            AuthenticatedPanel.SetGameFeedback(ApplicationChannelErrorMessage(exception), error: true);
        }
        catch (OperationCanceledException)
        {
        }
        catch (Exception exception)
        {
            _logger.LogError("Game action failed with {ExceptionType}.", exception.GetType().Name);
            AuthenticatedPanel.SetGameFeedback("Impossible de préparer le module.", error: true);
        }
        finally
        {
            AuthenticatedPanel.SetEntitlementBusy(false);
            AuthenticatedPanel.SetModulePreparationBusy(e.Slug, false);
            _operation.Dispose();
            _operation = null;
        }
    }

    private async void AuthenticatedPanel_DeviceRevokeRequested(object sender, Views.DeviceRevokeRequestedEventArgs e)
    {
        if (_operation is not null || _sessionService.CurrentSession is not AuthSession authSession)
        {
            return;
        }

        MessageBoxResult confirmation = MessageBox.Show(
            this,
            $"Retirer « {e.DisplayName} » de vos appareils autorisés ?",
            "Retirer l’appareil",
            MessageBoxButton.YesNo,
            MessageBoxImage.Question);
        if (confirmation != MessageBoxResult.Yes)
        {
            return;
        }

        _operation = new CancellationTokenSource(TimeSpan.FromSeconds(15));
        AuthenticatedPanel.SetDeviceOperationBusy(true);
        try
        {
            await _deviceVerificationService.RevokeDeviceAsync(authSession, e.DeviceId, _operation.Token);
            IReadOnlyList<DeviceInfo> devices = await _deviceVerificationService.RefreshDevicesAsync(authSession, _operation.Token);
            AuthenticatedPanel.UpdateDevices(devices);
            AuthenticatedPanel.SetDeviceFeedback("Appareil retiré.", isError: false);
        }
        catch (DeviceApiException exception)
        {
            AuthenticatedPanel.SetDeviceFeedback(DeviceErrorMessage(exception.Error), isError: true);
        }
        catch (OperationCanceledException)
        {
        }
        catch (Exception exception)
        {
            _logger.LogError("Device revocation failed with {ExceptionType}.", exception.GetType().Name);
            AuthenticatedPanel.SetDeviceFeedback("Impossible de retirer cet appareil.", isError: true);
        }
        finally
        {
            AuthenticatedPanel.SetDeviceOperationBusy(false);
            _operation.Dispose();
            _operation = null;
        }
    }

    private async void AuthenticatedPanel_RefreshRequested(object? sender, EventArgs e)
    {
        if (_operation is not null || _sessionService.CurrentSession is not AuthSession session)
        {
            return;
        }
        _operation = new CancellationTokenSource(TimeSpan.FromSeconds(20));
        AuthenticatedPanel.SetEntitlementBusy(true);
        try
        {
            LauncherHomeData home = await _launcherService.PrepareAuthenticatedHomeAsync(session, _operation.Token);
            AuthenticatedPanel.UpdateHome(home);
            AuthenticatedPanel.SetGameFeedback("Catalogue actualisé.");
        }
        catch (EntitlementApiException exception)
        {
            AuthenticatedPanel.SetGameFeedback(EntitlementErrorMessage(exception.Error), error: true);
        }
        catch (OperationCanceledException)
        {
        }
        finally
        {
            AuthenticatedPanel.SetEntitlementBusy(false);
            _operation.Dispose();
            _operation = null;
        }
    }

    private async void AuthenticatedPanel_LogoutRequested(object sender, EventArgs e)
    {
        if (_operation is not null)
        {
            return;
        }

        _operation = new CancellationTokenSource(TimeSpan.FromSeconds(10));
        AuthenticatedPanel.SetBusy(true);
        try
        {
            await _sessionService.LogoutAsync(_operation.Token);
        }
        catch (AuthApiException)
        {
            // SessionService clears the local session even when server revocation fails.
        }
        finally
        {
            _deviceState.Clear();
            AuthenticatedPanel.Reset();
            AuthenticatedPanel.Visibility = Visibility.Collapsed;
            LoginPanel.Visibility = Visibility.Visible;
            RestoreLoginSize();
            LoginPanel.FocusEmail();
            _operation.Dispose();
            _operation = null;
        }
    }

    private static string DeviceErrorMessage(DeviceApiError error) => error switch
    {
        DeviceApiError.DeviceLimitReached => "Nombre maximal d’appareils atteint. Retirez un ancien appareil depuis votre profil.",
        DeviceApiError.DeviceRevoked => "Cet appareil n’est plus autorisé.",
        DeviceApiError.DeviceClaimed => "Cet appareil est déjà associé à un autre compte.",
        DeviceApiError.PublicKeyMismatch => "L’identité enregistrée de cet appareil ne correspond plus.",
        DeviceApiError.Timeout or DeviceApiError.Unavailable => "Impossible de vérifier cet appareil.",
        _ => "La vérification de cet appareil a échoué."
    };

    private static string EntitlementErrorMessage(EntitlementApiError error) => error switch
    {
        EntitlementApiError.InvalidKey => "Clé d’activation invalide.",
        EntitlementApiError.KeyUsed => "Cette clé a déjà été utilisée.",
        EntitlementApiError.KeyExpired => "Cette clé a expiré.",
        EntitlementApiError.KeyRevoked => "Cette clé a été révoquée.",
        EntitlementApiError.AlreadyLifetime => "Cet abonnement est déjà valable à vie.",
        EntitlementApiError.RateLimited => "Trop de tentatives d’activation. Réessayez plus tard.",
        EntitlementApiError.BoundElsewhere => "Cet abonnement est lié à un autre appareil.",
        EntitlementApiError.SubscriptionRequired => "Un abonnement actif est requis.",
        EntitlementApiError.DeviceVerificationRequired => "Cet appareil doit être vérifié.",
        EntitlementApiError.Timeout or EntitlementApiError.Unavailable => "Impossible de contacter le serveur.",
        _ => "La demande n’a pas pu être traitée."
    };

    private static string ModuleErrorMessage(ModuleException exception) => exception switch
    {
        ModuleAuthorizationException { ErrorCode: "subscription_not_bound" } =>
            "Activez d’abord l’abonnement sur cet appareil.",
        ModuleAuthorizationException { ErrorCode: "subscription_bound_elsewhere" } =>
            "Cet abonnement est lié à un autre appareil.",
        ModuleAuthorizationException { ErrorCode: "subscription_expired" } =>
            "Cet abonnement a expiré.",
        ModuleAuthorizationException { ErrorCode: "subscription_suspended" } =>
            "Cet abonnement est suspendu.",
        ModuleAuthorizationException { ErrorCode: "module_unavailable" or "module_not_found" } =>
            "Aucun module n’est actuellement disponible.",
        ModuleTicketExpiredException => "L’autorisation a expiré. Réessayez.",
        InvalidModulePackageException or InvalidModuleSignatureException or ModuleDecryptionFailedException
            or ModuleHashMismatchException or ModuleGameMismatchException =>
            "Le module reçu n’a pas passé les contrôles de sécurité.",
        _ => "Impossible de préparer le module."
    };

    private static string LaunchAuthorizationMessage(GameAccessState state) => state switch
    {
        GameAccessState.Expired => "L’abonnement a expiré avant le lancement.",
        GameAccessState.Suspended => "L’abonnement est suspendu.",
        GameAccessState.BoundElsewhere => "Cet abonnement est lié à un autre appareil.",
        GameAccessState.ReadyToBind => "Activez d’abord l’abonnement sur cet appareil.",
        _ => "Ce jeu n’est plus autorisé."
    };

    private static string ApplicationErrorMessage(ApplicationLaunchException exception) => exception switch
    {
        ApplicationConfigurationException or ApplicationNotFoundException => "Application introuvable.",
        ApplicationSessionUnavailableException => "Fermez l’application déjà ouverte puis relancez-la depuis Pericles.",
        ApplicationCrashedException => "L’application s’est arrêtée pendant le lancement.",
        _ => "Impossible de démarrer l’application."
    };

    private static string GameProcessErrorMessage(GameProcessException exception) => exception switch
    {
        GameInstallationNotFoundException => "Deadlock est introuvable dans les bibliothèques Steam.",
        GameStartTimeoutException => "Deadlock n’a pas démarré dans le délai prévu.",
        _ => "Impossible de démarrer Deadlock via Steam."
    };

    private static string ApplicationChannelErrorMessage(ApplicationChannelException exception) => exception switch
    {
        ModuleRestartRequiredException => "Une version différente est déjà chargée. Redémarrez l’application.",
        ModuleLoadFailedException => "Le chargement du module a échoué.",
        ApplicationIdentityMismatchException => "L’application connectée ne correspond pas à l’exécutable configuré.",
        ApplicationHandshakeException => "L’application a refusé la connexion sécurisée.",
        _ => "Impossible de se connecter à l’application."
    };

    private void TitleBar_MouseLeftButtonDown(object sender, MouseButtonEventArgs e)
    {
        if (e.ButtonState == MouseButtonState.Pressed)
        {
            DragMove();
        }
    }

    private void Minimize_Click(object sender, RoutedEventArgs e) => WindowState = WindowState.Minimized;

    private void ExpandForDashboard()
    {
        Rect workArea = SystemParameters.WorkArea;
        ResizeMode = ResizeMode.CanResize;
        MinWidth = 980;
        MinHeight = 640;
        Width = Math.Min(1280, workArea.Width - 40);
        Height = Math.Min(780, workArea.Height - 40);
        Left = workArea.Left + (workArea.Width - Width) / 2;
        Top = workArea.Top + (workArea.Height - Height) / 2;
    }

    private void RestoreLoginSize()
    {
        Rect workArea = SystemParameters.WorkArea;
        ResizeMode = ResizeMode.NoResize;
        MinWidth = 420;
        MinHeight = 540;
        Width = 460;
        Height = 560;
        Left = workArea.Left + (workArea.Width - Width) / 2;
        Top = workArea.Top + (workArea.Height - Height) / 2;
    }

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        _operation?.Cancel();
        Close();
    }
}
