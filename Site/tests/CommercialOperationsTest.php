<?php

declare(strict_types=1);

namespace Pericles\Tests;

use PDO;
use Pericles\Admin\AdminService;
use Pericles\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class CommercialOperationsTest extends TestCase
{
    private PDO $database;
    private AdminService $admin;

    protected function setUp(): void
    {
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->database->exec(<<<'SQL'
CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,status TEXT);
CREATE TABLE roles(id INTEGER PRIMARY KEY,slug TEXT,name TEXT,rank_level INTEGER);
CREATE TABLE permissions(id INTEGER PRIMARY KEY,slug TEXT,name TEXT);
CREATE TABLE role_permissions(role_id INTEGER,permission_id INTEGER,PRIMARY KEY(role_id,permission_id));
CREATE TABLE user_roles(user_id INTEGER PRIMARY KEY,role_id INTEGER,assigned_by_user_id INTEGER,assigned_at TEXT);
CREATE TABLE products(id INTEGER PRIMARY KEY,slug TEXT,name TEXT,is_active INTEGER);
CREATE TABLE games(id INTEGER PRIMARY KEY,slug TEXT,commercial_status TEXT,purchases_allowed INTEGER,status_updated_at TEXT,updated_at TEXT);
CREATE TABLE product_games(product_id INTEGER,game_id INTEGER,PRIMARY KEY(product_id,game_id));
CREATE TABLE product_downtimes(id INTEGER PRIMARY KEY AUTOINCREMENT,product_id INTEGER,started_at TEXT,ended_at TEXT,duration_seconds INTEGER,freeze_access INTEGER,extension_applied_at TEXT,reason TEXT,created_by_user_id INTEGER,created_at TEXT);
CREATE TABLE product_media(id INTEGER PRIMARY KEY AUTOINCREMENT,product_id INTEGER,media_type TEXT,url TEXT,thumbnail_url TEXT,poster_url TEXT,title TEXT,alt_text TEXT,sort_order INTEGER,is_public INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE product_documents(id INTEGER PRIMARY KEY AUTOINCREMENT,product_id INTEGER,slug TEXT,title TEXT,section TEXT,body TEXT,access_level TEXT,sort_order INTEGER,is_published INTEGER,updated_at TEXT);
CREATE TABLE product_changelog(id INTEGER PRIMARY KEY AUTOINCREMENT,product_id INTEGER,version TEXT,summary TEXT,published_at TEXT,is_public INTEGER);
CREATE TABLE plans(id INTEGER PRIMARY KEY,product_id INTEGER,slug TEXT,name TEXT,duration_days INTEGER,is_lifetime INTEGER,price_cents INTEGER,sale_price_cents INTEGER,currency TEXT,badge TEXT,is_active INTEGER,updated_at TEXT);
CREATE TABLE subscriptions(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,product_id INTEGER,plan_id INTEGER,order_id INTEGER,status TEXT,purchased_at TEXT,activation_deadline_at TEXT,activated_at TEXT,starts_at TEXT,started_at TEXT,expires_at TEXT,bound_device_id INTEGER,bound_at TEXT,device_reset_available_at TEXT,cancelled_at TEXT,suspended_at TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE admin_audit_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_user_id INTEGER,action TEXT,target_type TEXT,target_id TEXT,metadata_json TEXT,ip_address TEXT,created_at TEXT);
SQL);
        $this->database->exec("INSERT INTO users VALUES(1,'admin@example.test','active'),(2,'support@example.test','active'),(3,'customer@example.test','active'); INSERT INTO roles VALUES(1,'admin','Administrator',100),(2,'moderator','Support',50); INSERT INTO permissions VALUES(1,'licenses.manage','Manage Access'),(2,'access.support_actions','Extend Access'),(3,'commerce.manage','Manage commerce'),(4,'content.manage','Manage content'); INSERT INTO role_permissions VALUES(1,1),(1,2),(1,3),(1,4),(2,2); INSERT INTO user_roles VALUES(1,1,NULL,'2026-01-01'),(2,2,1,'2026-01-01'); INSERT INTO products VALUES(1,'deadlock','Deadlock Enhancement',1); INSERT INTO games VALUES(1,'deadlock','operational',1,'2026-01-01','2026-01-01'); INSERT INTO product_games VALUES(1,1); INSERT INTO plans VALUES(1,1,'30-days','30 Days',30,0,2499,NULL,'EUR','Most Popular',1,'2026-01-01'),(2,1,'lifetime','Lifetime',NULL,1,14999,NULL,'EUR',NULL,1,'2026-01-01')");
        $this->admin = new AdminService($this->database);
    }

    public function testAdminGrantsPendingAccessAndSupportCanOnlyExtendIt(): void
    {
        $grant=$this->admin->grantAccess(1,3,1,'first_activation','Manual customer resolution','127.0.0.1');
        self::assertSame('Deadlock Enhancement',$grant['product_name']);
        $row=$this->database->query('SELECT * FROM subscriptions')->fetch();
        self::assertSame('pending',$row['status']);
        self::assertNull($row['expires_at']);
        self::assertNotNull($row['activation_deadline_at']);

        $this->admin->extendAccess(2,(int)$row['id'],1,'Paid Access recovery','127.0.0.1');
        $extended=$this->database->query('SELECT * FROM subscriptions')->fetch();
        self::assertSame('active',$extended['status']);
        self::assertNotNull($extended['expires_at']);

        try {
            $this->admin->grantAccess(2,3,1,'immediate','Unauthorized direct grant','127.0.0.1');
            self::fail('Support must not gain full Access grants from a limited extension permission.');
        } catch (ApiException $exception) {
            self::assertSame('forbidden',$exception->errorCode);
        }
        self::assertSame(['access.granted','access.extended'],$this->database->query('SELECT action FROM admin_audit_logs ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testLifetimeConversionAndCurrencyPricingArePersistedAndAudited(): void
    {
        $grant=$this->admin->grantAccess(1,3,1,'immediate','Initial timed Access','127.0.0.1');
        $this->admin->convertAccessToLifetime(1,(int)$grant['subscription_id'],'Customer upgrade','127.0.0.1');
        $row=$this->database->query('SELECT status,plan_id,expires_at FROM subscriptions')->fetch();
        self::assertSame('active',$row['status']);
        self::assertSame(2,(int)$row['plan_id']);
        self::assertNull($row['expires_at']);

        $this->admin->updateProductPricing(1,1,[1=>['price'=>'24.99','sale_price'=>'19.99','badge'=>'Most Popular','is_active'=>'1'],2=>['price'=>'149.99','sale_price'=>'','badge'=>'','is_active'=>'1']],'127.0.0.1');
        $plans=$this->database->query('SELECT id,price_cents,sale_price_cents FROM plans ORDER BY id')->fetchAll();
        self::assertSame(2499,(int)$plans[0]['price_cents']);
        self::assertSame(1999,(int)$plans[0]['sale_price_cents']);
        self::assertSame(14999,(int)$plans[1]['price_cents']);
        self::assertNull($plans[1]['sale_price_cents']);
        self::assertSame('pricing.updated',$this->database->query('SELECT action FROM admin_audit_logs ORDER BY id DESC LIMIT 1')->fetchColumn());
    }

    public function testFrozenDowntimeExtendsTimedAccessOnceAndCreatesAnAuditRecord(): void
    {
        $grant=$this->admin->grantAccess(1,3,1,'immediate','Initial timed Access','127.0.0.1');
        $before=(int)strtotime((string)$this->database->query('SELECT expires_at FROM subscriptions')->fetchColumn());

        $this->admin->updateEnhancementStatus(1,'deadlock','maintenance',false,'Scheduled maintenance','127.0.0.1',true);
        $this->database->exec("UPDATE product_downtimes SET started_at=datetime('now','-2 hours')");
        $this->admin->updateEnhancementStatus(1,'deadlock','operational',true,'Maintenance complete','127.0.0.1');

        $after=(int)strtotime((string)$this->database->query('SELECT expires_at FROM subscriptions')->fetchColumn());
        self::assertGreaterThanOrEqual(7198,$after-$before);
        self::assertLessThanOrEqual(7202,$after-$before);
        self::assertNotNull($this->database->query('SELECT extension_applied_at FROM product_downtimes')->fetchColumn());
        self::assertSame('access.downtime_compensated',$this->database->query('SELECT action FROM admin_audit_logs ORDER BY id DESC LIMIT 1')->fetchColumn());

        $this->admin->updateEnhancementStatus(1,'deadlock','operational',true,'No-op recovery','127.0.0.1');
        self::assertSame($after,(int)strtotime((string)$this->database->query('SELECT expires_at FROM subscriptions')->fetchColumn()));
    }

    public function testProductContentWritesRemainCompatibleUntilMigrationTenIsApplied(): void
    {
        $this->admin->saveProductMedia(1,1,null,['media_type'=>'image','url'=>'https://cdn.example.test/deadlock.webp','title'=>'Gameplay','alt_text'=>'Deadlock gameplay','sort_order'=>'1','is_public'=>'1','is_active'=>'1','is_primary'=>'1'],'127.0.0.1');
        self::assertSame('https://cdn.example.test/deadlock.webp',$this->database->query('SELECT url FROM product_media')->fetchColumn());

        $this->admin->saveDocument(1,1,null,['title'=>'Install','slug'=>'install','summary'=>'A concise summary','section'=>'installation','body'=>'Install the launcher and sign in.','access_level'=>'public','is_published'=>'1'],'127.0.0.1');
        self::assertSame('Install',$this->database->query('SELECT title FROM product_documents')->fetchColumn());

        $this->admin->saveChangelog(1,1,null,['version'=>'1.2.3','title'=>'Ignored until migration','summary'=>'Compatibility release','body'=>'Longer release notes','is_public'=>'1'],'127.0.0.1');
        self::assertSame('Compatibility release',$this->database->query('SELECT summary FROM product_changelog')->fetchColumn());
    }
}
