using System.Text.Json.Serialization;

namespace Launcher.Modules;

public sealed record ModuleTicketModule(
    [property: JsonPropertyName("version")] string Version);

public sealed record ModuleTicketResponse(
    [property: JsonPropertyName("ticket")] string Ticket,
    [property: JsonPropertyName("expires_in")] int ExpiresIn,
    [property: JsonPropertyName("game")] string Game,
    [property: JsonPropertyName("module")] ModuleTicketModule Module);

public sealed record DownloadedModulePackage(
    ReadOnlyMemory<byte> PackageBytes,
    byte[] SessionKey,
    ModuleTicketResponse Ticket);
