namespace Launcher.Configuration;

public sealed class ApiOptions
{
    public const string SectionName = "Api";

    public string BaseUrl { get; init; } = string.Empty;

    public int TimeoutSeconds { get; init; } = 15;

    public bool AllowInsecureLocalhost { get; init; }

    public static bool UsesAllowedTransport(ApiOptions options)
    {
        if (!Uri.TryCreate(options.BaseUrl, UriKind.Absolute, out Uri? uri))
        {
            return false;
        }

        return uri.Scheme == Uri.UriSchemeHttps
            || options.AllowInsecureLocalhost && uri.Scheme == Uri.UriSchemeHttp && uri.IsLoopback;
    }
}
