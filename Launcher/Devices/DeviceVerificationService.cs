using Launcher.Authentication;
using Microsoft.Extensions.Logging;
using System.Security.Cryptography;

namespace Launcher.Devices;

public sealed class DeviceVerificationService(
    IDeviceIdentityService identityService,
    IDeviceSignatureService signatureService,
    IDeviceApiClient apiClient,
    DeviceVerificationState state,
    ILogger<DeviceVerificationService> logger) : IDeviceVerificationService
{
    public async Task<DeviceSession> VerifyCurrentDeviceAsync(
        AuthSession authSession,
        CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(authSession);
        DeviceIdentity identity = await identityService.GetOrCreateAsync(cancellationToken);
        var registration = new RegisterDeviceRequest(
            identity.DeviceId,
            identity.PublicKey,
            identity.KeyAlgorithm,
            Environment.MachineName);
        await apiClient.RegisterAsync(registration, authSession.AccessToken, cancellationToken);

        DeviceChallengeResponse challenge = await apiClient.RequestChallengeAsync(
            identity.DeviceId,
            authSession.AccessToken,
            cancellationToken);
        byte[] challengeBytes;
        try
        {
            challengeBytes = Base64Url.Decode(challenge.Challenge);
        }
        catch (FormatException exception)
        {
            throw new DeviceApiException(DeviceApiError.ServerError, "The server returned an invalid challenge.", exception);
        }

        if (challengeBytes.Length != 32 || challenge.ExpiresAt <= DateTimeOffset.UtcNow)
        {
            throw new DeviceApiException(DeviceApiError.ChallengeExpired, "The device challenge is invalid or expired.");
        }

        string signature;
        try
        {
            signature = await signatureService.SignAsync(challengeBytes, cancellationToken);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(challengeBytes);
        }
        DeviceVerificationResponse verification = await apiClient.VerifyAsync(
            new VerifyDeviceRequest(identity.DeviceId, challenge.ChallengeId, signature),
            authSession.AccessToken,
            cancellationToken);
        if (!verification.DeviceVerified)
        {
            throw new DeviceApiException(DeviceApiError.InvalidSignature, "The server rejected the device signature.");
        }

        IReadOnlyList<DeviceInfo> devices = await apiClient.GetDevicesAsync(
            identity.DeviceId,
            authSession.AccessToken,
            cancellationToken);
        var session = new DeviceSession(identity, verification.VerifiedAt, devices);
        state.SetVerified(session);
        logger.LogInformation("Current device verified ({DeviceId}).", Mask(identity.DeviceId));
        return session;
    }

    public async Task<IReadOnlyList<DeviceInfo>> RefreshDevicesAsync(
        AuthSession authSession,
        CancellationToken cancellationToken)
    {
        DeviceSession session = state.CurrentSession
            ?? throw new InvalidOperationException("No verified device session is active.");
        IReadOnlyList<DeviceInfo> devices = await apiClient.GetDevicesAsync(
            session.Identity.DeviceId,
            authSession.AccessToken,
            cancellationToken);
        state.SetVerified(session with { Devices = devices });
        return devices;
    }

    public async Task RevokeDeviceAsync(
        AuthSession authSession,
        string deviceId,
        CancellationToken cancellationToken)
    {
        DeviceSession session = state.CurrentSession
            ?? throw new InvalidOperationException("No verified device session is active.");
        if (string.Equals(session.Identity.DeviceId, deviceId, StringComparison.Ordinal))
        {
            throw new InvalidOperationException("The current device cannot be removed from this screen.");
        }

        await apiClient.RevokeAsync(deviceId, authSession.AccessToken, cancellationToken);
    }

    private static string Mask(string deviceId) => deviceId.Length <= 8
        ? "********"
        : $"{deviceId[..4]}...{deviceId[^4..]}";
}
