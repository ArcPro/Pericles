using System.Net;
using System.Text;
using System.Text.Json;
using Launcher.Devices;
using Microsoft.Extensions.Logging.Abstractions;
using Xunit;

namespace Launcher.Tests.Devices;

public sealed class DeviceApiClientTests
{
    [Fact]
    public async Task RegisterDeviceRequestSerialization()
    {
        string? body = null;
        var client = CreateClient(async request =>
        {
            body = await request.Content!.ReadAsStringAsync();
            return Json(HttpStatusCode.OK, DeviceResponseJson());
        });

        await client.RegisterAsync(
            new RegisterDeviceRequest("aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", "PUBLIC", "ECDSA-P256", "My PC"),
            "token",
            CancellationToken.None);

        using JsonDocument document = JsonDocument.Parse(body!);
        Assert.Equal("aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", document.RootElement.GetProperty("device_id").GetString());
        Assert.Equal("PUBLIC", document.RootElement.GetProperty("public_key").GetString());
        Assert.Equal("ECDSA-P256", document.RootElement.GetProperty("key_algorithm").GetString());
        Assert.Equal("My PC", document.RootElement.GetProperty("display_name").GetString());
    }

    [Fact]
    public async Task ChallengeResponseParsing()
    {
        var client = CreateClient(_ => Task.FromResult(Json(
            HttpStatusCode.Created,
            $"{{\"challenge_id\":\"{new string('b', 64)}\",\"challenge\":\"{Base64Url.Encode(new byte[32])}\",\"expires_at\":\"2099-01-01T00:00:00+00:00\"}}")));

        DeviceChallengeResponse response = await client.RequestChallengeAsync(
            new string('a', 32), "token", CancellationToken.None);

        Assert.Equal(64, response.ChallengeId.Length);
        Assert.Equal(32, Base64Url.Decode(response.Challenge).Length);
    }

    [Fact]
    public Task DeviceLimitErrorHandled() => AssertDeviceError(
        "device_limit_reached",
        DeviceApiError.DeviceLimitReached);

    [Fact]
    public Task RevokedDeviceHandled() => AssertDeviceError(
        "device_revoked",
        DeviceApiError.DeviceRevoked);

    private static async Task AssertDeviceError(string serverError, DeviceApiError expected)
    {
        var client = CreateClient(_ => Task.FromResult(Json(
            HttpStatusCode.Conflict,
            $"{{\"error\":\"{serverError}\",\"message\":\"rejected\"}}")));

        DeviceApiException exception = await Assert.ThrowsAsync<DeviceApiException>(() => client.RegisterAsync(
            new RegisterDeviceRequest(new string('a', 32), "PUBLIC", "ECDSA-P256", "PC"),
            "token",
            CancellationToken.None));

        Assert.Equal(expected, exception.Error);
    }

    private static DeviceApiClient CreateClient(Func<HttpRequestMessage, Task<HttpResponseMessage>> responder)
    {
        var client = new HttpClient(new DeviceHttpMessageHandler(responder))
        {
            BaseAddress = new Uri("https://events.example.test/public/api/v1/"),
            Timeout = TimeSpan.FromSeconds(5)
        };
        return new DeviceApiClient(client, NullLogger<DeviceApiClient>.Instance);
    }

    private static HttpResponseMessage Json(HttpStatusCode status, string json) => new(status)
    {
        Content = new StringContent(json, Encoding.UTF8, "application/json")
    };

    private static string DeviceResponseJson() =>
        "{\"device\":{\"device_id\":\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\",\"display_name\":\"My PC\",\"created_at\":\"2026-08-16T12:00:00+00:00\",\"last_seen_at\":null,\"verified_at\":null,\"is_current\":true}}";
}

internal sealed class DeviceHttpMessageHandler(
    Func<HttpRequestMessage, Task<HttpResponseMessage>> responder) : HttpMessageHandler
{
    protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) =>
        responder(request);
}
