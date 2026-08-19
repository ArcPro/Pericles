<?php

declare(strict_types=1);

use Pericles\Account\AccountService;
use Pericles\Admin\AdminService;
use Pericles\Http\ApiException;
use Pericles\Security\AuthorizationService;
use PHPUnit\Framework\TestCase;

final class AdminPortalTest extends TestCase
{
    private PDO $database;
    private AuthorizationService $authorization;
    private AdminService $admin;

    protected function setUp(): void
    {
        $this->database = new PDO('sqlite::memory:');
        $this->database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->seed();
        $this->authorization = new AuthorizationService($this->database);
        $this->admin = new AdminService($this->database);
    }

    public function testRolesEnforceGranularPermissions(): void
    {
        self::assertTrue($this->authorization->can(1, 'licenses.manage'));
        self::assertTrue($this->authorization->can(2, 'devices.manage'));
        self::assertFalse($this->authorization->can(2, 'licenses.manage'));
        self::assertFalse($this->authorization->can(3, 'admin.access'));

        $this->expectException(ApiException::class);
        $this->authorization->requirePermission(3, 'admin.access');
    }

    public function testAdminCanGenerateAndAssignAProductKeyToAUser(): void
    {
        $grant = $this->admin->assignProduct(1, 3, 'deadlock', 'lifetime', '127.0.0.1');

        self::assertMatchesRegularExpression('/^PERI-(?:[A-Z2-9]{4}-){6}[A-Z2-9]{4}$/', $grant['plain_key']);
        self::assertSame('Deadlock', $grant['result']['product']['name']);
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM subscriptions WHERE user_id = 3')->fetchColumn());
        self::assertSame('redeemed', $this->database->query('SELECT status FROM activation_keys')->fetchColumn());
        self::assertSame('license.assigned', $this->database->query('SELECT action FROM admin_audit_logs')->fetchColumn());
    }

    public function testModeratorCanUnbindHwidButCannotAssignLicense(): void
    {
        $this->admin->unbindSubscription(2, 1, '127.0.0.1');
        self::assertNull($this->database->query('SELECT bound_device_id FROM subscriptions WHERE id = 1')->fetchColumn());
        self::assertSame('subscription.unbound', $this->database->query('SELECT action FROM admin_audit_logs')->fetchColumn());

        try {
            $this->admin->assignProduct(2, 3, 'deadlock', 'lifetime', '127.0.0.1');
            self::fail('A moderator must not assign licenses.');
        } catch (ApiException $exception) {
            self::assertSame('forbidden', $exception->errorCode);
        }

        try {
            $this->admin->deleteSubscription(2, 1, '127.0.0.1');
            self::fail('A moderator must not delete licenses.');
        } catch (ApiException $exception) {
            self::assertSame('forbidden', $exception->errorCode);
        }
    }

    public function testAdminCanPermanentlyDeleteSubscription(): void
    {
        $this->database->exec(
            "INSERT INTO activation_keys (key_hash,key_hint,plan_id,status,created_at,redeemed_at,redeemed_by_user_id,subscription_id) "
            . "VALUES ('abc123','1234',1,'redeemed','2026-01-01','2026-01-01',3,1)"
        );
        $this->database->exec(
            'INSERT INTO subscription_activations (subscription_id,activation_key_id,plan_id,activated_at) VALUES (1,1,1,"2026-01-01")'
        );

        $this->admin->deleteSubscription(1, 1, '127.0.0.1');

        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM subscriptions WHERE id = 1')->fetchColumn());
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM subscription_activations WHERE subscription_id = 1')->fetchColumn());
        self::assertNull($this->database->query('SELECT subscription_id FROM activation_keys WHERE id = 1')->fetchColumn());
        self::assertSame('subscription.deleted', $this->database->query('SELECT action FROM admin_audit_logs ORDER BY id DESC LIMIT 1')->fetchColumn());
    }

    public function testAdminCanChangeRoleAndDisableAnotherAccountButNotSelf(): void
    {
        $this->admin->updateUserRole(1, 3, 'moderator', '127.0.0.1');
        self::assertTrue($this->authorization->can(3, 'admin.access'));
        self::assertFalse($this->authorization->can(3, 'licenses.manage'));

        $this->admin->updateUserStatus(1, 3, 'disabled', '127.0.0.1');
        self::assertSame('disabled', $this->database->query('SELECT status FROM users WHERE id = 3')->fetchColumn());

        $this->expectException(ApiException::class);
        $this->admin->updateUserStatus(1, 1, 'disabled', '127.0.0.1');
    }

    public function testPlayerCanUpdateProfilePasswordAndUnbindOwnProduct(): void
    {
        $accounts = new AccountService($this->database);
        $profile = $accounts->updateProfile(3, 'New name');
        self::assertSame('New name', $profile['display_name']);

        $accounts->changePassword(3, 'old-password-123', 'new-password-456');
        $hash = $this->database->query('SELECT password_hash FROM users WHERE id = 3')->fetchColumn();
        self::assertTrue(password_verify('new-password-456', (string) $hash));
        self::assertNotNull($this->database->query('SELECT revoked_at FROM api_sessions WHERE user_id = 3')->fetchColumn());

        $accounts->unbindProduct(3, 'deadlock');
        self::assertNull($this->database->query('SELECT bound_device_id FROM subscriptions WHERE id = 1')->fetchColumn());
    }

