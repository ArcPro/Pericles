<?php

declare(strict_types=1);

namespace Pericles\Modules;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Pericles\Http\ApiException;

final class ModuleAuthorizationService
{
    public function __construct(private PDO $database)
    {
    }

    public function authorize(int $userId, int $deviceId, string $gameSlug, ?int $expectedVersionId = null): array
    {
        $this->activatePastDeadline($userId, $gameSlug);
        $identityQuery = $this->database->prepare(
            'SELECT u.status AS user_status, d.user_id AS device_user_id, d.verified_at, d.revoked_at '
            . 'FROM users u LEFT JOIN devices d ON d.id = :device_id WHERE u.id = :user_id LIMIT 1'
        );
        $identityQuery->execute([':device_id' => $deviceId, ':user_id' => $userId]);
        $identity = $identityQuery->fetch();
        if (!is_array($identity) || (string) $identity['user_status'] !== 'active') {
            throw new ApiException('invalid_token', 401, 'Invalid access token.');
        }
        if ($identity['device_user_id'] === null || (int) $identity['device_user_id'] !== $userId
            || $identity['verified_at'] === null) {
            throw new ApiException('device_not_verified', 403, 'A verified device is required.');
        }
        if ($identity['revoked_at'] !== null) {
            throw new ApiException('device_revoked', 403, 'This device is revoked.');
        }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $gameSlug)) {
            throw new ApiException('game_not_found', 404, 'Game not found.');
        }
        $gameQuery = $this->database->prepare('SELECT id, slug, name, is_active FROM games WHERE slug = :slug LIMIT 1');
        $gameQuery->execute([':slug' => $gameSlug]);
        $game = $gameQuery->fetch();
        if (!is_array($game)) {
            throw new ApiException('game_not_found', 404, 'Game not found.');
        }
        if ((int) $game['is_active'] !== 1) {
            throw new ApiException('game_disabled', 403, 'This game is unavailable.');
        }

        $moduleQuery = $this->database->prepare(
            'SELECT id, slug, name FROM modules WHERE game_id = :game_id AND is_active = 1 ORDER BY id ASC LIMIT 1'
        );
        $moduleQuery->execute([':game_id' => (int) $game['id']]);
        $module = $moduleQuery->fetch();
        if (!is_array($module)) {
            throw new ApiException('module_not_found', 404, 'No module is available for this game.');
        }

        $versionQuery = $this->database->prepare(
            "SELECT id, version, source_path, sha256, file_size, minimum_launcher_version "
            . "FROM module_versions WHERE module_id = :module_id AND status = 'active' "
            . 'ORDER BY published_at DESC, id DESC LIMIT 2'
        );
        $versionQuery->execute([':module_id' => (int) $module['id']]);
        $versions = $versionQuery->fetchAll();
        if (count($versions) !== 1) {
            throw new ApiException('module_unavailable', 503, 'No active module version is available.');
        }
        $version = $versions[0];
        if ($expectedVersionId !== null && (int) $version['id'] !== $expectedVersionId) {
            throw new ApiException('module_unavailable', 409, 'The requested module version is no longer available.');
        }

        $subscriptionQuery = $this->database->prepare(
            'SELECT s.status, s.expires_at, s.bound_device_id FROM subscriptions s '
            . 'INNER JOIN products p ON p.id = s.product_id AND p.is_active = 1 '
            . 'INNER JOIN product_games pg ON pg.product_id = p.id '
            . 'WHERE s.user_id = :user_id AND pg.game_id = :game_id ORDER BY s.id ASC'
        );
        $subscriptionQuery->execute([':user_id' => $userId, ':game_id' => (int) $game['id']]);
        $subscriptions = $subscriptionQuery->fetchAll();
        if ($subscriptions === []) {
            throw new ApiException('no_subscription', 403, 'An active subscription is required.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $hasExpired = false;
        $hasSuspended = false;
        $hasUnbound = false;
        $hasElsewhere = false;
        foreach ($subscriptions as $subscription) {
            $status = (string) $subscription['status'];
            if ($status === 'suspended') {
                $hasSuspended = true;
                continue;
            }
            $usableStatus = in_array($status, ['active', 'cancelled'], true);
            $notExpired = $subscription['expires_at'] === null
                || new DateTimeImmutable((string) $subscription['expires_at'], new DateTimeZone('UTC')) > $now;
            if (!$usableStatus || !$notExpired) {
                $hasExpired = true;
                continue;
            }
            if ($subscription['bound_device_id'] === null) {
                $hasUnbound = true;
                continue;
            }
            if ((int) $subscription['bound_device_id'] !== $deviceId) {
                $hasElsewhere = true;
                continue;
            }

            return [
                'game_id' => (int) $game['id'],
                'game_slug' => (string) $game['slug'],
                'module_id' => (int) $module['id'],
                'module_slug' => (string) $module['slug'],
                'module_version_id' => (int) $version['id'],
                'module_version' => (string) $version['version'],
                'source_path' => (string) $version['source_path'],
                'sha256' => (string) $version['sha256'],
                'file_size' => (int) $version['file_size'],
                'minimum_launcher_version' => $version['minimum_launcher_version'],
            ];
        }

        if ($hasUnbound) {
            throw new ApiException('subscription_not_bound', 409, 'The subscription must be bound to this device first.');
        }
        if ($hasElsewhere) {
            throw new ApiException('subscription_bound_elsewhere', 403, 'The subscription is bound to another device.');
        }
        if ($hasSuspended) {
            throw new ApiException('subscription_suspended', 403, 'The subscription is suspended.');
        }
        if ($hasExpired) {
            throw new ApiException('subscription_expired', 403, 'The subscription has expired.');
        }
        throw new ApiException('no_subscription', 403, 'An active subscription is required.');
    }

    private function activatePastDeadline(int $userId, string $gameSlug): void
    {
        try {
            $statement = $this->database->prepare(
                'SELECT s.id, s.activation_deadline_at, pl.duration_days FROM subscriptions s '
                . 'INNER JOIN plans pl ON pl.id = s.plan_id INNER JOIN products p ON p.id = s.product_id '
                . 'INNER JOIN product_games pg ON pg.product_id = p.id INNER JOIN games g ON g.id = pg.game_id '
                . "WHERE s.user_id = :user_id AND g.slug = :slug AND s.status = 'pending' "
                . 'AND s.activation_deadline_at <= :now LIMIT 1'
            );
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $statement->execute([':user_id' => $userId, ':slug' => $gameSlug, ':now' => $now->format('Y-m-d H:i:s')]);
            $row = $statement->fetch();
            if (!is_array($row) || (int) $row['duration_days'] < 1) return;
            $start = new DateTimeImmutable((string) $row['activation_deadline_at'], new DateTimeZone('UTC'));
            $update = $this->database->prepare(
                "UPDATE subscriptions SET status = 'active', activated_at = :activated_at, starts_at = :starts_at, started_at = :started_at, "
                . 'expires_at = :expires_at, activation_deadline_at = NULL, updated_at = :updated_at WHERE id = :id'
            );
            $startSql=$start->format('Y-m-d H:i:s');$update->execute([':activated_at'=>$startSql,':starts_at'=>$startSql,':started_at'=>$startSql,':expires_at'=>$start->modify('+'.(int)$row['duration_days'].' days')->format('Y-m-d H:i:s'),':updated_at'=>$now->format('Y-m-d H:i:s'),':id'=>(int)$row['id']]);
        } catch (\PDOException) {
            // Commerce migration is optional for legacy/test schemas.
        }
    }
}
