<?php

declare(strict_types=1);

namespace Pericles\Auth;

use PDO;
use Pericles\Http\ApiException;

final class AuthService
{
    private const DUMMY_PASSWORD_HASH = '$2y$10$wHcZC8OTmxZb9jFjOxJZYeY.jqL6I8V7G/iQrv7aQFqsZGE3VfZ4m';

    private PDO $database;

    public function __construct(PDO $database)
    {
        $this->database = $database;
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function authenticate(string $email, string $password): array
    {
        $statement = $this->database->prepare(
            'SELECT id, email, password_hash, status FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute([':email' => self::normalizeEmail($email)]);
        $user = $statement->fetch();

        $passwordHash = is_array($user) ? (string) $user['password_hash'] : self::DUMMY_PASSWORD_HASH;
        if (!password_verify($password, $passwordHash) || !is_array($user)) {
            throw new ApiException('invalid_credentials', 401, 'Invalid email or password.');
        }

        if ((string) $user['status'] !== 'active') {
            throw new ApiException('account_disabled', 403, 'This account is currently unavailable.');
        }

        return [
            'id' => (string) $user['id'],
            'email' => (string) $user['email'],
            'status' => (string) $user['status'],
        ];
    }

    public function register(string $email, string $password, string $displayName = ''): array
    {
        $email = self::normalizeEmail($email);
        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException('validation_error', 400, 'A valid email address is required.');
        }
        if (strlen($password) < 10 || strlen($password) > 1024) {
            throw new ApiException('validation_error', 400, 'The password must contain at least 10 characters.');
        }

        $displayName = trim($displayName);
        if ($displayName !== '' && (strlen($displayName) > 80 || preg_match('/[\x00-\x1F\x7F]/', $displayName) === 1)) {
            throw new ApiException('validation_error', 400, 'The display name must contain between 1 and 80 characters.');
        }

        $existing = $this->database->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $existing->execute([':email' => $email]);
        if ($existing->fetch() !== false) {
            throw new ApiException('email_taken', 409, 'An account with this email already exists.');
        }

        try {
            $statement = $this->database->prepare(
                'INSERT INTO users (email, password_hash, status, display_name) '
                . 'VALUES (:email, :password_hash, :status, :display_name)'
            );
            $statement->execute([
                ':email' => $email,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':status' => 'active',
                ':display_name' => $displayName === '' ? null : $displayName,
            ]);
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new ApiException('email_taken', 409, 'An account with this email already exists.');
            }
            throw $exception;
        }

        return [
            'id' => (string) $this->database->lastInsertId(),
            'email' => $email,
            'status' => 'active',
        ];
    }
}
