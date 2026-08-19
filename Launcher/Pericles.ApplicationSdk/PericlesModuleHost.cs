using System.IO.Pipes;
using System.Text.RegularExpressions;

namespace Pericles.ApplicationSdk;

public sealed class PericlesModuleHost : IAsyncDisposable
{
    private readonly PericlesModuleHostOptions _options;
    private readonly ModuleRegistry _registry;
    private readonly CancellationTokenSource _lifetime = new();
    private Task? _serverTask;

    public PericlesModuleHost(
        PericlesModuleHostOptions options,
        IProcessInjector? injector = null)
    {
        _options = options ?? throw new ArgumentNullException(nameof(options));
        ValidateOptions(options);

        _registry = new ModuleRegistry(
            options,
            injector ?? new WindowsProcessInjector(),
            ReportDiagnostic);
    }

    public event Action<string>? DiagnosticMessage;

    public Task StartAsync(CancellationToken cancellationToken = default)
    {
        cancellationToken.ThrowIfCancellationRequested();
        if (_serverTask is not null)
        {
            throw new InvalidOperationException("The Pericles module host is already running.");
        }
        _serverTask = RunServerAsync(_lifetime.Token);
        return Task.CompletedTask;
    }

    public async Task StopAsync(CancellationToken cancellationToken = default)
    {
        _lifetime.Cancel();
        if (_serverTask is null) return;
        try
        {
            await _serverTask.WaitAsync(cancellationToken).ConfigureAwait(false);
        }
        catch (OperationCanceledException) when (_lifetime.IsCancellationRequested)
        {
        }
    }

    public async ValueTask DisposeAsync()
    {
        await _lifetime.CancelAsync().ConfigureAwait(false);
        if (_serverTask is not null)
        {
            try
            {
                await _serverTask.ConfigureAwait(false);
            }
            catch (OperationCanceledException)
            {
            }
        }
        System.Security.Cryptography.CryptographicOperations.ZeroMemory(_options.SessionSecret);
        _lifetime.Dispose();
    }

    private async Task RunServerAsync(CancellationToken cancellationToken)
    {
        while (!cancellationToken.IsCancellationRequested)
        {
            await using var pipe = new NamedPipeServerStream(
                _options.PipeName,
                PipeDirection.InOut,
                1,
                PipeTransmissionMode.Byte,
                PipeOptions.Asynchronous | PipeOptions.CurrentUserOnly);
            try
            {
                ReportDiagnostic($"Waiting for launcher connection on pipe '{_options.PipeName}'.");
                await pipe.WaitForConnectionAsync(cancellationToken).ConfigureAwait(false);
                ReportDiagnostic("Launcher connected to the module host.");
                await HandleConnectionAsync(pipe, cancellationToken).ConfigureAwait(false);
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                break;
            }
            catch (IOException exception)
            {
                ReportDiagnostic($"IPC client disconnected: {exception.Message}");
            }
            catch (IpcProtocolException exception)
            {
                ReportDiagnostic($"IPC protocol request rejected: {exception.Message}");
            }
        }
    }

