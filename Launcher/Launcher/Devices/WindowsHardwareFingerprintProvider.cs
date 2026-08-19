using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text;
using Microsoft.Win32;

namespace Launcher.Devices;

public sealed class WindowsHardwareFingerprintProvider : IHardwareFingerprintProvider
{
    private const uint RawSmbiosProvider = 0x424D5352; // "RSMB"
    private static readonly byte[] Domain = Encoding.UTF8.GetBytes("Pericles.HardwareIdentity.v1\0");

    public string GetDeviceId()
    {
        if (!OperatingSystem.IsWindows())
        {
            throw new PlatformNotSupportedException("Hardware identity is supported only on Windows.");
        }

        byte[] material = TryReadSmbiosSystemUuid() ?? ReadWindowsMachineGuid();
        try
        {
            using IncrementalHash hasher = IncrementalHash.CreateHash(HashAlgorithmName.SHA256);
            hasher.AppendData(Domain);
            hasher.AppendData(material);
            byte[] digest = hasher.GetHashAndReset();
            try
            {
                return Convert.ToHexString(digest.AsSpan(0, 16)).ToLowerInvariant();
            }
            finally
            {
                CryptographicOperations.ZeroMemory(digest);
            }
        }
        finally
        {
            CryptographicOperations.ZeroMemory(material);
        }
    }

    private static byte[]? TryReadSmbiosSystemUuid()
    {
        uint required = GetSystemFirmwareTable(RawSmbiosProvider, 0, null, 0);
        if (required < 8 || required > 1024 * 1024)
        {
            return null;
        }

        byte[] table = new byte[required];
        uint written = GetSystemFirmwareTable(RawSmbiosProvider, 0, table, required);
        if (written != required || written < 8)
        {
            CryptographicOperations.ZeroMemory(table);
            return null;
        }

        try
        {
            int payloadLength = BitConverter.ToInt32(table, 4);
            int end = Math.Min(table.Length, 8 + Math.Max(payloadLength, 0));
            for (int offset = 8; offset + 4 <= end;)
            {
                byte type = table[offset];
                int structureLength = table[offset + 1];
                if (structureLength < 4 || offset + structureLength > end)
                {
                    break;
                }

                if (type == 1 && structureLength >= 24)
                {
                    byte[] uuid = table.AsSpan(offset + 8, 16).ToArray();
                    bool allZero = uuid.All(value => value == 0);
                    bool allOnes = uuid.All(value => value == byte.MaxValue);
                    if (!allZero && !allOnes)
                    {
                        return uuid;
                    }
                    CryptographicOperations.ZeroMemory(uuid);
                }

                int next = offset + structureLength;
                while (next + 1 < end && (table[next] != 0 || table[next + 1] != 0))
                {
                    next++;
                }
                if (next + 1 >= end || type == 127)
                {
                    break;
                }
                offset = next + 2;
            }
            return null;
        }
        finally
        {
            CryptographicOperations.ZeroMemory(table);
        }
    }

    private static byte[] ReadWindowsMachineGuid()
    {
        using RegistryKey baseKey = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64);
        using RegistryKey? key = baseKey.OpenSubKey(@"SOFTWARE\Microsoft\Cryptography", writable: false);
        string value = key?.GetValue("MachineGuid") as string ?? "";
        if (!Guid.TryParse(value, out Guid machineGuid))
        {
            throw new InvalidOperationException("Windows did not provide a usable hardware identity.");
        }
        return machineGuid.ToByteArray();
    }

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern uint GetSystemFirmwareTable(
        uint firmwareTableProviderSignature,
        uint firmwareTableId,
        [Out] byte[]? firmwareTableBuffer,
        uint bufferSize);
}
