using System.Globalization;
using System.IO;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using Launcher.Authentication;
using Launcher.Core;
using Launcher.Devices;
using Launcher.Games;
using Launcher.Launch;
using Launcher.Subscriptions;

namespace Launcher.Views;

public sealed class DeviceRevokeRequestedEventArgs(string deviceId, string displayName) : EventArgs
{
    public string DeviceId { get; } = deviceId;
    public string DisplayName { get; } = displayName;
}

public sealed class ActivationRequestedEventArgs(string key) : EventArgs
{
    public string Key { get; } = key;
}

public sealed class GameActionRequestedEventArgs(string slug, GameAccessState state) : EventArgs
{
    public string Slug { get; } = slug;
    public GameAccessState State { get; } = state;
}

public partial class AuthenticatedView : UserControl
{
    private string _email = string.Empty;
    private bool _emailVisible = true;
    private IGameImageService? _imageService;
    private readonly Dictionary<string, List<Button>> _gameButtons = new(StringComparer.Ordinal);
    private readonly HashSet<string> _activeGames = new(StringComparer.Ordinal);

    public AuthenticatedView() => InitializeComponent();

    public event EventHandler? LogoutRequested;
    public event EventHandler<DeviceRevokeRequestedEventArgs>? DeviceRevokeRequested;
    public event EventHandler<ActivationRequestedEventArgs>? ActivationRequested;
    public event EventHandler<GameActionRequestedEventArgs>? GameActionRequested;
    public event EventHandler? RefreshRequested;

    public void SetImageService(IGameImageService imageService) => _imageService = imageService;

    public void ShowSession(AuthSession session, LauncherHomeData home, DeviceSession deviceSession)
    {
        ArgumentNullException.ThrowIfNull(session);
        _email = session.Email;
        string playerName = CreatePlayerName(session.Email);
        PlayerNameText.Text = ProfileNameText.Text = playerName;
        ProfileEmailText.Text = session.Email;
        GradeText.Text = ProfileGradeText.Text = "MEMBRE";
        AvatarLetter.Text = playerName[..1].ToUpperInvariant();
        _emailVisible = true;
        HideEmailCheckBox.IsChecked = false;
        UpdateEmailDisplay();
        UpdateHome(home);
        UpdateDevices(deviceSession.Devices);
        GamesNavigation.IsChecked = true;
        ShowPage("Games");
    }

    public void UpdateHome(LauncherHomeData home)
    {
        RenderGames(home.Games);
        RenderSubscriptions(home.Subscriptions);
    }

    private void RenderGames(IReadOnlyList<GameInfo> games)
    {
        GamesPanel.Children.Clear();
        _gameButtons.Clear();
        GameInfo? featured = games.OrderBy(game => game.SortOrder).FirstOrDefault();
        FeaturedCard.Visibility = featured is null ? Visibility.Collapsed : Visibility.Visible;
        if (featured is not null)
        {
            FeaturedName.Text = featured.Name;
            FeaturedDescription.Text = featured.ShortDescription;
            FeaturedState.Text = StateText(featured.Access.State).ToUpperInvariant();
            FeaturedState.Foreground = StateBrush(featured.Access.State);
            FeaturedAction.Content = ActionText(featured.Access.State);
            FeaturedAction.Tag = featured;
            FeaturedAction.IsEnabled = featured.Access.State != GameAccessState.BoundElsewhere;
            RegisterGameButton(featured.Slug, FeaturedAction);
            FeaturedImage.Source = null;
            FeaturedImagePlaceholder.Visibility = Visibility.Visible;
            _ = LoadImageAsync(featured.ImageUrl, FeaturedImage, FeaturedImagePlaceholder);
        }
        foreach (GameInfo game in games.OrderBy(game => game.SortOrder))
        {
            GamesPanel.Children.Add(CreateGameCard(game));
        }
        if (games.Count == 0)
        {
            GamesPanel.Children.Add(new TextBlock
            {
                Text = "Aucun jeu disponible.",
                Foreground = (Brush)FindResource("MutedTextBrush")
            });
        }
    }

