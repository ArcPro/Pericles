<?php

declare(strict_types=1);

namespace Pericles\Device;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Pericles\Http\ApiException;
use Throwable;

final class DeviceService
{
    private PDO $database;

    private int $challengeTtl;

    private int $maximumDevices;

    private string $driver;

    private bool $transactionActive = false;

    public function __construct(PDO $database, int $challengeTtl = 60, int $maximumDevices = 3)
    {
        $this->database = $database;
        $this->challengeTtl = max(30, min(60, $challengeTtl));
        $this->maximumDevices = max(1, $maximumDevices);
        $this->driver = (string) $database->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function register(
        int $userId,
        string $deviceId,
        string $publicKey,
        string $keyAlgorithm,
        string $displayName
    ): array {
        $deviceId = $this->validateDeviceId($deviceId);
        $displayName = $this->validateDisplayName($displayName);
        $canonicalPublicKey = $this->validatePublicKey($publicKey, $keyAlgorithm);
        $publicKeyHash = hash('sha256', $canonicalPublicKey);

        $this->beginWriteTransaction();
        try {
            $this->lockUser($userId);
            $existing = $this->findByDeviceId($deviceId, true);
            if (is_array($existing)) {
                if ((int) $existing['user_id'] !== $userId) {
                    throw new ApiException('device_claimed', 409, 'This device belongs to another account.');
                }
                if ($existing['revoked_at'] !== null) {
                    throw new ApiException('device_revoked', 403, 'This device is revoked.');
                }
                if (!hash_equals((string) $existing['public_key_hash'], $publicKeyHash)
                    || (string) $existing['key_algorithm'] !== $keyAlgorithm) {
                    throw new ApiException('device_key_mismatch', 409, 'The registered public key cannot be replaced.');
                }

                $statement = $this->database->prepare(
                    'UPDATE devices SET display_name = :display_name WHERE id = :id'
                );
                $statement->execute([':display_name' => $displayName, ':id' => (int) $existing['id']]);
                $existing['display_name'] = $displayName;
                $this->commit();
                return $this->toPublicDevice($existing, $deviceId);
            }

            $count = $this->database->prepare(
                'SELECT COUNT(*) FROM devices WHERE user_id = :user_id AND revoked_at IS NULL'
            );
            $count->execute([':user_id' => $userId]);
            if ((int) $count->fetchColumn() >= $this->maximumDevices) {
                throw new ApiException('device_limit_reached', 409, 'The maximum number of devices has been reached.');
            }

            $now = $this->now();
            $statement = $this->database->prepare(
                'INSERT INTO devices '
                . '(user_id, device_id, public_key, public_key_hash, key_algorithm, display_name, created_at) '
                . 'VALUES (:user_id, :device_id, :public_key, :public_key_hash, :key_algorithm, :display_name, :created_at)'
            );
            $statement->execute([
                ':user_id' => $userId,
                ':device_id' => $deviceId,
                ':public_key' => $canonicalPublicKey,
                ':public_key_hash' => $publicKeyHash,
                ':key_algorithm' => $keyAlgorithm,
                ':display_name' => $displayName,
                ':created_at' => $now,
            ]);
            $created = [
                'device_id' => $deviceId,
                'display_name' => $displayName,
                'created_at' => $now,
                'last_seen_at' => null,
                'verified_at' => null,
            ];
            $this->commit();
            return $this->toPublicDevice($created, $deviceId);
        } catch (PDOException $exception) {
            $this->rollBack();
            if (in_array((string) $exception->getCode(), ['19', '23000'], true)) {
                $existing = $this->findByDeviceId($deviceId, false);
                if (is_array($existing)) {
                    if ((int) $existing['user_id'] !== $userId) {
                        throw new ApiException('device_claimed', 409, 'This device belongs to another account.');
                    }
                    if ($existing['revoked_at'] !== null) {
                        throw new ApiException('device_revoked', 403, 'This device is revoked.');
                    }
                    if (!hash_equals((string) $existing['public_key_hash'], $publicKeyHash)
                        || (string) $existing['key_algorithm'] !== $keyAlgorithm) {
                        throw new ApiException('device_key_mismatch', 409, 'The registered public key cannot be replaced.');
                    }
                    return $this->toPublicDevice($existing, $deviceId);
                }
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    public function createChallenge(int $userId, string $deviceId): array
    {
        $deviceId = $this->validateDeviceId($deviceId);
        $this->beginWriteTransaction();
        try {
            $device = $this->findOwnedDevice($userId, $deviceId, true);
            $this->ensureActive($device);

            $challenge = random_bytes(32);
            $challengeId = bin2hex(random_bytes(32));
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $expiresAt = $now->modify('+' . $this->challengeTtl . ' seconds');
            $statement = $this->database->prepare(
                'INSERT INTO device_challenges '
                . '(challenge_id, device_id, challenge_hash, challenge_data, created_at, expires_at) '
                . 'VALUES (:challenge_id, :device_id, :challenge_hash, :challenge_data, :created_at, :expires_at)'
            );
            $statement->bindValue(':challenge_id', $challengeId);
            $statement->bindValue(':device_id', (int) $device['id'], PDO::PARAM_INT);
            $statement->bindValue(':challenge_hash', hash('sha256', $challenge));
            $statement->bindValue(':challenge_data', $challenge, PDO::PARAM_LOB);
            $statement->bindValue(':created_at', $now->format('Y-m-d H:i:s'));
            $statement->bindValue(':expires_at', $expiresAt->format('Y-m-d H:i:s'));
            $statement->execute();
            $this->commit();

            return [
                'challenge_id' => $challengeId,
                'challenge' => Base64Url::encode($challenge),
                'expires_at' => $expiresAt->format(DATE_ATOM),
            ];
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    public function migrateHardwareId(int $userId, int $deviceRowId, string $hardwareDeviceId): array
    {
        $hardwareDeviceId = $this->validateDeviceId($hardwareDeviceId);
        $this->beginWriteTransaction();
        try {
            $suffix = $this->driver === 'mysql' ? ' FOR UPDATE' : '';
            $statement = $this->database->prepare(
                'SELECT * FROM devices WHERE id = :id AND user_id = :user_id LIMIT 1' . $suffix
            );
            $statement->execute([':id' => $deviceRowId, ':user_id' => $userId]);
            $device = $statement->fetch();
            if (!is_array($device)) {
                throw new ApiException('device_not_found', 404, 'Device not found.');
            }
            $this->ensureActive($device);
            if (hash_equals((string) $device['device_id'], $hardwareDeviceId)) {
                $this->commit();
                return $this->toPublicDevice($device, $hardwareDeviceId);
            }

            $existing = $this->findByDeviceId($hardwareDeviceId, true);
            if (is_array($existing) && (int) $existing['id'] !== $deviceRowId) {
                throw new ApiException(
                    'device_claimed',
                    409,
                    'This hardware identity is already registered.'
                );
            }

            $update = $this->database->prepare(
                'UPDATE devices SET device_id = :device_id WHERE id = :id AND user_id = :user_id'
            );
            $update->execute([
                ':device_id' => $hardwareDeviceId,
                ':id' => $deviceRowId,
                ':user_id' => $userId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new ApiException('device_not_found', 404, 'Device not found.');
            }
            $device['device_id'] = $hardwareDeviceId;
            $this->commit();
            return $this->toPublicDevice($device, $hardwareDeviceId);
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    public function verify(
        int $userId,
        string $deviceId,
        string $challengeId,
        string $encodedSignature,
        ?int $apiSessionId = null
    ): array {
        $deviceId = $this->validateDeviceId($deviceId);
        if (!preg_match('/^[a-f0-9]{64}$/', $challengeId)) {
            throw new ApiException('validation_error', 400, 'Invalid challenge identifier.');
        }
        $signature = Base64Url::decode($encodedSignature);
        if ($signature === null || strlen($signature) < 8 || strlen($signature) > 160) {
            throw new ApiException('validation_error', 400, 'Invalid signature encoding.');
        }

        $this->beginWriteTransaction();
        try {
            $suffix = $this->driver === 'mysql' ? ' FOR UPDATE' : '';
            $statement = $this->database->prepare(
                'SELECT c.id AS challenge_row_id, c.challenge_hash, c.challenge_data, c.expires_at, c.used_at, '
                . 'd.id AS device_row_id, d.public_key, d.revoked_at '
                . 'FROM device_challenges c INNER JOIN devices d ON d.id = c.device_id '
                . 'WHERE c.challenge_id = :challenge_id AND d.device_id = :device_id AND d.user_id = :user_id LIMIT 1'
                . $suffix
            );
            $statement->execute([
                ':challenge_id' => $challengeId,
                ':device_id' => $deviceId,
                ':user_id' => $userId,
            ]);
            $row = $statement->fetch();
            if (!is_array($row)) {
                throw new ApiException('challenge_not_found', 404, 'Device challenge not found.');
            }
            if ($row['revoked_at'] !== null) {
                throw new ApiException('device_revoked', 403, 'This device is revoked.');
            }
            if ($row['used_at'] !== null) {
                throw new ApiException('challenge_used', 409, 'This challenge has already been used.');
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if (new DateTimeImmutable((string) $row['expires_at'], new DateTimeZone('UTC')) <= $now) {
                throw new ApiException('challenge_expired', 410, 'This challenge has expired.');
            }
            $challenge = is_resource($row['challenge_data'])
                ? (string) stream_get_contents($row['challenge_data'])
                : (string) $row['challenge_data'];
            if (strlen($challenge) !== 32
                || !hash_equals((string) $row['challenge_hash'], hash('sha256', $challenge))) {
                throw new ApiException('challenge_invalid', 500, 'The stored challenge is invalid.');
            }
            if (openssl_verify($challenge, $signature, (string) $row['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
                throw new ApiException('invalid_signature', 403, 'The device signature is invalid.');
            }

            $nowSql = $now->format('Y-m-d H:i:s');
            $consume = $this->database->prepare(
                'UPDATE device_challenges SET used_at = :used_at '
                . 'WHERE id = :id AND used_at IS NULL AND expires_at > :now'
            );
            $consume->execute([
                ':used_at' => $nowSql,
                ':id' => (int) $row['challenge_row_id'],
                ':now' => $nowSql,
            ]);
            if ($consume->rowCount() !== 1) {
                throw new ApiException('challenge_used', 409, 'This challenge is no longer usable.');
            }

            $update = $this->database->prepare(
                'UPDATE devices SET verified_at = :verified_at, last_seen_at = :last_seen_at WHERE id = :id'
            );
            $update->execute([
                ':verified_at' => $nowSql,
                ':last_seen_at' => $nowSql,
                ':id' => (int) $row['device_row_id'],
            ]);
            if ($apiSessionId !== null) {
                $linkSession = $this->database->prepare(
                    'UPDATE api_sessions SET device_id = :device_id '
                    . 'WHERE id = :session_id AND user_id = :user_id AND revoked_at IS NULL'
                );
                $linkSession->execute([
                    ':device_id' => (int) $row['device_row_id'],
                    ':session_id' => $apiSessionId,
                    ':user_id' => $userId,
                ]);
                if ($linkSession->rowCount() !== 1) {
                    $linked = $this->database->prepare(
                        'SELECT COUNT(*) FROM api_sessions WHERE id = :session_id AND user_id = :user_id '
                        . 'AND device_id = :device_id AND revoked_at IS NULL'
                    );
                    $linked->execute([
                        ':session_id' => $apiSessionId,
                        ':user_id' => $userId,
                        ':device_id' => (int) $row['device_row_id'],
                    ]);
                    if ((int) $linked->fetchColumn() !== 1) {
                        throw new ApiException('invalid_token', 401, 'Invalid access token.');
                    }
                }
            }
            $this->commit();
            return ['device_verified' => true, 'verified_at' => $now->format(DATE_ATOM)];
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    public function listDevices(int $userId, ?string $currentDeviceId): array
    {
        $statement = $this->database->prepare(
            'SELECT device_id, display_name, created_at, last_seen_at, verified_at '
            . 'FROM devices WHERE user_id = :user_id AND revoked_at IS NULL ORDER BY created_at ASC'
        );
        $statement->execute([':user_id' => $userId]);
        $devices = [];
        foreach ($statement->fetchAll() as $row) {
            $devices[] = $this->toPublicDevice($row, $currentDeviceId);
        }
        return $devices;
    }

    public function revoke(int $userId, string $deviceId): void
    {
        $deviceId = $this->validateDeviceId($deviceId);
        $this->beginWriteTransaction();
        try {
            $device = $this->findOwnedDevice($userId, $deviceId, true);
            $this->ensureActive($device);
            $now = $this->now();
            $statement = $this->database->prepare(
                'UPDATE devices SET revoked_at = :revoked_at WHERE id = :id AND revoked_at IS NULL'
            );
            $statement->execute([':revoked_at' => $now, ':id' => (int) $device['id']]);
            $challenges = $this->database->prepare(
                'UPDATE device_challenges SET used_at = :used_at WHERE device_id = :device_id AND used_at IS NULL'
            );
            $challenges->execute([':used_at' => $now, ':device_id' => (int) $device['id']]);
            $this->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    private function validateDeviceId(string $deviceId): string
    {
        $deviceId = strtolower(trim($deviceId));
        if (!preg_match('/^[a-f0-9]{32}$/', $deviceId)) {
            throw new ApiException('validation_error', 400, 'Invalid device identifier.');
        }
        return $deviceId;
    }

    private function validateDisplayName(string $displayName): string
    {
        $displayName = trim($displayName);
        if ($displayName === '' || strlen($displayName) > 100 || preg_match('/[\x00-\x1F\x7F]/', $displayName)) {
            throw new ApiException('validation_error', 400, 'Invalid device display name.');
        }
        return $displayName;
    }

    private function validatePublicKey(string $publicKey, string $keyAlgorithm): string
    {
        if ($keyAlgorithm !== 'ECDSA-P256' || strlen($publicKey) > 2048) {
            throw new ApiException('validation_error', 400, 'Unsupported device key algorithm.');
        }
        $key = openssl_pkey_get_public($publicKey);
        if ($key === false) {
            throw new ApiException('validation_error', 400, 'Invalid device public key.');
        }
        $details = openssl_pkey_get_details($key);
        $curveName = is_array($details) && isset($details['ec']['curve_name'])
            ? (string) $details['ec']['curve_name']
            : '';
        if (!is_array($details)
            || (int) ($details['type'] ?? -1) !== OPENSSL_KEYTYPE_EC
            || (int) ($details['bits'] ?? 0) !== 256
            || !in_array($curveName, ['prime256v1', 'secp256r1'], true)
            || !is_string($details['key'] ?? null)) {
            throw new ApiException('validation_error', 400, 'The public key must use ECDSA P-256.');
        }
        return trim($details['key']) . "\n";
    }

    private function findOwnedDevice(int $userId, string $deviceId, bool $lock): array
    {
        $suffix = $lock && $this->driver === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->database->prepare(
            'SELECT * FROM devices WHERE user_id = :user_id AND device_id = :device_id LIMIT 1' . $suffix
        );
        $statement->execute([':user_id' => $userId, ':device_id' => $deviceId]);
        $device = $statement->fetch();
        if (!is_array($device)) {
            throw new ApiException('device_not_found', 404, 'Device not found.');
        }
        return $device;
    }

    private function findByDeviceId(string $deviceId, bool $lock): ?array
    {
        $suffix = $lock && $this->driver === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->database->prepare(
            'SELECT * FROM devices WHERE device_id = :device_id LIMIT 1' . $suffix
        );
        $statement->execute([':device_id' => $deviceId]);
        $device = $statement->fetch();
        return is_array($device) ? $device : null;
    }

    private function ensureActive(array $device): void
    {
        if ($device['revoked_at'] !== null) {
            throw new ApiException('device_revoked', 403, 'This device is revoked.');
        }
    }

    private function lockUser(int $userId): void
    {
        $suffix = $this->driver === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->database->prepare('SELECT id FROM users WHERE id = :id LIMIT 1' . $suffix);
        $statement->execute([':id' => $userId]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException('invalid_token', 401, 'Invalid access token.');
        }
    }

    private function toPublicDevice(array $row, ?string $currentDeviceId): array
    {
        return [
            'device_id' => (string) $row['device_id'],
            'display_name' => (string) $row['display_name'],
            'created_at' => $this->toAtom((string) $row['created_at']),
            'last_seen_at' => $row['last_seen_at'] === null ? null : $this->toAtom((string) $row['last_seen_at']),
            'verified_at' => $row['verified_at'] === null ? null : $this->toAtom((string) $row['verified_at']),
            'is_current' => $currentDeviceId !== null
                && hash_equals(strtolower($currentDeviceId), (string) $row['device_id']),
        ];
    }

    private function beginWriteTransaction(): void
    {
        if ($this->driver === 'sqlite') {
            $this->database->exec('BEGIN IMMEDIATE TRANSACTION');
        } else {
            $this->database->beginTransaction();
        }
        $this->transactionActive = true;
    }

    private function commit(): void
    {
        if ($this->driver === 'sqlite') {
            $this->database->exec('COMMIT');
        } else {
            $this->database->commit();
        }
        $this->transactionActive = false;
    }

    private function rollBack(): void
    {
        if (!$this->transactionActive) {
            return;
        }
        if ($this->driver === 'sqlite') {
            $this->database->exec('ROLLBACK');
        } elseif ($this->database->inTransaction()) {
            $this->database->rollBack();
        }
        $this->transactionActive = false;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function toAtom(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
