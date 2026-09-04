<?php

declare(strict_types=1);

namespace Pericles\Account;

use PDO;
use Pericles\Http\ApiException;

final class AccountService
{
    public function __construct(private PDO $database)
    {
    }

    public function profile(int $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT id, email, display_name, status, created_at FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute([':id' => $userId]);
        $user = $statement->fetch();
        if (!is_array($user)) {
            throw new ApiException('user_not_found', 404, 'Account not found.');
        }
        return $user;
    }

    public function updateProfile(int $userId, string $displayName): array
    {
        $displayName = trim($displayName);
        if ($displayName === '' || strlen($displayName) > 80 || preg_match('/[\x00-\x1F\x7F]/', $displayName)) {
            throw new ApiException('validation_error', 400, 'The display name must contain between 1 and 80 characters.');
        }
        $statement = $this->database->prepare('UPDATE users SET display_name = :display_name WHERE id = :id');
        $statement->execute([':display_name' => $displayName, ':id' => $userId]);
        return $this->profile($userId);
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        if (strlen($newPassword) < 10 || strlen($newPassword) > 1024) {
            throw new ApiException('validation_error', 400, 'The new password must contain at least 10 characters.');
        }
        $statement = $this->database->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $userId]);
        $hash = $statement->fetchColumn();
        if (!is_string($hash) || !password_verify($currentPassword, $hash)) {
            throw new ApiException('invalid_password', 403, 'The current password is incorrect.');
        }
        if (password_verify($newPassword, $hash)) {
            throw new ApiException('password_unchanged', 409, 'Choose a password that differs from your current password.');
        }
        $update = $this->database->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $update->execute([':hash' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $userId]);
        $revoke = $this->database->prepare(
            'UPDATE api_sessions SET revoked_at = :now WHERE user_id = :user_id AND revoked_at IS NULL'
        );
        $revoke->execute([':now' => gmdate('Y-m-d H:i:s'), ':user_id' => $userId]);
    }

    /**
     * Returns the operational data used by the member UI. Every value comes
     * from the Pericles database; unsupported Lovable demo domains are kept
     * empty by the page renderer instead of being filled with sample data.
     */
    public function workspace(int $userId): array
    {
        $versions = $this->database->prepare(
            'SELECT p.slug AS product_slug, p.name AS product_name, mv.version, mv.file_size, '
            . 'mv.published_at, mv.minimum_launcher_version '
            . 'FROM subscriptions s INNER JOIN products p ON p.id = s.product_id '
            . 'LEFT JOIN product_games pg ON pg.product_id = p.id '
            . 'LEFT JOIN modules m ON m.game_id = pg.game_id AND m.is_active = 1 '
            . "LEFT JOIN module_versions mv ON mv.module_id = m.id AND mv.status = 'active' "
            . 'WHERE s.user_id = :user_id ORDER BY p.name, mv.published_at DESC'
        );
        $versions->execute([':user_id' => $userId]);

        $downloads = $this->database->prepare(
            'SELECT g.name AS product_name, mv.version, md.status, md.requested_at, md.completed_at, '
            . 'd.display_name AS device_name '
            . 'FROM module_downloads md INNER JOIN games g ON g.id = md.game_id '
            . 'INNER JOIN module_versions mv ON mv.id = md.module_version_id '
            . 'INNER JOIN devices d ON d.id = md.device_id '
            . 'WHERE md.user_id = :user_id ORDER BY md.requested_at DESC LIMIT 50'
        );
        $downloads->execute([':user_id' => $userId]);

        $sessions = $this->database->prepare(
            'SELECT s.created_at, s.last_seen_at, s.expires_at, s.created_ip, s.user_agent, '
            . 'd.display_name AS device_name '
            . 'FROM api_sessions s LEFT JOIN devices d ON d.id = s.device_id '
            . 'WHERE s.user_id = :user_id AND s.revoked_at IS NULL AND s.expires_at > :now '
            . 'ORDER BY COALESCE(s.last_seen_at, s.created_at) DESC LIMIT 20'
        );
        $sessions->execute([':user_id' => $userId, ':now' => gmdate('Y-m-d H:i:s')]);

        return [
            'versions' => $versions->fetchAll(),
            'downloads' => $downloads->fetchAll(),
            'sessions' => $sessions->fetchAll(),
        ];
    }

    public function unbindProduct(int $userId, string $productSlug): void
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $productSlug)) {
            throw new ApiException('validation_error', 400, 'Invalid product.');
        }
        $commerceReset = true;
        try {
            $find = $this->database->prepare(
                'SELECT s.id, s.bound_device_id, s.device_reset_available_at FROM subscriptions s INNER JOIN products p ON p.id = s.product_id '
                . 'WHERE s.user_id = :user_id AND p.slug = :slug LIMIT 1'
            );
            $find->execute([':user_id' => $userId, ':slug' => $productSlug]);
        } catch (\PDOException) {
            $commerceReset = false;
            $find = $this->database->prepare(
                'SELECT s.id, s.bound_device_id FROM subscriptions s INNER JOIN products p ON p.id = s.product_id '
                . 'WHERE s.user_id = :user_id AND p.slug = :slug LIMIT 1'
            );
            $find->execute([':user_id' => $userId, ':slug' => $productSlug]);
        }
        $subscription = $find->fetch();
        if (!is_array($subscription)) {
            throw new ApiException('subscription_not_found', 404, 'License not found.');
        }
        if ($subscription['bound_device_id'] === null) {
            throw new ApiException('subscription_not_bound', 409, 'This license is not linked to a device.');
        }
        if ($commerceReset && is_string($subscription['device_reset_available_at'] ?? null)
            && strtotime((string) $subscription['device_reset_available_at']) > time()) {
            throw new ApiException('device_reset_cooldown', 429, 'Your next self-service device reset is available on ' . gmdate('M j, Y H:i', (int) strtotime((string) $subscription['device_reset_available_at'])) . ' UTC.');
        }
        if ($commerceReset) {
            $lastReset = $this->database->prepare('SELECT MAX(reset_at) FROM device_reset_history WHERE user_id=:user_id AND actor_user_id IS NULL');
            $lastReset->execute([':user_id'=>$userId]); $lastResetAt=$lastReset->fetchColumn();
            if (is_string($lastResetAt) && strtotime($lastResetAt) > time()-7*86400) {
                throw new ApiException('device_reset_cooldown', 429, 'Self-service device reset is limited to once every seven days.');
            }
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($commerceReset) $this->database->beginTransaction();
        try {
        $update = $this->database->prepare(
            'UPDATE subscriptions SET bound_device_id = NULL, bound_at = NULL' . ($commerceReset ? ', device_reset_available_at = :available_at' : '') . ', updated_at = :now '
            . 'WHERE id = :id AND user_id = :user_id'
        );
        $params = [':now' => $now, ':id' => (int) $subscription['id'], ':user_id' => $userId];
        if ($commerceReset) $params[':available_at'] = gmdate('Y-m-d H:i:s', time() + 7 * 86400);
        $update->execute($params);
        if ($commerceReset) {
            $history = $this->database->prepare('INSERT INTO device_reset_history (user_id,subscription_id,actor_user_id,reason,reset_at) VALUES (:user_id,:subscription_id,NULL,:reason,:reset_at)');
            $history->execute([':user_id'=>$userId,':subscription_id'=>(int)$subscription['id'],':reason'=>'Self-service device reset',':reset_at'=>$now]);
            $this->database->commit();
        }
        } catch (\Throwable $exception) {
            if ($commerceReset && $this->database->inTransaction()) $this->database->rollBack();
            throw $exception;
        }
    }
}