    private Border CreateGameCard(GameInfo game)
    {
        var image = new Image
        {
            Stretch = Stretch.UniformToFill,
            Opacity = game.Access.State is GameAccessState.Expired or GameAccessState.Suspended ? .35 : .82
        };
        var placeholder = new Border
        {
            Background = new SolidColorBrush(Color.FromRgb(31, 25, 43)),
            Child = new TextBlock
            {
                Text = game.Name[..1].ToUpperInvariant(),
                FontSize = 42,
                FontWeight = FontWeights.Bold,
                Foreground = new SolidColorBrush(Color.FromRgb(105, 75, 151)),
                HorizontalAlignment = HorizontalAlignment.Center,
                VerticalAlignment = VerticalAlignment.Center
            }
        };
        var imageGrid = new Grid();
        imageGrid.Children.Add(placeholder);
        imageGrid.Children.Add(image);
        _ = LoadImageAsync(game.ImageUrl, image, placeholder);

        var action = new Button
        {
            Content = ActionText(game.Access.State),
            Tag = game,
            Height = 34,
            Margin = new Thickness(12, 8, 12, 12),
            Style = (Style)FindResource(game.Access.State is GameAccessState.ReadyToBind or GameAccessState.Available
                ? "ActionButtonStyle"
                : "GhostButtonStyle"),
            IsEnabled = game.Access.State != GameAccessState.BoundElsewhere
        };
        action.Click += GameAction_Click;
        RegisterGameButton(game.Slug, action);

        var details = new StackPanel { Margin = new Thickness(12, 10, 12, 0) };
        details.Children.Add(new TextBlock
        {
            Text = game.Name,
            FontSize = 15,
            FontWeight = FontWeights.SemiBold,
            TextTrimming = TextTrimming.CharacterEllipsis
        });
        details.Children.Add(new TextBlock
        {
            Text = StateText(game.Access.State),
            Margin = new Thickness(0, 4, 0, 0),
            FontSize = 11,
            Foreground = StateBrush(game.Access.State)
        });
        details.Children.Add(new TextBlock
        {
            Text = ExpirationText(game.Access.ExpiresAt),
            Margin = new Thickness(0, 3, 0, 0),
            FontSize = 10,
            Foreground = (Brush)FindResource("MutedTextBrush")
        });

        var grid = new Grid { Background = new SolidColorBrush(Color.FromRgb(24, 21, 32)) };
        grid.RowDefinitions.Add(new RowDefinition { Height = new GridLength(155) });
        grid.RowDefinitions.Add(new RowDefinition { Height = new GridLength(74) });
        grid.RowDefinitions.Add(new RowDefinition { Height = new GridLength(54) });
        grid.Children.Add(imageGrid);
        Grid.SetRow(details, 1);
        grid.Children.Add(details);
        Grid.SetRow(action, 2);
        grid.Children.Add(action);
        return new Border
        {
            Width = 225,
            Height = 285,
            Margin = new Thickness(0, 0, 16, 16),
            CornerRadius = new CornerRadius(10),
            ClipToBounds = true,
            BorderBrush = new SolidColorBrush(Color.FromRgb(64, 49, 83)),
            BorderThickness = new Thickness(1),
            Child = grid
        };
    }

    private async Task LoadImageAsync(string url, Image target, Border placeholder)
    {
        if (_imageService is null)
        {
            return;
        }
        byte[]? bytes = await _imageService.DownloadAsync(url, CancellationToken.None);
        if (bytes is null)
        {
            return;
        }
        try
        {
            using var stream = new MemoryStream(bytes);
            var bitmap = new BitmapImage();
            bitmap.BeginInit();
            bitmap.CacheOption = BitmapCacheOption.OnLoad;
            bitmap.StreamSource = stream;
            bitmap.EndInit();
            bitmap.Freeze();
            target.Source = bitmap;
            placeholder.Visibility = Visibility.Collapsed;
        }
        catch (Exception exception) when (exception is NotSupportedException or IOException)
        {
        }
    }

