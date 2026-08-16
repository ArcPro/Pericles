<?php

declare(strict_types=1);

namespace Pericles\Activation;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Pericles\Http\ApiException;
use Throwable;

final class ActivationService
{
    private PDO $database;
    private string $driver;
    private bool $transactionActive = false;

    public function __construct(PDO $database)
    {
        $this->database = $database;
        $this->driver = (string) $database->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function redeem(int $userId, string $plainKey): array
    {
        $key = $this->normalizeKey($plainKey);
        $keyHash = hash('sha256', $key);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowSql = $now->format('Y-m-d H:i:s');

        $this->beginWriteTransaction();
        try {
            $suffix = $this->driver === 'mysql' ? ' FOR UPDATE' : '';
            $statement = $this->database->prepare(
                'SELECT k.*, p.duration_days, p.is_lifetime, p.is_active AS plan_active, '
                . 'pr.id AS product_id, pr.slug AS product_slug, pr.name AS product_name, '
                . 'pr.is_active AS product_active '
                . 'FROM activation_keys k '
                . 'INNER JOIN plans p ON p.id = k.plan_id '
                . 'INNER JOIN products pr ON pr.id = p.product_id '
                . 'WHERE k.key_hash = :key_hash LIMIT 1' . $suffix
            );
            $statement->execute([':key_hash' => $keyHash]);
            $activationKey = $statement->fetch();
            if (!is_array($activationKey)) {
                throw new ApiException('invalid_activation_key', 404, 'Invalid activation key.');
            }
            $this->validateActivationKey($activationKey, $now);
            $userLock = $this->database->prepare(
                'SELECT id FROM users WHERE id = :user_id LIMIT 1' . ($this->driver === 'mysql' ? ' FOR UPDATE' : '')
            );
            $userLock->execute([':user_id' => $userId]);
            if ($userLock->fetchColumn() === false) {
                throw new ApiException('invalid_token', 401, 'Invalid access token.');
            }

            $subscription = $this->findSubscription($userId, (int) $activationKey['product_id'], true);
            $previousExpiresAt = is_array($subscription) ? $subscription['expires_at'] : null;
            $isLifetime = (int) $activationKey['is_lifetime'] === 1;

            if (is_array($subscription) && $subscription['expires_at'] === null && !$isLifetime) {
                throw new ApiException(
                    'subscription_already_lifetime',
                    409,
                    'This subscription already has lifetime access.'
                );
            }

            $newExpiresAt = null;
            if (!$isLifetime) {
                $days = (int) $activationKey['duration_days'];
                if ($days < 1) {
                    throw new ApiException('plan_disabled', 409, 'This plan is unavailable.');
                }
                $base = $now;
                if (is_array($subscription) && $subscription['expires_at'] !== null) {
                    $currentExpiry = new DateTimeImmutable((string) $subscription['expires_at'], new DateTimeZone('UTC'));
                    if ($currentExpiry > $base) {
                        $base = $currentExpiry;
                    }
                }
                $newExpiresAt = $base->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
            }

            if (!is_array($subscription)) {
                $insert = $this->database->prepare(
                    'INSERT INTO subscriptions '
                    . '(user_id, product_id, status, started_at, expires_at, bound_device_id, bound_at, '
                    . 'created_at, updated_at) '
                    . 'VALUES (:user_id, :product_id, :status, :started_at, :expires_at, NULL, NULL, '
                    . ':created_at, :updated_at)'
                );
                $insert->execute([
                    ':user_id' => $userId,
                    ':product_id' => (int) $activationKey['product_id'],
                    ':status' => 'active',
                    ':started_at' => $nowSql,
                    ':expires_at' => $newExpiresAt,
                    ':created_at' => $nowSql,
                    ':updated_at' => $nowSql,
                ]);
                $subscriptionId = (int) $this->database->lastInsertId();
            } else {
                $subscriptionId = (int) $subscription['id'];
                $update = $this->database->prepare(
                    'UPDATE subscriptions SET status = :status, expires_at = :expires_at, '
                    . 'cancelled_at = NULL, suspended_at = NULL, updated_at = :updated_at WHERE id = :id'
                );
                $update->execute([
                    ':status' => 'active',
                    ':expires_at' => $newExpiresAt,
                    ':updated_at' => $nowSql,
                    ':id' => $subscriptionId,
                ]);
            }

            $consume = $this->database->prepare(
                'UPDATE activation_keys SET status = :status, redeemed_at = :redeemed_at, '
                . 'redeemed_by_user_id = :user_id, subscription_id = :subscription_id '
                . 'WHERE id = :id AND status = :unused'
            );
            $consume->execute([
                ':status' => 'redeemed',
                ':redeemed_at' => $nowSql,
                ':user_id' => $userId,
                ':subscription_id' => $subscriptionId,
                ':id' => (int) $activationKey['id'],
                ':unused' => 'unused',
            ]);
            if ($consume->rowCount() !== 1) {
                throw new ApiException('activation_key_used', 409, 'This activation key has already been used.');
            }

            $history = $this->database->prepare(
                'INSERT INTO subscription_activations '
                . '(subscription_id, activation_key_id, plan_id, activated_at, previous_expires_at, new_expires_at) '
                . 'VALUES (:subscription_id, :activation_key_id, :plan_id, :activated_at, '
                . ':previous_expires_at, :new_expires_at)'
            );
            $history->execute([
                ':subscription_id' => $subscriptionId,
                ':activation_key_id' => (int) $activationKey['id'],
                ':plan_id' => (int) $activationKey['plan_id'],
                ':activated_at' => $nowSql,
                ':previous_expires_at' => $previousExpiresAt,
                ':new_expires_at' => $newExpiresAt,
            ]);

            $this->commit();
            return [
                'success' => true,
                'product' => [
                    'slug' => (string) $activationKey['product_slug'],
                    'name' => (string) $activationKey['product_name'],
                ],
                'subscription' => [
                    'status' => 'active',
                    'expires_at' => $newExpiresAt === null ? null : $this->toAtom($newExpiresAt),
                    'device_binding' => is_array($subscription) && $subscription['bound_device_id'] !== null
                        ? 'bound'
                        : 'unbound',
                ],
            ];
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    public static function normalizeKey(string $plainKey): string
    {
        $normalized = strtoupper(trim($plainKey));
        $normalized = preg_replace('/\s+/', '', $normalized) ?? '';
        if (!preg_match('/^PERI(?:-[A-Z2-9]{4}){6,8}$/', $normalized)) {
            throw new ApiException('invalid_activation_key', 400, 'Invalid activation key.');
        }
        return $normalized;
    }

    private function validateActivationKey(array $key, DateTimeImmutable $now): void
    {
        $status = (string) $key['status'];
        if ($status === 'redeemed') {
            throw new ApiException('activation_key_used', 409, 'This activation key has already been used.');
        }
        if ($status === 'revoked' || $key['revoked_at'] !== null) {
            throw new ApiException('activation_key_revoked', 403, 'This activation key has been revoked.');
        }
        if ($status === 'expired'
            || ($key['expires_at'] !== null
                && new DateTimeImmutable((string) $key['expires_at'], new DateTimeZone('UTC')) <= $now)) {
            throw new ApiException('activation_key_expired', 410, 'This activation key has expired.');
        }
        if ((int) $key['product_active'] !== 1) {
            throw new ApiException('product_disabled', 409, 'This product is unavailable.');
        }
        if ((int) $key['plan_active'] !== 1) {
            throw new ApiException('plan_disabled', 409, 'This plan is unavailable.');
        }
    }

    private function findSubscription(int $userId, int $productId, bool $lock): ?array
    {
        $suffix = $lock && $this->driver === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->database->prepare(
            'SELECT * FROM subscriptions WHERE user_id = :user_id AND product_id = :product_id LIMIT 1' . $suffix
        );
        $statement->execute([':user_id' => $userId, ':product_id' => $productId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
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

    private function toAtom(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
