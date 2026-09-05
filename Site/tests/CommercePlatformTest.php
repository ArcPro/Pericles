<?php

declare(strict_types=1);

namespace Pericles\Tests;

use PDO;
use Pericles\Commerce\CheckoutService;
use Pericles\Commerce\CommercialCatalogService;
use Pericles\Commerce\StripePaymentService;
use Pericles\Http\ApiException;
use PHPUnit\Framework\TestCase;
use Stripe\WebhookSignature;

final class CommercePlatformTest extends TestCase
{
    private PDO $database;
    private CheckoutService $checkout;

    protected function setUp(): void
    {
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->database->exec($this->schema());
        $now=gmdate('Y-m-d H:i:s');
        $this->database->exec("INSERT INTO users(id,email) VALUES(1,'buyer@example.test');
            INSERT INTO games(id,slug,name,short_description,image_url,is_active,is_public,sort_order,commercial_status,purchases_allowed,status_updated_at,updated_at) VALUES(1,'deadlock','Deadlock','Live description','https://cdn.example/deadlock.webp',1,1,10,'operational',1,'$now','$now');
            INSERT INTO products(id,slug,name,short_description,description,compatibility,operating_systems,requirements,included_items_json,seo_title,seo_description,is_active,is_public) VALUES(1,'deadlock','Deadlock Enhancement','Configurable premium enhancement','Real product copy','Windows 11','Windows 10/11','64-bit Windows',NULL,'Deadlock Enhancement','Live SEO copy',1,1);
            INSERT INTO product_games(product_id,game_id) VALUES(1,1);
            INSERT INTO plans(id,product_id,slug,name,duration_days,is_lifetime,price_cents,sale_price_cents,currency,badge,is_active,sort_order) VALUES(1,1,'30d','30 Days',30,0,2999,2499,'EUR','Most Popular',1,30),(2,1,'lifetime','Lifetime',NULL,1,14999,NULL,'EUR',NULL,1,50),(3,1,'90d','90 Days',90,0,5999,NULL,'EUR','Best Value',1,40);
            INSERT INTO product_changelog(product_id,version,summary,published_at,is_public) VALUES(1,'1.2.0','Compatibility update','$now',1);");
        $this->checkout = new CheckoutService($this->database);
    }

    public function testCatalogUsesGameTableAndImageUrlWithDatabasePricing(): void
    {
        $items=(new CommercialCatalogService($this->database))->listEnhancements();
        self::assertCount(1,$items);
        self::assertSame('Deadlock',$items[0]['game_name']);
        self::assertSame('https://cdn.example/deadlock.webp',$items[0]['image_url']);
        self::assertSame(2499,$items[0]['minimum_price_cents']);
        self::assertSame(17,$items[0]['plans'][0]['saving_percent']);
        self::assertTrue($items[0]['can_purchase']);
        self::assertSame(20, $items[0]['plans'][1]['saving_percent']);
    }

    public function testProductContentOnlyReturnsActivePublicRecords(): void
    {
        $this->database->exec("INSERT INTO product_feature_categories(id,product_id,name,description,is_active,sort_order) VALUES(1,1,'Combat','Aim options',1,10),(2,1,'Hidden','No',0,20);
            INSERT INTO product_features(id,category_id,name,short_description,is_highlighted,is_active,is_public,sort_order) VALUES(1,1,'Aim assist','Configurable behavior',1,1,1,10),(2,1,'Private feature','No',0,1,0,20),(3,2,'Inactive category item','No',0,1,1,10);
            INSERT INTO product_media(id,product_id,media_type,url,thumbnail_url,poster_url,title,alt_text,is_public,is_active,sort_order) VALUES(1,1,'image','/media/live.webp',NULL,NULL,'Live','Preview',1,1,10),(2,1,'image','/media/hidden.webp',NULL,NULL,'Hidden','Hidden',1,0,20);
            INSERT INTO product_faqs(id,product_id,question,answer,is_public,is_active,sort_order) VALUES(1,1,'How?','Securely.',1,1,10),(2,1,'Hidden?','No.',0,1,20);");
        $product = (new CommercialCatalogService($this->database))->enhancement('deadlock');
        self::assertCount(1, $product['features']);
        self::assertSame('Aim assist', $product['features'][0]['items'][0]['name']);
        self::assertTrue($product['features'][0]['items'][0]['highlighted']);
        self::assertCount(1, $product['media']);
        self::assertSame('/media/live.webp', $product['media'][0]['url']);
        self::assertCount(1, $product['faqs']);
    }

    public function testCheckoutSnapshotsPriceAndCreatesOnlyPendingOrder(): void
    {
        $session=$this->checkout->start('deadlock',1,null,'product-page');
        $loaded=$this->checkout->checkout($session['token'],1);
        self::assertSame(2499,(int)$loaded['price_cents']);
        self::assertSame('https://cdn.example/deadlock.webp',$loaded['image_url']);
        $order=$this->checkout->createOrder($session['token'],1,'2026-08-30',true);
        self::assertSame('pending',$order['status']);
        self::assertSame(2499,(int)$order['amount_cents']);
        self::assertSame(0,(int)$this->database->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn());
    }

    public function testVerifiedPaymentIsIdempotentAndGrantsPendingAccess(): void
    {
        $session=$this->checkout->start('deadlock',1,1,'product-page');
        $order=$this->checkout->createOrder($session['token'],1,'2026-08-30',true);
        $first=$this->checkout->confirmPayment('test-provider','evt-1',(string)$order['order_number'],'pay-1',2499,'EUR');
        $second=$this->checkout->confirmPayment('test-provider','evt-1',(string)$order['order_number'],'pay-1',2499,'EUR');
        self::assertFalse($first['idempotent']);
        self::assertTrue($second['idempotent']);
        self::assertSame('paid',$this->database->query('SELECT status FROM orders')->fetchColumn());
        $access=$this->database->query('SELECT status,activation_deadline_at,expires_at FROM subscriptions')->fetch();
        self::assertSame('pending',$access['status']);
        self::assertNotNull($access['activation_deadline_at']);
        self::assertNull($access['expires_at']);
        self::assertSame(1,(int)$this->database->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }

    public function testStripeElementsSessionUsesThePericlesOrderSnapshot(): void
    {
        $checkout=$this->checkout->start('deadlock',1,1,'product-page');
        $this->checkout->createOrder($checkout['token'],1,'2026-08-30',true);
        $order=$this->checkout->orderForCheckout($checkout['token'],1);
        $captured=[];
        $stripe=new StripePaymentService(
            $this->checkout,
            'pk_test_example',
            'sk_test_example',
            'whsec_example',
            'https://events.example.test/public/',
            static function(array $params,array $options) use (&$captured):array {
                $captured=['params'=>$params,'options'=>$options];
                return ['id'=>'cs_test_pericles','client_secret'=>'cs_test_pericles_secret_example'];
            }
        );

        $session=$stripe->createEmbeddedSession($order,$checkout['token']);

        self::assertSame('cs_test_pericles_secret_example',$session['client_secret']);
        self::assertSame('elements',$captured['params']['ui_mode']);
        self::assertSame('payment',$captured['params']['mode']);
        self::assertArrayNotHasKey('submit_type',$captured['params']);
        self::assertSame(2499,$captured['params']['line_items'][0]['price_data']['unit_amount']);
        self::assertSame('eur',$captured['params']['line_items'][0]['price_data']['currency']);
        self::assertSame('Deadlock Enhancement',$captured['params']['line_items'][0]['price_data']['product_data']['name']);
        self::assertSame('30 Days Access',$captured['params']['line_items'][0]['price_data']['product_data']['description']);
        self::assertSame($order['order_number'],$captured['params']['client_reference_id']);
        self::assertSame($order['order_number'],$captured['params']['metadata']['pericles_order_number']);
        self::assertStringContainsString('/checkout/'.$checkout['token'].'?stripe_return=1&session_id={CHECKOUT_SESSION_ID}',$captured['params']['return_url']);
        $requestFingerprint=hash(
            'sha256',
            json_encode($captured['params'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)
        );
        self::assertSame(
            'pericles-checkout-'.$order['order_number'].'-'.$requestFingerprint,
            $captured['options']['idempotency_key']
        );
    }

    public function testPaidStripeWebhookIsVerifiedAndGrantsAccess(): void
    {
        $checkout=$this->checkout->start('deadlock',1,1,'product-page');
        $order=$this->checkout->createOrder($checkout['token'],1,'2026-08-30',true);
        $event=[
            'id'=>'evt_stripe_paid',
            'object'=>'event',
            'type'=>'checkout.session.completed',
            'data'=>['object'=>[
                'id'=>'cs_test_paid',
                'object'=>'checkout.session',
                'payment_status'=>'paid',
                'payment_intent'=>'pi_test_paid',
                'client_reference_id'=>$order['order_number'],
                'metadata'=>['pericles_order_number'=>$order['order_number']],
                'amount_total'=>2499,
                'currency'=>'eur',
            ]],
        ];
        $stripe=new StripePaymentService(
            $this->checkout,
            'pk_test_example',
            'sk_test_example',
            'whsec_example',
            'https://events.example.test/public'
        );
        $payload=json_encode($event,JSON_THROW_ON_ERROR);
        $signature=WebhookSignature::generateSignatureHeader($payload,'whsec_example');

        $result=$stripe->handleWebhook($payload,$signature);

        self::assertTrue($result['processed']);
        self::assertSame('paid',$this->database->query('SELECT status FROM orders')->fetchColumn());
        self::assertSame('pending',$this->database->query('SELECT status FROM subscriptions')->fetchColumn());
        self::assertSame('stripe',$this->database->query('SELECT provider FROM payments')->fetchColumn());
        self::assertSame('pi_test_paid',$this->database->query('SELECT provider_reference FROM payments')->fetchColumn());
    }

    public function testUnpaidStripeCheckoutDoesNotGrantAccess(): void
    {
        $checkout=$this->checkout->start('deadlock',1,1,'product-page');
        $order=$this->checkout->createOrder($checkout['token'],1,'2026-08-30',true);
        $event=[
            'id'=>'evt_stripe_unpaid',
            'type'=>'checkout.session.completed',
            'data'=>['object'=>[
                'payment_status'=>'unpaid',
                'client_reference_id'=>$order['order_number'],
            ]],
        ];
        $stripe=new StripePaymentService(
            $this->checkout,'pk_test_example','sk_test_example','whsec_example','https://events.example.test/public',null,
            static fn():array=>$event
        );

        $result=$stripe->handleWebhook('{}','t=1,v1=test');

        self::assertFalse($result['processed']);
        self::assertSame('pending',$this->database->query('SELECT status FROM orders')->fetchColumn());
        self::assertSame(0,(int)$this->database->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn());
    }

    public function testStripeWebhookRejectsAnInvalidSignature(): void
    {
        $stripe=new StripePaymentService(
            $this->checkout,'pk_test_example','sk_test_example','whsec_example','https://events.example.test/public'
        );

        try {
            $stripe->handleWebhook('{}','t=1,v1=invalid');
            self::fail('An invalid Stripe signature must be rejected.');
        } catch (ApiException $exception) {
            self::assertSame('invalid_webhook_signature',$exception->errorCode);
            self::assertSame(400,$exception->statusCode);
        }
    }

    public function testRenewalStacksDurationAndLifetimeUpgradeRemovesExpiration(): void
    {
        $first=$this->checkout->start('deadlock',1,1,'renew');
        $firstOrder=$this->checkout->createOrder($first['token'],1,'2026-08-30',true);
        $this->checkout->confirmPayment('test-provider','evt-renew-1',(string)$firstOrder['order_number'],'pay-renew-1',2499,'EUR');
        $oldExpiry=gmdate('Y-m-d H:i:s',time()+10*86400);
        $this->database->prepare("UPDATE subscriptions SET status='active',started_at=:start,starts_at=:start,activated_at=:start,activation_deadline_at=NULL,expires_at=:expires")->execute([':start'=>gmdate('Y-m-d H:i:s'),':expires'=>$oldExpiry]);

        $renew=$this->checkout->start('deadlock',1,1,'renew');
        $renewOrder=$this->checkout->createOrder($renew['token'],1,'2026-08-30',true);
        $this->checkout->confirmPayment('test-provider','evt-renew-2',(string)$renewOrder['order_number'],'pay-renew-2',2499,'EUR');
        $renewedExpiry=(string)$this->database->query('SELECT expires_at FROM subscriptions')->fetchColumn();
        self::assertSame(strtotime($oldExpiry)+30*86400,strtotime($renewedExpiry));

        $lifetime=$this->checkout->start('deadlock',2,1,'upgrade');
        $lifetimeOrder=$this->checkout->createOrder($lifetime['token'],1,'2026-08-30',true);
        $this->checkout->confirmPayment('test-provider','evt-life',(string)$lifetimeOrder['order_number'],'pay-life',14999,'EUR');
        $access=$this->database->query('SELECT status,expires_at FROM subscriptions')->fetch();
        self::assertSame('active',$access['status']);
        self::assertNull($access['expires_at']);
    }

    private function schema(): string
    {
        return <<<'SQL'
CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT);
CREATE TABLE games(id INTEGER PRIMARY KEY,slug TEXT,name TEXT,short_description TEXT,image_url TEXT,is_active INTEGER,is_public INTEGER,sort_order INTEGER,commercial_status TEXT,purchases_allowed INTEGER,status_updated_at TEXT,updated_at TEXT);
CREATE TABLE products(id INTEGER PRIMARY KEY,slug TEXT,name TEXT,short_description TEXT,description TEXT,compatibility TEXT,operating_systems TEXT,requirements TEXT,included_items_json TEXT,seo_title TEXT,seo_description TEXT,is_active INTEGER,is_public INTEGER);
CREATE TABLE product_games(product_id INTEGER,game_id INTEGER);
CREATE TABLE plans(id INTEGER PRIMARY KEY,product_id INTEGER,slug TEXT,name TEXT,duration_days INTEGER,is_lifetime INTEGER,price_cents INTEGER,sale_price_cents INTEGER,currency TEXT,badge TEXT,is_active INTEGER,sort_order INTEGER);
CREATE TABLE modules(id INTEGER PRIMARY KEY,game_id INTEGER,is_active INTEGER);
CREATE TABLE module_versions(id INTEGER PRIMARY KEY,module_id INTEGER,version TEXT,status TEXT,published_at TEXT);
CREATE TABLE product_feature_categories(id INTEGER PRIMARY KEY,product_id INTEGER,name TEXT,description TEXT,is_active INTEGER,sort_order INTEGER);
CREATE TABLE product_features(id INTEGER PRIMARY KEY,category_id INTEGER,name TEXT,short_description TEXT,is_highlighted INTEGER,is_active INTEGER,is_public INTEGER,sort_order INTEGER);
CREATE TABLE product_media(id INTEGER PRIMARY KEY,product_id INTEGER,media_type TEXT,url TEXT,thumbnail_url TEXT,poster_url TEXT,title TEXT,alt_text TEXT,is_public INTEGER,is_active INTEGER,sort_order INTEGER);
CREATE TABLE product_documents(id INTEGER PRIMARY KEY,product_id INTEGER,slug TEXT,title TEXT,section TEXT,body TEXT,access_level TEXT,is_published INTEGER,sort_order INTEGER,updated_at TEXT);
CREATE TABLE product_changelog(id INTEGER PRIMARY KEY AUTOINCREMENT,product_id INTEGER,version TEXT,summary TEXT,published_at TEXT,is_public INTEGER);
CREATE TABLE product_faqs(id INTEGER PRIMARY KEY,product_id INTEGER,question TEXT,answer TEXT,is_public INTEGER,is_active INTEGER,sort_order INTEGER);
CREATE TABLE devices(id INTEGER PRIMARY KEY,display_name TEXT);
CREATE TABLE checkout_sessions(id INTEGER PRIMARY KEY AUTOINCREMENT,token_hash TEXT,user_id INTEGER,product_id INTEGER,plan_id INTEGER,price_cents INTEGER,currency TEXT,source TEXT,status TEXT,expires_at TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE orders(id INTEGER PRIMARY KEY AUTOINCREMENT,order_number TEXT,user_id INTEGER,product_id INTEGER,plan_id INTEGER,checkout_session_id INTEGER UNIQUE,status TEXT,amount_cents INTEGER,currency TEXT,product_name_snapshot TEXT,plan_name_snapshot TEXT,duration_days_snapshot INTEGER,is_lifetime_snapshot INTEGER,terms_version TEXT,immediate_delivery_consent_at TEXT,created_at TEXT,updated_at TEXT,paid_at TEXT);
CREATE TABLE payments(id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER,provider TEXT,provider_reference TEXT,status TEXT,amount_cents INTEGER,currency TEXT,method TEXT,created_at TEXT,confirmed_at TEXT,updated_at TEXT,UNIQUE(provider,provider_reference));
CREATE TABLE payment_webhook_events(id INTEGER PRIMARY KEY AUTOINCREMENT,provider TEXT,event_id TEXT,payload_hash TEXT,processed_at TEXT,UNIQUE(provider,event_id));
CREATE TABLE subscriptions(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,product_id INTEGER,plan_id INTEGER,order_id INTEGER,status TEXT,purchased_at TEXT,activation_deadline_at TEXT,activated_at TEXT,starts_at TEXT,started_at TEXT,expires_at TEXT,bound_device_id INTEGER,bound_at TEXT,cancelled_at TEXT,suspended_at TEXT,created_at TEXT,updated_at TEXT,UNIQUE(user_id,product_id));
SQL;
    }
}
