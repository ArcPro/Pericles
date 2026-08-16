<?php

declare(strict_types=1);

namespace Pericles\Tests;

use PDO;
use Pericles\Http\ApiException;
use Pericles\Modules\ModuleAuthorizationService;
use Pericles\Modules\ModuleDownloadService;
use Pericles\Modules\ModulePackageBuilder;
use Pericles\Modules\ModuleRateLimiter;
use Pericles\Modules\ModuleStorage;
use Pericles\Modules\ModuleTicketService;
use PHPUnit\Framework\TestCase;

final class Phase5ModuleTest extends TestCase
{
    private PDO $database;
    private ModuleAuthorizationService $authorization;
    private ModuleTicketService $tickets;
    private ModuleStorage $storage;
    private ModulePackageBuilder $builder;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->database->exec($this->schema());
        $now = gmdate('Y-m-d H:i:s');
        $future = gmdate('Y-m-d H:i:s', time() + 86400);
        $fixtureHash = hash_file('sha256', __DIR__ . '/Fixtures/TestModule.bin');
        $fixtureSize = filesize(__DIR__ . '/Fixtures/TestModule.bin');
        $this->database->exec(
            "INSERT INTO users (email,status) VALUES ('normal@test','active'),('premium@test','active');
             INSERT INTO devices (user_id,device_id,verified_at) VALUES (1,'device-a','$now'),(1,'device-b','$now'),(2,'device-c','$now');
             INSERT INTO games (slug,name,is_active) VALUES ('deadlock','Deadlock',1),('counter-strike-2','Counter-Strike 2',1);
             INSERT INTO products (slug,name,is_active) VALUES ('deadlock','Deadlock',1),('counter-strike-2','Counter-Strike 2',1);
             INSERT INTO product_games (product_id,game_id) VALUES (1,1),(2,2);
             INSERT INTO subscriptions (user_id,product_id,status,expires_at,bound_device_id) VALUES (1,1,'active','$future',1);
             INSERT INTO modules (game_id,slug,name,is_active) VALUES (1,'deadlock-main','Deadlock Main',1),(2,'counter-strike-2-main','CS2 Main',1);
             INSERT INTO module_versions (module_id,version,source_path,sha256,file_size,status,published_at) VALUES
                (1,'1.0.0','deadlock/1.0.0/Module.dll','$fixtureHash',$fixtureSize,'active','$now'),
                (2,'1.0.0','counter-strike-2/1.0.0/Module.dll','$fixtureHash',$fixtureSize,'active','$now');"
        );
        $this->storagePath = sys_get_temp_dir() . '/pericles-modules-' . bin2hex(random_bytes(8));
        $this->storage = new ModuleStorage($this->storagePath, 1024 * 1024);
        $this->storage->publish('deadlock', '1.0.0', __DIR__ . '/Fixtures/TestModule.bin');
        $this->storage->publish('counter-strike-2', '1.0.0', __DIR__ . '/Fixtures/TestModule.bin');
        $this->authorization = new ModuleAuthorizationService($this->database);
        $this->tickets = new ModuleTicketService($this->database, $this->authorization, 30);
        $this->builder = new ModulePackageBuilder(
            __DIR__ . '/Fixtures/module-signing-test-private.pem',
            'pericles-modules-development-test',
            1024 * 1024
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);
    }

    public function testValidDeadlockSubscriptionCanRequestTicket(): void
    {
        $ticket = $this->tickets->issue(1, 1, 'deadlock', '127.0.0.1');
        self::assertSame('deadlock', $ticket['game']);
        self::assertSame('1.0.0', $ticket['module']['version']);
        self::assertSame(30, $ticket['expires_in']);
    }

    public function testProductsAndPremiumRemainIndependent(): void
    {
        $this->assertApiError(fn () => $this->tickets->issue(1, 1, 'counter-strike-2', null), 'no_subscription');
        $this->assertApiError(fn () => $this->tickets->issue(2, 3, 'deadlock', null), 'no_subscription');
    }

    public function testCancelledButUnexpiredSubscriptionRemainsUsable(): void
    {
        $this->database->exec("UPDATE subscriptions SET status = 'cancelled' WHERE id = 1");
        $ticket = $this->tickets->issue(1, 1, 'deadlock', null);
        self::assertSame('deadlock', $ticket['game']);
    }

    public function testExpiredSuspendedUnboundAndElsewhereSubscriptionsAreRejected(): void
    {
        $this->database->exec("UPDATE subscriptions SET expires_at = '2000-01-01 00:00:00'");
        $this->assertApiError(fn () => $this->tickets->issue(1, 1, 'deadlock', null), 'subscription_expired');
        $this->database->exec("UPDATE subscriptions SET status = 'suspended', expires_at = NULL");
        $this->assertApiError(fn () => $this->tickets->issue(1, 1, 'deadlock', null), 'subscription_suspended');
        $this->database->exec("UPDATE subscriptions SET status = 'active', bound_device_id = NULL");
        $this->assertApiError(fn () => $this->tickets->issue(1, 1, 'deadlock', null), 'subscription_not_bound');
        $this->database->exec('UPDATE subscriptions SET bound_device_id = 2');
        $this->assertApiError(fn () => $this->tickets->issue(1, 1, 'deadlock', null), 'subscription_bound_elsewhere');
    }

    public function testRevokedAndUnverifiedDevicesCannotRequestTicket(): void
    {
        $this->database->exec("UPDATE devices SET revoked_at = CURRENT_TIMESTAMP WHERE id = 1");
        $this->assertApiError(fn () => $this->tickets->issue(1, 1, 'deadlock', null), 'device_revoked');
        $this->database->exec('UPDATE devices SET revoked_at = NULL, verified_at = NULL WHERE id = 1');
        $this->assertApiError(fn () => $this->tickets->issue(1, 1, 'deadlock', null), 'device_not_verified');
    }

    public function testTicketIsRandomHashedAndBoundToAuthorizationContext(): void
    {
        $first = $this->tickets->issue(1, 1, 'deadlock', '127.0.0.1');
        $second = $this->tickets->issue(1, 1, 'deadlock', '127.0.0.1');
        self::assertNotSame($first['ticket'], $second['ticket']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $first['ticket']);
        $row = $this->database->query('SELECT * FROM module_tickets ORDER BY id LIMIT 1')->fetch();
        self::assertSame(hash('sha256', $first['ticket']), $row['ticket_hash']);
        self::assertNotSame($first['ticket'], $row['ticket_hash']);
        self::assertSame(1, (int) $row['user_id']);
        self::assertSame(1, (int) $row['device_id']);
        self::assertSame(1, (int) $row['game_id']);
        self::assertSame(1, (int) $row['module_version_id']);
    }

    public function testExpiredUsedRevokedAndInvalidTicketsAreRejected(): void
    {
        $expired = $this->tickets->issue(1, 1, 'deadlock', null);
        $this->database->exec("UPDATE module_tickets SET expires_at = '2000-01-01 00:00:00'");
        $this->assertApiError(fn () => $this->tickets->consumeForDownload(1, 1, $expired['ticket'], null), 'ticket_expired');

        $this->database->exec('DELETE FROM module_tickets');
        $used = $this->tickets->issue(1, 1, 'deadlock', null);
        $this->database->exec("UPDATE module_tickets SET used_at = CURRENT_TIMESTAMP");
        $this->assertApiError(fn () => $this->tickets->consumeForDownload(1, 1, $used['ticket'], null), 'ticket_used');

        $this->database->exec('DELETE FROM module_tickets');
        $revoked = $this->tickets->issue(1, 1, 'deadlock', null);
        $this->database->exec("UPDATE module_tickets SET revoked_at = CURRENT_TIMESTAMP");
        $this->assertApiError(fn () => $this->tickets->consumeForDownload(1, 1, $revoked['ticket'], null), 'ticket_invalid');
        $this->assertApiError(fn () => $this->tickets->consumeForDownload(1, 1, str_repeat('A', 43), null), 'ticket_invalid');
    }

    public function testTicketIsStrictlySingleUse(): void
    {
        $ticket = $this->tickets->issue(1, 1, 'deadlock', null);
        $this->tickets->consumeForDownload(1, 1, $ticket['ticket'], null);
        $this->assertApiError(fn () => $this->tickets->consumeForDownload(1, 1, $ticket['ticket'], null), 'ticket_used');
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM module_downloads')->fetchColumn());
    }

    public function testTicketCannotBeUsedByAnotherUserOrDevice(): void
    {
        $ticket = $this->tickets->issue(1, 1, 'deadlock', null);
        $this->assertApiError(
            fn () => $this->tickets->consumeForDownload(2, 3, $ticket['ticket'], null),
            'ticket_invalid'
        );
        $this->assertApiError(
            fn () => $this->tickets->consumeForDownload(1, 2, $ticket['ticket'], null),
            'ticket_invalid'
        );
    }

    public function testModuleRateLimitAppliesToUserDeviceOrIp(): void
    {
        $limiter = new ModuleRateLimiter($this->database, 1, 1, 60);
        $limiter->consume(1, 1, 'ticket', '127.0.0.1');
        $this->assertApiError(fn () => $limiter->consume(1, 2, 'ticket', '127.0.0.2'), 'module_rate_limited');
    }

    public function testValidDownloadProducesSignedDecryptablePackageAndAudit(): void
    {
        $ticket = $this->tickets->issue(1, 1, 'deadlock', null);
        $service = new ModuleDownloadService($this->tickets, $this->storage, $this->builder);
        $result = $service->download(1, 1, $ticket['ticket'], '127.0.0.1');
        $parsed = $this->parsePackage($result->package);
        $publicKey = openssl_pkey_get_public(file_get_contents(__DIR__ . '/../../Launcher/Launcher/Assets/module-signing-public.pem'));
        self::assertNotFalse($publicKey);
        self::assertSame(1, openssl_verify($parsed['signed'], $parsed['signature'], $publicKey, OPENSSL_ALGO_SHA256));
        $plaintext = openssl_decrypt(
            $parsed['ciphertext'],
            'aes-256-gcm',
            $result->sessionKey,
            OPENSSL_RAW_DATA,
            $parsed['nonce'],
            $parsed['tag'],
            $parsed['manifest_bytes']
        );
        self::assertSame(file_get_contents(__DIR__ . '/Fixtures/TestModule.bin'), $plaintext);
        self::assertSame(hash('sha256', $plaintext), $parsed['manifest']['payload_sha256']);
        self::assertSame('completed', $this->database->query('SELECT status FROM module_downloads')->fetchColumn());
    }

    public function testFreshEncryptionProducesDifferentPackages(): void
    {
        $module = $this->authorization->authorize(1, 1, 'deadlock');
        $payload = file_get_contents(__DIR__ . '/Fixtures/TestModule.bin');
        $first = $this->builder->build($module, $payload);
        $second = $this->builder->build($module, $payload);
        self::assertNotSame($first->sessionKey, $second->sessionKey);
        self::assertNotSame($first->package, $second->package);
    }

    public function testDisabledGameModuleAndVersionAreRevalidatedAtDownload(): void
    {
        $ticket = $this->tickets->issue(1, 1, 'deadlock', null);
        $this->database->exec('UPDATE games SET is_active = 0 WHERE id = 1');
        $this->assertApiError(fn () => $this->tickets->consumeForDownload(1, 1, $ticket['ticket'], null), 'game_disabled');

        $this->database->exec('UPDATE games SET is_active = 1; DELETE FROM module_tickets; UPDATE modules SET is_active = 1');
        $ticket = $this->tickets->issue(1, 1, 'deadlock', null);
        $this->database->exec('UPDATE modules SET is_active = 0 WHERE id = 1');
        $this->assertApiError(fn () => $this->tickets->consumeForDownload(1, 1, $ticket['ticket'], null), 'module_not_found');

        $this->database->exec("UPDATE modules SET is_active = 1; DELETE FROM module_tickets; UPDATE module_versions SET status = 'active'");
        $ticket = $this->tickets->issue(1, 1, 'deadlock', null);
        $this->database->exec("UPDATE module_versions SET status = 'disabled' WHERE id = 1");
        $this->assertApiError(fn () => $this->tickets->consumeForDownload(1, 1, $ticket['ticket'], null), 'module_unavailable');
    }

    public function testSubscriptionAndDeviceRevocationBetweenTicketAndDownloadAreRejected(): void
    {
        $ticket = $this->tickets->issue(1, 1, 'deadlock', null);
        $this->database->exec("UPDATE subscriptions SET status = 'suspended'");
        $this->assertApiError(fn () => $this->tickets->consumeForDownload(1, 1, $ticket['ticket'], null), 'subscription_suspended');

        $this->database->exec("UPDATE subscriptions SET status = 'active'; DELETE FROM module_tickets");
        $ticket = $this->tickets->issue(1, 1, 'deadlock', null);
        $this->database->exec("UPDATE devices SET revoked_at = CURRENT_TIMESTAMP WHERE id = 1");
        $this->assertApiError(fn () => $this->tickets->consumeForDownload(1, 1, $ticket['ticket'], null), 'device_revoked');
    }

    private function assertApiError(callable $callback, string $expected): void
    {
        try {
            $callback();
            self::fail('Expected ApiException.');
        } catch (ApiException $exception) {
            self::assertSame($expected, $exception->errorCode);
        }
    }

    private function parsePackage(string $package): array
    {
        self::assertSame('PERI', substr($package, 0, 4));
        self::assertSame(1, unpack('n', substr($package, 4, 2))[1]);
        $offset = 6;
        $read = static function () use (&$offset, $package): string {
            $length = unpack('N', substr($package, $offset, 4))[1];
            $offset += 4;
            $value = substr($package, $offset, $length);
            $offset += $length;
            return $value;
        };
        $manifestBytes = $read();
        $nonce = $read();
        $tag = $read();
        $ciphertext = $read();
        $signed = substr($package, 0, $offset);
        $signature = $read();
        self::assertSame(strlen($package), $offset);
        return [
            'manifest_bytes' => $manifestBytes,
            'manifest' => json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR),
            'nonce' => $nonce,
            'tag' => $tag,
            'ciphertext' => $ciphertext,
            'signed' => $signed,
            'signature' => $signature,
        ];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) rmdir($item->getPathname());
            else unlink($item->getPathname());
        }
        rmdir($directory);
    }

    private function schema(): string
    {
        return <<<'SQL'
CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT,email TEXT,status TEXT);
CREATE TABLE devices (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,device_id TEXT,verified_at TEXT NULL,revoked_at TEXT NULL);
CREATE TABLE games (id INTEGER PRIMARY KEY AUTOINCREMENT,slug TEXT,name TEXT,is_active INTEGER);
CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT,slug TEXT,name TEXT,is_active INTEGER);
CREATE TABLE product_games (product_id INTEGER,game_id INTEGER,PRIMARY KEY(product_id,game_id));
CREATE TABLE subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,product_id INTEGER,status TEXT,expires_at TEXT NULL,bound_device_id INTEGER NULL);
CREATE TABLE modules (id INTEGER PRIMARY KEY AUTOINCREMENT,game_id INTEGER,slug TEXT,name TEXT,is_active INTEGER);
CREATE TABLE module_versions (id INTEGER PRIMARY KEY AUTOINCREMENT,module_id INTEGER,version TEXT,source_path TEXT,sha256 TEXT,file_size INTEGER,status TEXT,minimum_launcher_version TEXT NULL,published_at TEXT NULL);
CREATE TABLE module_tickets (id INTEGER PRIMARY KEY AUTOINCREMENT,ticket_hash TEXT UNIQUE,user_id INTEGER,device_id INTEGER,game_id INTEGER,module_version_id INTEGER,created_at TEXT,expires_at TEXT,used_at TEXT NULL,revoked_at TEXT NULL,request_ip TEXT NULL);
CREATE TABLE module_downloads (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,device_id INTEGER,game_id INTEGER,module_version_id INTEGER,module_ticket_id INTEGER UNIQUE,status TEXT,requested_at TEXT,completed_at TEXT NULL,ip_address TEXT NULL);
CREATE TABLE module_request_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,device_id INTEGER,request_type TEXT,ip_address TEXT,requested_at TEXT);
SQL;
    }

}
