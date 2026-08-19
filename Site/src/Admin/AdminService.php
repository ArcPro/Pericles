<?php

declare(strict_types=1);

namespace Pericles\Admin;

use PDO;
use Pericles\Activation\ActivationKeyGenerator;
use Pericles\Activation\ActivationService;
use Pericles\Http\ApiException;
use Pericles\Security\AuthorizationService;

final class AdminService
{
    private AuthorizationService $authorization;

    public function __construct(private PDO $database)
    {
        $this->authorization = new AuthorizationService($database);
    }

    public function dashboard(int $actorUserId): array
    {
        $this->authorization->requirePermission($actorUserId, 'admin.access');
        $permissions = $this->authorization->permissionsForUser($actorUserId);
        $users = [];
        if (in_array('users.view', $permissions, true)) {
            $users = $this->database->query(
                'SELECT u.id, u.email, u.display_name, u.status, u.created_at, '
                . 'COALESCE(r.slug, \'player\') AS role_slug, COALESCE(r.name, \'Player\') AS role_name, '
                . '(SELECT COUNT(*) FROM subscriptions s WHERE s.user_id = u.id) AS product_count, '
                . '(SELECT COUNT(*) FROM devices d WHERE d.user_id = u.id AND d.revoked_at IS NULL) AS device_count '
                . 'FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id '
                . 'ORDER BY u.created_at DESC, u.id DESC LIMIT 100'
            )->fetchAll();
        }
        $subscriptions = $this->database->query(
            'SELECT s.id, s.status, s.expires_at, s.bound_device_id, u.id AS user_id, u.email, '
            . 'p.name AS product_name, p.slug AS product_slug, d.device_id, d.display_name AS device_name '
            . 'FROM subscriptions s INNER JOIN users u ON u.id = s.user_id '
            . 'INNER JOIN products p ON p.id = s.product_id LEFT JOIN devices d ON d.id = s.bound_device_id '
            . 'ORDER BY s.updated_at DESC LIMIT 100'
        )->fetchAll();
        $plans = [];
        if (in_array('licenses.manage', $permissions, true)) {
            $plans = $this->database->query(
                'SELECT p.slug AS product_slug, p.name AS product_name, pl.slug AS plan_slug, pl.name AS plan_name '
                . 'FROM plans pl INNER JOIN products p ON p.id = pl.product_id '
                . 'WHERE p.is_active = 1 AND pl.is_active = 1 ORDER BY p.name, pl.duration_days'
            )->fetchAll();
        }
        $audit = [];
        if (in_array('audit.view', $permissions, true)) {
            $audit = $this->database->query(
                'SELECT a.action, a.target_type, a.target_id, a.metadata_json, a.created_at, u.email AS actor_email '
                . 'FROM admin_audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id '
                . 'ORDER BY a.id DESC LIMIT 30'
            )->fetchAll();
        }
        return [
            'role' => $this->authorization->roleForUser($actorUserId),
            'permissions' => $permissions,
            'users' => $users,
            'subscriptions' => $subscriptions,
            'plans' => $plans,
            'audit' => $audit,
            'metrics' => [
                'users' => (int) $this->database->query('SELECT COUNT(*) FROM users')->fetchColumn(),
                'active_subscriptions' => (int) $this->database->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'")->fetchColumn(),
                'bound_products' => (int) $this->database->query('SELECT COUNT(*) FROM subscriptions WHERE bound_device_id IS NOT NULL')->fetchColumn(),
                'unused_keys' => in_array('licenses.manage', $permissions, true)
                    ? (int) $this->database->query("SELECT COUNT(*) FROM activation_keys WHERE status = 'unused'")->fetchColumn()
                    : null,
            ],
        ];
    }

