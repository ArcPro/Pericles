<?php

declare(strict_types=1);

namespace Pericles\Security;

use PDO;
use Pericles\Http\ApiException;

final class AuthorizationService
{
    public function __construct(private PDO $database)
    {
    }

    public function roleForUser(int $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT r.slug, r.name, r.rank_level FROM user_roles ur '
            . 'INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :user_id LIMIT 1'
        );
        $statement->execute([':user_id' => $userId]);
        $role = $statement->fetch();
        return is_array($role)
            ? ['slug' => (string) $role['slug'], 'name' => (string) $role['name'], 'rank' => (int) $role['rank_level']]
            : ['slug' => 'player', 'name' => 'Player', 'rank' => 0];
    }

    public function permissionsForUser(int $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT p.slug FROM user_roles ur INNER JOIN role_permissions rp ON rp.role_id = ur.role_id '
            . 'INNER JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = :user_id ORDER BY p.slug'
        );
        $statement->execute([':user_id' => $userId]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function can(int $userId, string $permission): bool
    {
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM user_roles ur INNER JOIN role_permissions rp ON rp.role_id = ur.role_id '
            . 'INNER JOIN permissions p ON p.id = rp.permission_id '
            . 'WHERE ur.user_id = :user_id AND p.slug = :permission'
        );
        $statement->execute([':user_id' => $userId, ':permission' => $permission]);
        return (int) $statement->fetchColumn() > 0;
    }

    public function requirePermission(int $userId, string $permission): void
    {
        if (!$this->can($userId, $permission)) {
            throw new ApiException('forbidden', 403, 'You do not have the required permission.');
        }
    }
}
