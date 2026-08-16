using System.Security.Cryptography;
using Launcher.Authentication;
using Microsoft.Extensions.Logging;

namespace Launcher.Modules;

public sealed class ModuleService(
    IModuleApiClient apiClient,
    IModulePackageParser parser,
    IModulePackageVerifier verifier,
    IModuleDecryptor decryptor,
    ILogger<ModuleService> logger) : IModuleService
{
    public async Task<PreparedModule> PrepareModuleAsync(
        AuthSession session,
        string gameSlug,
        CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(session);
        ArgumentException.ThrowIfNullOrWhiteSpace(gameSlug);
        ModuleTicketResponse ticket = await apiClient.RequestTicketAsync(gameSlug, session.AccessToken, cancellationToken);
        if (!string.Equals(ticket.Game, gameSlug, StringComparison.Ordinal))
        {
            throw new ModuleGameMismatchException("The ticket game does not match the requested game.");
        }

        DownloadedModulePackage download = await apiClient.DownloadAsync(ticket, session.AccessToken, cancellationToken);
        try
        {
            ModulePackage package = parser.Parse(download.PackageBytes);
            verifier.Verify(package);
            if (!string.Equals(package.Manifest.GameSlug, gameSlug, StringComparison.Ordinal)
                || !string.Equals(package.Manifest.GameSlug, ticket.Game, StringComparison.Ordinal))
            {
                throw new ModuleGameMismatchException("The module package belongs to another game.");
            }
            if (!string.Equals(package.Manifest.ModuleVersion, ticket.Module.Version, StringComparison.Ordinal))
            {
                throw new InvalidModulePackageException("The module version does not match the ticket.");
            }

            byte[] plaintext = decryptor.Decrypt(package, download.SessionKey);
            try
            {
                if (plaintext.Length != package.Manifest.PayloadSize)
                {
                    throw new ModuleHashMismatchException("The module payload size is invalid.");
                }
                byte[] actualHash = SHA256.HashData(plaintext);
                byte[] expectedHash;
                try
                {
                    expectedHash = Convert.FromHexString(package.Manifest.PayloadSha256);
                }
                catch (FormatException)
                {
                    throw new InvalidModulePackageException("The module payload hash encoding is invalid.");
                }
                if (!CryptographicOperations.FixedTimeEquals(actualHash, expectedHash))
                {
                    throw new ModuleHashMismatchException("The module payload hash is invalid.");
                }
                logger.LogInformation(
                    "Module payload validated for {GameSlug} version {ModuleVersion}.",
                    gameSlug,
                    package.Manifest.ModuleVersion);
                return new PreparedModule(
                    gameSlug,
                    package.Manifest.ModuleSlug,
                    package.Manifest.ModuleVersion,
                    plaintext,
                    package.Manifest.PayloadSha256);
            }
            catch
            {
                CryptographicOperations.ZeroMemory(plaintext);
                throw;
            }
        }
        finally
        {
            CryptographicOperations.ZeroMemory(download.SessionKey);
        }
    }
}