    public function assignProduct(
        int $actorUserId,
        int $targetUserId,
        string $productSlug,
        string $planSlug,
        string $ipAddress
    ): array {
        $this->authorization->requirePermission($actorUserId, 'licenses.manage');
        $target = $this->findUser($targetUserId);
        if ((string) $target['status'] !== 'active') {
            throw new ApiException('account_disabled', 409, 'The target account is disabled.');
        }
        try {
            $keys = (new ActivationKeyGenerator($this->database))->generate($productSlug, $planSlug, 1);
            $result = (new ActivationService($this->database))->redeem($targetUserId, $keys[0]);
        } catch (\RuntimeException $exception) {
            throw new ApiException('invalid_plan', 400, $exception->getMessage());
        }
        $this->audit($actorUserId, 'license.assigned', 'user', (string) $targetUserId, [
            'email' => (string) $target['email'],
            'product' => $productSlug,
            'plan' => $planSlug,
            'key_hint' => '...' . substr($keys[0], -4),
        ], $ipAddress);
        return ['plain_key' => $keys[0], 'result' => $result, 'user' => $target];
    }

    public function unbindSubscription(int $actorUserId, int $subscriptionId, string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId, 'devices.manage');
        $find = $this->database->prepare(
            'SELECT s.id, s.bound_device_id, s.user_id, p.slug AS product_slug FROM subscriptions s '
            . 'INNER JOIN products p ON p.id = s.product_id WHERE s.id = :id LIMIT 1'
        );
        $find->execute([':id' => $subscriptionId]);
        $subscription = $find->fetch();
        if (!is_array($subscription)) {
            throw new ApiException('subscription_not_found', 404, 'License not found.');
        }
        if ($subscription['bound_device_id'] === null) {
            throw new ApiException('subscription_not_bound', 409, 'This license is not linked to a device.');
        }
        $update = $this->database->prepare(
            'UPDATE subscriptions SET bound_device_id = NULL, bound_at = NULL, updated_at = :now WHERE id = :id'
        );
        $update->execute([':now' => gmdate('Y-m-d H:i:s'), ':id' => $subscriptionId]);
        $this->audit($actorUserId, 'subscription.unbound', 'subscription', (string) $subscriptionId, [
            'user_id' => (int) $subscription['user_id'],
            'product' => (string) $subscription['product_slug'],
            'device_id' => (int) $subscription['bound_device_id'],
        ], $ipAddress);
    }

    public function deleteSubscription(int $actorUserId, int $subscriptionId, string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId, 'licenses.manage');
        $find = $this->database->prepare(
            'SELECT s.id, s.user_id, s.status, u.email, p.slug AS product_slug, p.name AS product_name '
            . 'FROM subscriptions s INNER JOIN users u ON u.id = s.user_id '
            . 'INNER JOIN products p ON p.id = s.product_id WHERE s.id = :id LIMIT 1'
        );
        $find->execute([':id' => $subscriptionId]);
        $subscription = $find->fetch();
        if (!is_array($subscription)) {
            throw new ApiException('subscription_not_found', 404, 'License not found.');
        }

        $driver = (string) $this->database->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $this->database->exec('BEGIN IMMEDIATE TRANSACTION');
        } else {
            $this->database->beginTransaction();
        }

        try {
            $deleteActivations = $this->database->prepare(
                'DELETE FROM subscription_activations WHERE subscription_id = :subscription_id'
            );
            $deleteActivations->execute([':subscription_id' => $subscriptionId]);

            $detachKeys = $this->database->prepare(
                'UPDATE activation_keys SET subscription_id = NULL WHERE subscription_id = :subscription_id'
            );
            $detachKeys->execute([':subscription_id' => $subscriptionId]);

            $delete = $this->database->prepare('DELETE FROM subscriptions WHERE id = :id');
            $delete->execute([':id' => $subscriptionId]);
            if ($delete->rowCount() !== 1) {
                throw new ApiException('subscription_not_found', 404, 'License not found.');
            }

            if ($driver === 'sqlite') {
                $this->database->exec('COMMIT');
            } else {
                $this->database->commit();
            }
        } catch (\Throwable $exception) {
            if ($driver === 'sqlite') {
                $this->database->exec('ROLLBACK');
            } elseif ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }

        $this->audit($actorUserId, 'subscription.deleted', 'subscription', (string) $subscriptionId, [
            'user_id' => (int) $subscription['user_id'],
            'email' => (string) $subscription['email'],
            'product' => (string) $subscription['product_slug'],
            'product_name' => (string) $subscription['product_name'],
            'status' => (string) $subscription['status'],
        ], $ipAddress);
    }

    public function updateUserStatus(int $actorUserId, int $targetUserId, string $status, string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId, 'users.manage');
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new ApiException('validation_error', 400, 'Invalid user status.');
        }
        if ($actorUserId === $targetUserId && $status === 'disabled') {
            throw new ApiException('self_lockout', 409, 'You cannot disable your own account.');
        }
        $target = $this->findUser($targetUserId);
        $update = $this->database->prepare('UPDATE users SET status = :status WHERE id = :id');
        $update->execute([':status' => $status, ':id' => $targetUserId]);
        if ($status === 'disabled') {
            $revoke = $this->database->prepare(
                'UPDATE api_sessions SET revoked_at = :now WHERE user_id = :user_id AND revoked_at IS NULL'
            );
            $revoke->execute([':now' => gmdate('Y-m-d H:i:s'), ':user_id' => $targetUserId]);
        }
        $this->audit($actorUserId, 'user.status_changed', 'user', (string) $targetUserId, [
            'email' => (string) $target['email'], 'status' => $status,
        ], $ipAddress);
    }

    public function updateUserRole(int $actorUserId, int $targetUserId, string $roleSlug, string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId, 'users.manage');
        if (!in_array($roleSlug, ['player', 'moderator', 'admin'], true)) {
            throw new ApiException('validation_error', 400, 'Invalid role.');
        }
        if ($actorUserId === $targetUserId && $roleSlug !== 'admin') {
            throw new ApiException('self_lockout', 409, 'You cannot remove your own administrator role.');
        }
        $target = $this->findUser($targetUserId);
        if ($roleSlug === 'player') {
            $delete = $this->database->prepare('DELETE FROM user_roles WHERE user_id = :user_id');
            $delete->execute([':user_id' => $targetUserId]);
        } else {
            $role = $this->database->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
            $role->execute([':slug' => $roleSlug]);
            $roleId = $role->fetchColumn();
            if ($roleId === false) {
                throw new ApiException('role_not_found', 500, 'The requested role is not configured.');
            }
            $existing = $this->database->prepare('SELECT COUNT(*) FROM user_roles WHERE user_id = :user_id');
            $existing->execute([':user_id' => $targetUserId]);
            if ((int) $existing->fetchColumn() > 0) {
                $statement = $this->database->prepare(
                    'UPDATE user_roles SET role_id = :role_id, assigned_by_user_id = :actor, assigned_at = :now '
                    . 'WHERE user_id = :user_id'
                );
            } else {
                $statement = $this->database->prepare(
                    'INSERT INTO user_roles (user_id, role_id, assigned_by_user_id, assigned_at) '
                    . 'VALUES (:user_id, :role_id, :actor, :now)'
                );
            }
            $statement->execute([
                ':user_id' => $targetUserId, ':role_id' => (int) $roleId,
                ':actor' => $actorUserId, ':now' => gmdate('Y-m-d H:i:s'),
            ]);
        }
        $this->audit($actorUserId, 'user.role_changed', 'user', (string) $targetUserId, [
            'email' => (string) $target['email'], 'role' => $roleSlug,
        ], $ipAddress);
    }

    private function findUser(int $userId): array
    {
        $statement = $this->database->prepare('SELECT id, email, status FROM users WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $userId]);
        $user = $statement->fetch();
        if (!is_array($user)) {
            throw new ApiException('user_not_found', 404, 'User not found.');
        }
        return $user;
    }

    private function audit(
        int $actorUserId,
        string $action,
        string $targetType,
        ?string $targetId,
        array $metadata,
        string $ipAddress
    ): void {
        $statement = $this->database->prepare(
            'INSERT INTO admin_audit_logs '
            . '(actor_user_id, action, target_type, target_id, metadata_json, ip_address, created_at) '
            . 'VALUES (:actor, :action, :target_type, :target_id, :metadata, :ip, :created_at)'
        );
        $statement->execute([
            ':actor' => $actorUserId,
            ':action' => $action,
            ':target_type' => $targetType,
            ':target_id' => $targetId,
            ':metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ip' => substr($ipAddress, 0, 45),
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
