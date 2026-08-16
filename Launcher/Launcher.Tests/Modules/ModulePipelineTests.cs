using System.Diagnostics;
using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using Launcher.Authentication;
using Launcher.Modules;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using Xunit;

namespace Launcher.Tests.Modules;

public sealed class ModulePipelineTests
{
    private static readonly ModuleOptions OptionsValue = new()
    {
        MaxPackageSizeMb = 1,
        TrustedSigningKeys = new Dictionary<string, string>(StringComparer.Ordinal)
        {
            ["pericles-modules-development-test"] = "Launcher.Assets.module-signing-public.pem"
        }
    };

    [Fact]
    public async Task RequestModuleTicketSuccess()
    {
        HttpRequestMessage? captured = null;
        var client = CreateApiClient(async request =>
        {
            captured = request;
            string body = await request.Content!.ReadAsStringAsync();
            Assert.Contains("\"game\":\"deadlock\"", body, StringComparison.Ordinal);
            return Json(HttpStatusCode.Created,
                "{\"ticket\":\"ticket-value\",\"expires_in\":30,\"game\":\"deadlock\",\"module\":{\"version\":\"1.0.0\"}}");
        });

        ModuleTicketResponse ticket = await client.RequestTicketAsync("deadlock", "access-token", CancellationToken.None);

        Assert.Equal("deadlock", ticket.Game);
        Assert.Equal("1.0.0", ticket.Module.Version);
        Assert.Equal("Bearer", captured!.Headers.Authorization!.Scheme);
        Assert.Equal("access-token", captured.Headers.Authorization.Parameter);
    }

    [Fact]
    public async Task RequestModuleTicketUnauthorized()
    {
        var client = CreateApiClient(_ => Task.FromResult(Json(
            HttpStatusCode.Forbidden,
            "{\"error\":\"no_subscription\",\"message\":\"Subscription required.\"}")));

        ModuleAuthorizationException exception = await Assert.ThrowsAsync<ModuleAuthorizationException>(() =>
            client.RequestTicketAsync("deadlock", "access-token", CancellationToken.None));

        Assert.Equal("no_subscription", exception.ErrorCode);
    }

    [Fact]
    public void PackageParserAcceptsValidPackage()
    {
        PhpPackageFixture fixture = BuildPhpFixture();
        ModulePackage package = CreateParser().Parse(fixture.Package);

        Assert.Equal((ushort) 1, package.PackageVersion);
        Assert.Equal("deadlock", package.Manifest.GameSlug);
        Assert.Equal("deadlock-main", package.Manifest.ModuleSlug);
        Assert.Equal(fixture.Payload.Length, package.Manifest.PayloadSize);
    }

    [Fact]
    public void PackageParserRejectsTruncation()
    {
        byte[] package = BuildPhpFixture().Package[..^1];
        Assert.Throws<InvalidModulePackageException>(() => CreateParser().Parse(package));
    }

    [Fact]
    public void PackageParserRejectsInvalidMagic()
    {
        byte[] package = BuildPhpFixture().Package.ToArray();
        package[0] ^= 0xff;
        Assert.Throws<InvalidModulePackageException>(() => CreateParser().Parse(package));
    }

    [Fact]
    public void PackageParserRejectsUnsupportedVersion()
    {
        byte[] package = BuildPhpFixture().Package.ToArray();
        package[4] = 0;
        package[5] = 2;
        Assert.Throws<InvalidModulePackageException>(() => CreateParser().Parse(package));
    }

    [Fact]
    public void SignatureIsAccepted()
    {
        ModulePackage package = CreateParser().Parse(BuildPhpFixture().Package);
        CreateVerifier().Verify(package);
    }

    [Fact]
    public void ModifiedManifestIsRejected()
    {
        byte[] bytes = BuildPhpFixture().Package.ToArray();
        ModulePackage original = CreateParser().Parse(bytes);
        int offset = FindSectionOffset(bytes, original.ManifestBytes.Span);
        int marker = original.ManifestBytes.Span.IndexOf("deadlock"u8);
        Assert.True(marker >= 0);
        bytes[offset + marker] = (byte) 'x';

        Assert.Throws<InvalidModuleSignatureException>(() => CreateVerifier().Verify(CreateParser().Parse(bytes)));
    }

