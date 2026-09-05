<?php

declare(strict_types=1);

namespace Pericles\Catalog;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Pericles\Http\ApiException;
use Throwable;

final class CatalogService
{
    private string $driver;
    private bool $transactionActive = false;

    public function __construct(private PDO $database)
    {
        $this->driver = (string) $database->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function listGames(int $userId, int $currentDeviceId): array
    {
        $statement = $this->database->prepare(
            'SELECT g.id AS game_id, g.slug, g.name, g.short_description, g.image_url, g.sort_order, '
            . 's.id AS subscription_id, s.status AS subscription_status, s.expires_at, s.bound_device_id '
            . 'FROM games g '
            . 'LEFT JOIN product_games pg ON pg.game_id = g.id '
            . 'LEFT JOIN products p ON p.id = pg.product_id AND p.is_active = 1 '
            . 'LEFT JOIN subscriptions s ON s.product_id = p.id AND s.user_id = :user_id '
            . 'WHERE g.is_active = 1 ORDER BY g.sort_order ASC, g.name ASC'
        );
        $statement->execute([':user_id' => $userId]);

        $games = [];
        foreach ($statement->fetchAll() as $row) {
            $slug = (string) $row['slug'];
            if (!isset($games[$slug])) {
                $games[$slug] = [
                    'slug' => $slug,
                    'name' => (string) $row['name'],
                    'short_description' => (string) $row['short_description'],
                    'image_url' => (string) $row['image_url'],
                    'sort_order' => (int) $row['sort_order'],
                    '_candidates' => [],
                ];
            }
            if ($row['subscription_id'] !== null) {
                $games[$slug]['_candidates'][] = $row;
            }
        }

        foreach ($games as &$game) {
            $game['access'] = $this->evaluateAccess($game['_candidates'], $currentDeviceId);
            unset($game['_candidates']);
        }
        unset($game);
        return array_values($games);
    }

    public function bindGame(int $userId, int $currentDeviceId, string $slug): array
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new ApiException('validation_error', 400, 'Invalid game slug.');
        }

