<?php

declare(strict_types=1);

namespace Pericles\Tests;

use DateTimeImmutable;
use PDO;
use Pericles\Device\Base64Url;
use Pericles\Device\DeviceService;
use Pericles\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class DeviceServiceTest extends TestCase
{
    private PDO $database;

    private DeviceService $devices;

    protected function setUp(): void
    {
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->database->exec(
            "CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE
            );
            CREATE TABLE devices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                device_id TEXT NOT NULL UNIQUE,
                public_key TEXT NOT NULL,
                public_key_hash TEXT NOT NULL,
                key_algorithm TEXT NOT NULL,
                display_name TEXT NOT NULL,
                created_at TEXT NOT NULL,
                last_seen_at TEXT NULL,
                verified_at TEXT NULL,
                revoked_at TEXT NULL
            );
            CREATE TABLE device_challenges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                challenge_id TEXT NOT NULL UNIQUE,
                device_id INTEGER NOT NULL,
                challenge_hash TEXT NOT NULL,
                challenge_data BLOB NOT NULL,
                created_at TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT NULL
            );
            CREATE TABLE api_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                device_id INTEGER NULL,
                revoked_at TEXT NULL
            );"
        );
        $this->database->exec(
            "INSERT INTO users (email) VALUES ('first@example.test'), ('second@example.test')"
        );
        $this->devices = new DeviceService($this->database, 60, 3);
    }

    public function testRegisterNewDevice(): void
    {
        $key = $this->createKeyPair();
        $device = $this->register(1, str_repeat('a', 32), $key['public']);

        self::assertSame(str_repeat('a', 32), $device['device_id']);
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM devices')->fetchColumn());
        self::assertStringNotContainsString('PRIVATE KEY', (string) $this->database->query('SELECT public_key FROM devices')->fetchColumn());
    }

    public function testExistingDeviceRegistrationIsIdempotent(): void
    {
        $key = $this->createKeyPair();
        $this->register(1, str_repeat('a', 32), $key['public']);
        $this->devices->register(1, str_repeat('a', 32), $key['public'], 'ECDSA-P256', 'Renamed PC');

        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM devices')->fetchColumn());
        self::assertSame('Renamed PC', $this->database->query('SELECT display_name FROM devices')->fetchColumn());
    }

    public function testExistingDevicePublicKeyCannotBeReplaced(): void
    {
        $firstKey = $this->createKeyPair();
        $replacementKey = $this->createKeyPair();
        $this->register(1, str_repeat('a', 32), $firstKey['public']);

        $this->assertApiError(
            fn () => $this->devices->register(
                1,
                str_repeat('a', 32),
                $replacementKey['public'],
                'ECDSA-P256',
                'Replaced PC'
            ),
            'device_key_mismatch',
            409
        );
    }

    public function testDeviceCannotBeClaimedByAnotherUser(): void
    {
        $key = $this->createKeyPair();
        $this->register(1, str_repeat('a', 32), $key['public']);

        $this->assertApiError(
            fn () => $this->devices->register(
                2,
                str_repeat('a', 32),
                $key['public'],
                'ECDSA-P256',
                'Other PC'
            ),
            'device_claimed',
            409
        );
    }

    public function testChallengeCreation(): void
    {
        $key = $this->createKeyPair();
        $this->register(1, str_repeat('a', 32), $key['public']);
        $challenge = $this->devices->createChallenge(1, str_repeat('a', 32));

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $challenge['challenge_id']);
        self::assertSame(32, strlen((string) Base64Url::decode($challenge['challenge'])));
        self::assertSame(hash('sha256', (string) Base64Url::decode($challenge['challenge'])),
            $this->database->query('SELECT challenge_hash FROM device_challenges')->fetchColumn());
    }

    public function testHardwareIdMigrationKeepsTheSameDeviceRow(): void
    {
        $key = $this->createKeyPair();
        $this->register(1, str_repeat('a', 32), $key['public']);
        $originalRowId = (int) $this->database->query('SELECT id FROM devices')->fetchColumn();

        $device = $this->devices->migrateHardwareId(1, $originalRowId, str_repeat('b', 32));

        self::assertSame(str_repeat('b', 32), $device['device_id']);
        self::assertSame($originalRowId, (int) $this->database->query('SELECT id FROM devices')->fetchColumn());
        self::assertSame(str_repeat('b', 32), $this->database->query('SELECT device_id FROM devices')->fetchColumn());
    }

    public function testHardwareIdMigrationRejectsAnExistingIdentity(): void
    {
        $firstKey = $this->createKeyPair();
        $secondKey = $this->createKeyPair();
        $this->register(1, str_repeat('a', 32), $firstKey['public']);
        $this->register(1, str_repeat('b', 32), $secondKey['public']);

        $this->assertApiError(
            fn () => $this->devices->migrateHardwareId(1, 1, str_repeat('b', 32)),
            'device_claimed',
            409
        );
    }

    public function testChallengeExpires(): void
    {
        [$key, $challenge] = $this->registeredChallenge();
        $this->database->exec("UPDATE device_challenges SET expires_at = '2000-01-01 00:00:00'");
        $signature = $this->signChallenge($key['private'], $challenge['challenge']);

        $this->assertApiError(
            fn () => $this->devices->verify(1, str_repeat('a', 32), $challenge['challenge_id'], $signature),
            'challenge_expired',
            410
        );
    }

    public function testChallengeIsSingleUse(): void
    {
        [$key, $challenge] = $this->registeredChallenge();
        $signature = $this->signChallenge($key['private'], $challenge['challenge']);
        $this->devices->verify(1, str_repeat('a', 32), $challenge['challenge_id'], $signature);

        $this->assertApiError(
            fn () => $this->devices->verify(1, str_repeat('a', 32), $challenge['challenge_id'], $signature),
            'challenge_used',
            409
        );
    }

    public function testValidSignature(): void
    {
        [$key, $challenge] = $this->registeredChallenge();
        $signature = $this->signChallenge($key['private'], $challenge['challenge']);
        $result = $this->devices->verify(1, str_repeat('a', 32), $challenge['challenge_id'], $signature);

        self::assertTrue($result['device_verified']);
        self::assertNotNull($this->database->query('SELECT verified_at FROM devices')->fetchColumn());
        self::assertNotNull($this->database->query('SELECT used_at FROM device_challenges')->fetchColumn());
    }

    public function testVerificationAssociatesDeviceWithApiSession(): void
    {
        [$key, $challenge] = $this->registeredChallenge();
        $this->database->exec('INSERT INTO api_sessions (user_id) VALUES (1)');
        $signature = $this->signChallenge($key['private'], $challenge['challenge']);

        $this->devices->verify(
            1,
            str_repeat('a', 32),
            $challenge['challenge_id'],
            $signature,
            1
        );

        self::assertSame(1, (int) $this->database->query('SELECT device_id FROM api_sessions WHERE id = 1')->fetchColumn());
    }

    public function testInvalidSignature(): void
    {
        [, $challenge] = $this->registeredChallenge();
        $otherKey = $this->createKeyPair();
        $signature = $this->signChallenge($otherKey['private'], $challenge['challenge']);

        $this->assertApiError(
            fn () => $this->devices->verify(1, str_repeat('a', 32), $challenge['challenge_id'], $signature),
            'invalid_signature',
            403
        );
    }

    public function testRevokedDeviceIsRejected(): void
    {
        [$key, $challenge] = $this->registeredChallenge();
        $signature = $this->signChallenge($key['private'], $challenge['challenge']);
        $this->database->exec("UPDATE devices SET revoked_at = '2026-08-16 12:00:00'");

        $this->assertApiError(
            fn () => $this->devices->verify(1, str_repeat('a', 32), $challenge['challenge_id'], $signature),
            'device_revoked',
            403
        );
    }

    public function testDeviceListAndRevocation(): void
    {
        $key = $this->createKeyPair();
        $this->register(1, str_repeat('a', 32), $key['public']);
        $listed = $this->devices->listDevices(1, str_repeat('a', 32));

        self::assertCount(1, $listed);
        self::assertTrue($listed[0]['is_current']);
        self::assertArrayNotHasKey('public_key', $listed[0]);

        $this->devices->revoke(1, str_repeat('a', 32));
        self::assertCount(0, $this->devices->listDevices(1, null));
    }

    public function testDeviceLimit(): void
    {
        $service = new DeviceService($this->database, 60, 2);
        $key1 = $this->createKeyPair();
        $key2 = $this->createKeyPair();
        $key3 = $this->createKeyPair();
        $service->register(1, str_repeat('a', 32), $key1['public'], 'ECDSA-P256', 'PC 1');
        $service->register(1, str_repeat('b', 32), $key2['public'], 'ECDSA-P256', 'PC 2');

        $this->assertApiError(
            fn () => $service->register(1, str_repeat('c', 32), $key3['public'], 'ECDSA-P256', 'PC 3'),
            'device_limit_reached',
            409
        );
    }

    private function register(int $userId, string $deviceId, string $publicKey): array
    {
        return $this->devices->register($userId, $deviceId, $publicKey, 'ECDSA-P256', 'Test PC');
    }

    private function registeredChallenge(): array
    {
        $key = $this->createKeyPair();
        $this->register(1, str_repeat('a', 32), $key['public']);
        return [$key, $this->devices->createChallenge(1, str_repeat('a', 32))];
    }

    private function createKeyPair(): array
    {
        $options = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ];
        $windowsConfig = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (is_file($windowsConfig)) {
            $options['config'] = $windowsConfig;
        }
        $private = openssl_pkey_new($options);
        self::assertNotFalse($private);
        $details = openssl_pkey_get_details($private);
        self::assertIsArray($details);
        return ['private' => $private, 'public' => $details['key']];
    }

    private function signChallenge(\OpenSSLAsymmetricKey $privateKey, string $encodedChallenge): string
    {
        $signature = '';
        self::assertTrue(openssl_sign(
            (string) Base64Url::decode($encodedChallenge),
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA256
        ));
        return Base64Url::encode($signature);
    }

    private function assertApiError(callable $action, string $errorCode, int $statusCode): void
    {
        try {
            $action();
            self::fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
            self::assertSame($statusCode, $exception->statusCode);
        }
    }
}
