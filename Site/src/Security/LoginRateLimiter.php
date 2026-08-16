<?php

declare(strict_types=1);

namespace Pericles\Security;

use DateTimeImmutable;
use PDO;
use Pericles\Http\ApiException;

final class LoginRateLimiter
{
    private PDO $database;

    private int $maximumAttempts;

    private int $windowSeconds;

    public function __construct(
        PDO $database,
        int $maximumAttempts,
        int $windowSeconds
    ) {
        $this->database = $database;
        $this->maximumAttempts = $maximumAttempts;
        $this->windowSeconds = $windowSeconds;
    }

    public function ensureAllowed(string $ipAddress, string $email): void
    {
        $threshold = (new DateTimeImmutable())->modify('-' . $this->windowSeconds . ' seconds')->format('Y-m-d H:i:s');
        $emailHash = hash('sha256', strtolower(trim($email)));

        $this->database->prepare('DELETE FROM login_attempts WHERE attempted_at < :threshold')
            ->execute([':threshold' => $threshold]);

        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM login_attempts '
            . 'WHERE attempted_at >= :threshold AND (ip_address = :ip_address OR email_hash = :email_hash)'
        );
        $statement->execute([
            ':threshold' => $threshold,
            ':ip_address' => $ipAddress,
            ':email_hash' => $emailHash,
        ]);

        if ((int) $statement->fetchColumn() >= $this->maximumAttempts) {
            throw new ApiException('rate_limited', 429, 'Too many login attempts. Try again later.');
        }
    }

    public function recordFailure(string $ipAddress, string $email): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO login_attempts (ip_address, email_hash, attempted_at) '
            . 'VALUES (:ip_address, :email_hash, :attempted_at)'
        );
        $statement->execute([
            ':ip_address' => substr($ipAddress, 0, 45),
            ':email_hash' => hash('sha256', strtolower(trim($email))),
            ':attempted_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function clear(string $ipAddress, string $email): void
    {
        $statement = $this->database->prepare(
            'DELETE FROM login_attempts WHERE ip_address = :ip_address OR email_hash = :email_hash'
        );
        $statement->execute([
            ':ip_address' => $ipAddress,
            ':email_hash' => hash('sha256', strtolower(trim($email))),
        ]);
    }
}