    private void RenderSubscriptions(IReadOnlyList<SubscriptionInfo> subscriptions)
    {
        SubscriptionsPanel.Children.Clear();
        if (subscriptions.Count == 0)
        {
            SubscriptionsPanel.Children.Add(new TextBlock
            {
                Text = "Aucun abonnement.",
                Foreground = (Brush)FindResource("MutedTextBrush")
            });
            return;
        }
        foreach (SubscriptionInfo subscription in subscriptions)
        {
            string expiry = subscription.ExpiresAt is null
                ? "À vie"
                : subscription.ExpiresAt.Value.ToLocalTime().ToString("dd/MM/yyyy", CultureInfo.GetCultureInfo("fr-FR"));
            string binding = subscription.DeviceBinding.State switch
            {
                "current_device" => "PC actuel",
                "other_device" => "Autre appareil",
                _ => "Non activé"
            };
            var row = new Grid();
            row.ColumnDefinitions.Add(new ColumnDefinition());
            row.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
            var stack = new StackPanel();
            stack.Children.Add(new TextBlock { Text = subscription.Product.Name, FontWeight = FontWeights.SemiBold });
            stack.Children.Add(new TextBlock
            {
                Text = $"Expire : {expiry}  ·  Appareil : {binding}",
                Margin = new Thickness(0, 5, 0, 0),
                FontSize = 11,
                Foreground = (Brush)FindResource("MutedTextBrush")
            });
            row.Children.Add(stack);
            var status = new TextBlock
            {
                Text = subscription.Status.ToUpperInvariant(),
                Foreground = subscription.Status == "suspended"
                    ? (Brush)FindResource("ErrorBrush")
                    : (Brush)FindResource("SuccessBrush"),
                FontSize = 10,
                FontWeight = FontWeights.Bold,
                VerticalAlignment = VerticalAlignment.Center
            };
            Grid.SetColumn(status, 1);
            row.Children.Add(status);
            SubscriptionsPanel.Children.Add(Card(row));
        }
    }

    public void UpdateDevices(IReadOnlyList<DeviceInfo> devices)
    {
        DevicesPanel.Children.Clear();
        foreach (DeviceInfo device in devices)
        {
            var grid = new Grid();
            grid.ColumnDefinitions.Add(new ColumnDefinition());
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
            var details = new StackPanel();
            details.Children.Add(new TextBlock { Text = device.DisplayName, FontWeight = FontWeights.SemiBold });
            details.Children.Add(new TextBlock
            {
                Text = device.IsCurrent ? "● Cet appareil · maintenant" : "Dernière activité : " + FormatLastSeen(device.LastSeenAt),
                Margin = new Thickness(0, 4, 0, 0),
                FontSize = 11,
                Foreground = device.IsCurrent
                    ? (Brush)FindResource("SuccessBrush")
                    : (Brush)FindResource("MutedTextBrush")
            });
            grid.Children.Add(details);
            if (!device.IsCurrent)
            {
                var remove = new Button
                {
                    Content = "RETIRER",
                    Tag = device,
                    Style = (Style)FindResource("GhostButtonStyle"),
                    Height = 34,
                    VerticalAlignment = VerticalAlignment.Center
                };
                remove.Click += RemoveDevice_Click;
                Grid.SetColumn(remove, 1);
                grid.Children.Add(remove);
            }
            DevicesPanel.Children.Add(Card(grid));
        }
    }

    private Border Card(UIElement child) => new()
    {
        Padding = new Thickness(17, 13, 17, 13),
        Margin = new Thickness(0, 0, 0, 8),
        CornerRadius = new CornerRadius(9),
        Background = new SolidColorBrush(Color.FromRgb(23, 20, 31)),
        BorderBrush = new SolidColorBrush(Color.FromRgb(57, 46, 73)),
        BorderThickness = new Thickness(1),
        Child = child
    };

    public void SetDeviceOperationBusy(bool busy)
    {
        foreach (Button button in DevicesPanel.Children.OfType<Border>()
                     .SelectMany(border => (border.Child as Grid)?.Children.OfType<Button>() ?? []))
        {
            button.IsEnabled = !busy;
        }
    }

    public void SetDeviceFeedback(string? message, bool isError)
    {
        DeviceFeedbackText.Text = message ?? "";
        DeviceFeedbackText.Foreground = isError
            ? (Brush)FindResource("ErrorBrush")
            : new SolidColorBrush(Color.FromRgb(184, 149, 245));
        DeviceFeedbackText.Visibility = string.IsNullOrWhiteSpace(message) ? Visibility.Collapsed : Visibility.Visible;
    }

    public void SetBusy(bool busy)
    {
        LogoutButton.IsEnabled = !busy;
        LogoutButton.Content = busy ? "DÉCONNEXION..." : "↪   SE DÉCONNECTER";
    }

    public void SetEntitlementBusy(bool busy)
    {
        ActivationSubmitButton.IsEnabled = !busy;
        GamesPanel.IsEnabled = !busy;
        FeaturedAction.IsEnabled = !busy && FeaturedAction.Tag is GameInfo game
            && game.Access.State != GameAccessState.BoundElsewhere;
        ActivationSubmitButton.Content = busy ? "VÉRIFICATION..." : "ACTIVER";
    }

