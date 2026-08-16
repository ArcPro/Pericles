using System.Buffers.Binary;
using System.Text.Json;
using Microsoft.Extensions.Options;

namespace Launcher.Modules;

public sealed class ModulePackageParser(IOptions<ModuleOptions> options) : IModulePackageParser
{
    private const int HeaderBytes = 6;
    private const int MaximumManifestBytes = 64 * 1024;
    private readonly int _maximumPackageBytes = checked(options.Value.MaxPackageSizeMb * 1024 * 1024 + 64 * 1024);

    public ModulePackage Parse(ReadOnlyMemory<byte> packageBytes)
    {
        if (packageBytes.Length < HeaderBytes + 5 * sizeof(uint) || packageBytes.Length > _maximumPackageBytes)
        {
            throw new InvalidModulePackageException("The module package size is invalid.");
        }
        ReadOnlySpan<byte> data = packageBytes.Span;
        if (!data[..4].SequenceEqual("PERI"u8))
        {
            throw new InvalidModulePackageException("The module package magic is invalid.");
        }
        ushort packageVersion = BinaryPrimitives.ReadUInt16BigEndian(data.Slice(4, 2));
        if (packageVersion != 1)
        {
            throw new InvalidModulePackageException("The module package version is not supported.");
        }
        int offset = HeaderBytes;
        ReadOnlyMemory<byte> manifestBytes = ReadSection(packageBytes, ref offset, MaximumManifestBytes, allowEmpty: false);
        ReadOnlyMemory<byte> nonce = ReadSection(packageBytes, ref offset, 12, allowEmpty: false);
        if (nonce.Length != 12) throw new InvalidModulePackageException("The AES-GCM nonce length is invalid.");
        ReadOnlyMemory<byte> tag = ReadSection(packageBytes, ref offset, 16, allowEmpty: false);
        if (tag.Length != 16) throw new InvalidModulePackageException("The AES-GCM tag length is invalid.");
        ReadOnlyMemory<byte> ciphertext = ReadSection(packageBytes, ref offset, _maximumPackageBytes, allowEmpty: false);
        int signedLength = offset;
        ReadOnlyMemory<byte> signature = ReadSection(packageBytes, ref offset, 256, allowEmpty: false);
        if (signature.Length < 8 || offset != packageBytes.Length)
        {
            throw new InvalidModulePackageException("The module package contains invalid trailing data.");
        }

        ModuleManifest manifest;
        try
        {
            manifest = JsonSerializer.Deserialize<ModuleManifest>(manifestBytes.Span, new JsonSerializerOptions(JsonSerializerDefaults.Web))
                ?? throw new JsonException("Empty manifest.");
        }
        catch (Exception exception) when (exception is JsonException or NotSupportedException)
        {
            throw new InvalidModulePackageException("The module manifest is invalid.", exception);
        }
        if (manifest.PackageVersion != packageVersion
            || string.IsNullOrWhiteSpace(manifest.GameSlug)
            || string.IsNullOrWhiteSpace(manifest.ModuleSlug)
            || string.IsNullOrWhiteSpace(manifest.ModuleVersion)
            || !System.Text.RegularExpressions.Regex.IsMatch(manifest.PayloadSha256, "^[a-f0-9]{64}$")
            || manifest.PayloadSize < 1
            || manifest.PayloadSize != ciphertext.Length
            || string.IsNullOrWhiteSpace(manifest.SigningKeyId))
        {
            throw new InvalidModulePackageException("The module manifest fields are invalid.");
        }
        return new ModulePackage(
            packageVersion,
            manifest,
            manifestBytes,
            nonce,
            tag,
            ciphertext,
            signature,
            packageBytes[..signedLength]);
    }

    private static ReadOnlyMemory<byte> ReadSection(
        ReadOnlyMemory<byte> package,
        ref int offset,
        int maximumLength,
        bool allowEmpty)
    {
        if (offset > package.Length - sizeof(uint))
        {
            throw new InvalidModulePackageException("The module package is truncated.");
        }
        uint rawLength = BinaryPrimitives.ReadUInt32BigEndian(package.Span.Slice(offset, sizeof(uint)));
        offset += sizeof(uint);
        if (rawLength > int.MaxValue || rawLength > maximumLength || (!allowEmpty && rawLength == 0))
        {
            throw new InvalidModulePackageException("A module package section length is invalid.");
        }
        int length = (int) rawLength;
        if (offset > package.Length - length)
        {
            throw new InvalidModulePackageException("The module package is truncated.");
        }
        ReadOnlyMemory<byte> section = package.Slice(offset, length);
        offset += length;
        return section;
    }
}
