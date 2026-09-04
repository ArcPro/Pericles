<?php

declare(strict_types=1);

namespace Pericles\Tests;

use DateTimeImmutable;
use PDO;
use Pericles\Auth\AuthService;
use Pericles\Auth\SessionService;
use Pericles\Http\ApiException;
use Pericles\Security\LoginRateLimiter;
use PHPUnit\Framework\TestCase;

final class AuthenticationTest extends TestCase
{
    private PDO $database;

    private AuthService $auth;

    private SessionService $sessions;

    protected function setUp(): void
    {
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->database->exec(
            "CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'active',
                display_name TEXT,
                created_at TEXT,
                updated_at TEXT
            );
            CREATE TABLE api_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash TEXT NOT NULL UNIQUE,
                user_id INTEGER NOT NULL,
                device_id INTEGER NULL,
                created_at TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                revoked_at TEXT NULL,
                last_seen_at TEXT NULL,
                created_ip TEXT NULL,
                user_agent TEXT NULL
            );
            CREATE TABLE devices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                device_id TEXT NOT NULL UNIQUE,
                revoked_at TEXT NULL
            );
            CREATE TABLE login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_address TEXT NOT NULL,
                email_hash TEXT NOT NULL,
                attempted_at TEXT NOT NULL
            );"
        );

        $this->auth = new AuthService($this->database);
        $this->sessions = new SessionService($this->database, 3600);
    }

    public function testLoginSuccess(): void
    {
        $this->createUser('test@pericles.local', 'CorrectPassword!');
        $user = $this->auth->authenticate(' TEST@PERICLES.LOCAL ', 'CorrectPassword!');

        self::assertSame('test@pericles.local', $user['email']);
        self::assertSame('active', $user['status']);
    }

    public function testInvalidPasswordUsesGenericError(): void
    {
        $this->createUser('test@pericles.local', 'CorrectPassword!');
        $this->assertApiError(
            fn () => $this->auth->authenticate('test@pericles.local', 'WrongPassword!'),
            'invalid_credentials',
            401
        );
    }

    public function testUnknownEmailUsesGenericError(): void
    {
        $this->assertApiError(
            fn () => $this->auth->authenticate('missing@pericles.local', 'WrongPassword!'),
            'invalid_credentials',
            401
        );
    }

    public function testDisabledAccountIsRejected(): void
    {
        $this->createUser('disabled@pericles.local', 'CorrectPassword!', 'disabled');
        $this->assertApiError(
            fn () => $this->auth->authenticate('disabled@pericles.local', 'CorrectPassword!'),
            'account_disabled',
            403
        );
    }

    public function testAccessTokenHas256BitsOfRandomData(): void
    {
        $user = $this->createUser('test@pericles.local', 'CorrectPassword!');
        $first = $this->sessions->create($user, '127.0.0.1', 'PHPUnit');
        $second = $this->sessions->create($user, '127.0.0.1', 'PHPUnit');

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['access_token']);
        self::assertNotSame($first['access_token'], $second['access_token']);
    }

    public function testOnlyAccessTokenHashIsStored(): void
    {
        $user = $this->createUser('test@pericles.local', 'CorrectPassword!');
        $result = $this->sessions->create($user, '127.0.0.1', 'PHPUnit');
        $stored = (string) $this->database->query('SELECT token_hash FROM api_sessions')->fetchColumn();

        self::assertNotSame($result['access_token'], $stored);
        self::assertSame(hash('sha256', $result['access_token']), $stored);
    }

    public function testMeWithValidToken(): void
    {
        $user = $this->createUser('test@pericles.local', 'CorrectPassword!');
        $token = $this->sessions->create($user, '127.0.0.1', 'PHPUnit')['access_token'];

        self::assertSame('test@pericles.local', $this->sessions->currentUser($token)['email']);
    }

    public function testMeWithInvalidToken(): void
    {
        $this->assertApiError(
            fn () => $this->sessions->currentUser('not-a-valid-token'),
            'invalid_token',
            401
        );
    }

    public function testExpiredTokenIsRejected(): void
    {
        $user = $this->createUser('test@pericles.local', 'CorrectPassword!');
        $token = bin2hex(random_bytes(32));
        $statement = $this->database->prepare(
            'INSERT INTO api_sessions (token_hash, user_id, created_at, expires_at) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([
            hash('sha256', $token),
            $user['id'],
            (new DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'),
            (new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
        ]);

        $this->assertApiError(fn () => $this->sessions->currentUser($token), 'token_expired', 401);
    }

    public function testLogoutRevokesToken(): void
    {
        $user = $this->createUser('test@pericles.local', 'CorrectPassword!');
        $token = $this->sessions->create($user, '127.0.0.1', 'PHPUnit')['access_token'];
        $this->sessions->revoke($token);

        $this->assertApiError(fn () => $this->sessions->currentUser($token), 'invalid_token', 401);
    }

    public function testRegisterCreatesActiveAccount(): void
    {
        $user = $this->auth->register('  NEW@PERICLES.LOCAL  ', 'CorrectPassword!', 'Ada');
        $stored = $this->database->query('SELECT email, status, display_name FROM users WHERE id = ' . (int) $user['id'])->fetch();

        self::assertSame('new@pericles.local', $user['email']);
        self::assertSame('active', $user['status']);
        self::assertSame('new@pericles.local', $stored['email']);
        self::assertSame('Ada', $stored['display_name']);
        $this->auth->authenticate('new@pericles.local', 'CorrectPassword!');
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        $this->createUser('test@pericles.local', 'CorrectPassword!');
        $this->assertApiError(
            fn () => $this->auth->register('TEST@pericles.local', 'AnotherPass1'),
            'email_taken',
            409
        );
    }

    public function testRegisterRejectsShortPassword(): void
    {
        $this->assertApiError(
            fn () => $this->auth->register('new@pericles.local', 'short'),
            'validation_error',
            400
        );
    }

    public function testRegisterRejectsInvalidEmail(): void
    {
        $this->assertApiError(
            fn () => $this->auth->register('not-an-email', 'CorrectPassword!'),
            'validation_error',
            400
        );
    }

    public function testRegisterRequiresTermsAcceptance(): void
    {
        $this->assertApiError(
            fn () => $this->auth->register('new@pericles.local', 'CorrectPassword!', 'Ada', false),
            'terms_required',
            400
        );
    }

    public function testRateLimiterBlocksAfterConfiguredFailures(): void
    {
        $limiter = new LoginRateLimiter($this->database, 2, 300);
        $limiter->recordFailure('127.0.0.1', 'test@pericles.local');
        $limiter->recordFailure('127.0.0.1', 'test@pericles.local');

        $this->assertApiError(
            fn () => $limiter->ensureAllowed('127.0.0.1', 'test@pericles.local'),
            'rate_limited',
            429
        );
    }

    private function createUser(string $email, string $password, string $status = 'active'): array
    {
        $statement = $this->database->prepare(
            'INSERT INTO users (email, password_hash, status) VALUES (:email, :password_hash, :status)'
        );
        $statement->execute([
            ':email' => strtolower($email),
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':status' => $status,
        ]);

        return [
            'id' => (string) $this->database->lastInsertId(),
            'email' => strtolower($email),
            'status' => $status,
        ];
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
