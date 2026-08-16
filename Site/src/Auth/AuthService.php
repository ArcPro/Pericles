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
}
