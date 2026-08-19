using System.Text;

internal static class ApplicationLog
{
    private static readonly object Gate = new();
    private static readonly string LogDirectory = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "Pericles",
        "Logs");

    public static void Write(string message)
    {
        string line = $"[{DateTimeOffset.Now:yyyy-MM-dd HH:mm:ss.fff zzz}] {message}";
#if DEBUG
        Console.WriteLine(line);
#else
        try
        {
            lock (Gate)
            {
                Directory.CreateDirectory(LogDirectory);
                string path = Path.Combine(LogDirectory, $"module-host-{DateTime.UtcNow:yyyyMMdd}.log");
                File.AppendAllText(path, line + Environment.NewLine, Encoding.UTF8);
            }
        }
        catch
        {
            // Background diagnostics must never interrupt the module host.
        }
#endif
    }
}
