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
CREATE TABLE support_tickets(id INTEGER PRIMARY KEY AUTOINCREMENT,ticket_number TEXT,user_id INTEGER NULL,guest_email TEXT,assigned_to_user_id INTEGER NULL,subject TEXT,category TEXT,status TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE support_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,ticket_id INTEGER,author_user_id INTEGER NULL,author_email TEXT,message TEXT,is_staff_reply INTEGER,created_at TEXT);
SQL);
        $this->database->prepare('INSERT INTO users(id,email,display_name,status,password_hash) VALUES(1,?,?,?,?)')->execute(['member@example.test', 'Member', 'active', password_hash('PreviousPassword1!', PASSWORD_DEFAULT)]);
        $this->database->exec("INSERT INTO api_sessions(id,user_id,revoked_at) VALUES(1,1,NULL)");
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
