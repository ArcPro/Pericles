using System.Net;
using System.Text;
using System.Text.Json;
using Launcher.Activation;
using Launcher.Authentication;
using Launcher.Core;
using Launcher.Games;
using Launcher.Subscriptions;
using Launcher.Modules;
using Microsoft.Extensions.Logging.Abstractions;
using Xunit;

namespace Launcher.Tests.Entitlements;

public sealed class EntitlementApiClientTests
{
    [Fact]
    public async Task GameCatalogDeserializationTest()
    {
        GameInfo game = (await CatalogWithState("available").GetGamesAsync("token", CancellationToken.None)).Single();
        Assert.Equal("deadlock", game.Slug);
        Assert.Equal(GameAccessState.Available, game.Access.State);
        Assert.Equal(new DateTimeOffset(2026, 9, 15, 12, 0, 0, TimeSpan.Zero), game.Access.ExpiresAt);
    }

    [Fact]
    public async Task DeadlockLockedWithoutSubscriptionTest() =>
        Assert.Equal(GameAccessState.Locked, (await Games("locked")).Single().Access.State);

    [Fact]
    public async Task DeadlockReadyToBindTest() =>
        Assert.Equal(GameAccessState.ReadyToBind, (await Games("ready_to_bind")).Single().Access.State);

    [Fact]
    public async Task DeadlockAvailableOnCurrentDeviceTest() =>
        Assert.Equal(GameAccessState.Available, (await Games("available")).Single().Access.State);

    [Fact]
    public async Task DeadlockBoundElsewhereTest() =>
        Assert.Equal(GameAccessState.BoundElsewhere, (await Games("bound_elsewhere")).Single().Access.State);

    [Fact]
    public async Task CounterStrikeIndependentFromDeadlockTest()
    {
        var client = new GameCatalogApiClient(Client(_ => Json(HttpStatusCode.OK,
            "{\"games\":[" + GameJson("deadlock", "Deadlock", "available") + ","
            + GameJson("counter-strike-2", "Counter-Strike 2", "locked") + "]}")));
        IReadOnlyList<GameInfo> games = await client.GetGamesAsync("token", CancellationToken.None);
        Assert.Equal(GameAccessState.Available, games.Single(game => game.Slug == "deadlock").Access.State);
        Assert.Equal(GameAccessState.Locked, games.Single(game => game.Slug == "counter-strike-2").Access.State);
    }

