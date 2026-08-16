using System.Buffers.Binary;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Pericles.ApplicationSdk;

public static class IpcProtocol
{
    public const int Version = 1;
    public const int MaximumMessageBytes = 64 * 1024;
    public const string Hello = "hello";
    public const string HelloAck = "hello_ack";
    public const string PrepareModule = "prepare_module";
    public const string PrepareModuleAck = "prepare_module_ack";
    public const string LoadModule = "load_module";
    public const string ModuleLoaded = "module_loaded";
    public const string ModuleFailed = "module_failed";
    public const string Ping = "ping";
    public const string Pong = "pong";
    public const string Shutdown = "shutdown";
    public const string Error = "error";

    internal static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web)
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower
    };

    public static IpcMessage Create<T>(string messageType, T payload, string? requestId = null) => new(
        Version,
        requestId ?? Guid.NewGuid().ToString("N"),
        messageType,
        DateTimeOffset.UtcNow,
        JsonSerializer.SerializeToElement(payload, JsonOptions));

    public static IpcMessage CreateHello(
        string applicationIdentity,
        string gameSlug,
        string sessionNonce,
        ReadOnlySpan<byte> sessionSecret)
    {
        string requestId = Guid.NewGuid().ToString("N");
        DateTimeOffset timestamp = DateTimeOffset.UtcNow;
        string proof = CreateHandshakeProof(
            "launcher",
            requestId,
            timestamp,
            applicationIdentity,
            gameSlug,
            sessionNonce,
            sessionSecret);
        return new IpcMessage(
            Version,
            requestId,
            Hello,
            timestamp,
            JsonSerializer.SerializeToElement(
                new HelloPayload(applicationIdentity, gameSlug, sessionNonce, proof),
                JsonOptions));
    }

    public static IpcMessage CreateHelloAck(
        IpcMessage hello,
        string applicationIdentity,
        string gameSlug,
        string sessionNonce,
        ReadOnlySpan<byte> sessionSecret)
    {
        DateTimeOffset timestamp = DateTimeOffset.UtcNow;
        string proof = CreateHandshakeProof(
            "application",
            hello.RequestId,
            timestamp,
            applicationIdentity,
            gameSlug,
            sessionNonce,
            sessionSecret);
        return new IpcMessage(
            Version,
            hello.RequestId,
            HelloAck,
            timestamp,
            JsonSerializer.SerializeToElement(
                new HelloPayload(applicationIdentity, gameSlug, sessionNonce, proof),
                JsonOptions));
    }

    public static bool VerifyHandshakeProof(
        IpcMessage message,
        HelloPayload payload,
        string role,
        ReadOnlySpan<byte> sessionSecret)
    {
        string expected = CreateHandshakeProof(
            role,
            message.RequestId,
            message.Timestamp,
            payload.ApplicationIdentity,
            payload.GameSlug,
            payload.SessionNonce,
            sessionSecret);
        if (expected.Length != payload.Proof.Length) return false;
        return CryptographicOperations.FixedTimeEquals(
            Encoding.ASCII.GetBytes(expected),
            Encoding.ASCII.GetBytes(payload.Proof));
    }

    private static string CreateHandshakeProof(
        string role,
        string requestId,
        DateTimeOffset timestamp,
        string applicationIdentity,
        string gameSlug,
        string sessionNonce,
        ReadOnlySpan<byte> sessionSecret)
    {
        if (sessionSecret.Length != 32) throw new IpcProtocolException("The IPC session secret is invalid.");
        string canonical = string.Join('\n',
            Version.ToString(System.Globalization.CultureInfo.InvariantCulture),
            role,
            requestId,
            timestamp.ToUnixTimeMilliseconds().ToString(System.Globalization.CultureInfo.InvariantCulture),
            applicationIdentity,
            gameSlug,
            sessionNonce);
        byte[] proof = HMACSHA256.HashData(sessionSecret, Encoding.UTF8.GetBytes(canonical));
        return Convert.ToBase64String(proof).TrimEnd('=').Replace('+', '-').Replace('/', '_');
    }

    public static T ReadPayload<T>(IpcMessage message) =>
        message.Payload.Deserialize<T>(JsonOptions)
        ?? throw new IpcProtocolException("The IPC payload is empty.");

    public static async Task WriteAsync(Stream stream, IpcMessage message, CancellationToken cancellationToken)
    {
        byte[] json = JsonSerializer.SerializeToUtf8Bytes(message, JsonOptions);
        if (json.Length is < 1 or > MaximumMessageBytes)
        {
            throw new IpcProtocolException("The IPC message size is invalid.");
        }
        byte[] header = new byte[sizeof(uint)];
        BinaryPrimitives.WriteUInt32BigEndian(header, (uint) json.Length);
        await stream.WriteAsync(header, cancellationToken).ConfigureAwait(false);
        await stream.WriteAsync(json, cancellationToken).ConfigureAwait(false);
        await stream.FlushAsync(cancellationToken).ConfigureAwait(false);
    }

    public static async Task<IpcMessage> ReadAsync(Stream stream, CancellationToken cancellationToken)
    {
        byte[] header = new byte[sizeof(uint)];
        await stream.ReadExactlyAsync(header, cancellationToken).ConfigureAwait(false);
        uint rawLength = BinaryPrimitives.ReadUInt32BigEndian(header);
        if (rawLength is < 1 or > MaximumMessageBytes)
        {
            throw new IpcProtocolException("The IPC message size is invalid.");
        }
        byte[] json = new byte[(int) rawLength];
        await stream.ReadExactlyAsync(json, cancellationToken).ConfigureAwait(false);
        try
        {
            return JsonSerializer.Deserialize<IpcMessage>(json, JsonOptions)
                ?? throw new IpcProtocolException("The IPC message is empty.");
        }
        catch (JsonException exception)
        {
            throw new IpcProtocolException("The IPC message is invalid.", exception);
        }
    }

    public static void ValidateEnvelope(IpcMessage message, TimeSpan maximumClockSkew)
    {
        if (message.ProtocolVersion != Version)
        {
            throw new IpcProtocolException("The IPC protocol version is unsupported.");
        }
        if (!Guid.TryParseExact(message.RequestId, "N", out _)
            || string.IsNullOrWhiteSpace(message.MessageType)
            || (DateTimeOffset.UtcNow - message.Timestamp).Duration() > maximumClockSkew)
        {
            throw new IpcProtocolException("The IPC message envelope is invalid or expired.");
        }
    }
}

public sealed record IpcMessage(
    [property: JsonPropertyName("protocol_version")] int ProtocolVersion,
    [property: JsonPropertyName("request_id")] string RequestId,
    [property: JsonPropertyName("message_type")] string MessageType,
    [property: JsonPropertyName("timestamp")] DateTimeOffset Timestamp,
    [property: JsonPropertyName("payload")] JsonElement Payload);

public sealed record HelloPayload(string ApplicationIdentity, string GameSlug, string SessionNonce, string Proof);
public sealed record SessionPayload(string SessionNonce);
public sealed record LoadModulePayload(
    string Game,
    string Version,
    string Path,
    string Sha256,
    string? EntryPoint,
    int GameProcessId,
    string SessionNonce);
public sealed record ModuleResultPayload(string Game, string Version, string Code);
public sealed record ErrorPayload(string Code);

public sealed class IpcProtocolException(string message, Exception? innerException = null)
    : Exception(message, innerException);
