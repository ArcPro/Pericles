<?php

declare(strict_types=1);

namespace Pericles\Commerce;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Pericles\Device\Base64Url;
use Pericles\Http\ApiException;
use Throwable;

final class CheckoutService
{
    private string $driver;
    private bool $transactionActive = false;

    public function __construct(private PDO $database)
    {
        $this->driver = (string) $database->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function start(string $productSlug, int $planId, ?int $userId, string $source): array
    {
        $plan = $this->loadPurchasablePlan($productSlug, $planId);
        $token = Base64Url::encode(random_bytes(32));
        $now = $this->now();
        $expires = (new DateTimeImmutable($now, new DateTimeZone('UTC')))->modify('+2 hours')->format('Y-m-d H:i:s');
        $statement = $this->database->prepare(
            'INSERT INTO checkout_sessions (token_hash, user_id, product_id, plan_id, price_cents, currency, source, status, expires_at, created_at, updated_at) '
            . 'VALUES (:token_hash, :user_id, :product_id, :plan_id, :price_cents, :currency, :source, \'open\', :expires_at, :created_at, :updated_at)'
        );
        $statement->execute([
            ':token_hash' => hash('sha256', $token), ':user_id' => $userId,
            ':product_id' => (int) $plan['product_id'], ':plan_id' => (int) $plan['plan_id'],
            ':price_cents' => (int) $plan['effective_price_cents'], ':currency' => (string) $plan['currency'],
            ':source' => substr(trim($source) ?: 'product', 0, 100), ':expires_at' => $expires,
            ':created_at' => $now, ':updated_at' => $now,
        ]);
        return ['token' => $token, 'expires_at' => $expires];
    }

    public function checkout(string $token, ?int $userId = null): array
    {
        $row = $this->findCheckout($token, false);
        if ($userId !== null && $row['user_id'] === null) {
            $update = $this->database->prepare('UPDATE checkout_sessions SET user_id = :user_id, updated_at = :now WHERE id = :id AND user_id IS NULL');
            $update->execute([':user_id' => $userId, ':now' => $this->now(), ':id' => (int) $row['id']]);
            $row['user_id'] = $userId;
        }
        if ($userId !== null && $row['user_id'] !== null && (int) $row['user_id'] !== $userId) {
            throw new ApiException('checkout_forbidden', 403, 'This checkout belongs to another account.');
        }
        return $row;
    }

    public function createOrder(string $token, int $userId, ?string $termsVersion, bool $immediateDeliveryConsent): array
    {
        if (!$immediateDeliveryConsent) {
            throw new ApiException('consent_required', 400, 'Immediate digital delivery consent is required.');
        }
        $this->begin();
        try {
            $checkout = $this->findCheckout($token, true);
            if ($checkout['user_id'] !== null && (int) $checkout['user_id'] !== $userId) {
                throw new ApiException('checkout_forbidden', 403, 'This checkout belongs to another account.');
            }
            $currentPrice = $checkout['sale_price_cents'] !== null && (int) $checkout['sale_price_cents'] < (int) $checkout['current_price_cents']
                ? (int) $checkout['sale_price_cents'] : (int) $checkout['current_price_cents'];
            if ($currentPrice !== (int) $checkout['price_cents'] || (string) $checkout['plan_currency'] !== (string) $checkout['currency']) {
                throw new ApiException('price_changed', 409, 'The price changed. Review the updated order before continuing.');
            }
            $existing = $this->database->prepare('SELECT * FROM orders WHERE checkout_session_id = :checkout_id LIMIT 1');
            $existing->execute([':checkout_id' => (int) $checkout['id']]);
            $order = $existing->fetch();
            if (!is_array($order)) {
                $now = $this->now();
                $orderNumber = 'PER-' . gmdate('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
                $insert = $this->database->prepare(
                    'INSERT INTO orders (order_number, user_id, product_id, plan_id, checkout_session_id, status, amount_cents, currency, '
                    . 'product_name_snapshot, plan_name_snapshot, duration_days_snapshot, is_lifetime_snapshot, terms_version, '
                    . 'immediate_delivery_consent_at, created_at, updated_at) VALUES (:number, :user_id, :product_id, :plan_id, :checkout_id, '
                    . "'pending', :amount, :currency, :product_name, :plan_name, :duration_days, :is_lifetime, :terms_version, :consent_at, :created_at, :updated_at)"
                );
                $insert->execute([
                    ':number' => $orderNumber, ':user_id' => $userId, ':product_id' => (int) $checkout['product_id'],
                    ':plan_id' => (int) $checkout['plan_id'], ':checkout_id' => (int) $checkout['id'],
                    ':amount' => (int) $checkout['price_cents'], ':currency' => (string) $checkout['currency'],
                    ':product_name' => (string) $checkout['product_name'], ':plan_name' => (string) $checkout['plan_name'],
                    ':duration_days' => $checkout['duration_days'], ':is_lifetime' => (int) $checkout['is_lifetime'],
                    ':terms_version' => $termsVersion === null ? null : substr($termsVersion, 0, 50),
                    ':consent_at' => $now, ':created_at' => $now, ':updated_at' => $now,
                ]);
                $order = $this->orderById((int) $this->database->lastInsertId());
            }
            $attach = $this->database->prepare('UPDATE checkout_sessions SET user_id = :user_id, updated_at = :now WHERE id = :id');
            $attach->execute([':user_id' => $userId, ':now' => $this->now(), ':id' => (int) $checkout['id']]);
            $this->commit();
            return $order;
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    public function ordersForUser(int $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT o.id,o.product_id,o.plan_id,o.order_number, o.product_name_snapshot, o.plan_name_snapshot, o.amount_cents, o.currency, '
            . 'o.status, o.created_at, o.paid_at, p.provider, p.method, p.provider_reference '
            . 'FROM orders o LEFT JOIN payments p ON p.order_id = o.id WHERE o.user_id = :user_id '
            . 'ORDER BY o.created_at DESC, o.id DESC'
        );
        $statement->execute([':user_id' => $userId]);
        return $statement->fetchAll();
    }

    public function orderForUser(string $orderNumber, int $userId): array
    {
        $statement = $this->database->prepare('SELECT o.*,p.provider,p.provider_reference,p.method,p.confirmed_at FROM orders o LEFT JOIN payments p ON p.order_id=o.id WHERE o.order_number = :number AND o.user_id = :user_id LIMIT 1');
        $statement->execute([':number' => $orderNumber, ':user_id' => $userId]);
        $order = $statement->fetch();
        if (!is_array($order)) throw new ApiException('order_not_found', 404, 'Order not found.');
        return $order;
    }

    public function orderForCheckout(string $token, int $userId): array
    {
        $checkout = $this->checkout($token, $userId);
        $statement = $this->database->prepare(
            'SELECT o.*, u.email AS customer_email FROM orders o '
            . 'INNER JOIN users u ON u.id = o.user_id '
            . 'WHERE o.checkout_session_id = :checkout_id AND o.user_id = :user_id LIMIT 1'
        );
        $statement->execute([':checkout_id' => (int) $checkout['id'], ':user_id' => $userId]);
        $order = $statement->fetch();
        if (!is_array($order)) {
            throw new ApiException('order_not_found', 404, 'Create the order before starting payment.');
        }
        return $order;
    }

    public function confirmPayment(string $provider, string $eventId, string $orderNumber, string $reference, int $amountCents, string $currency): array
    {
        if (trim($provider) === '' || trim($eventId) === '' || trim($orderNumber) === '' || trim($reference) === '' || $amountCents < 0 || !preg_match('/^[A-Z]{3}$/', strtoupper($currency))) {
            throw new ApiException('invalid_payment_event', 400, 'The payment event is incomplete.');
        }
        $this->begin();
        try {
            $event = $this->database->prepare('SELECT id FROM payment_webhook_events WHERE provider = :provider AND event_id = :event_id LIMIT 1');
            $event->execute([':provider' => $provider, ':event_id' => $eventId]);
            if ($event->fetchColumn() !== false) {
                $this->commit();
                return ['success' => true, 'idempotent' => true];
            }
            $orderQuery = $this->database->prepare('SELECT * FROM orders WHERE order_number = :number LIMIT 1' . ($this->driver === 'mysql' ? ' FOR UPDATE' : ''));
            $orderQuery->execute([':number' => $orderNumber]);
            $order = $orderQuery->fetch();
            if (!is_array($order)) throw new ApiException('order_not_found', 404, 'Order not found.');
            if ((int) $order['amount_cents'] !== $amountCents || strtoupper((string) $order['currency']) !== strtoupper($currency)) {
                throw new ApiException('payment_mismatch', 409, 'Payment amount or currency does not match the order.');
            }
            $now = $this->now();
            $paymentSql = 'INSERT INTO payments (order_id, provider, provider_reference, status, amount_cents, currency, created_at, confirmed_at, updated_at) '
                . "VALUES (:order_id, :provider, :reference, 'paid', :amount, :currency, :created_at, :confirmed_at, :updated_at) ";
            $paymentSql .= $this->driver === 'sqlite'
                ? "ON CONFLICT(provider,provider_reference) DO UPDATE SET status='paid',confirmed_at=excluded.confirmed_at,updated_at=excluded.updated_at"
                : "ON DUPLICATE KEY UPDATE status = 'paid', confirmed_at = VALUES(confirmed_at), updated_at = VALUES(updated_at)";
            $payment = $this->database->prepare($paymentSql);
            $payment->execute([':order_id' => (int) $order['id'], ':provider' => $provider, ':reference' => $reference, ':amount' => $amountCents, ':currency' => strtoupper($currency), ':created_at' => $now, ':confirmed_at' => $now, ':updated_at' => $now]);
            if ((string) $order['status'] !== 'paid') {
                $update = $this->database->prepare("UPDATE orders SET status = 'paid', paid_at = :paid_at, updated_at = :updated_at WHERE id = :id AND status <> 'paid'");
                $update->execute([':paid_at' => $now, ':updated_at' => $now, ':id' => (int) $order['id']]);
                $this->grantAccess($order, $now);
                $checkout = $this->database->prepare("UPDATE checkout_sessions SET status = 'converted', updated_at = :now WHERE id = :id");
                $checkout->execute([':now' => $now, ':id' => (int) $order['checkout_session_id']]);
            }
            $record = $this->database->prepare('INSERT INTO payment_webhook_events (provider, event_id, payload_hash, processed_at) VALUES (:provider, :event_id, :hash, :now)');
            $record->execute([':provider' => $provider, ':event_id' => $eventId, ':hash' => hash('sha256', $provider . '|' . $eventId . '|' . $orderNumber . '|' . $reference), ':now' => $now]);
            $this->commit();
            return ['success' => true, 'idempotent' => false, 'order_number' => $orderNumber];
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    private function grantAccess(array $order, string $now): void
    {
        $subscriptionQuery = $this->database->prepare('SELECT * FROM subscriptions WHERE user_id = :user_id AND product_id = :product_id LIMIT 1' . ($this->driver === 'mysql' ? ' FOR UPDATE' : ''));
        $subscriptionQuery->execute([':user_id' => (int) $order['user_id'], ':product_id' => (int) $order['product_id']]);
        $subscription = $subscriptionQuery->fetch();
        $lifetime = (int) $order['is_lifetime_snapshot'] === 1;
        $duration = (int) ($order['duration_days_snapshot'] ?? 0);
        if (!$lifetime && $duration < 1) throw new ApiException('plan_invalid', 500, 'The order duration is invalid.');
        if (is_array($subscription) && $subscription['expires_at'] === null && (string) $subscription['status'] === 'active' && !$lifetime) {
            throw new ApiException('access_already_lifetime', 409, 'This account already has Lifetime Access.');
        }
        $nowDate = new DateTimeImmutable($now, new DateTimeZone('UTC'));
        $hasActiveTime = is_array($subscription) && in_array((string) $subscription['status'], ['active','cancelled'], true)
            && $subscription['expires_at'] !== null && new DateTimeImmutable((string) $subscription['expires_at'], new DateTimeZone('UTC')) > $nowDate;
        if ($lifetime) {
            $status = 'active'; $startedAt = $subscription['started_at'] ?? $now; $startsAt = $subscription['starts_at'] ?? $now;
            $activatedAt = $subscription['activated_at'] ?? $now; $deadline = null; $expiresAt = null;
        } elseif ($hasActiveTime) {
            $status = 'active'; $startedAt = (string) $subscription['started_at']; $startsAt = $subscription['starts_at'] ?? $subscription['started_at'];
            $activatedAt = $subscription['activated_at'] ?? $subscription['started_at']; $deadline = null;
            $expiresAt = (new DateTimeImmutable((string) $subscription['expires_at'], new DateTimeZone('UTC')))->modify('+' . $duration . ' days')->format('Y-m-d H:i:s');
        } else {
            $status = 'pending'; $startedAt = $now; $startsAt = null; $activatedAt = null;
            $deadline = $nowDate->modify('+7 days')->format('Y-m-d H:i:s'); $expiresAt = null;
        }
        if (!is_array($subscription)) {
            $insert = $this->database->prepare(
                'INSERT INTO subscriptions (user_id, product_id, plan_id, order_id, status, purchased_at, activation_deadline_at, activated_at, starts_at, started_at, expires_at, bound_device_id, bound_at, created_at, updated_at) '
                . 'VALUES (:user_id, :product_id, :plan_id, :order_id, :status, :purchased_at, :deadline, :activated_at, :starts_at, :started_at, :expires_at, NULL, NULL, :created_at, :updated_at)'
            );
            $insert->execute([':user_id'=>(int)$order['user_id'], ':product_id'=>(int)$order['product_id'], ':plan_id'=>(int)$order['plan_id'], ':order_id'=>(int)$order['id'], ':status'=>$status, ':purchased_at'=>$now, ':deadline'=>$deadline, ':activated_at'=>$activatedAt, ':starts_at'=>$startsAt, ':started_at'=>$startedAt, ':expires_at'=>$expiresAt, ':created_at'=>$now, ':updated_at'=>$now]);
        } else {
            $update = $this->database->prepare(
                'UPDATE subscriptions SET plan_id = :plan_id, order_id = :order_id, status = :status, purchased_at = :purchased_at, '
                . 'activation_deadline_at = :deadline, activated_at = :activated_at, starts_at = :starts_at, started_at = :started_at, '
                . 'expires_at = :expires_at, cancelled_at = NULL, suspended_at = NULL, updated_at = :now WHERE id = :id'
            );
            $update->execute([':plan_id'=>(int)$order['plan_id'], ':order_id'=>(int)$order['id'], ':status'=>$status, ':purchased_at'=>$now, ':deadline'=>$deadline, ':activated_at'=>$activatedAt, ':starts_at'=>$startsAt, ':started_at'=>$startedAt, ':expires_at'=>$expiresAt, ':now'=>$now, ':id'=>(int)$subscription['id']]);
        }
    }

    private function loadPurchasablePlan(string $slug, int $planId): array
    {
        $statement = $this->database->prepare(
            'SELECT p.id AS product_id, p.name AS product_name, pl.id AS plan_id, pl.name AS plan_name, pl.price_cents, '
            . 'pl.sale_price_cents, pl.currency, pl.is_lifetime, g.name AS game_name, g.commercial_status, g.purchases_allowed '
            . 'FROM games g INNER JOIN product_games pg ON pg.game_id = g.id INNER JOIN products p ON p.id = pg.product_id '
            . 'INNER JOIN plans pl ON pl.product_id = p.id WHERE g.slug = :slug AND pl.id = :plan_id '
            . 'AND g.is_active = 1 AND g.is_public = 1 AND p.is_active = 1 AND p.is_public = 1 AND pl.is_active = 1 LIMIT 1'
        );
        $statement->execute([':slug' => $slug, ':plan_id' => $planId]);
        $plan = $statement->fetch();
        if (!is_array($plan) || $plan['price_cents'] === null) throw new ApiException('plan_unavailable', 404, 'This Access Plan is unavailable.');
        if ((int) $plan['purchases_allowed'] !== 1 || (string) $plan['commercial_status'] !== 'operational') throw new ApiException('product_unavailable', 409, 'This Enhancement is not currently available for purchase.');
        $regular = (int) $plan['price_cents']; $sale = $plan['sale_price_cents'] === null ? null : (int) $plan['sale_price_cents'];
        $plan['effective_price_cents'] = $sale !== null && $sale < $regular ? $sale : $regular;
        return $plan;
    }

    private function findCheckout(string $token, bool $lock): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,60}$/', $token)) throw new ApiException('checkout_not_found', 404, 'Checkout not found.');
        $statement = $this->database->prepare(
            'SELECT c.*, p.name AS product_name, pl.name AS plan_name, pl.duration_days, pl.is_lifetime, '
            . 'pl.price_cents AS current_price_cents, pl.sale_price_cents, pl.currency AS plan_currency, '
            . 'g.slug AS game_slug, g.name AS game_name, g.image_url, g.commercial_status, g.purchases_allowed '
            . 'FROM checkout_sessions c INNER JOIN products p ON p.id = c.product_id INNER JOIN plans pl ON pl.id = c.plan_id '
            . 'INNER JOIN product_games pg ON pg.product_id = p.id INNER JOIN games g ON g.id = pg.game_id '
            . 'WHERE c.token_hash = :hash LIMIT 1' . ($lock && $this->driver === 'mysql' ? ' FOR UPDATE' : '')
        );
        $statement->execute([':hash' => hash('sha256', $token)]);
        $row = $statement->fetch();
        if (!is_array($row)) throw new ApiException('checkout_not_found', 404, 'Checkout not found.');
        if ((string) $row['status'] === 'converted') return $row;
        if ((string) $row['status'] !== 'open' || new DateTimeImmutable((string) $row['expires_at'], new DateTimeZone('UTC')) <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            throw new ApiException('checkout_expired', 410, 'This checkout session has expired.');
        }
        return $row;
    }

    private function orderById(int $id): array
    {
        $statement = $this->database->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) throw new ApiException('order_not_found', 500, 'Order could not be created.');
        return $row;
    }

    private function begin(): void { if ($this->driver === 'sqlite') $this->database->exec('BEGIN IMMEDIATE TRANSACTION'); else $this->database->beginTransaction(); $this->transactionActive = true; }
    private function commit(): void { if ($this->driver === 'sqlite') $this->database->exec('COMMIT'); else $this->database->commit(); $this->transactionActive = false; }
    private function rollBack(): void { if (!$this->transactionActive) return; if ($this->driver === 'sqlite') $this->database->exec('ROLLBACK'); elseif ($this->database->inTransaction()) $this->database->rollBack(); $this->transactionActive = false; }
    private function now(): string { return gmdate('Y-m-d H:i:s'); }
}
