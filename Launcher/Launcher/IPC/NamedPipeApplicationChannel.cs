using System.Diagnostics;
using System.IO;
using System.IO.Pipes;
using System.Runtime.InteropServices;
using Launcher.Application;
using Launcher.Modules;
using Launcher.Launch;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Microsoft.Win32.SafeHandles;
using Pericles.ApplicationSdk;

namespace Launcher.IPC;

public sealed class NamedPipeApplicationChannel(
    IOptions<LaunchOptions> options,
    ILogger<NamedPipeApplicationChannel> logger) : IApplicationChannel
{
    public async Task<ModuleLoadResult> LoadModuleAsync(
        ApplicationInstance application,
        GameApplicationDefinition game,
        RuntimeModuleHandle module,
        int gameProcessId,
        IProgress<GameLaunchStage>? progress,
        CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(application);
        ArgumentNullException.ThrowIfNull(game);
        ArgumentNullException.ThrowIfNull(module);
        using var pipe = new NamedPipeClientStream(
            ".",
            game.PipeName,
            PipeDirection.InOut,
            PipeOptions.Asynchronous | PipeOptions.CurrentUserOnly);
        try
        {
            logger.LogInformation("Connecting to the configured application IPC pipe.");
            using (var connectTimeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken))
            {
                connectTimeout.CancelAfter(TimeSpan.FromSeconds(options.Value.IpcConnectTimeoutSeconds));
                await pipe.ConnectAsync(connectTimeout.Token).ConfigureAwait(false);
            }
            ValidateServerProcess(pipe.SafePipeHandle, application.ProcessId);

            using var loadTimeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
            loadTimeout.CancelAfter(TimeSpan.FromSeconds(options.Value.ModuleLoadTimeoutSeconds));
            string nonce = application.IpcSessionNonce;
            IpcMessage hello = IpcProtocol.CreateHello(
                game.ApplicationIdentity,
                game.GameSlug,
                nonce,
                application.IpcSessionSecret);
            await IpcProtocol.WriteAsync(pipe, hello, loadTimeout.Token).ConfigureAwait(false);
            IpcMessage helloAck = await ReadResponseAsync(pipe, hello.RequestId, loadTimeout.Token).ConfigureAwait(false);
            if (helloAck.MessageType != IpcProtocol.HelloAck)
            {
                throw new ApplicationHandshakeException("The application rejected the IPC handshake.");
            }
            HelloPayload acknowledged = IpcProtocol.ReadPayload<HelloPayload>(helloAck);
            if (!string.Equals(acknowledged.ApplicationIdentity, game.ApplicationIdentity, StringComparison.Ordinal)
                || !string.Equals(acknowledged.GameSlug, game.GameSlug, StringComparison.Ordinal)
                || !string.Equals(acknowledged.SessionNonce, nonce, StringComparison.Ordinal)
                || !IpcProtocol.VerifyHandshakeProof(
                    helloAck,
                    acknowledged,
                    "application",
                    application.IpcSessionSecret))
            {
                throw new ApplicationHandshakeException("The application returned an invalid IPC identity.");
            }
            logger.LogInformation("IPC handshake successful.");

            IpcMessage prepare = IpcProtocol.Create(IpcProtocol.PrepareModule, new SessionPayload(nonce));
            await IpcProtocol.WriteAsync(pipe, prepare, loadTimeout.Token).ConfigureAwait(false);
            IpcMessage prepareAck = await ReadResponseAsync(pipe, prepare.RequestId, loadTimeout.Token).ConfigureAwait(false);
            if (prepareAck.MessageType != IpcProtocol.PrepareModuleAck)
            {
                throw new ApplicationHandshakeException("The application did not acknowledge module preparation.");
            }

            progress?.Report(GameLaunchStage.LoadingModule);
            IpcMessage load = IpcProtocol.Create(
                IpcProtocol.LoadModule,
                new LoadModulePayload(
                    module.GameSlug,
                    module.Version,
                    module.Path,
                    module.ExpectedSha256,
                    game.EntryPoint,
                    gameProcessId,
                    nonce));
            logger.LogInformation("Sending the cooperative module load request.");
            await IpcProtocol.WriteAsync(pipe, load, loadTimeout.Token).ConfigureAwait(false);
            IpcMessage response = await ReadResponseAsync(pipe, load.RequestId, loadTimeout.Token).ConfigureAwait(false);
            ModuleResultPayload result = IpcProtocol.ReadPayload<ModuleResultPayload>(response);
            if (response.MessageType == IpcProtocol.ModuleLoaded && result.Code is "loaded" or "already_loaded")
            {
                logger.LogInformation(
                    "Module loaded successfully into game process {GameProcessId} via the owned application.",
                    gameProcessId);
                return new ModuleLoadResult(result.Game, result.Version, result.Code);
            }
            if (result.Code == "restart_required") throw new ModuleRestartRequiredException();
            throw new ModuleLoadFailedException(result.Code);
        }
        catch (OperationCanceledException exception) when (!cancellationToken.IsCancellationRequested)
        {
            throw new ApplicationConnectionException("The application IPC operation timed out.", exception);
        }
        catch (IOException exception)
        {
            throw new ApplicationConnectionException("The application IPC connection failed.", exception);
        }
        catch (IpcProtocolException exception)
        {
            throw new ApplicationHandshakeException(exception.Message);
        }
    }

    private static async Task<IpcMessage> ReadResponseAsync(
        NamedPipeClientStream pipe,
        string requestId,
        CancellationToken cancellationToken)
    {
        IpcMessage response = await IpcProtocol.ReadAsync(pipe, cancellationToken).ConfigureAwait(false);
        IpcProtocol.ValidateEnvelope(response, TimeSpan.FromSeconds(30));
        if (!string.Equals(response.RequestId, requestId, StringComparison.Ordinal))
        {
            throw new ApplicationHandshakeException("The application returned an unrelated IPC response.");
        }
        if (response.MessageType == IpcProtocol.Error)
        {
            throw new ApplicationHandshakeException("The application rejected the IPC request.");
        }
        return response;
    }

    private static void ValidateServerProcess(SafePipeHandle pipe, int expectedProcessId)
    {
        if (!OperatingSystem.IsWindows())
        {
            return;
        }

        if (!GetNamedPipeServerProcessId(pipe, out uint actualProcessId))
        {
            throw new ApplicationIdentityMismatchException();
        }

        if (actualProcessId != (uint) expectedProcessId)
        {
            try
            {
                using Process process = Process.GetProcessById((int)actualProcessId);
                string actualPath = process.MainModule is not null ? process.MainModule.FileName : "<unknown>";
                Console.WriteLine($"[ipc] Named pipe server PID mismatch. expected={expectedProcessId}, actual={actualProcessId}, actualPath={actualPath}");
            }
            catch
            {
                Console.WriteLine($"[ipc] Named pipe server PID mismatch. expected={expectedProcessId}, actual={actualProcessId}, actualPath=<unavailable>");
            }

            throw new ApplicationIdentityMismatchException();
        }
    }

    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetNamedPipeServerProcessId(SafePipeHandle pipe, out uint serverProcessId);
}