    public void SetModulePreparationBusy(string gameSlug, bool busy)
    {
        if (!_gameButtons.TryGetValue(gameSlug, out List<Button>? buttons)) return;
        foreach (Button button in buttons)
        {
            button.IsEnabled = !busy && !_activeGames.Contains(gameSlug);
            if (button.Tag is GameInfo game)
            {
                button.Content = busy
                    ? "PRÉPARATION..."
                    : _activeGames.Contains(gameSlug) ? "● ACTIF" : ActionText(game.Access.State);
            }
        }
    }

    public void SetGameLaunchStage(string gameSlug, GameLaunchStage stage)
    {
        if (stage == GameLaunchStage.Active) _activeGames.Add(gameSlug);
        if (!_gameButtons.TryGetValue(gameSlug, out List<Button>? buttons)) return;
        string label = stage switch
        {
            GameLaunchStage.RefreshingAuthorization or GameLaunchStage.PreparingModule
                or GameLaunchStage.WritingRuntimeModule => "PRÉPARATION...",
            GameLaunchStage.LocatingGame => "RECHERCHE DU JEU...",
            GameLaunchStage.StartingGame => "DÉMARRAGE DU JEU...",
            GameLaunchStage.GameRunning => "JEU DÉMARRÉ...",
            GameLaunchStage.StartingApplication => "DÉMARRAGE APP...",
            GameLaunchStage.ConnectingApplication => "CONNEXION...",
            GameLaunchStage.LoadingModule => "CHARGEMENT...",
            GameLaunchStage.Active => "● ACTIF",
            _ => "PRÉPARATION..."
        };
        foreach (Button button in buttons)
        {
            button.Content = label;
            button.IsEnabled = false;
        }
    }

    public void SetActivationFeedback(string? message)
    {
        ActivationFeedbackText.Text = message ?? "";
        ActivationFeedbackText.Visibility = string.IsNullOrWhiteSpace(message) ? Visibility.Collapsed : Visibility.Visible;
    }

    public void SetGameFeedback(string message, bool error = false)
    {
        GameFeedbackText.Text = message;
        GameFeedbackText.Foreground = error
            ? (Brush)FindResource("ErrorBrush")
            : new SolidColorBrush(Color.FromRgb(184, 149, 245));
        GameFeedbackText.Visibility = Visibility.Visible;
    }

    public void CloseActivation()
    {
        ActivationOverlay.Visibility = Visibility.Collapsed;
        ActivationKeyText.Clear();
        SetActivationFeedback(null);
    }

    public void Reset()
    {
        _email = "";
        FeaturedCard.Visibility = Visibility.Collapsed;
        _gameButtons.Clear();
        _activeGames.Clear();
        GamesPanel.Children.Clear();
        SubscriptionsPanel.Children.Clear();
        DevicesPanel.Children.Clear();
        CloseActivation();
        SetBusy(false);
    }

    private void Navigation_Checked(object sender, RoutedEventArgs e)
    {
        if (sender is RadioButton { Tag: string page } && GamesPage is not null)
        {
            ShowPage(page);
        }
    }

    private void ShowPage(string page)
    {
        GamesPage.Visibility = page == "Games" ? Visibility.Visible : Visibility.Collapsed;
        ProfilePage.Visibility = page == "Profile" ? Visibility.Visible : Visibility.Collapsed;
        SettingsPage.Visibility = page == "Settings" ? Visibility.Visible : Visibility.Collapsed;
        SupportPage.Visibility = page == "Support" ? Visibility.Visible : Visibility.Collapsed;
    }

    private void ToggleEmailButton_Click(object sender, RoutedEventArgs e)
    {
        _emailVisible = !_emailVisible;
        HideEmailCheckBox.IsChecked = !_emailVisible;
        UpdateEmailDisplay();
    }

    private void HideEmailCheckBox_Changed(object sender, RoutedEventArgs e)
    {
        if (EmailText is null) return;
        _emailVisible = HideEmailCheckBox.IsChecked != true;
        UpdateEmailDisplay();
    }

    private void UpdateEmailDisplay()
    {
        EmailText.Text = _emailVisible ? _email : MaskEmail(_email);
        ToggleEmailButton.Content = _emailVisible ? "Masquer l’email" : "Afficher l’email";
    }

    private void OpenActivation_Click(object sender, RoutedEventArgs e)
    {
        ActivationOverlay.Visibility = Visibility.Visible;
        ActivationKeyText.Focus();
    }

