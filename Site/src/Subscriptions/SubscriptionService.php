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
        $this->activatePastDeadlines($userId);
        $commerceFields = true;
        try {
            $statement = $this->database->prepare(
                'SELECT s.id, s.status, s.purchased_at, s.started_at, s.expires_at, s.bound_device_id, s.bound_at, '
                . 's.device_reset_available_at, s.activation_deadline_at, s.activated_at, s.starts_at, '
                . 'p.slug, p.name, g.name AS game_name, g.image_url, g.commercial_status, pl.name AS plan_name, pl.is_lifetime, o.order_number, '
                . 'd.display_name AS device_name FROM subscriptions s INNER JOIN products p ON p.id = s.product_id '
                . 'LEFT JOIN product_games pg ON pg.product_id = p.id LEFT JOIN games g ON g.id = pg.game_id LEFT JOIN plans pl ON pl.id=s.plan_id '
                . 'LEFT JOIN devices d ON d.id=s.bound_device_id LEFT JOIN orders o ON o.id=s.order_id '
                . 'WHERE s.user_id = :user_id ORDER BY p.name ASC'
            );
            $statement->execute([':user_id' => $userId]);
        } catch (\PDOException) {
            $commerceFields = false;
            $statement = $this->database->prepare(
                'SELECT s.status, s.started_at, s.expires_at, s.bound_device_id, p.slug, p.name, g.image_url '
                . 'FROM subscriptions s INNER JOIN products p ON p.id = s.product_id '
                . 'LEFT JOIN product_games pg ON pg.product_id = p.id LEFT JOIN games g ON g.id = pg.game_id '
                . 'WHERE s.user_id = :user_id ORDER BY p.name ASC'
            );
            $statement->execute([':user_id' => $userId]);
        }
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
                'id' => (int) ($row['id'] ?? 0),
                'product' => ['slug' => (string) $row['slug'], 'name' => (string) $row['name'], 'image_url' => (string) ($row['image_url'] ?? '')],
                'game_name' => (string) ($row['game_name'] ?? $row['name']),
                'product_status' => (string) ($row['commercial_status'] ?? 'operational'),
                'status' => $status,
                'started_at' => $this->toAtom((string) $row['started_at']),
                'purchased_at' => !$commerceFields || $row['purchased_at'] === null ? null : $this->toAtom((string) $row['purchased_at']),
                'expires_at' => $row['expires_at'] === null ? null : $this->toAtom((string) $row['expires_at']),
                'activation_deadline_at' => !$commerceFields || $row['activation_deadline_at'] === null ? null : $this->toAtom((string) $row['activation_deadline_at']),
                'activated_at' => !$commerceFields || $row['activated_at'] === null ? null : $this->toAtom((string) $row['activated_at']),
                'plan_name' => $commerceFields ? $row['plan_name'] : null,
                'is_lifetime' => $commerceFields ? (int) ($row['is_lifetime'] ?? 0) === 1 : $row['expires_at'] === null,
                'order_number' => $commerceFields ? ($row['order_number'] ?? null) : null,
                'device_binding' => [
                    'state' => $binding,
                    'name' => $commerceFields ? $row['device_name'] : null,
                    'linked_at' => !$commerceFields || $row['bound_at'] === null ? null : $this->toAtom((string) $row['bound_at']),
                    'reset_available_at' => !$commerceFields || $row['device_reset_available_at'] === null ? null : $this->toAtom((string) $row['device_reset_available_at']),
                ],
            ];
        }
        return $result;
    }

    private function activatePastDeadlines(int $userId): void
    {
        try {
            $statement = $this->database->prepare(
                'SELECT s.id, s.activation_deadline_at, pl.duration_days FROM subscriptions s '
                . 'INNER JOIN plans pl ON pl.id = s.plan_id WHERE s.user_id = :user_id '
                . "AND s.status = 'pending' AND s.activation_deadline_at <= :now"
            );
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $nowSql = $now->format('Y-m-d H:i:s');
            $statement->execute([':user_id' => $userId, ':now' => $nowSql]);
            foreach ($statement->fetchAll() as $row) {
                if ((int) $row['duration_days'] < 1) continue;
                $start = new DateTimeImmutable((string) $row['activation_deadline_at'], new DateTimeZone('UTC'));
                $update = $this->database->prepare(
                    "UPDATE subscriptions SET status = 'active', activated_at = :activated_at, starts_at = :starts_at, started_at = :started_at, "
                    . 'expires_at = :expires_at, activation_deadline_at = NULL, updated_at = :updated_at WHERE id = :id'
                );
                $startSql=$start->format('Y-m-d H:i:s');$update->execute([':activated_at'=>$startSql,':starts_at'=>$startSql,':started_at'=>$startSql,':expires_at'=>$start->modify('+'.(int)$row['duration_days'].' days')->format('Y-m-d H:i:s'),':updated_at'=>$nowSql,':id'=>(int)$row['id']]);
            }
        } catch (\PDOException) {
            // Commerce migration is optional for legacy/test schemas.
        }
    }

    private function toAtom(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