    private async Task HandleConnectionAsync(NamedPipeServerStream pipe, CancellationToken cancellationToken)
    {
        IpcMessage hello = await IpcProtocol.ReadAsync(pipe, cancellationToken).ConfigureAwait(false);
        IpcProtocol.ValidateEnvelope(hello, _options.MessageClockSkew);
        if (hello.MessageType != IpcProtocol.Hello)
        {
            await SendErrorAsync(pipe, hello.RequestId, "handshake_required", cancellationToken).ConfigureAwait(false);
            return;
        }
        HelloPayload helloPayload = IpcProtocol.ReadPayload<HelloPayload>(hello);
        if (!string.Equals(helloPayload.ApplicationIdentity, _options.ApplicationIdentity, StringComparison.Ordinal)
            || !string.Equals(helloPayload.GameSlug, _options.GameSlug, StringComparison.Ordinal)
            || !SecureNonceEquals(helloPayload.SessionNonce, _options.SessionNonce)
            || !IpcProtocol.VerifyHandshakeProof(hello, helloPayload, "launcher", _options.SessionSecret))
        {
            await SendErrorAsync(pipe, hello.RequestId, "handshake_rejected", cancellationToken).ConfigureAwait(false);
            return;
        }
        string sessionNonce = helloPayload.SessionNonce;
        await IpcProtocol.WriteAsync(
            pipe,
            IpcProtocol.CreateHelloAck(
                hello,
                _options.ApplicationIdentity,
                _options.GameSlug,
                sessionNonce,
                _options.SessionSecret),
            cancellationToken).ConfigureAwait(false);
        ReportDiagnostic($"Authenticated launcher handshake for game '{_options.GameSlug}'.");

        var requestIds = new HashSet<string>(StringComparer.Ordinal) { hello.RequestId };
        while (pipe.IsConnected && !cancellationToken.IsCancellationRequested)
        {
            IpcMessage request = await IpcProtocol.ReadAsync(pipe, cancellationToken).ConfigureAwait(false);
            IpcProtocol.ValidateEnvelope(request, _options.MessageClockSkew);
            if (!requestIds.Add(request.RequestId))
            {
                await SendErrorAsync(pipe, request.RequestId, "duplicate_request", cancellationToken).ConfigureAwait(false);
                continue;
            }

            if (request.MessageType == IpcProtocol.PrepareModule)
            {
                SessionPayload payload = IpcProtocol.ReadPayload<SessionPayload>(request);
                if (!SecureNonceEquals(payload.SessionNonce, sessionNonce))
                {
                    await SendErrorAsync(pipe, request.RequestId, "invalid_session", cancellationToken).ConfigureAwait(false);
                    return;
                }
                await IpcProtocol.WriteAsync(
                    pipe,
                    IpcProtocol.Create(IpcProtocol.PrepareModuleAck, new SessionPayload(sessionNonce), request.RequestId),
                    cancellationToken).ConfigureAwait(false);
                ReportDiagnostic("Module preparation acknowledged.");
                continue;
            }
            if (request.MessageType == IpcProtocol.LoadModule)
            {
                LoadModulePayload payload = IpcProtocol.ReadPayload<LoadModulePayload>(request);
                if (!SecureNonceEquals(payload.SessionNonce, sessionNonce))
                {
                    await SendErrorAsync(pipe, request.RequestId, "invalid_session", cancellationToken).ConfigureAwait(false);
                    return;
                }
                ReportDiagnostic(
                    $"Load request received for {payload.Game} {payload.Version}; target game PID={payload.GameProcessId}.");
                ModuleResultPayload result = await _registry.LoadAsync(payload, cancellationToken).ConfigureAwait(false);
                string responseType = result.Code is "loaded" or "already_loaded"
                    ? IpcProtocol.ModuleLoaded
                    : IpcProtocol.ModuleFailed;
                await IpcProtocol.WriteAsync(
                    pipe,
                    IpcProtocol.Create(responseType, result, request.RequestId),
                    cancellationToken).ConfigureAwait(false);
                ReportDiagnostic(
                    $"Load request completed for game PID {payload.GameProcessId}: {result.Code}.");

                // A module load is a complete launcher transaction. The launcher closes its
                // one-shot client pipe as soon as it receives this response, so do not begin
                // another read that can only race that close and raise a broken-pipe IOException.
                return;
            }
            if (request.MessageType == IpcProtocol.Ping)
            {
                SessionPayload payload = IpcProtocol.ReadPayload<SessionPayload>(request);
                if (!SecureNonceEquals(payload.SessionNonce, sessionNonce)) return;
                await IpcProtocol.WriteAsync(
                    pipe,
                    IpcProtocol.Create(IpcProtocol.Pong, payload, request.RequestId),
                    cancellationToken).ConfigureAwait(false);
                continue;
            }
            if (request.MessageType == IpcProtocol.Shutdown)
            {
                SessionPayload payload = IpcProtocol.ReadPayload<SessionPayload>(request);
                if (SecureNonceEquals(payload.SessionNonce, sessionNonce))
                {
                    await IpcProtocol.WriteAsync(
                        pipe,
                        IpcProtocol.Create(IpcProtocol.Shutdown, payload, request.RequestId),
                        cancellationToken).ConfigureAwait(false);
                }
                return;
            }
            await SendErrorAsync(pipe, request.RequestId, "unsupported_message", cancellationToken).ConfigureAwait(false);
        }
    }

    private static bool SecureNonceEquals(string supplied, string expected)
    {
        if (supplied.Length != expected.Length) return false;
        return System.Security.Cryptography.CryptographicOperations.FixedTimeEquals(
            System.Text.Encoding.ASCII.GetBytes(supplied),
            System.Text.Encoding.ASCII.GetBytes(expected));
    }

    private void ReportDiagnostic(string message)
    {
        try
        {
            DiagnosticMessage?.Invoke(message);
        }
        catch
        {
            // Diagnostics must never interrupt the authenticated module-host protocol.
        }
    }

    private static Task SendErrorAsync(Stream stream, string requestId, string code, CancellationToken cancellationToken) =>
        IpcProtocol.WriteAsync(
            stream,
            IpcProtocol.Create(IpcProtocol.Error, new ErrorPayload(code), requestId),
            cancellationToken);

    private static void ValidateOptions(PericlesModuleHostOptions options)
    {
        if (!Regex.IsMatch(options.ApplicationIdentity ?? "", "^[A-Za-z0-9._-]{1,80}$")
            || !Regex.IsMatch(options.GameSlug ?? "", "^[a-z0-9]+(?:-[a-z0-9]+)*$")
            || !Regex.IsMatch(options.PipeName ?? "", "^[A-Za-z0-9._-]{1,120}$")
            || !Regex.IsMatch(options.SessionNonce ?? "", "^[A-Za-z0-9_-]{43}$")
            || options.SessionSecret is null
            || options.SessionSecret.Length != 32
            || options.MessageClockSkew < TimeSpan.FromSeconds(5)
            || options.MessageClockSkew > TimeSpan.FromMinutes(2))
        {
            throw new ArgumentException("The Pericles module host configuration is invalid.", nameof(options));
        }
        _ = Path.GetFullPath(options.RuntimeRoot);
    }
}