    private void Refresh_Click(object sender, RoutedEventArgs e) => RefreshRequested?.Invoke(this, EventArgs.Empty);

    private void CloseActivation_Click(object sender, RoutedEventArgs e) => CloseActivation();

    private void ActivationSubmit_Click(object sender, RoutedEventArgs e)
    {
        string key = ActivationKeyText.Text.Trim();
        if (key == "")
        {
            SetActivationFeedback("Saisissez une clé d’activation.");
            return;
        }
        ActivationRequested?.Invoke(this, new ActivationRequestedEventArgs(key));
    }

    private void GameAction_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not Button { Tag: GameInfo game }) return;
        if (game.Access.State is GameAccessState.Locked or GameAccessState.Expired or GameAccessState.Suspended)
        {
            OpenActivation_Click(sender, e);
            return;
        }
        if (game.Access.State == GameAccessState.ReadyToBind)
        {
            GameActionRequested?.Invoke(this, new GameActionRequestedEventArgs(game.Slug, game.Access.State));
            return;
        }
        if (game.Access.State == GameAccessState.Available)
        {
            GameActionRequested?.Invoke(this, new GameActionRequestedEventArgs(game.Slug, game.Access.State));
        }
    }

    private void LogoutButton_Click(object sender, RoutedEventArgs e) => LogoutRequested?.Invoke(this, EventArgs.Empty);

    private void RemoveDevice_Click(object sender, RoutedEventArgs e)
    {
        if (sender is Button { Tag: DeviceInfo device })
        {
            DeviceRevokeRequested?.Invoke(this, new DeviceRevokeRequestedEventArgs(device.DeviceId, device.DisplayName));
        }
    }

    private Brush StateBrush(GameAccessState state) => state is GameAccessState.Available or GameAccessState.ReadyToBind
        ? (Brush)FindResource("SuccessBrush")
        : state is GameAccessState.Expired or GameAccessState.Suspended
            ? (Brush)FindResource("ErrorBrush")
            : (Brush)FindResource("MutedTextBrush");

    private static string StateText(GameAccessState state) => state switch
    {
        GameAccessState.Locked => "Abonnement requis",
        GameAccessState.ReadyToBind => "Prêt à activer",
        GameAccessState.Available => "Disponible",
        GameAccessState.BoundElsewhere => "Lié à un autre appareil",
        GameAccessState.Expired => "Abonnement expiré",
        GameAccessState.Suspended => "Abonnement suspendu",
        _ => "Indisponible"
    };

    private static string ActionText(GameAccessState state) => state switch
    {
        GameAccessState.ReadyToBind => "ACTIVER SUR CET APPAREIL",
        GameAccessState.Available => "▶ LANCER",
        GameAccessState.BoundElsewhere => "AUTRE APPAREIL",
        _ => "ACTIVER UNE CLÉ"
    };

    private static string ExpirationText(DateTimeOffset? expiry) => expiry is null
        ? ""
        : "Expire le " + expiry.Value.ToLocalTime().ToString("dd/MM/yyyy", CultureInfo.GetCultureInfo("fr-FR"));

    private static string FormatLastSeen(DateTimeOffset? lastSeen) => lastSeen is null
        ? "jamais"
        : lastSeen.Value.ToLocalTime().ToString("dd/MM/yyyy HH:mm", CultureInfo.GetCultureInfo("fr-FR"));

    private static string CreatePlayerName(string email)
    {
        string local = email.Split('@', 2)[0].Replace('.', ' ').Replace('_', ' ').Trim();
        return string.IsNullOrWhiteSpace(local)
            ? "Joueur"
            : CultureInfo.GetCultureInfo("fr-FR").TextInfo.ToTitleCase(local.ToLowerInvariant());
    }

    private static string MaskEmail(string email)
    {
        string[] parts = email.Split('@', 2);
        if (parts.Length != 2) return "••••••";
        string prefix = parts[0].Length <= 2 ? parts[0][..1] : parts[0][..2];
        return prefix + "••••@" + parts[1];
    }

    private void RegisterGameButton(string gameSlug, Button button)
    {
        if (!_gameButtons.TryGetValue(gameSlug, out List<Button>? buttons))
        {
            buttons = [];
            _gameButtons[gameSlug] = buttons;
        }
        buttons.Add(button);
        if (_activeGames.Contains(gameSlug))
        {
            button.Content = "● ACTIF";
            button.IsEnabled = false;
        }
    }
}
