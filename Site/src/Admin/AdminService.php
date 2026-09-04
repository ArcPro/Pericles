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
            'SELECT s.id, s.status, s.started_at, s.expires_at, s.bound_device_id, u.id AS user_id, u.email, '
            . 'p.name AS product_name, p.slug AS product_slug, d.device_id, d.display_name AS device_name, '
            . '(SELECT ak.key_hint FROM activation_keys ak WHERE ak.subscription_id = s.id '
            . 'ORDER BY ak.redeemed_at DESC, ak.id DESC LIMIT 1) AS key_hint '
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
                'SELECT a.action, a.target_type, a.target_id, a.metadata_json, a.ip_address, a.created_at, u.email AS actor_email '
                . 'FROM admin_audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id '
                . 'ORDER BY a.id DESC LIMIT 30'
            )->fetchAll();
        }
        $products = $this->database->query(
            'SELECT p.id, p.slug, p.name, p.type, p.is_active, '
            . '(SELECT COUNT(*) FROM subscriptions s WHERE s.product_id = p.id) AS license_count '
            . 'FROM products p ORDER BY p.name'
        )->fetchAll();
        $payments = [];
        $supportTickets = [];
        try {
            $products = $this->database->query(
                'SELECT p.id, p.slug, p.name, p.type, p.short_description,p.description,p.compatibility,p.operating_systems,p.requirements,p.seo_title,p.seo_description,p.is_public,p.is_featured,p.is_active, g.slug AS game_slug, g.name AS game_name, g.image_url, '
                . 'g.commercial_status, g.purchases_allowed, (SELECT COUNT(*) FROM subscriptions s WHERE s.product_id = p.id) AS license_count '
                . 'FROM products p LEFT JOIN product_games pg ON pg.product_id = p.id LEFT JOIN games g ON g.id = pg.game_id ORDER BY p.name'
            )->fetchAll();
            foreach ($products as &$product) {
                $plansQuery = $this->database->prepare('SELECT id, name, slug, price_cents, sale_price_cents, currency, badge, is_active FROM plans WHERE product_id = :id ORDER BY sort_order, duration_days, id');
                $plansQuery->execute([':id' => (int) $product['id']]);
                $product['commerce_plans'] = $plansQuery->fetchAll();
                $mediaQuery = $this->database->prepare('SELECT id,media_type,url,thumbnail_url,poster_url,title,alt_text,sort_order,is_public,is_active FROM product_media WHERE product_id=:id ORDER BY sort_order,id');
                $mediaQuery->execute([':id' => (int) $product['id']]);
                $product['media'] = $mediaQuery->fetchAll();
                $categoryQuery = $this->database->prepare('SELECT id,name,description,sort_order,is_active FROM product_feature_categories WHERE product_id=:id ORDER BY sort_order,id');
                $categoryQuery->execute([':id' => (int) $product['id']]);
                $product['feature_categories'] = $categoryQuery->fetchAll();
                foreach ($product['feature_categories'] as &$category) {
                    $featureQuery = $this->database->prepare('SELECT id,name,short_description,is_highlighted,sort_order,is_public,is_active FROM product_features WHERE category_id=:id ORDER BY sort_order,id');
                    $featureQuery->execute([':id' => (int) $category['id']]);
                    $category['features'] = $featureQuery->fetchAll();
                }
                unset($category);
                $faqQuery = $this->database->prepare('SELECT id,question,answer,sort_order,is_public,is_active FROM product_faqs WHERE product_id=:id ORDER BY sort_order,id');
                $faqQuery->execute([':id' => (int) $product['id']]);
                $product['faqs'] = $faqQuery->fetchAll();
            }
            unset($product);
            $payments = $this->database->query(
                'SELECT o.order_number,o.product_name_snapshot,o.plan_name_snapshot,o.amount_cents,o.currency,o.status,o.created_at,o.paid_at,u.email,p.provider,p.provider_reference '
                . 'FROM orders o INNER JOIN users u ON u.id=o.user_id LEFT JOIN payments p ON p.order_id=o.id ORDER BY o.created_at DESC LIMIT 200'
            )->fetchAll();
            $supportTickets = $this->database->query(
                'SELECT t.ticket_number,t.subject,t.category,t.status,t.updated_at,COALESCE(u.email,t.guest_email) AS email FROM support_tickets t LEFT JOIN users u ON u.id=t.user_id ORDER BY t.updated_at DESC LIMIT 200'
            )->fetchAll();
        } catch (\PDOException) {
            // Keeps the legacy administration usable until migration 008 is applied.
        }
        $versions = $this->database->query(
            'SELECT g.name AS product_name, m.name AS module_name, mv.version, mv.file_size, mv.status, '
            . 'mv.minimum_launcher_version, mv.created_at, mv.published_at '
            . 'FROM module_versions mv INNER JOIN modules m ON m.id = mv.module_id '
            . 'INNER JOIN games g ON g.id = m.game_id ORDER BY mv.created_at DESC LIMIT 100'
        )->fetchAll();
        return [
            'role' => $this->authorization->roleForUser($actorUserId),
            'permissions' => $permissions,
            'users' => $users,
            'subscriptions' => $subscriptions,
            'plans' => $plans,
            'audit' => $audit,
            'products' => $products,
            'versions' => $versions,
            'payments' => $payments,
            'support_tickets' => $supportTickets,
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

    public function updatePlan(int $actorUserId, int $planId, int $priceCents, ?int $salePriceCents, ?string $badge, bool $isActive, string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId, 'commerce.manage');
        if ($planId < 1 || $priceCents < 0 || $priceCents > 100000000 || ($salePriceCents !== null && ($salePriceCents < 0 || $salePriceCents >= $priceCents))) {
            throw new ApiException('validation_error', 400, 'Enter a valid price and an optional lower sale price.');
        }
        $badge = trim((string) $badge);
        if (strlen($badge) > 40) throw new ApiException('validation_error', 400, 'The badge is too long.');
        $statement = $this->database->prepare('UPDATE plans SET price_cents=:price,sale_price_cents=:sale,badge=:badge,is_active=:active,updated_at=:now WHERE id=:id');
        $statement->execute([':price'=>$priceCents, ':sale'=>$salePriceCents, ':badge'=>$badge === '' ? null : $badge, ':active'=>$isActive?1:0, ':now'=>gmdate('Y-m-d H:i:s'), ':id'=>$planId]);
        if ($statement->rowCount() === 0) {
            $exists = $this->database->prepare('SELECT COUNT(*) FROM plans WHERE id=:id'); $exists->execute([':id'=>$planId]);
            if ((int) $exists->fetchColumn() === 0) throw new ApiException('plan_not_found', 404, 'Access Plan not found.');
        }
        $this->audit($actorUserId, 'plan.pricing_updated', 'plan', (string) $planId, ['price_cents'=>$priceCents,'sale_price_cents'=>$salePriceCents,'badge'=>$badge,'is_active'=>$isActive], $ipAddress);
    }

    public function updateEnhancementStatus(int $actorUserId, string $gameSlug, string $status, bool $purchasesAllowed, string $reason, string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId, 'commerce.manage');
        if (!in_array($status, ['operational','updating','maintenance','discontinued'], true)) throw new ApiException('validation_error', 400, 'Invalid Enhancement status.');
        $find = $this->database->prepare('SELECT g.id,g.commercial_status,p.id AS product_id FROM games g INNER JOIN product_games pg ON pg.game_id=g.id INNER JOIN products p ON p.id=pg.product_id WHERE g.slug=:slug LIMIT 1');
        $find->execute([':slug'=>$gameSlug]); $game=$find->fetch();
        if (!is_array($game)) throw new ApiException('product_not_found', 404, 'Enhancement not found.');
        $now=gmdate('Y-m-d H:i:s'); $old=(string)$game['commercial_status'];
        $this->database->beginTransaction();
        try {
            $this->database->prepare('UPDATE games SET commercial_status=:status,purchases_allowed=:allowed,status_updated_at=:now,updated_at=:now WHERE id=:id')->execute([':status'=>$status,':allowed'=>$purchasesAllowed?1:0,':now'=>$now,':id'=>(int)$game['id']]);
            if ($old === 'operational' && $status !== 'operational') {
                $this->database->prepare('INSERT INTO product_downtimes (product_id,started_at,reason,created_by_user_id,created_at) VALUES (:product_id,:started_at,:reason,:actor,:created_at)')->execute([':product_id'=>(int)$game['product_id'],':started_at'=>$now,':reason'=>substr(trim($reason) ?: ucfirst($status),0,255),':actor'=>$actorUserId,':created_at'=>$now]);
            } elseif ($old !== 'operational' && $status === 'operational') {
                $open=$this->database->prepare('SELECT id,started_at FROM product_downtimes WHERE product_id=:product_id AND ended_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE'); $open->execute([':product_id'=>(int)$game['product_id']]); $downtime=$open->fetch();
                if (is_array($downtime)) {
                    $seconds=max(0,time()-(int)strtotime((string)$downtime['started_at']));
                    $this->database->prepare('UPDATE product_downtimes SET ended_at=:now,duration_seconds=:seconds WHERE id=:id')->execute([':now'=>$now,':seconds'=>$seconds,':id'=>(int)$downtime['id']]);
                    if ($seconds > 0) $this->database->prepare('UPDATE subscriptions SET expires_at=DATE_ADD(expires_at,INTERVAL :seconds SECOND),updated_at=:now WHERE product_id=:product_id AND status IN (\'active\',\'cancelled\') AND expires_at IS NOT NULL AND expires_at>:started_at')->execute([':seconds'=>$seconds,':now'=>$now,':product_id'=>(int)$game['product_id'],':started_at'=>(string)$downtime['started_at']]);
                }
            }
            $this->database->commit();
        } catch (\Throwable $exception) { if ($this->database->inTransaction()) $this->database->rollBack(); throw $exception; }
        $this->audit($actorUserId, 'enhancement.status_updated', 'game', (string)$game['id'], ['slug'=>$gameSlug,'status'=>$status,'purchases_allowed'=>$purchasesAllowed], $ipAddress);
    }

    public function updateProductContent(int $actorUserId, int $productId, array $input, string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId, 'content.manage');
        $name = trim((string) ($input['name'] ?? ''));
        if ($productId < 1 || $name === '' || strlen($name) > 180) throw new ApiException('validation_error', 400, 'Enter a valid product name.');
        $find = $this->database->prepare('SELECT id FROM products WHERE id=:id LIMIT 1');
        $find->execute([':id' => $productId]);
        if ($find->fetchColumn() === false) throw new ApiException('product_not_found', 404, 'Enhancement not found.');
        $values = [
            ':id' => $productId, ':name' => $name,
            ':short' => $this->nullableText($input['short_description'] ?? null, 500),
            ':description' => $this->nullableText($input['description'] ?? null, 20000),
            ':compatibility' => $this->nullableText($input['compatibility'] ?? null, 500),
            ':systems' => $this->nullableText($input['operating_systems'] ?? null, 500),
            ':requirements' => $this->nullableText($input['requirements'] ?? null, 20000),
            ':seo_title' => $this->nullableText($input['seo_title'] ?? null, 255),
            ':seo_description' => $this->nullableText($input['seo_description'] ?? null, 500),
            ':public' => isset($input['is_public']) ? 1 : 0, ':featured' => isset($input['is_featured']) ? 1 : 0,
        ];
        $this->database->prepare('UPDATE products SET name=:name,short_description=:short,description=:description,compatibility=:compatibility,operating_systems=:systems,requirements=:requirements,seo_title=:seo_title,seo_description=:seo_description,is_public=:public,is_featured=:featured WHERE id=:id')->execute($values);
        $cover = $this->nullableText($input['image_url'] ?? null, 2048);
        $this->database->prepare('UPDATE games SET image_url=:cover,updated_at=:now WHERE id=(SELECT game_id FROM product_games WHERE product_id=:product LIMIT 1)')->execute([':cover' => $cover, ':now' => gmdate('Y-m-d H:i:s'), ':product' => $productId]);
        $this->audit($actorUserId, 'product.content_updated', 'product', (string) $productId, ['name' => $name, 'is_public' => isset($input['is_public']), 'is_featured' => isset($input['is_featured'])], $ipAddress);
    }

    public function saveProductMedia(int $actorUserId, int $productId, ?int $mediaId, array $input, string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId, 'content.manage');
        $type = trim((string) ($input['media_type'] ?? ''));
        $url = trim((string) ($input['url'] ?? ''));
        if ($productId < 1 || !in_array($type, ['image', 'gif', 'video'], true) || $url === '' || strlen($url) > 2048) {
            throw new ApiException('validation_error', 400, 'Select a media type and provide a valid media URL.');
        }
        $values = [
            ':product' => $productId, ':type' => $type, ':url' => $url,
            ':thumbnail' => $this->nullableText($input['thumbnail_url'] ?? null, 2048),
            ':poster' => $this->nullableText($input['poster_url'] ?? null, 2048),
            ':title' => $this->nullableText($input['title'] ?? null, 180),
            ':alt' => $this->nullableText($input['alt_text'] ?? null, 255),
            ':sort' => (int) ($input['sort_order'] ?? 0), ':public' => isset($input['is_public']) ? 1 : 0,
            ':active' => isset($input['is_active']) ? 1 : 0, ':now' => gmdate('Y-m-d H:i:s'),
        ];
        if ($mediaId === null) {
            $statement = $this->database->prepare('INSERT INTO product_media (product_id,media_type,url,thumbnail_url,poster_url,title,alt_text,sort_order,is_public,is_active,created_at,updated_at) VALUES (:product,:type,:url,:thumbnail,:poster,:title,:alt,:sort,:public,:active,:now,:now)');
            $statement->execute($values);
            $mediaId = (int) $this->database->lastInsertId();
        } else {
            $values[':id'] = $mediaId;
            $statement = $this->database->prepare('UPDATE product_media SET product_id=:product,media_type=:type,url=:url,thumbnail_url=:thumbnail,poster_url=:poster,title=:title,alt_text=:alt,sort_order=:sort,is_public=:public,is_active=:active,updated_at=:now WHERE id=:id');
            $statement->execute($values);
        }
        $this->audit($actorUserId, 'product.media_saved', 'product_media', (string) $mediaId, ['product_id' => $productId, 'type' => $type], $ipAddress);
    }

    public function deleteProductMedia(int $actorUserId, int $mediaId, string $ipAddress): void
    {
        $this->deleteContentRow($actorUserId, 'product_media', $mediaId, 'product.media_deleted', $ipAddress);
    }

    public function saveFeatureCategory(int $actorUserId, int $productId, ?int $categoryId, array $input, string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId, 'content.manage');
        $name = trim((string) ($input['name'] ?? ''));
        if ($productId < 1 || $name === '' || strlen($name) > 120) throw new ApiException('validation_error', 400, 'Enter a feature category name.');
        $values = [':product' => $productId, ':name' => $name, ':description' => $this->nullableText($input['description'] ?? null, 500), ':sort' => (int) ($input['sort_order'] ?? 0), ':active' => isset($input['is_active']) ? 1 : 0];
        if ($categoryId === null) {
            $statement = $this->database->prepare('INSERT INTO product_feature_categories (product_id,name,description,sort_order,is_active) VALUES (:product,:name,:description,:sort,:active)');
            $statement->execute($values); $categoryId = (int) $this->database->lastInsertId();
        } else {
            $values[':id'] = $categoryId;
            $this->database->prepare('UPDATE product_feature_categories SET product_id=:product,name=:name,description=:description,sort_order=:sort,is_active=:active WHERE id=:id')->execute($values);
        }
        $this->audit($actorUserId, 'product.feature_category_saved', 'product_feature_category', (string) $categoryId, ['product_id' => $productId, 'name' => $name], $ipAddress);
    }

    public function deleteFeatureCategory(int $actorUserId, int $categoryId, string $ipAddress): void
    {
        $this->deleteContentRow($actorUserId, 'product_feature_categories', $categoryId, 'product.feature_category_deleted', $ipAddress);
    }

    public function saveFeature(int $actorUserId, int $categoryId, ?int $featureId, array $input, string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId, 'content.manage');
        $name = trim((string) ($input['name'] ?? ''));
        if ($categoryId < 1 || $name === '' || strlen($name) > 160) throw new ApiException('validation_error', 400, 'Enter a feature name.');
        $values = [':category' => $categoryId, ':name' => $name, ':description' => $this->nullableText($input['short_description'] ?? null, 500), ':highlighted' => isset($input['is_highlighted']) ? 1 : 0, ':sort' => (int) ($input['sort_order'] ?? 0), ':public' => isset($input['is_public']) ? 1 : 0, ':active' => isset($input['is_active']) ? 1 : 0];
        if ($featureId === null) {
            $statement = $this->database->prepare('INSERT INTO product_features (category_id,name,short_description,is_highlighted,sort_order,is_public,is_active) VALUES (:category,:name,:description,:highlighted,:sort,:public,:active)');
            $statement->execute($values); $featureId = (int) $this->database->lastInsertId();
        } else {
            $values[':id'] = $featureId;
            $this->database->prepare('UPDATE product_features SET category_id=:category,name=:name,short_description=:description,is_highlighted=:highlighted,sort_order=:sort,is_public=:public,is_active=:active WHERE id=:id')->execute($values);
        }
        $this->audit($actorUserId, 'product.feature_saved', 'product_feature', (string) $featureId, ['category_id' => $categoryId, 'name' => $name], $ipAddress);
    }

    public function deleteFeature(int $actorUserId, int $featureId, string $ipAddress): void
    {
        $this->deleteContentRow($actorUserId, 'product_features', $featureId, 'product.feature_deleted', $ipAddress);
    }

    public function saveFaq(int $actorUserId, int $productId, ?int $faqId, array $input, string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId, 'content.manage');
        $question = trim((string) ($input['question'] ?? ''));
        $answer = trim((string) ($input['answer'] ?? ''));
        if ($productId < 1 || $question === '' || $answer === '' || strlen($question) > 255) throw new ApiException('validation_error', 400, 'Enter both an FAQ question and answer.');
        $values = [':product' => $productId, ':question' => $question, ':answer' => $answer, ':sort' => (int) ($input['sort_order'] ?? 0), ':public' => isset($input['is_public']) ? 1 : 0, ':active' => isset($input['is_active']) ? 1 : 0];
        if ($faqId === null) {
            $statement = $this->database->prepare('INSERT INTO product_faqs (product_id,question,answer,sort_order,is_public,is_active) VALUES (:product,:question,:answer,:sort,:public,:active)');
            $statement->execute($values); $faqId = (int) $this->database->lastInsertId();
        } else {
            $values[':id'] = $faqId;
            $this->database->prepare('UPDATE product_faqs SET product_id=:product,question=:question,answer=:answer,sort_order=:sort,is_public=:public,is_active=:active WHERE id=:id')->execute($values);
        }
        $this->audit($actorUserId, 'product.faq_saved', 'product_faq', (string) $faqId, ['product_id' => $productId, 'question' => $question], $ipAddress);
    }

    public function deleteFaq(int $actorUserId, int $faqId, string $ipAddress): void
    {
        $this->deleteContentRow($actorUserId, 'product_faqs', $faqId, 'product.faq_deleted', $ipAddress);
    }

    private function deleteContentRow(int $actorUserId, string $table, int $id, string $action, string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId, 'content.manage');
        if (!in_array($table, ['product_media', 'product_feature_categories', 'product_features', 'product_faqs'], true) || $id < 1) throw new ApiException('validation_error', 400, 'Invalid content item.');
        $statement = $this->database->prepare('DELETE FROM ' . $table . ' WHERE id=:id');
        $statement->execute([':id' => $id]);
        if ($statement->rowCount() !== 1) throw new ApiException('content_not_found', 404, 'Content item not found.');
        $this->audit($actorUserId, $action, $table, (string) $id, [], $ipAddress);
    }

    private function nullableText(mixed $value, int $maximum): ?string
    {
        $value = trim(is_string($value) ? $value : '');
        if ($value === '') return null;
        if (strlen($value) > $maximum) throw new ApiException('validation_error', 400, 'One of the content fields is too long.');
        return $value;
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
