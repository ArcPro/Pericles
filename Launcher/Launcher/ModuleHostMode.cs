using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Text;
using Pericles.ApplicationSdk;

namespace Launcher;

internal static class ModuleHostMode
{
    internal const string ModeArgument = "--pericles-module-host";

    internal static bool IsRequested(IReadOnlyList<string> args) =>
        args.Any(value => string.Equals(value, ModeArgument, StringComparison.Ordinal));

    internal static async Task<int> RunAsync(IReadOnlyList<string> args)
    {
        try
        {
            string game = GetRequiredArgument(args, "--game");
            string identity = GetRequiredArgument(args, "--identity");
            string pipe = GetRequiredArgument(args, "--pipe");
            int parentProcessId = int.Parse(
                GetRequiredArgument(args, "--parent-pid"),
                NumberStyles.None,
                CultureInfo.InvariantCulture);
            if (parentProcessId <= 0 || parentProcessId == Environment.ProcessId)
            {
                throw new InvalidOperationException("The module host parent process is invalid.");
            }

            using Process parent = Process.GetProcessById(parentProcessId);
            await using var host = new PericlesModuleHost(
                PericlesModuleHostOptions.FromLauncherEnvironment(identity, game, pipe));
            host.DiagnosticMessage += message => ModuleHostLog.Write($"[module-host] {message}");

            ModuleHostLog.Write(
                $"Starting embedded module host. identity={identity}, game={game}, pipe={pipe}, parent={parentProcessId}");
            await host.StartAsync();
            await parent.WaitForExitAsync();
            ModuleHostLog.Write("Launcher parent exited; stopping embedded module host.");
            await host.StopAsync();
            return 0;
        }
        catch (Exception exception)
        {
            ModuleHostLog.Write($"Embedded module host failed: {exception}");
            return 1;
        }
    }

    private static string GetRequiredArgument(IReadOnlyList<string> args, string name)
    {
        for (int index = 0; index < args.Count - 1; index++)
        {
            if (string.Equals(args[index], name, StringComparison.Ordinal))
            {
                string value = args[index + 1];
                if (!string.IsNullOrWhiteSpace(value))
                {
                    return value;
                }
            }
        }
        throw new InvalidOperationException($"The required module host argument '{name}' is missing.");
    }

    private static class ModuleHostLog
    {
        private static readonly object Gate = new();
        private static readonly string LogDirectory = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "Pericles",
            "Logs");

        internal static void Write(string message)
        {
            try
            {
                lock (Gate)
                {
                    Directory.CreateDirectory(LogDirectory);
                    string path = Path.Combine(LogDirectory, $"module-host-{DateTime.UtcNow:yyyyMMdd}.log");
                    string line = $"[{DateTimeOffset.Now:yyyy-MM-dd HH:mm:ss.fff zzz}] {message}{Environment.NewLine}";
                    File.AppendAllText(path, line, Encoding.UTF8);
                }
            }
            catch
            {
                // Diagnostics must not interrupt the hidden host process.
            }
        }
    }
}
