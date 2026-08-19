using Pericles.ApplicationSdk;

using var shutdown = new CancellationTokenSource();
Console.CancelKeyPress += (_, eventArgs) =>
{
    eventArgs.Cancel = true;
    shutdown.Cancel();
};

ApplicationLog.Write("Starting module host. identity=pericles-demo-application, game=deadlock, pipe=Pericles.Deadlock");

try
{
    await using var host = new PericlesModuleHost(PericlesModuleHostOptions.FromLauncherEnvironment(
        "pericles-demo-application",
        "deadlock",
        "Pericles.Deadlock"));
    host.DiagnosticMessage += message =>
        ApplicationLog.Write($"[module-host] {message}");

    await host.StartAsync(shutdown.Token);
    ApplicationLog.Write("Pericles Demo Application prête.");
    try
    {
        await Task.Delay(Timeout.InfiniteTimeSpan, shutdown.Token);
    }
    catch (OperationCanceledException)
    {
    }
}
catch (Exception ex)
{
    ApplicationLog.Write($"Startup failed: {ex}");
    Environment.ExitCode = 1;
}
