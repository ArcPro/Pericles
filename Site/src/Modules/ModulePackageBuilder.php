<?php

declare(strict_types=1);

namespace Pericles\Modules;

use DateTimeImmutable;
use DateTimeZone;
use OpenSSLAsymmetricKey;
use Pericles\Http\ApiException;
use RuntimeException;

final class ModulePackageBuilder
{
    public const MAGIC = 'PERI';
    public const PACKAGE_VERSION = 1;
    public const NONCE_BYTES = 12;
    public const TAG_BYTES = 16;

    private OpenSSLAsymmetricKey $privateKey;

    public function __construct(
        string $privateKeyPath,
        private string $signingKeyId,
        private int $maximumPackageBytes
    ) {
        if ($privateKeyPath === '' || !is_file($privateKeyPath) || !is_readable($privateKeyPath)) {
            throw new RuntimeException('Module signing private key is not configured.');
        }
        $publicRoot = realpath(__DIR__ . '/../../public');
        $keyPath = realpath($privateKeyPath);
        if ($keyPath === false || ($publicRoot !== false
                && str_starts_with(strtolower(str_replace('\\', '/', $keyPath)), strtolower(str_replace('\\', '/', $publicRoot)) . '/'))) {
            throw new RuntimeException('Module signing private key must be outside the public web root.');
        }
        $pem = file_get_contents($keyPath);
        $key = is_string($pem) ? openssl_pkey_get_private($pem) : false;
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($key === false || !is_array($details)
            || (int) ($details['type'] ?? -1) !== OPENSSL_KEYTYPE_EC
            || !in_array((string) ($details['ec']['curve_name'] ?? ''), ['prime256v1', 'secp256r1'], true)) {
            throw new RuntimeException('Module signing key must be ECDSA P-256.');
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $this->signingKeyId)) {
            throw new RuntimeException('Invalid module signing key identifier.');
        }
        $this->privateKey = $key;
        $this->maximumPackageBytes = max(1024, $this->maximumPackageBytes);
    }

    public function build(array $authorizedModule, string $plaintext): ModulePackageResult
    {
        $payloadSize = strlen($plaintext);
        if ($payloadSize < 1 || $payloadSize > $this->maximumPackageBytes) {
            throw new ApiException('module_unavailable', 503, 'The module is unavailable.');
        }
        $manifest = [
            'package_version' => self::PACKAGE_VERSION,
            'game_slug' => (string) $authorizedModule['game_slug'],
            'module_slug' => (string) $authorizedModule['module_slug'],
            'module_version' => (string) $authorizedModule['module_version'],
            'payload_sha256' => hash('sha256', $plaintext),
            'payload_size' => $payloadSize,
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'signing_key_id' => $this->signingKeyId,
        ];
        $manifestBytes = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $sessionKey = random_bytes(32);
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $sessionKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $manifestBytes,
            self::TAG_BYTES
        );
        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('Unable to encrypt module package.');
        }

        $signedData = self::MAGIC
            . pack('n', self::PACKAGE_VERSION)
            . pack('N', strlen($manifestBytes)) . $manifestBytes
            . pack('N', strlen($nonce)) . $nonce
            . pack('N', strlen($tag)) . $tag
            . pack('N', strlen($ciphertext)) . $ciphertext;
        $signature = '';
        if (!openssl_sign($signedData, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign module package.');
        }
        $package = $signedData . pack('N', strlen($signature)) . $signature;
        if (strlen($package) > $this->maximumPackageBytes + 64 * 1024) {
            throw new RuntimeException('Generated module package exceeds the configured limit.');
        }
        return new ModulePackageResult($package, $sessionKey, $manifest);
    }
}
