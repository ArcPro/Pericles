<?php

declare(strict_types=1);

namespace Pericles\Activation;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Pericles\Http\ApiException;

final class ActivationRateLimiter
{
    public function __construct(
        private PDO $database,
        private int $limit = 10,
        private int $windowSeconds = 900
    ) {
        $this->limit = max(1, $this->limit);
        $this->windowSeconds = max(60, $this->windowSeconds);
    }

    public function consume(int $userId, string $ipAddress): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $threshold = $now->modify('-' . $this->windowSeconds . ' seconds')->format('Y-m-d H:i:s');
        $query = $this->database->prepare(
            'SELECT COUNT(*) FROM activation_attempts '
            . 'WHERE attempted_at >= :threshold AND (user_id = :user_id OR ip_address = :ip_address)'
        );
        $query->execute([
            ':threshold' => $threshold,
            ':user_id' => $userId,
            ':ip_address' => $ipAddress,
        ]);
        if ((int) $query->fetchColumn() >= $this->limit) {
            throw new ApiException('activation_rate_limited', 429, 'Too many activation attempts.');
        }

        $insert = $this->database->prepare(
            'INSERT INTO activation_attempts (user_id, ip_address, attempted_at) '
            . 'VALUES (:user_id, :ip_address, :attempted_at)'
        );
        $insert->execute([
            ':user_id' => $userId,
            ':ip_address' => substr($ipAddress, 0, 45),
            ':attempted_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }
}