    private function seed(): void
    {
        $password = password_hash('old-password-123', PASSWORD_DEFAULT);
        $insertUser = $this->database->prepare(
            'INSERT INTO users (id,email,password_hash,status,display_name,created_at) VALUES (?,?,?,?,?,?)'
        );
        $insertUser->execute([1, 'admin@test.local', $password, 'active', 'Admin', '2026-01-01 00:00:00']);
        $insertUser->execute([2, 'mod@test.local', $password, 'active', 'Mod', '2026-01-02 00:00:00']);
        $insertUser->execute([3, 'player@test.local', $password, 'active', 'Player', '2026-01-03 00:00:00']);
        $this->database->exec("INSERT INTO roles VALUES (1,'moderator','Moderator',50),(2,'admin','Administrator',100)");
        $permissions = ['admin.access','users.view','users.manage','devices.manage','licenses.manage','audit.view'];
        foreach ($permissions as $index => $permission) {
            $statement = $this->database->prepare('INSERT INTO permissions (id,slug,name) VALUES (?,?,?)');
            $statement->execute([$index + 1, $permission, $permission]);
            $this->database->exec('INSERT INTO role_permissions VALUES (2,' . ($index + 1) . ')');
        }
        $this->database->exec('INSERT INTO role_permissions VALUES (1,1),(1,2),(1,4)');
        $this->database->exec("INSERT INTO user_roles VALUES (1,2,NULL,'2026-01-01'),(2,1,1,'2026-01-01')");
        $this->database->exec("INSERT INTO products VALUES (1,'deadlock','Deadlock',1)");
        $this->database->exec("INSERT INTO plans VALUES (1,1,'lifetime','Lifetime',NULL,1,1)");
        $this->database->exec("INSERT INTO devices VALUES (1,3,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Gaming PC',NULL,'2026-01-01',NULL)");
        $this->database->exec("INSERT INTO subscriptions VALUES (1,3,1,'active','2026-01-01',NULL,1,'2026-01-01',NULL,NULL,'2026-01-01','2026-01-01')");
        $this->database->exec("INSERT INTO api_sessions VALUES (1,3,NULL)");
    }

    private function createSchema(): void
    {
        $this->database->exec(<<<'SQL'
CREATE TABLE users (id INTEGER PRIMARY KEY,email TEXT,password_hash TEXT,status TEXT,display_name TEXT,created_at TEXT);
CREATE TABLE roles (id INTEGER PRIMARY KEY,slug TEXT UNIQUE,name TEXT,rank_level INTEGER);
CREATE TABLE permissions (id INTEGER PRIMARY KEY,slug TEXT UNIQUE,name TEXT);
CREATE TABLE role_permissions (role_id INTEGER,permission_id INTEGER,PRIMARY KEY(role_id,permission_id));
CREATE TABLE user_roles (user_id INTEGER PRIMARY KEY,role_id INTEGER,assigned_by_user_id INTEGER,assigned_at TEXT);
CREATE TABLE products (id INTEGER PRIMARY KEY,slug TEXT UNIQUE,name TEXT,is_active INTEGER);
CREATE TABLE plans (id INTEGER PRIMARY KEY,product_id INTEGER,slug TEXT,name TEXT,duration_days INTEGER,is_lifetime INTEGER,is_active INTEGER);
CREATE TABLE devices (id INTEGER PRIMARY KEY,user_id INTEGER,device_id TEXT,display_name TEXT,revoked_at TEXT,created_at TEXT,last_seen_at TEXT);
CREATE TABLE subscriptions (id INTEGER PRIMARY KEY,user_id INTEGER,product_id INTEGER,status TEXT,started_at TEXT,expires_at TEXT,bound_device_id INTEGER,bound_at TEXT,cancelled_at TEXT,suspended_at TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE activation_keys (id INTEGER PRIMARY KEY AUTOINCREMENT,key_hash TEXT UNIQUE,key_hint TEXT,plan_id INTEGER,status TEXT,created_at TEXT,expires_at TEXT,redeemed_at TEXT,redeemed_by_user_id INTEGER,subscription_id INTEGER,transferable INTEGER,max_transfers INTEGER,transfer_count INTEGER,revoked_at TEXT);
CREATE TABLE subscription_activations (id INTEGER PRIMARY KEY AUTOINCREMENT,subscription_id INTEGER,activation_key_id INTEGER,plan_id INTEGER,activated_at TEXT,previous_expires_at TEXT,new_expires_at TEXT);
CREATE TABLE api_sessions (id INTEGER PRIMARY KEY,user_id INTEGER,revoked_at TEXT);
CREATE TABLE admin_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT,actor_user_id INTEGER,action TEXT,target_type TEXT,target_id TEXT,metadata_json TEXT,ip_address TEXT,created_at TEXT);
SQL);
    }
}
