<?php

declare(strict_types=1);

namespace Pericles\Tests;

use PDO;
use Pericles\Auth\PasswordResetService;
use Pericles\Http\ApiException;
use Pericles\Support\SupportService;
use PHPUnit\Framework\TestCase;

final class CommercialUxTest extends TestCase
{
    private PDO $database;

    protected function setUp(): void
    {
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->database->exec(<<<'SQL'
CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,display_name TEXT,status TEXT,password_hash TEXT);
CREATE TABLE api_sessions(id INTEGER PRIMARY KEY,user_id INTEGER,revoked_at TEXT);
CREATE TABLE password_reset_tokens(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,token_hash TEXT,created_at TEXT,expires_at TEXT,used_at TEXT,request_ip TEXT);
CREATE TABLE password_reset_attempts(id INTEGER PRIMARY KEY AUTOINCREMENT,email_hash TEXT,ip_address TEXT,attempted_at TEXT);
CREATE TABLE roles(id INTEGER PRIMARY KEY,slug TEXT,name TEXT);
CREATE TABLE user_roles(user_id INTEGER PRIMARY KEY,role_id INTEGER);
CREATE TABLE products(id INTEGER PRIMARY KEY,slug TEXT,name TEXT);
CREATE TABLE games(id INTEGER PRIMARY KEY,slug TEXT,name TEXT);
CREATE TABLE product_games(product_id INTEGER,game_id INTEGER);
CREATE TABLE orders(id INTEGER PRIMARY KEY,user_id INTEGER,order_number TEXT);
CREATE TABLE support_tickets(id INTEGER PRIMARY KEY AUTOINCREMENT,ticket_number TEXT,user_id INTEGER NULL,guest_email TEXT,assigned_to_user_id INTEGER NULL,product_id INTEGER NULL,order_id INTEGER NULL,subject TEXT,category TEXT,priority TEXT,status TEXT,created_at TEXT,updated_at TEXT,closed_at TEXT,resolved_at TEXT);
CREATE TABLE support_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,ticket_id INTEGER,author_user_id INTEGER NULL,author_email TEXT,message TEXT,is_staff_reply INTEGER,is_internal_note INTEGER DEFAULT 0,created_at TEXT);
CREATE TABLE support_attachments(id INTEGER PRIMARY KEY AUTOINCREMENT,ticket_id INTEGER,message_id INTEGER,uploaded_by_user_id INTEGER,original_name TEXT,storage_name TEXT,mime_type TEXT,file_size INTEGER,created_at TEXT);
CREATE TABLE admin_audit_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_user_id INTEGER,action TEXT,target_type TEXT,target_id TEXT,metadata_json TEXT,ip_address TEXT,created_at TEXT);
SQL);
        $this->database->prepare('INSERT INTO users(id,email,display_name,status,password_hash) VALUES(1,?,?,?,?)')->execute(['member@example.test', 'Member', 'active', password_hash('PreviousPassword1!', PASSWORD_DEFAULT)]);
        $this->database->prepare('INSERT INTO users(id,email,display_name,status,password_hash) VALUES(2,?,?,?,?)')->execute(['staff@example.test', 'Support', 'active', password_hash('StaffPassword1!', PASSWORD_DEFAULT)]);
        $this->database->prepare('INSERT INTO users(id,email,display_name,status,password_hash) VALUES(3,?,?,?,?)')->execute(['other@example.test', 'Other', 'active', password_hash('OtherPassword1!', PASSWORD_DEFAULT)]);
        $this->database->exec("INSERT INTO api_sessions(id,user_id,revoked_at) VALUES(1,1,NULL)");
        $this->database->exec("INSERT INTO roles(id,slug,name) VALUES(1,'moderator','Support'); INSERT INTO user_roles(user_id,role_id) VALUES(2,1); INSERT INTO products(id,slug,name) VALUES(1,'deadlock','Deadlock'); INSERT INTO games(id,slug,name) VALUES(1,'deadlock','Deadlock'); INSERT INTO product_games(product_id,game_id) VALUES(1,1); INSERT INTO orders(id,user_id,order_number) VALUES(1,1,'PER-TEST-12345678')");
    }

    public function testGuestCanCreateSupportTicketWithEmailContext(): void
    {
        $ticket = (new SupportService($this->database))->createRequest(null, 'GUEST@Example.test', 'Installation issue', 'installation', 'The launcher does not complete installation.');
        self::assertStringStartsWith('SUP-', $ticket['ticket_number']);
        self::assertSame('guest@example.test', $this->database->query('SELECT guest_email FROM support_tickets')->fetchColumn());
        self::assertSame('guest@example.test', $this->database->query('SELECT author_email FROM support_messages')->fetchColumn());
    }

    public function testGuestSupportRequiresValidEmail(): void
    {
        $this->expectException(ApiException::class);
        (new SupportService($this->database))->createRequest(null, 'invalid', 'Access issue', 'access', 'I cannot activate my purchased access.');
    }

    public function testPersistentSupportWorkflowEnforcesOwnershipAndHidesInternalNotes(): void
    {
        $service = new SupportService($this->database);
        $created = $service->createRequest(1, null, 'Paid Access is missing', 'access', 'My completed order does not show Access.', 'urgent', 1, 1);
        $reference = (string) $created['ticket_number'];
        self::assertSame('urgent', $service->ticketForUser($reference, 1)['priority']);

        try {
            $service->ticketForUser($reference, 3);
            self::fail('Another customer must not read this ticket.');
        } catch (ApiException $exception) {
            self::assertSame('ticket_not_found', $exception->errorCode);
        }

        $service->updateByStaff($reference, 2, 'high', 'in_progress', 2, 'Customer supplied proof was reviewed.', true, '127.0.0.1');
        $customerView = $service->ticketForUser($reference, 1);
        $staffView = $service->ticketForAdmin($reference);
        self::assertSame('high', $customerView['priority']);
        self::assertSame('in_progress', $customerView['status']);
        self::assertCount(1, $customerView['messages']);
        self::assertCount(2, $staffView['messages']);
        self::assertSame(1, (int) $staffView['messages'][1]['is_internal_note']);
        self::assertSame('support.ticket_updated', $this->database->query('SELECT action FROM admin_audit_logs ORDER BY id LIMIT 1')->fetchColumn());
    }

    public function testCustomerReplyReopensResolvedTicketAndCanMarkItSolved(): void
    {
        $service = new SupportService($this->database);
        $created = $service->createRequest(1, null, 'Installation does not start', 'installation', 'The launcher exits before installation begins.');
        $reference = (string) $created['ticket_number'];
        $service->markSolved($reference, 1);
        self::assertSame('resolved', $service->ticketForUser($reference, 1)['status']);
        $service->replyForUser($reference, 1, 'The same installation issue has returned.');
        self::assertSame('open', $service->ticketForUser($reference, 1)['status']);
        self::assertCount(2, $service->ticketForUser($reference, 1)['messages']);
    }

    public function testPasswordResetChangesPasswordRevokesSessionsAndIsSingleUse(): void
    {
        $service = new PasswordResetService($this->database);
        $request = $service->request('MEMBER@example.test', '127.0.0.1');
        self::assertIsArray($request);
        $service->reset((string) $request['token'], 'NewSecurePassword2!');
        self::assertTrue(password_verify('NewSecurePassword2!', (string) $this->database->query('SELECT password_hash FROM users WHERE id=1')->fetchColumn()));
        self::assertNotNull($this->database->query('SELECT revoked_at FROM api_sessions WHERE id=1')->fetchColumn());
        $this->expectException(ApiException::class);
        $service->reset((string) $request['token'], 'AnotherSecurePassword3!');
    }
}
