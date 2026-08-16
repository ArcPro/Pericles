<?php

declare(strict_types=1);

namespace Pericles\Auth;

use DateTimeImmutable;
use PDO;
use Pericles\Http\ApiException;

final class SessionService
{
    private PDO $database;

    private int $tokenTtl;

    public function __construct(
        PDO $database,
        int $tokenTtl
    ) {
        $this->database = $database;
        $this->tokenTtl = $tokenTtl;
    }

    public function create(array $user, ?string $ipAddress, ?string $userAgent): array
    {
        $token = bin2hex(random_bytes(32));
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify('+' . $this->tokenTtl . ' seconds');

        $statement = $this->database->prepare(
            'INSERT INTO api_sessions '
            . '(token_hash, user_id, created_at, expires_at, last_seen_at, created_ip, user_agent) '
            . 'VALUES (:token_hash, :user_id, :created_at, :expires_at, :last_seen_at, :created_ip, :user_agent)'
        );
        $statement->execute([
            ':token_hash' => hash('sha256', $token),
            ':user_id' => (int) $user['id'],
            ':created_at' => $now->format('Y-m-d H:i:s'),
            ':expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            ':last_seen_at' => $now->format('Y-m-d H:i:s'),
            ':created_ip' => $ipAddress,
            ':user_agent' => $userAgent === null ? null : substr($userAgent, 0, 512),
        ]);

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->tokenTtl,
        ];
    }

    public function currentUser(string $token): array
    {
        $session = $this->currentSession($token);

        return [
            'id' => (string) $session['user_id'],
            'email' => (string) $session['email'],
            'status' => (string) $session['status'],
        ];
    }

    public function currentSession(string $token): array
    {
        if ($token === '') {
            throw new ApiException('invalid_token', 401, 'Invalid access token.');
        }

        $statement = $this->database->prepare(
            'SELECT s.id AS session_id, s.device_id AS device_row_id, s.expires_at, s.revoked_at, '
            . 'u.id AS user_id, u.email, u.status, d.device_id, d.revoked_at AS device_revoked_at '
            . 'FROM api_sessions s INNER JOIN users u ON u.id = s.user_id '
            . 'LEFT JOIN devices d ON d.id = s.device_id '
            . 'WHERE s.token_hash = :token_hash LIMIT 1'
        );
        $statement->execute([':token_hash' => hash('sha256', $token)]);
        $row = $statement->fetch();

        if (!is_array($row) || $row['revoked_at'] !== null) {
            throw new ApiException('invalid_token', 401, 'Invalid access token.');
        }

        if (new DateTimeImmutable((string) $row['expires_at']) <= new DateTimeImmutable()) {
            throw new ApiException('token_expired', 401, 'Access token has expired.');
        }

        if ((string) $row['status'] !== 'active') {
            throw new ApiException('account_disabled', 403, 'This account is currently unavailable.');
        }

        $this->database->prepare('UPDATE api_sessions SET last_seen_at = :last_seen_at WHERE id = :id')
            ->execute([
                ':last_seen_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                ':id' => (int) $row['session_id'],
            ]);

        return [
            'session_id' => (int) $row['session_id'],
            'user_id' => (int) $row['user_id'],
            'email' => (string) $row['email'],
            'status' => (string) $row['status'],
            'device_row_id' => $row['device_row_id'] === null ? null : (int) $row['device_row_id'],
            'device_id' => $row['device_id'] === null ? null : (string) $row['device_id'],
            'device_revoked_at' => $row['device_revoked_at'],
        ];
    }

    public function requireVerifiedDevice(string $token): array
    {
        $session = $this->currentSession($token);
        if ($session['device_row_id'] === null || $session['device_id'] === null) {
            throw new ApiException('device_verification_required', 403, 'A verified device is required.');
        }
        if ($session['device_revoked_at'] !== null) {
            throw new ApiException('device_revoked', 403, 'This device is revoked.');
        }

        return $session;
    }

    public function revoke(string $token): void
    {
        if ($token === '') {
            throw new ApiException('invalid_token', 401, 'Invalid access token.');
        }

        $statement = $this->database->prepare(
            'UPDATE api_sessions SET revoked_at = :revoked_at '
            . 'WHERE token_hash = :token_hash AND revoked_at IS NULL'
        );
        $statement->execute([
            ':revoked_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':token_hash' => hash('sha256', $token),
        ]);

        if ($statement->rowCount() === 0) {
            throw new ApiException('invalid_token', 401, 'Invalid access token.');
        }
    }
}
