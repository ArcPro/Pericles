<?php

declare(strict_types=1);

namespace Pericles\Subscriptions;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class SubscriptionService
{
    public function __construct(private PDO $database)
    {
    }

    public function listForUser(int $userId, ?int $currentDeviceId): array
    {
        $statement = $this->database->prepare(
            'SELECT s.status, s.started_at, s.expires_at, s.bound_device_id, p.slug, p.name '
            . 'FROM subscriptions s INNER JOIN products p ON p.id = s.product_id '
            . 'WHERE s.user_id = :user_id ORDER BY p.name ASC'
        );
        $statement->execute([':user_id' => $userId]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $status = (string) $row['status'];
            if ($status !== 'suspended' && $row['expires_at'] !== null
                && new DateTimeImmutable((string) $row['expires_at'], new DateTimeZone('UTC'))
                    <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                $status = 'expired';
            }
            $binding = 'unbound';
            if ($row['bound_device_id'] !== null) {
                $binding = $currentDeviceId !== null && (int) $row['bound_device_id'] === $currentDeviceId
                    ? 'current_device'
                    : 'other_device';
            }
            $result[] = [
                'product' => ['slug' => (string) $row['slug'], 'name' => (string) $row['name']],
                'status' => $status,
                'started_at' => $this->toAtom((string) $row['started_at']),
                'expires_at' => $row['expires_at'] === null ? null : $this->toAtom((string) $row['expires_at']),
                'device_binding' => ['state' => $binding],
            ];
        }
        return $result;
    }

    private function toAtom(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
