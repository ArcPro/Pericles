using System.Net.Mail;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;

namespace Launcher.Views;

public sealed class LoginRequestedEventArgs(string email, string password) : EventArgs
{
    public string Email { get; } = email;

    public string Password { get; } = password;
}

public partial class LoginView : UserControl
{
    private bool _isClearingPassword;

    public LoginView()
    {
        InitializeComponent();
    }

    public event EventHandler<LoginRequestedEventArgs>? LoginRequested;

    public void SetBusy(bool isBusy)
    {
        EmailInput.IsEnabled = !isBusy;
        PasswordInput.IsEnabled = !isBusy;
        LoginButton.IsEnabled = !isBusy;
        LoginButton.Content = isBusy ? "CONNEXION..." : "SE CONNECTER";
    }

    public void SetError(string? message)
    {
        ErrorText.Text = message ?? string.Empty;
        ErrorContainer.Visibility = string.IsNullOrWhiteSpace(message) ? Visibility.Collapsed : Visibility.Visible;
    }

    public void ClearPassword()
    {
        _isClearingPassword = true;
        try
        {
            PasswordInput.Clear();
        }
        finally
        {
            _isClearingPassword = false;
        }
    }

    public void FocusEmail() => EmailInput.Focus();

    private void LoginView_Loaded(object sender, RoutedEventArgs e) => FocusEmail();

    private void LoginButton_Click(object sender, RoutedEventArgs e) => Submit();

    private void PasswordInput_KeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter)
        {
            Submit();
        }
    }

    private void Input_Changed(object sender, RoutedEventArgs e)
    {
        if (!_isClearingPassword)
        {
            SetError(null);
        }
    }

    private void Submit()
    {
        string email = EmailInput.Text.Trim();
        string password = PasswordInput.Password;

        if (!IsValidEmail(email) || string.IsNullOrEmpty(password))
        {
            SetError("Saisissez une adresse email et un mot de passe valides.");
            return;
        }

        LoginRequested?.Invoke(this, new LoginRequestedEventArgs(email, password));
    }

    private static bool IsValidEmail(string email)
    {
        try
        {
            return new MailAddress(email).Address.Equals(email, StringComparison.OrdinalIgnoreCase);
        }
        catch (FormatException)
        {
            return false;
        }
    }
}
