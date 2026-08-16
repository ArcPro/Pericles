using Pericles.ApplicationSdk;
using Xunit;

namespace Launcher.Tests.IPC;

public sealed class IpcProtocolTests
{
    [Fact]
    public void LoadModulePayloadTransmitsGameProcessId()
    {
        var payload = new LoadModulePayload(
            "deadlock",
            "1.0.0",
            @"C:\runtime\module.dll",
            new string('a', 64),
            null,
            4242,
            new string('n', 43));

        IpcMessage message = IpcProtocol.Create(IpcProtocol.LoadModule, payload);
        LoadModulePayload received = IpcProtocol.ReadPayload<LoadModulePayload>(message);

        Assert.Equal(4242, received.GameProcessId);
        Assert.Contains("\"game_process_id\":4242", message.Payload.GetRawText(), StringComparison.Ordinal);
    }
}
