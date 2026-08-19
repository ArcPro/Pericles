<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Pericles\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[$script, $email, $role] = array_pad($argv, 3, null);
if (!is_string($email) || !is_string($role) || !in_array($role, ['player', 'moderator', 'admin'], true)) {
    fwrite(STDERR, "Usage: php tools/set-role.php <email> <player|moderator|admin>\n");
    exit(1);
}

$database = Database::getConnection();
$user = $database->prepare('SELECT id, email FROM users WHERE email = :email LIMIT 1');
$user->execute([':email' => strtolower(trim($email))]);
$record = $user->fetch();
if (!is_array($record)) {
    fwrite(STDERR, "User not found.\n");
    exit(1);
}

if ($role === 'player') {
    $statement = $database->prepare('DELETE FROM user_roles WHERE user_id = :user_id');
    $statement->execute([':user_id' => (int) $record['id']]);
} else {
    $roleQuery = $database->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $roleQuery->execute([':slug' => $role]);
    $roleId = $roleQuery->fetchColumn();
    if ($roleId === false) {
        fwrite(STDERR, "Role tables are missing. Run migration 006_roles_admin.sql first.\n");
        exit(1);
    }
    $exists = $database->prepare('SELECT COUNT(*) FROM user_roles WHERE user_id = :user_id');
    $exists->execute([':user_id' => (int) $record['id']]);
    $statement = (int) $exists->fetchColumn() > 0
        ? $database->prepare('UPDATE user_roles SET role_id = :role_id, assigned_at = :now WHERE user_id = :user_id')
        : $database->prepare('INSERT INTO user_roles (user_id, role_id, assigned_at) VALUES (:user_id, :role_id, :now)');
    $statement->execute([
        ':user_id' => (int) $record['id'],
        ':role_id' => (int) $roleId,
        ':now' => gmdate('Y-m-d H:i:s'),
    ]);
}

fwrite(STDOUT, sprintf("%s is now %s.\n", $record['email'], $role));
