using Launcher.Authentication;
using Launcher.Devices;
using Microsoft.Extensions.Logging.Abstractions;
using Xunit;

namespace Launcher.Tests.Devices;

public sealed class DeviceVerificationServiceTests
{
    [Fact]
    public async Task ValidChallengeVerificationFlow()
    {
        var api = new FakeDeviceApiClient();
        var state = new DeviceVerificationState();
        var signatures = new RecordingSignatureService();
        var service = new DeviceVerificationService(
            new FakeIdentityService(),
            signatures,
            api,
            state,
            NullLogger<DeviceVerificationService>.Instance);

        DeviceSession session = await service.VerifyCurrentDeviceAsync(Auth(), CancellationToken.None);

        Assert.True(session.DeviceVerified);
        Assert.True(state.DeviceVerified);
        Assert.Equal(32, signatures.SignedData?.Length);
        Assert.True(api.Registered);
        Assert.True(api.Verified);
    }

    private static AuthSession Auth() => new("42", "user@example.test", "token", DateTimeOffset.UtcNow.AddHours(1));

    private sealed class FakeIdentityService : IDeviceIdentityService
    {
        public Task<DeviceIdentity> GetOrCreateAsync(CancellationToken cancellationToken) => Task.FromResult(new DeviceIdentity
        {
            SchemaVersion = 1,
            DeviceId = new string('a', 32),
            PublicKey = "PUBLIC",
            KeyAlgorithm = "ECDSA-P256",
            CreatedAt = DateTimeOffset.UtcNow
        });
    }

    private sealed class RecordingSignatureService : IDeviceSignatureService
    {
        public byte[]? SignedData { get; private set; }

        public Task<string> SignAsync(ReadOnlyMemory<byte> data, CancellationToken cancellationToken)
        {
            SignedData = data.ToArray();
            return Task.FromResult("signature");
        }
    }

    private sealed class FakeDeviceApiClient : IDeviceApiClient
    {
        public bool Registered { get; private set; }
        public bool Verified { get; private set; }

        public Task<DeviceRegistrationResponse> RegisterAsync(RegisterDeviceRequest request, string accessToken, CancellationToken cancellationToken)
        {
            Registered = true;
            return Task.FromResult(new DeviceRegistrationResponse(Info(true)));
        }

        public Task<DeviceChallengeResponse> RequestChallengeAsync(string deviceId, string accessToken, CancellationToken cancellationToken) =>
            Task.FromResult(new DeviceChallengeResponse(new string('b', 64), Base64Url.Encode(new byte[32]), DateTimeOffset.UtcNow.AddMinutes(1)));

        public Task<DeviceVerificationResponse> VerifyAsync(VerifyDeviceRequest request, string accessToken, CancellationToken cancellationToken)
        {
            Verified = true;
            return Task.FromResult(new DeviceVerificationResponse(true, DateTimeOffset.UtcNow));
        }

        public Task<IReadOnlyList<DeviceInfo>> GetDevicesAsync(string currentDeviceId, string accessToken, CancellationToken cancellationToken) =>
            Task.FromResult<IReadOnlyList<DeviceInfo>>([Info(true)]);

        public Task RevokeAsync(string deviceId, string accessToken, CancellationToken cancellationToken) => Task.CompletedTask;

        private static DeviceInfo Info(bool current) => new(
            new string('a', 32), "Test PC", DateTimeOffset.UtcNow, DateTimeOffset.UtcNow, DateTimeOffset.UtcNow, current);
    }
}
