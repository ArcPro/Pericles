<?php

declare(strict_types=1);

namespace Pericles\Modules;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Pericles\Http\ApiException;

final class ModuleRateLimiter
{
    public function __construct(
        private PDO $database,
        private int $ticketLimit,
        private int $downloadLimit,
        private int $windowSeconds
    ) {
        $this->ticketLimit = max(1, $ticketLimit);
        $this->downloadLimit = max(1, $downloadLimit);
        $this->windowSeconds = max(10, $windowSeconds);
    }

    public function consume(int $userId, int $deviceId, string $requestType, string $ipAddress): void
    {
        if (!in_array($requestType, ['ticket', 'download'], true)) {
            throw new \InvalidArgumentException('Invalid module request type.');
        }
        $limit = $requestType === 'ticket' ? $this->ticketLimit : $this->downloadLimit;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $threshold = $now->modify('-' . $this->windowSeconds . ' seconds')->format('Y-m-d H:i:s');
        $query = $this->database->prepare(
            'SELECT COUNT(*) FROM module_request_attempts WHERE request_type = :request_type '
            . 'AND requested_at >= :threshold '
            . 'AND (user_id = :user_id OR device_id = :device_id OR ip_address = :ip_address)'
        );
        $query->execute([
            ':request_type' => $requestType,
            ':threshold' => $threshold,
            ':user_id' => $userId,
            ':device_id' => $deviceId,
            ':ip_address' => substr($ipAddress, 0, 45),
        ]);
        if ((int) $query->fetchColumn() >= $limit) {
            throw new ApiException('module_rate_limited', 429, 'Too many module requests.');
        }
        $insert = $this->database->prepare(
            'INSERT INTO module_request_attempts (user_id, device_id, request_type, ip_address, requested_at) '
            . 'VALUES (:user_id, :device_id, :request_type, :ip_address, :requested_at)'
        );
        $insert->execute([
            ':user_id' => $userId,
            ':device_id' => $deviceId,
            ':request_type' => $requestType,
            ':ip_address' => substr($ipAddress, 0, 45),
            ':requested_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }
}
