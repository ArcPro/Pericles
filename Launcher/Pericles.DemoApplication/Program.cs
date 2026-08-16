using Pericles.ApplicationSdk;

using var shutdown = new CancellationTokenSource();
Console.CancelKeyPress += (_, eventArgs) =>
{
    eventArgs.Cancel = true;
    shutdown.Cancel();
};

Console.WriteLine("[PericlesDemoApp] Starting module host...");
Console.WriteLine($"[PericlesDemoApp] Expected identity=pericles-demo-application, game=deadlock, pipe=Pericles.Deadlock");
Console.WriteLine($"[PericlesDemoApp] SessionNonce={Environment.GetEnvironmentVariable(PericlesModuleHostOptions.SessionNonceEnvironmentVariable) ?? "<missing>"}");

try
{
    await using var host = new PericlesModuleHost(PericlesModuleHostOptions.FromLauncherEnvironment(
        "pericles-demo-application",
        "deadlock",
        "Pericles.Deadlock"));

    await host.StartAsync(shutdown.Token);
    Console.WriteLine("Pericles Demo Application prête. Ctrl+C pour quitter.");
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
    Console.WriteLine($"[PericlesDemoApp] Startup failed: {ex.GetType().Name}: {ex.Message}");
    Console.WriteLine(ex);
    Environment.ExitCode = 1;
}
