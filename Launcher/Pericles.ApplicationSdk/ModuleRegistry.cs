using System.Security.Cryptography;

namespace Pericles.ApplicationSdk;

internal sealed class ModuleRegistry(
    PericlesModuleHostOptions options,
    IProcessInjector injector,
    Action<string>? diagnostic = null)
{
    private readonly Dictionary<string, LoadedModule> _modules = new(StringComparer.Ordinal);
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly string _runtimeRoot = EnsureDirectoryRoot(options.RuntimeRoot);

    public async Task<ModuleResultPayload> LoadAsync(LoadModulePayload request, CancellationToken cancellationToken)
    {
        if (!string.Equals(request.Game, options.GameSlug, StringComparison.Ordinal)
            || !System.Text.RegularExpressions.Regex.IsMatch(request.Sha256, "^[a-f0-9]{64}$"))
        {
            return new ModuleResultPayload(request.Game, request.Version, "invalid_request");
        }

        await _gate.WaitAsync(cancellationToken).ConfigureAwait(false);
        try
        {
            if (_modules.TryGetValue(request.Game, out LoadedModule? existing))
            {
                string code = existing.Version == request.Version
                    && existing.Sha256 == request.Sha256
                    && existing.Initialized
                        ? "already_loaded"
                        : "restart_required";
                return new ModuleResultPayload(request.Game, request.Version, code);
            }

            string path;
            try
            {
                path = ValidatePath(request.Path);
            }
            catch
            {
                return new ModuleResultPayload(request.Game, request.Version, "invalid_path");
            }

            // Vérifier le hash du fichier
            byte[] actualHash;
            await using (var stream = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.Read))
            {
                actualHash = await SHA256.HashDataAsync(stream, cancellationToken).ConfigureAwait(false);
            }
            byte[] expectedHash = Convert.FromHexString(request.Sha256);
            if (!CryptographicOperations.FixedTimeEquals(actualHash, expectedHash))
            {
                return new ModuleResultPayload(request.Game, request.Version, "hash_mismatch");
            }

            try
            {
                diagnostic?.Invoke(
                    $"Injecting module {request.Game} {request.Version} into game PID {request.GameProcessId}.");
                ProcessInjectionResult result = injector.Inject(
                    request.GameProcessId,
                    path,
                    request.EntryPoint);
                diagnostic?.Invoke(
                    $"Injection result for game PID {request.GameProcessId}: code={result.Code}, nativeError={result.NativeError?.ToString() ?? "none"}, detail={result.ErrorMessage ?? "none"}.");

                switch (result.Code)
                {
                    case "loaded":
                        _modules.Add(request.Game, new LoadedModule(
                            request.Version,
                            request.Sha256,
                            new NativeModuleReference(result.RemoteModuleHandle ?? nint.Zero, path, request.EntryPoint),
                            true));
                        return new ModuleResultPayload(request.Game, request.Version, "loaded");

                    case "already_loaded":
                        return new ModuleResultPayload(request.Game, request.Version, "already_loaded");

                    case "initialization_failed":
                        // Le module est chargé mais l'initialisation a échoué
                        if (result.RemoteModuleHandle.HasValue && result.RemoteModuleHandle.Value != nint.Zero)
                        {
                            _modules.Add(request.Game, new LoadedModule(
                                request.Version,
                                request.Sha256,
                                new NativeModuleReference(result.RemoteModuleHandle.Value, path, request.EntryPoint),
                                false));
                        }
                        return new ModuleResultPayload(request.Game, request.Version, "initialization_failed");

                    case "invalid_path":
                    case "hash_mismatch":
                    case "load_failed":
                    default:
                        return new ModuleResultPayload(request.Game, request.Version, result.Code);
                }
            }
            catch (Exception exception)
            {
                diagnostic?.Invoke(
                    $"Injection threw {exception.GetType().Name}: {exception.Message}");
                return new ModuleResultPayload(request.Game, request.Version, "load_failed");
            }
        }
        finally
        {
            _gate.Release();
        }
    }

    private string ValidatePath(string path)
    {
        if (string.IsNullOrWhiteSpace(path) || !Path.IsPathFullyQualified(path)
            || path.StartsWith("\\\\", StringComparison.Ordinal)
            || !string.Equals(Path.GetExtension(path), ".dll", StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidOperationException("Invalid runtime module path.");
        }
        string canonical = Path.GetFullPath(path);
        var file = new FileInfo(canonical);
        if (!file.Exists)
        {
            throw new InvalidOperationException("The runtime module does not exist.");
        }
        FileSystemInfo? resolvedLink = file.ResolveLinkTarget(returnFinalTarget: true);
        if (resolvedLink is not null)
        {
            canonical = Path.GetFullPath(resolvedLink.FullName);
        }
        if (canonical.StartsWith("\\\\", StringComparison.Ordinal)
            || !canonical.StartsWith(_runtimeRoot, StringComparison.OrdinalIgnoreCase)
            || !File.Exists(canonical))
        {
            throw new InvalidOperationException("The runtime module is outside the allowed root.");
        }
        return canonical;
    }

    private static string EnsureDirectoryRoot(string path)
    {
        string canonical = Path.GetFullPath(path).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
        return canonical + Path.DirectorySeparatorChar;
    }

    private sealed record LoadedModule(
        string Version,
        string Sha256,
        NativeModuleReference Reference,
        bool Initialized);
}
