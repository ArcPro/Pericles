using System.IO;
using System.Net.Http;

namespace Launcher.Games;

public sealed class RemoteGameImageService(HttpClient httpClient) : IGameImageService
{
    private const int MaximumImageBytes = 5 * 1024 * 1024;

    public async Task<byte[]?> DownloadAsync(string imageUrl, CancellationToken cancellationToken)
    {
        if (!Uri.TryCreate(imageUrl, UriKind.Absolute, out Uri? uri) || uri.Scheme != Uri.UriSchemeHttps)
        {
            return null;
        }
        try
        {
            using HttpResponseMessage response = await httpClient.GetAsync(uri, HttpCompletionOption.ResponseHeadersRead, cancellationToken);
            if (!response.IsSuccessStatusCode
                || response.Content.Headers.ContentLength is > MaximumImageBytes
                || response.Content.Headers.ContentType?.MediaType?.StartsWith("image/", StringComparison.OrdinalIgnoreCase) != true)
            {
                return null;
            }
            byte[] bytes = await response.Content.ReadAsByteArrayAsync(cancellationToken);
            return bytes.Length is > 0 and <= MaximumImageBytes ? bytes : null;
        }
        catch (Exception exception) when (exception is HttpRequestException or TaskCanceledException or IOException)
        {
            return null;
        }
    }
}