    [Fact]
    public void ModifiedCiphertextIsRejected()
    {
        byte[] bytes = BuildPhpFixture().Package.ToArray();
        ModulePackage original = CreateParser().Parse(bytes);
        int offset = FindSectionOffset(bytes, original.EncryptedPayload.Span);
        bytes[offset] ^= 0x01;

        Assert.Throws<InvalidModuleSignatureException>(() => CreateVerifier().Verify(CreateParser().Parse(bytes)));
    }

    [Fact]
    public void ModifiedSignatureIsRejected()
    {
        byte[] bytes = BuildPhpFixture().Package.ToArray();
        bytes[^1] ^= 0x01;

        Assert.Throws<InvalidModuleSignatureException>(() => CreateVerifier().Verify(CreateParser().Parse(bytes)));
    }

    [Fact]
    public void AesGcmDecryptsValidPackage()
    {
        PhpPackageFixture fixture = BuildPhpFixture();
        ModulePackage package = CreateParser().Parse(fixture.Package);

        byte[] plaintext = new ModuleDecryptor().Decrypt(package, fixture.SessionKey);

        Assert.Equal(fixture.Payload, plaintext);
        CryptographicOperations.ZeroMemory(plaintext);
    }

    [Fact]
    public void ModifiedAuthenticationTagFails()
    {
        PhpPackageFixture fixture = BuildPhpFixture();
        ModulePackage package = CreateParser().Parse(fixture.Package);
        byte[] modifiedTag = package.AuthenticationTag.ToArray();
        modifiedTag[0] ^= 0x01;

        Assert.Throws<ModuleDecryptionFailedException>(() =>
            new ModuleDecryptor().Decrypt(package with { AuthenticationTag = modifiedTag }, fixture.SessionKey));
    }

    [Fact]
    public async Task PayloadHashIsValidated()
    {
        PhpPackageFixture fixture = BuildPhpFixture();
        PreparedModule prepared = await CreateService(fixture).PrepareModuleAsync(
            Session(), "deadlock", CancellationToken.None);

        using (prepared)
        {
            Assert.Equal(fixture.Payload, prepared.Payload);
            Assert.Equal(Convert.ToHexString(SHA256.HashData(fixture.Payload)).ToLowerInvariant(), prepared.Hash);
        }
    }

    [Fact]
    public async Task PayloadHashMismatchIsRejected()
    {
        PhpPackageFixture fixture = BuildPhpFixture();
        byte[] alteredPayload = fixture.Payload.ToArray();
        alteredPayload[0] ^= 0xff;
        ModuleService service = CreateService(fixture, new FixedDecryptor(alteredPayload));

        await Assert.ThrowsAsync<ModuleHashMismatchException>(() =>
            service.PrepareModuleAsync(Session(), "deadlock", CancellationToken.None));
    }

    [Fact]
    public async Task PackageGameMismatchIsRejected()
    {
        PhpPackageFixture fixture = BuildPhpFixture("counter-strike-2");
        ModuleService service = CreateService(fixture, ticketGame: "deadlock");

        await Assert.ThrowsAsync<ModuleGameMismatchException>(() =>
            service.PrepareModuleAsync(Session(), "deadlock", CancellationToken.None));
    }

    [Fact]
    public async Task DownloadSizeLimitIsEnforced()
    {
        byte[] oversized = new byte[2 * 1024 * 1024];
        var client = CreateApiClient(_ =>
        {
            var response = new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new ByteArrayContent(oversized)
            };
            response.Headers.Add("X-Module-Session-Key", Base64UrlEncode(RandomNumberGenerator.GetBytes(32)));
            return Task.FromResult(response);
        });
        var ticket = new ModuleTicketResponse("ticket-value", 30, "deadlock", new ModuleTicketModule("1.0.0"));

