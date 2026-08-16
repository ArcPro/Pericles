namespace Pericles.ApplicationSdk;

public sealed class PericlesModuleHostOptions
{
    public const string SessionNonceEnvironmentVariable = "PERICLES_IPC_SESSION_NONCE";
    public const string SessionSecretEnvironmentVariable = "PERICLES_IPC_SESSION_SECRET";
    public const string RuntimeRootEnvironmentVariable = "PERICLES_RUNTIME_ROOT";
    public required string ApplicationIdentity { get; init; }
    public required string GameSlug { get; init; }
    public required string PipeName { get; init; }
    public required string SessionNonce { get; init; }
    public required byte[] SessionSecret { get; init; }
    public string RuntimeRoot { get; init; } = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "Pericles",
        "Runtime");
    public TimeSpan MessageClockSkew { get; init; } = TimeSpan.FromSeconds(30);

    public static PericlesModuleHostOptions FromLauncherEnvironment(
        string applicationIdentity,
        string gameSlug,
        string pipeName)
    {
        string nonce = Environment.GetEnvironmentVariable(SessionNonceEnvironmentVariable) ?? "";
        string encodedSecret = Environment.GetEnvironmentVariable(SessionSecretEnvironmentVariable) ?? "";
        string runtimeRoot = Environment.GetEnvironmentVariable(RuntimeRootEnvironmentVariable) ?? "";
        byte[] secret;
        try
        {
            secret = DecodeBase64Url(encodedSecret);
        }
        catch (FormatException exception)
        {
            throw new InvalidOperationException("The application was not started by an authenticated Pericles launcher session.", exception);
        }
        Environment.SetEnvironmentVariable(SessionNonceEnvironmentVariable, null);
        Environment.SetEnvironmentVariable(SessionSecretEnvironmentVariable, null);
        Environment.SetEnvironmentVariable(RuntimeRootEnvironmentVariable, null);
        return new PericlesModuleHostOptions
        {
            ApplicationIdentity = applicationIdentity,
            GameSlug = gameSlug,
            PipeName = pipeName,
            SessionNonce = nonce,
            SessionSecret = secret,
            RuntimeRoot = string.IsNullOrWhiteSpace(runtimeRoot)
                ? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Pericles", "Runtime")
                : runtimeRoot
        };
    }

    private static byte[] DecodeBase64Url(string value)
    {
        string padded = value.Replace('-', '+').Replace('_', '/');
        padded += new string('=', (4 - padded.Length % 4) % 4);
        return Convert.FromBase64String(padded);
    }
}