    [Fact]
    public async Task PremiumGradeDoesNotUnlockGamesTest()
    {
        GameInfo game = (await Games("locked")).Single();
        Assert.Equal(GameAccessState.Locked, game.Access.State);
        Assert.DoesNotContain("premium", JsonSerializer.Serialize(game), StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task ActivationKeyRequestTest()
    {
        string? body = null;
        string? authorization = null;
        var api = new ActivationApiClient(Client(async request =>
        {
            body = await request.Content!.ReadAsStringAsync();
            authorization = request.Headers.Authorization?.ToString();
            return Json(HttpStatusCode.OK,
                "{\"success\":true,\"product\":{\"slug\":\"deadlock\",\"name\":\"Deadlock\"},\"subscription\":{\"status\":\"active\",\"expires_at\":null,\"device_binding\":\"unbound\"}}");
        }));
        await api.RedeemKeyAsync("PERI-AAAA-BBBB-CCCC-DDDD-EEEE-FFFF-GGGG", "secret-token", CancellationToken.None);
        using JsonDocument document = JsonDocument.Parse(body!);
        Assert.Equal("PERI-AAAA-BBBB-CCCC-DDDD-EEEE-FFFF-GGGG", document.RootElement.GetProperty("key").GetString());
        Assert.Equal("Bearer secret-token", authorization);
        Assert.False(document.RootElement.TryGetProperty("user_id", out _));
    }

    [Fact]
    public async Task BindGameRequestTest()
    {
        string? path = null;
        string? body = null;
        var api = new GameCatalogApiClient(Client(async request =>
        {
            path = request.RequestUri!.AbsolutePath;
            body = request.Content is null ? null : await request.Content.ReadAsStringAsync();
            return Json(HttpStatusCode.OK, "{\"success\":true,\"state\":\"available\"}");
        }));
        await api.BindGameToCurrentDeviceAsync("deadlock", "token", CancellationToken.None);
        Assert.EndsWith("/games/deadlock/bind-device", path);
        Assert.Null(body);
    }

    [Fact]
    public async Task ActivationSuccessRefreshesCatalogTest()
    {
        var catalog = new FakeCatalog();
        var activation = new FakeActivation();
        var subscriptions = new FakeSubscriptions();
        var service = new LauncherService(catalog, activation, subscriptions, new FakeModuleService(), NullLogger<LauncherService>.Instance);
        var session = new AuthSession("1", "user@example.test", "token", DateTimeOffset.UtcNow.AddHours(1));

        LauncherHomeData home = await service.RedeemAndRefreshAsync(session, "PERI-KEY", CancellationToken.None);

        Assert.True(activation.Called);
        Assert.Equal(1, catalog.GetCalls);
        Assert.Single(home.Games);
        Assert.Single(home.Subscriptions);
    }

    [Fact]
    public async Task RemoteImageFailureUsesFallbackTest()
    {
        var service = new RemoteGameImageService(Client(_ => new HttpResponseMessage(HttpStatusCode.NotFound)));
        Assert.Null(await service.DownloadAsync("https://cdn.example.test/missing.jpg", CancellationToken.None));
        Assert.Null(await service.DownloadAsync("http://insecure.example.test/image.jpg", CancellationToken.None));
    }

    private static async Task<IReadOnlyList<GameInfo>> Games(string state) =>
        await CatalogWithState(state).GetGamesAsync("token", CancellationToken.None);

    private static GameCatalogApiClient CatalogWithState(string state) => new(Client(_ => Json(
        HttpStatusCode.OK,
        "{\"games\":[" + GameJson("deadlock", "Deadlock", state) + "]}")));

    private static string GameJson(string slug, string name, string state) =>
        $"{{\"slug\":\"{slug}\",\"name\":\"{name}\",\"short_description\":\"Description\",\"image_url\":\"https://cdn.example.test/{slug}.jpg\",\"sort_order\":10,\"access\":{{\"state\":\"{state}\",\"expires_at\":\"2026-09-15T12:00:00+00:00\"}}}}";

    private static HttpClient Client(Func<HttpRequestMessage, Task<HttpResponseMessage>> responder) =>
        new(new EntitlementHandler(responder)) { BaseAddress = new Uri("https://api.example.test/api/v1/") };

    private static HttpClient Client(Func<HttpRequestMessage, HttpResponseMessage> responder) =>
        Client(request => Task.FromResult(responder(request)));

    private static HttpResponseMessage Json(HttpStatusCode status, string json) => new(status)
    {
        Content = new StringContent(json, Encoding.UTF8, "application/json")
    };

    private sealed class FakeCatalog : IGameCatalogApiClient
    {
        public int GetCalls { get; private set; }
        public Task<IReadOnlyList<GameInfo>> GetGamesAsync(string accessToken, CancellationToken cancellationToken)
        {
            GetCalls++;
            IReadOnlyList<GameInfo> games = [new("deadlock", "Deadlock", "", "https://example.test/a.jpg", 1, new(GameAccessState.Locked, null))];
            return Task.FromResult(games);
        }
        public Task BindGameToCurrentDeviceAsync(string slug, string accessToken, CancellationToken cancellationToken) => Task.CompletedTask;
    }

    private sealed class FakeActivation : IActivationApiClient
    {
        public bool Called { get; private set; }
        public Task<ActivationResponse> RedeemKeyAsync(string key, string accessToken, CancellationToken cancellationToken)
        {
            Called = true;
            return Task.FromResult(new ActivationResponse(true, new("deadlock", "Deadlock"), new("active", null, "unbound")));
        }
    }

    private sealed class FakeSubscriptions : ISubscriptionApiClient
    {
        public Task<IReadOnlyList<SubscriptionInfo>> GetSubscriptionsAsync(string accessToken, CancellationToken cancellationToken)
        {
            IReadOnlyList<SubscriptionInfo> subscriptions = [new(new("deadlock", "Deadlock"), "active", DateTimeOffset.UtcNow, null, new("unbound"))];
            return Task.FromResult(subscriptions);
        }
    }

    private sealed class FakeModuleService : IModuleService
    {
        public Task<PreparedModule> PrepareModuleAsync(
            AuthSession session,
            string gameSlug,
            CancellationToken cancellationToken) =>
            Task.FromResult(new PreparedModule(gameSlug, gameSlug + "-main", "1.0.0", [1], new string('0', 64)));
    }
}

internal sealed class EntitlementHandler(Func<HttpRequestMessage, Task<HttpResponseMessage>> responder) : HttpMessageHandler
{
    protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) => responder(request);
}