        await Assert.ThrowsAsync<ModuleDownloadException>(() =>
            client.DownloadAsync(ticket, "access-token", CancellationToken.None));
    }

    [Fact]
    public async Task PhpOpenSslPackageIsVerifiedAndDecryptedByDotNet()
    {
        PhpPackageFixture fixture = BuildPhpFixture();
        using PreparedModule prepared = await CreateService(fixture).PrepareModuleAsync(
            Session(), "deadlock", CancellationToken.None);

        Assert.Equal(fixture.Payload, prepared.Payload);
        Assert.Equal("1.0.0", prepared.Version);
    }

    private static ModuleService CreateService(
        PhpPackageFixture fixture,
        IModuleDecryptor? decryptor = null,
        string ticketGame = "deadlock")
    {
        var ticket = new ModuleTicketResponse("ticket-value", 30, ticketGame, new ModuleTicketModule("1.0.0"));
        var api = new FixedModuleApiClient(ticket, new DownloadedModulePackage(fixture.Package, fixture.SessionKey.ToArray(), ticket));
        return new ModuleService(
            api,
            CreateParser(),
            CreateVerifier(),
            decryptor ?? new ModuleDecryptor(),
            NullLogger<ModuleService>.Instance);
    }

    private static ModulePackageParser CreateParser() => new(Options.Create(OptionsValue));

    private static ModulePackageVerifier CreateVerifier() => new(
        new EmbeddedModuleSigningKeyProvider(Options.Create(OptionsValue)),
        NullLogger<ModulePackageVerifier>.Instance);

    private static ModuleApiClient CreateApiClient(Func<HttpRequestMessage, Task<HttpResponseMessage>> responder)
    {
        var http = new HttpClient(new ModuleHttpMessageHandler(responder))
        {
            BaseAddress = new Uri("https://events.example.test/public/api/v1/"),
            Timeout = TimeSpan.FromSeconds(5)
        };
        return new ModuleApiClient(http, Options.Create(OptionsValue), NullLogger<ModuleApiClient>.Instance);
    }

    private static HttpResponseMessage Json(HttpStatusCode status, string body) => new(status)
    {
        Content = new StringContent(body, Encoding.UTF8, "application/json")
    };

    private static int FindSectionOffset(byte[] package, ReadOnlySpan<byte> section)
    {
        int offset = package.AsSpan().IndexOf(section);
        Assert.True(offset >= 0);
        return offset;
    }

    private static AuthSession Session() => new(
        "1", "admin@pericles.local", "access-token", DateTimeOffset.UtcNow.AddMinutes(10));

    private static PhpPackageFixture BuildPhpFixture(string gameSlug = "deadlock")
    {
        string script = FindRepositoryFile(Path.Combine("Site", "tests", "build_module_package_fixture.php"));
        var startInfo = new ProcessStartInfo("php")
        {
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
            CreateNoWindow = true
        };
        startInfo.ArgumentList.Add(script);
        startInfo.ArgumentList.Add(gameSlug);
        using Process process = Process.Start(startInfo)!;
        string output = process.StandardOutput.ReadToEnd();
        string error = process.StandardError.ReadToEnd();
        process.WaitForExit();
        Assert.True(process.ExitCode == 0, error);
        PhpPackageFixtureJson result = JsonSerializer.Deserialize<PhpPackageFixtureJson>(
            output,
            new JsonSerializerOptions(JsonSerializerDefaults.Web))!;
        return new PhpPackageFixture(
            Convert.FromBase64String(result.Package),
            Convert.FromBase64String(result.SessionKey),
            Convert.FromBase64String(result.Payload));
    }

    private static string FindRepositoryFile(string relativePath)
    {
        DirectoryInfo? directory = new(AppContext.BaseDirectory);
        while (directory is not null)
        {
            string candidate = Path.Combine(directory.FullName, relativePath);
            if (File.Exists(candidate)) return candidate;
            directory = directory.Parent;
        }
        throw new FileNotFoundException("Could not locate repository test helper.", relativePath);
    }

    private static string Base64UrlEncode(byte[] data) => Convert.ToBase64String(data)
        .TrimEnd('=')
        .Replace('+', '-')
        .Replace('/', '_');

    private sealed record PhpPackageFixture(byte[] Package, byte[] SessionKey, byte[] Payload);
    private sealed record PhpPackageFixtureJson(
        [property: JsonPropertyName("package")] string Package,
        [property: JsonPropertyName("session_key")] string SessionKey,
        [property: JsonPropertyName("payload")] string Payload);

    private sealed class FixedModuleApiClient(
        ModuleTicketResponse ticket,
        DownloadedModulePackage package) : IModuleApiClient
    {
        public Task<ModuleTicketResponse> RequestTicketAsync(string gameSlug, string accessToken, CancellationToken cancellationToken) =>
            Task.FromResult(ticket);

        public Task<DownloadedModulePackage> DownloadAsync(ModuleTicketResponse requestedTicket, string accessToken, CancellationToken cancellationToken) =>
            Task.FromResult(package);
    }

    private sealed class FixedDecryptor(byte[] payload) : IModuleDecryptor
    {
        public byte[] Decrypt(ModulePackage package, ReadOnlySpan<byte> sessionKey) => payload.ToArray();
    }

    private sealed class ModuleHttpMessageHandler(
        Func<HttpRequestMessage, Task<HttpResponseMessage>> responder) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) =>
            responder(request);
    }
}
