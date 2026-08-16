<?php

declare(strict_types=1);

namespace Pericles\Tests;

use DateTimeImmutable;
use PDO;
use Pericles\Activation\ActivationKeyGenerator;
use Pericles\Activation\ActivationService;
use Pericles\Catalog\CatalogService;
use Pericles\Http\ApiException;
use Pericles\Subscriptions\SubscriptionService;
use PHPUnit\Framework\TestCase;

final class Phase4SubscriptionTest extends TestCase
{
    private PDO $database;
    private ActivationService $activation;
    private CatalogService $catalog;

    protected function setUp(): void
    {
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->database->exec($this->schema());
        $now = gmdate('Y-m-d H:i:s');
        $insert = $this->database->prepare(
            "INSERT INTO games (slug,name,short_description,image_url,is_active,sort_order,created_at,updated_at)
             VALUES (?,?,?,?,1,?,?,?)"
        );
        $insert->execute(['deadlock', 'Deadlock', 'Tactical game', 'https://example.test/deadlock.jpg', 10, $now, $now]);
        $insert->execute(['counter-strike-2', 'Counter-Strike 2', 'Competitive game', 'https://example.test/cs2.jpg', 20, $now, $now]);
        $this->database->exec(
            "INSERT INTO users (email) VALUES ('normal@example.test'), ('premium@example.test');
             INSERT INTO devices (user_id,device_id) VALUES (1,'device-a'),(1,'device-b'),(2,'device-c');
             INSERT INTO products (slug,name,type,is_active,created_at,updated_at) VALUES
                ('deadlock','Deadlock','game',1,'$now','$now'),
                ('counter-strike-2','Counter-Strike 2','game',1,'$now','$now');
             INSERT INTO product_games (product_id,game_id) VALUES (1,1),(2,2);
             INSERT INTO plans (product_id,slug,name,duration_days,is_lifetime,is_active,created_at,updated_at) VALUES
                (1,'30-days','30 days',30,0,1,'$now','$now'),
                (1,'lifetime','Lifetime',NULL,1,1,'$now','$now'),
                (2,'30-days','30 days',30,0,1,'$now','$now');"
        );
        $this->activation = new ActivationService($this->database);
        $this->catalog = new CatalogService($this->database);
    }

    public function testRedeemValidKeyCreatesUnboundSubscriptionAndStoresOnlyHash(): void
    {
        $key = $this->key(1);
        $result = $this->activation->redeem(1, strtolower($key));

        self::assertTrue($result['success']);
        self::assertSame('deadlock', $result['product']['slug']);
        self::assertSame('unbound', $result['subscription']['device_binding']);
        $subscription = $this->database->query('SELECT * FROM subscriptions')->fetch();
        self::assertNull($subscription['bound_device_id']);
        self::assertSame(hash('sha256', $key), $this->database->query('SELECT key_hash FROM activation_keys')->fetchColumn());
        self::assertStringNotContainsString($key, (string) $this->database->query('SELECT key_hash FROM activation_keys')->fetchColumn());
    }

    public function testInvalidUsedExpiredAndRevokedKeysAreRejected(): void
    {
        $this->assertApiError(fn () => $this->activation->redeem(1, 'PERI-AAAA-AAAA-AAAA-AAAA-AAAA-AAAA-AAAA'), 'invalid_activation_key');

        $used = $this->key(1, 'redeemed');
        $this->assertApiError(fn () => $this->activation->redeem(1, $used), 'activation_key_used');

        $expired = $this->key(1, 'unused', gmdate('Y-m-d H:i:s', time() - 60));
        $this->assertApiError(fn () => $this->activation->redeem(1, $expired), 'activation_key_expired');

        $revoked = $this->key(1, 'revoked');
        $this->assertApiError(fn () => $this->activation->redeem(1, $revoked), 'activation_key_revoked');
    }

