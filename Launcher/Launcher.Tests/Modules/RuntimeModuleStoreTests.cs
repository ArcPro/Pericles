using System.Diagnostics;
using System.Security.Cryptography;
using Launcher.Application;
using Launcher.Modules;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using Xunit;

namespace Launcher.Tests.Modules;

public sealed class RuntimeModuleStoreTests
{
    [Fact]
    public async Task DisposeRetainsModuleWhileMarkedApplicationIsActive()
    {
        string root = CreateRuntimeRoot();
        try
        {
            var store = CreateStore(root);
            using PreparedModule prepared = CreateModule();
            RuntimeModuleHandle runtime = await store.WriteAsync(prepared, CancellationToken.None);
            string modulePath = runtime.Path;
            using Process process = Process.GetCurrentProcess();
            var application = new ApplicationInstance(
                process.MainModule!.FileName,
                process.Id,
                new DateTimeOffset(process.StartTime.ToUniversalTime(), TimeSpan.Zero),
                false,
                "test-session",
                new byte[32]);

            await store.MarkInUseAsync(runtime, application, CancellationToken.None);
            await runtime.DisposeAsync();

            Assert.True(File.Exists(modulePath));
        }
        finally
        {
            if (Directory.Exists(root))
            {
                Directory.Delete(root, recursive: true);
            }
        }
    }

    [Fact]
    public async Task DisposeRemovesModuleThatWasNotMarkedInUse()
    {
        string root = CreateRuntimeRoot();
        try
        {
            var store = CreateStore(root);
            using PreparedModule prepared = CreateModule();
            RuntimeModuleHandle runtime = await store.WriteAsync(prepared, CancellationToken.None);
            string modulePath = runtime.Path;

            await runtime.DisposeAsync();

            Assert.False(File.Exists(modulePath));
        }
        finally
        {
            if (Directory.Exists(root))
            {
                Directory.Delete(root, recursive: true);
            }
        }
    }

    private static RuntimeModuleStore CreateStore(string root) => new(
        Options.Create(new LaunchOptions { RuntimeRoot = root }),
        NullLogger<RuntimeModuleStore>.Instance);

    private static PreparedModule CreateModule()
    {
        byte[] payload = "test-module"u8.ToArray();
        return new PreparedModule(
            "deadlock",
            "deadlock-main",
            "0.1.0",
            payload,
            Convert.ToHexString(SHA256.HashData(payload)).ToLowerInvariant());
    }

    private static string CreateRuntimeRoot() => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "Pericles",
        "Runtime",
        "Launcher.Tests",
        Guid.NewGuid().ToString("N"));
}