        $this->beginWriteTransaction();
        try {
            $this->activatePendingAccess($userId, $slug);
            $suffix = $this->driver === 'mysql' ? ' FOR UPDATE' : '';
            $statement = $this->database->prepare(
                'SELECT s.id, s.status, s.expires_at, s.bound_device_id '
                . 'FROM subscriptions s '
                . 'INNER JOIN products p ON p.id = s.product_id AND p.is_active = 1 '
                . 'INNER JOIN product_games pg ON pg.product_id = p.id '
                . 'INNER JOIN games g ON g.id = pg.game_id AND g.is_active = 1 '
                . 'WHERE s.user_id = :user_id AND g.slug = :slug ORDER BY s.id ASC' . $suffix
            );
            $statement->execute([':user_id' => $userId, ':slug' => $slug]);
            $valid = array_values(array_filter($statement->fetchAll(), fn (array $row): bool => $this->isUsable($row)));
            if ($valid === []) {
                throw new ApiException('subscription_required', 403, 'An active subscription is required.');
            }

            foreach ($valid as $subscription) {
                if ((int) ($subscription['bound_device_id'] ?? 0) === $currentDeviceId) {
                    $this->commit();
                    return ['success' => true, 'state' => 'available'];
                }
            }
            foreach ($valid as $subscription) {
                if ($subscription['bound_device_id'] === null) {
                    $now = $this->now();
                    $bind = $this->database->prepare(
                        'UPDATE subscriptions SET bound_device_id = :device_id, bound_at = :bound_at, '
                        . 'updated_at = :updated_at WHERE id = :id AND bound_device_id IS NULL'
                    );
                    $bind->execute([
                        ':device_id' => $currentDeviceId,
                        ':bound_at' => $now,
                        ':updated_at' => $now,
                        ':id' => (int) $subscription['id'],
                    ]);
                    if ($bind->rowCount() !== 1) {
                        throw new ApiException(
                            'subscription_bound_to_another_device',
                            409,
                            'This subscription is bound to another device.'
                        );
                    }
                    $this->commit();
                    return ['success' => true, 'state' => 'available'];
                }
            }

            throw new ApiException(
                'subscription_bound_to_another_device',
                409,
                'This subscription is bound to another device.'
            );
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    private function evaluateAccess(array $subscriptions, int $currentDeviceId): array
    {
        $bestState = 'locked';
        $bestRank = 0;
        $bestExpiry = null;
        foreach ($subscriptions as $subscription) {
            $state = $this->stateFor($subscription, $currentDeviceId);
            $rank = [
                'locked' => 0,
                'expired' => 1,
                'suspended' => 2,
                'bound_elsewhere' => 3,
                'ready_to_bind' => 4,
                'available' => 5,
            ][$state];
            if ($rank > $bestRank) {
                $bestState = $state;
                $bestRank = $rank;
                $bestExpiry = $subscription['expires_at'] === null
                    ? null
                    : $this->toAtom((string) $subscription['expires_at']);
            }
        }
        return ['state' => $bestState, 'expires_at' => $bestExpiry];
    }

    private function stateFor(array $subscription, int $currentDeviceId): string
    {
        if ((string) $subscription['subscription_status'] === 'suspended') {
            return 'suspended';
        }
        if (!$this->isUsable([
            'status' => $subscription['subscription_status'],
            'expires_at' => $subscription['expires_at'],
        ])) {
            return 'expired';
        }
        if ($subscription['bound_device_id'] === null) {
            return 'ready_to_bind';
        }
        return (int) $subscription['bound_device_id'] === $currentDeviceId
            ? 'available'
            : 'bound_elsewhere';
    }

    private function isUsable(array $subscription): bool
    {
        if (!in_array((string) $subscription['status'], ['active', 'cancelled'], true)) {
            return false;
        }
        return $subscription['expires_at'] === null
            || new DateTimeImmutable((string) $subscription['expires_at'], new DateTimeZone('UTC'))
                > new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function activatePendingAccess(int $userId, string $slug): void
    {
        try {
            $statement = $this->database->prepare(
                'SELECT s.id, pl.duration_days FROM subscriptions s INNER JOIN plans pl ON pl.id = s.plan_id '
                . 'INNER JOIN products p ON p.id = s.product_id INNER JOIN product_games pg ON pg.product_id = p.id '
                . 'INNER JOIN games g ON g.id = pg.game_id WHERE s.user_id = :user_id AND g.slug = :slug '
                . "AND s.status = 'pending' LIMIT 1" . ($this->driver === 'mysql' ? ' FOR UPDATE' : '')
            );
            $statement->execute([':user_id' => $userId, ':slug' => $slug]);
            $pending = $statement->fetch();
            if (!is_array($pending) || (int) $pending['duration_days'] < 1) return;
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $update = $this->database->prepare(
                "UPDATE subscriptions SET status = 'active', activated_at = :activated_at, starts_at = :starts_at, started_at = :started_at, "
                . 'expires_at = :expires_at, activation_deadline_at = NULL, updated_at = :updated_at WHERE id = :id'
            );
            $update->execute([
                ':activated_at' => $now->format('Y-m-d H:i:s'),
                ':starts_at' => $now->format('Y-m-d H:i:s'),
                ':started_at' => $now->format('Y-m-d H:i:s'),
                ':expires_at' => $now->modify('+' . (int) $pending['duration_days'] . ' days')->format('Y-m-d H:i:s'),
                ':updated_at' => $now->format('Y-m-d H:i:s'),
                ':id' => (int) $pending['id'],
            ]);
        } catch (\PDOException) {
            // Commerce migration is optional for legacy/test schemas.
        }
    }

    private function beginWriteTransaction(): void
    {
        if ($this->driver === 'sqlite') {
            $this->database->exec('BEGIN IMMEDIATE TRANSACTION');
        } else {
            $this->database->beginTransaction();
        }
        $this->transactionActive = true;
    }

    private function commit(): void
    {
        if ($this->driver === 'sqlite') {
            $this->database->exec('COMMIT');
        } else {
            $this->database->commit();
        }
        $this->transactionActive = false;
    }

    private function rollBack(): void
    {
        if (!$this->transactionActive) {
            return;
        }
        if ($this->driver === 'sqlite') {
            $this->database->exec('ROLLBACK');
        } elseif ($this->database->inTransaction()) {
            $this->database->rollBack();
        }
        $this->transactionActive = false;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function toAtom(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