    public function testSecondKeySameProductExtendsWithoutParallelSubscription(): void
    {
        $this->activation->redeem(1, $this->key(1));
        $firstExpiry = (string) $this->database->query('SELECT expires_at FROM subscriptions')->fetchColumn();
        $this->activation->redeem(1, $this->key(1));
        $secondExpiry = (string) $this->database->query('SELECT expires_at FROM subscriptions')->fetchColumn();

        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn());
        self::assertSame(2, (int) $this->database->query('SELECT COUNT(*) FROM subscription_activations')->fetchColumn());
        self::assertSame(30, (new DateTimeImmutable($firstExpiry))->diff(new DateTimeImmutable($secondExpiry))->days);
    }

    public function testExpiredRenewalStartsFromNowAndActiveRenewalFromExpiration(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->insertSubscription(1, 1, 'active', gmdate('Y-m-d H:i:s', time() - 86400), null);
        $this->activation->redeem(1, $this->key(1));
        $expiry = new DateTimeImmutable(
            (string) $this->database->query('SELECT expires_at FROM subscriptions')->fetchColumn(),
            new \DateTimeZone('UTC')
        );
        self::assertEqualsWithDelta(time() + 30 * 86400, $expiry->getTimestamp(), 3);

        $this->database->exec('DELETE FROM subscription_activations; DELETE FROM activation_keys; DELETE FROM subscriptions');
        $future = gmdate('Y-m-d H:i:s', strtotime('+10 days'));
        $this->insertSubscription(1, 1, 'active', $future, null);
        $this->activation->redeem(1, $this->key(1));
        $expiry = new DateTimeImmutable(
            (string) $this->database->query('SELECT expires_at FROM subscriptions')->fetchColumn(),
            new \DateTimeZone('UTC')
        );
        self::assertEqualsWithDelta(time() + 40 * 86400, $expiry->getTimestamp(), 3);
    }

    public function testLifetimeAndTemporaryKeyOnLifetimeRules(): void
    {
        $this->activation->redeem(1, $this->key(2));
        self::assertNull($this->database->query('SELECT expires_at FROM subscriptions')->fetchColumn());

        $temporary = $this->key(1);
        $this->assertApiError(fn () => $this->activation->redeem(1, $temporary), 'subscription_already_lifetime');
        $status = $this->database->prepare('SELECT status FROM activation_keys WHERE key_hash = ?');
        $status->execute([hash('sha256', $temporary)]);
        self::assertSame('unused', $status->fetchColumn());
    }

    public function testSameKeyCannotBeConsumedTwice(): void
    {
        $key = $this->key(1);
        $this->activation->redeem(1, $key);
        $this->assertApiError(fn () => $this->activation->redeem(2, $key), 'activation_key_used');
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM subscription_activations')->fetchColumn());
    }

    public function testProductsRemainIndependentAndPremiumHasNoAuthority(): void
    {
        $this->activation->redeem(1, $this->key(1));
        $games = $this->gamesBySlug(1, 1);
        self::assertSame('ready_to_bind', $games['deadlock']['access']['state']);
        self::assertSame('locked', $games['counter-strike-2']['access']['state']);

        $premiumGames = $this->gamesBySlug(2, 3);
        self::assertSame('locked', $premiumGames['deadlock']['access']['state']);
        self::assertSame('locked', $premiumGames['counter-strike-2']['access']['state']);

        $this->activation->redeem(1, $this->key(3));
        $games = $this->gamesBySlug(1, 1);
        self::assertSame('ready_to_bind', $games['deadlock']['access']['state']);
        self::assertSame('ready_to_bind', $games['counter-strike-2']['access']['state']);
        self::assertSame(2, (int) $this->database->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn());
    }

    public function testBindingIsIdempotentAndCannotRebindToAnotherDevice(): void
    {
        $this->activation->redeem(1, $this->key(1));
        self::assertSame('available', $this->catalog->bindGame(1, 1, 'deadlock')['state']);
        self::assertSame('available', $this->catalog->bindGame(1, 1, 'deadlock')['state']);
        self::assertSame(1, (int) $this->database->query('SELECT bound_device_id FROM subscriptions')->fetchColumn());

        $games = $this->gamesBySlug(1, 2);
        self::assertSame('bound_elsewhere', $games['deadlock']['access']['state']);
        $this->assertApiError(fn () => $this->catalog->bindGame(1, 2, 'deadlock'), 'subscription_bound_to_another_device');
        self::assertSame(1, (int) $this->database->query('SELECT bound_device_id FROM subscriptions')->fetchColumn());
    }

    public function testAdminResetAllowsNewBinding(): void
    {
        $this->activation->redeem(1, $this->key(1));
        $this->catalog->bindGame(1, 1, 'deadlock');
        $this->database->exec('UPDATE subscriptions SET bound_device_id = NULL, bound_at = NULL');
        $this->catalog->bindGame(1, 2, 'deadlock');
        self::assertSame(2, (int) $this->database->query('SELECT bound_device_id FROM subscriptions')->fetchColumn());
    }

    public function testCancelledKeepsAccessUntilExpiryButSuspendedStopsImmediately(): void
    {
        $future = gmdate('Y-m-d H:i:s', strtotime('+5 days'));
        $this->insertSubscription(1, 1, 'cancelled', $future, 1);
        self::assertSame('available', $this->gamesBySlug(1, 1)['deadlock']['access']['state']);
        $this->database->exec("UPDATE subscriptions SET status = 'suspended'");
        self::assertSame('suspended', $this->gamesBySlug(1, 1)['deadlock']['access']['state']);
    }

    public function testBundleCanGrantMultipleGames(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->database->exec(
            "INSERT INTO products (slug,name,type,is_active,created_at,updated_at) VALUES ('bundle','Bundle','bundle',1,'$now','$now');
             INSERT INTO product_games (product_id,game_id) VALUES (3,1),(3,2);"
        );
        $this->insertSubscription(1, 3, 'active', gmdate('Y-m-d H:i:s', strtotime('+5 days')), null);
        $games = $this->gamesBySlug(1, 1);
        self::assertSame('ready_to_bind', $games['deadlock']['access']['state']);
        self::assertSame('ready_to_bind', $games['counter-strike-2']['access']['state']);
    }

    public function testSubscriptionListingDoesNotExposeDeviceIdentifiers(): void
    {
        $this->insertSubscription(1, 1, 'active', gmdate('Y-m-d H:i:s', strtotime('+5 days')), 1);
        $result = (new SubscriptionService($this->database))->listForUser(1, 1);
        self::assertSame('current_device', $result[0]['device_binding']['state']);
        self::assertArrayNotHasKey('bound_device_id', $result[0]);
    }

    public function testGeneratedKeyHasEntropyAndOnlyHashIsPersisted(): void
    {
        $keys = (new ActivationKeyGenerator($this->database))->generate('deadlock', '30-days', 2);
        self::assertMatchesRegularExpression('/^PERI(?:-[A-Z2-9]{4}){7}$/', $keys[0]);
        self::assertNotSame($keys[0], $keys[1]);
        $hashes = $this->database->query('SELECT key_hash FROM activation_keys')->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains(hash('sha256', $keys[0]), $hashes);
        self::assertNotContains($keys[0], $hashes);
    }

    private function key(int $planId, string $status = 'unused', ?string $expiresAt = null): string
    {
        $suffix = str_pad(strtoupper(base_convert((string) (int) (microtime(true) * 1000000), 10, 32)), 28, 'A', STR_PAD_LEFT);
        $suffix = strtr(substr($suffix, -28), ['0' => '2', '1' => '3', 'I' => 'J', 'O' => 'P']);
        $key = 'PERI-' . implode('-', str_split($suffix, 4));
        $statement = $this->database->prepare(
            'INSERT INTO activation_keys (key_hash,key_hint,plan_id,status,created_at,expires_at,transferable,max_transfers,transfer_count) '
            . 'VALUES (?,?,?,?,?,?,0,0,0)'
        );
        $statement->execute([hash('sha256', $key), '...' . substr($key, -4), $planId, $status, gmdate('Y-m-d H:i:s'), $expiresAt]);
        return $key;
    }

    private function insertSubscription(int $userId, int $productId, string $status, ?string $expiresAt, ?int $deviceId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->database->prepare(
            'INSERT INTO subscriptions (user_id,product_id,status,started_at,expires_at,bound_device_id,bound_at,created_at,updated_at) '
            . 'VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $statement->execute([$userId, $productId, $status, $now, $expiresAt, $deviceId, $deviceId === null ? null : $now, $now, $now]);
    }

    private function gamesBySlug(int $userId, int $deviceId): array
    {
        $games = [];
        foreach ($this->catalog->listGames($userId, $deviceId) as $game) {
            $games[$game['slug']] = $game;
        }
        return $games;
    }

    private function assertApiError(callable $callback, string $code): void
    {
        try {
            $callback();
            self::fail('Expected ApiException.');
        } catch (ApiException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }

    private function schema(): string
    {
        return <<<'SQL'
CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT,email TEXT NOT NULL UNIQUE);
CREATE TABLE devices (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,device_id TEXT NOT NULL UNIQUE);
CREATE TABLE games (id INTEGER PRIMARY KEY AUTOINCREMENT,slug TEXT NOT NULL UNIQUE,name TEXT NOT NULL,short_description TEXT NOT NULL,image_url TEXT NOT NULL,is_active INTEGER NOT NULL,sort_order INTEGER NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL);
CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT,slug TEXT NOT NULL UNIQUE,name TEXT NOT NULL,type TEXT NOT NULL,is_active INTEGER NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL);
CREATE TABLE product_games (product_id INTEGER NOT NULL,game_id INTEGER NOT NULL,PRIMARY KEY(product_id,game_id));
CREATE TABLE plans (id INTEGER PRIMARY KEY AUTOINCREMENT,product_id INTEGER NOT NULL,slug TEXT NOT NULL,name TEXT NOT NULL,duration_days INTEGER NULL,is_lifetime INTEGER NOT NULL,is_active INTEGER NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,UNIQUE(product_id,slug));
CREATE TABLE subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,product_id INTEGER NOT NULL,status TEXT NOT NULL,started_at TEXT NOT NULL,expires_at TEXT NULL,bound_device_id INTEGER NULL,bound_at TEXT NULL,cancelled_at TEXT NULL,suspended_at TEXT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,UNIQUE(user_id,product_id));
CREATE TABLE activation_keys (id INTEGER PRIMARY KEY AUTOINCREMENT,key_hash TEXT NOT NULL UNIQUE,key_hint TEXT NOT NULL,plan_id INTEGER NOT NULL,status TEXT NOT NULL,created_at TEXT NOT NULL,expires_at TEXT NULL,redeemed_at TEXT NULL,redeemed_by_user_id INTEGER NULL,subscription_id INTEGER NULL,transferable INTEGER NOT NULL,max_transfers INTEGER NOT NULL,transfer_count INTEGER NOT NULL,revoked_at TEXT NULL);
CREATE TABLE subscription_activations (id INTEGER PRIMARY KEY AUTOINCREMENT,subscription_id INTEGER NOT NULL,activation_key_id INTEGER NOT NULL UNIQUE,plan_id INTEGER NOT NULL,activated_at TEXT NOT NULL,previous_expires_at TEXT NULL,new_expires_at TEXT NULL);
SQL;
    }
}
