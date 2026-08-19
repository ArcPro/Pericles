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

    public function unbindProduct(int $userId, string $productSlug): void
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $productSlug)) {
            throw new ApiException('validation_error', 400, 'Invalid product.');
        }
        $find = $this->database->prepare(
            'SELECT s.id, s.bound_device_id FROM subscriptions s INNER JOIN products p ON p.id = s.product_id '
            . 'WHERE s.user_id = :user_id AND p.slug = :slug LIMIT 1'
        );
        $find->execute([':user_id' => $userId, ':slug' => $productSlug]);
        $subscription = $find->fetch();
        if (!is_array($subscription)) {
            throw new ApiException('subscription_not_found', 404, 'License not found.');
        }
        if ($subscription['bound_device_id'] === null) {
            throw new ApiException('subscription_not_bound', 409, 'This license is not linked to a device.');
        }
        $update = $this->database->prepare(
            'UPDATE subscriptions SET bound_device_id = NULL, bound_at = NULL, updated_at = :now '
            . 'WHERE id = :id AND user_id = :user_id'
        );
        $update->execute([':now' => gmdate('Y-m-d H:i:s'), ':id' => (int) $subscription['id'], ':user_id' => $userId]);
    }
}
