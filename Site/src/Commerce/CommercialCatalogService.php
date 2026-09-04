<?php

declare(strict_types=1);

namespace Pericles\Commerce;

use PDO;
use Pericles\Http\ApiException;

final class CommercialCatalogService
{
    public function __construct(private PDO $database)
    {
    }

    public function listEnhancements(bool $includeUnavailable = true): array
    {
        $sql = $this->baseProductQuery()
            . ' WHERE g.is_active = 1 AND g.is_public = 1 AND p.is_active = 1 AND p.is_public = 1';
        if (!$includeUnavailable) {
            $sql .= " AND g.commercial_status <> 'discontinued'";
        }
        $sql .= ' ORDER BY g.sort_order ASC, g.name ASC';
        $rows = $this->database->query($sql)->fetchAll();
        foreach ($rows as &$row) {
            $row = $this->normalizeProduct($row);
        }
        unset($row);
        return $rows;
    }

    public function enhancement(string $slug, ?int $userId = null): array
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new ApiException('product_not_found', 404, 'Enhancement not found.');
        }
        $statement = $this->database->prepare(
            $this->baseProductQuery()
            . ' WHERE g.slug = :slug AND g.is_public = 1 AND p.is_public = 1 LIMIT 1'
        );
        $statement->execute([':slug' => $slug]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException('product_not_found', 404, 'Enhancement not found.');
        }
        $product = $this->normalizeProduct($row);
        $product['features'] = $this->features((int) $row['product_id']);
        $product['media'] = $this->media((int) $row['product_id']);
        $product['documents'] = $this->documents((int) $row['product_id'], $userId);
        $product['changelog'] = $this->changelog((int) $row['product_id'], 10);
        $product['faqs'] = $this->faqs((int) $row['product_id']);
        $product['owned_access'] = $userId === null ? null : $this->ownedAccess((int) $row['product_id'], $userId);
        return $product;
    }

    public function publicChangelog(?string $productSlug = null, int $limit = 100): array
    {
        $sql = 'SELECT c.version, c.summary, c.published_at, g.slug AS game_slug, g.name AS game_name, g.image_url '
            . 'FROM product_changelog c INNER JOIN products p ON p.id = c.product_id '
            . 'INNER JOIN product_games pg ON pg.product_id = p.id INNER JOIN games g ON g.id = pg.game_id '
            . 'WHERE c.is_public = 1 AND p.is_public = 1 AND g.is_public = 1';
        $params = [];
        if ($productSlug !== null && $productSlug !== '') {
            $sql .= ' AND g.slug = :slug';
            $params[':slug'] = $productSlug;
        }
        $sql .= ' ORDER BY c.published_at DESC, c.id DESC LIMIT ' . max(1, min(200, $limit));
        $statement = $this->database->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function statusOverview(): array
    {
        $games = $this->database->query(
            'SELECT g.slug, g.name, g.image_url, g.commercial_status, g.status_updated_at, '
            . '(SELECT d.reason FROM product_downtimes d INNER JOIN product_games pg2 ON pg2.product_id = d.product_id '
            . 'WHERE pg2.game_id = g.id AND d.ended_at IS NULL ORDER BY d.id DESC LIMIT 1) AS incident '
            . 'FROM games g WHERE g.is_public = 1 AND g.is_active = 1 ORDER BY g.sort_order, g.name'
        )->fetchAll();
        $maintenance = $this->database->query(
            'SELECT g.name AS game_name, d.started_at, d.ended_at, d.reason, d.duration_seconds '
            . 'FROM product_downtimes d INNER JOIN product_games pg ON pg.product_id = d.product_id '
            . 'INNER JOIN games g ON g.id = pg.game_id WHERE d.ended_at IS NOT NULL '
            . 'ORDER BY d.ended_at DESC LIMIT 10'
        )->fetchAll();
        $services = [];
        try {
            $services = $this->database->query(
                "SELECT service_key,name,status,incident,updated_at FROM platform_service_status ORDER BY FIELD(service_key,'platform','authentication','launcher','downloads'),name"
            )->fetchAll();
        } catch (\PDOException) {
        }
        return ['services' => $services, 'games' => $games, 'maintenance' => $maintenance];
    }

    public function content(string $key): ?array
    {
        if (!preg_match('/^[a-z0-9-]{2,100}$/', $key)) return null;
        try {
            $statement = $this->database->prepare('SELECT content_key,title,body,version,updated_at FROM platform_content WHERE content_key=:key AND is_published=1 LIMIT 1');
            $statement->execute([':key' => $key]);
            $row = $statement->fetch();
            return is_array($row) ? $row : null;
        } catch (\PDOException) {
            return null;
        }
    }

    public function documentationForUser(int $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT d.id, d.slug, d.title, d.section, d.body, d.access_level, d.updated_at, '
            . 'g.slug AS game_slug, g.name AS game_name, g.image_url, s.status AS access_status, s.expires_at '
            . 'FROM product_documents d INNER JOIN products p ON p.id = d.product_id '
            . 'INNER JOIN product_games pg ON pg.product_id = p.id INNER JOIN games g ON g.id = pg.game_id '
            . 'INNER JOIN subscriptions s ON s.product_id = p.id AND s.user_id = :user_id '
            . 'WHERE d.is_published = 1 AND (d.access_level IN (\'public\',\'previous_customer\') '
            . 'OR (d.access_level = \'active_access\' AND s.status IN (\'active\',\'cancelled\') '
            . 'AND (s.expires_at IS NULL OR s.expires_at > :now))) '
            . 'ORDER BY g.sort_order, d.sort_order, d.title'
        );
        $statement->execute([':user_id' => $userId, ':now' => gmdate('Y-m-d H:i:s')]);
        return $statement->fetchAll();
    }

    private function baseProductQuery(): string
    {
        return 'SELECT p.id AS product_id, p.slug AS product_slug, p.name AS product_name, p.short_description AS product_short_description, p.description, '
            . 'p.compatibility, p.operating_systems, p.requirements, p.included_items_json, p.seo_title, p.seo_description, g.id AS game_id, g.slug AS game_slug, '
            . 'g.name AS game_name, g.short_description, g.image_url, g.commercial_status, '
            . 'g.purchases_allowed, g.status_updated_at, g.updated_at, '
            . '(SELECT mv.version FROM modules m INNER JOIN module_versions mv ON mv.module_id = m.id '
            . "WHERE m.game_id = g.id AND m.is_active = 1 AND mv.status = 'active' "
            . 'ORDER BY mv.published_at DESC, mv.id DESC LIMIT 1) AS latest_version, '
            . '(SELECT mv.published_at FROM modules m INNER JOIN module_versions mv ON mv.module_id = m.id '
            . "WHERE m.game_id = g.id AND m.is_active = 1 AND mv.status = 'active' "
            . 'ORDER BY mv.published_at DESC, mv.id DESC LIMIT 1) AS latest_release '
            . 'FROM games g INNER JOIN product_games pg ON pg.game_id = g.id '
            . 'INNER JOIN products p ON p.id = pg.product_id';
    }

    private function normalizeProduct(array $row): array
    {
        $row['plans'] = $this->plans((int) $row['product_id']);
        $row['minimum_price_cents'] = null;
        foreach ($row['plans'] as $plan) {
            $price = $plan['effective_price_cents'];
            if ($price !== null && ($row['minimum_price_cents'] === null || $price < $row['minimum_price_cents'])) {
                $row['minimum_price_cents'] = $price;
            }
        }
        $row['can_purchase'] = (int) $row['purchases_allowed'] === 1
            && (string) $row['commercial_status'] === 'operational';
        return $row;
    }

    private function plans(int $productId): array
    {
        $statement = $this->database->prepare(
            'SELECT id, slug, name, duration_days, is_lifetime, price_cents, sale_price_cents, currency, badge, sort_order '
            . 'FROM plans WHERE product_id = :product_id AND is_active = 1 AND price_cents IS NOT NULL '
            . 'ORDER BY sort_order, duration_days, id'
        );
        $statement->execute([':product_id' => $productId]);
        $plans = $statement->fetchAll();
        foreach ($plans as &$plan) {
            $regular = (int) $plan['price_cents'];
            $sale = $plan['sale_price_cents'] === null ? null : (int) $plan['sale_price_cents'];
            $plan['price_cents'] = $regular;
            $plan['sale_price_cents'] = $sale;
            $plan['effective_price_cents'] = $sale !== null && $sale < $regular ? $sale : $regular;
            $plan['saving_percent'] = $sale !== null && $sale < $regular
                ? (int) round((1 - ($sale / $regular)) * 100)
                : null;
        }
        unset($plan);
        $thirtyDayPrice = null;
        foreach ($plans as $candidate) if ((int) ($candidate['duration_days'] ?? 0) === 30) { $thirtyDayPrice = (int) $candidate['effective_price_cents']; break; }
        if ($thirtyDayPrice !== null) {
            foreach ($plans as &$plan) {
                if ((int) ($plan['duration_days'] ?? 0) === 90) {
                    $baseline = $thirtyDayPrice * 3;
                    $price = (int) $plan['effective_price_cents'];
                    if ($price < $baseline) $plan['saving_percent'] = (int) round((1 - $price / $baseline) * 100);
                }
            }
            unset($plan);
        }
        return $plans;
    }

    private function features(int $productId): array
    {
        $statement = $this->database->prepare(
            'SELECT c.id AS category_id, c.name AS category_name, c.description AS category_description, f.id, f.name, f.short_description, f.is_highlighted '
            . 'FROM product_feature_categories c INNER JOIN product_features f ON f.category_id = c.id '
            . 'WHERE c.product_id = :product_id AND c.is_active = 1 AND f.is_active = 1 AND f.is_public = 1 '
            . 'ORDER BY c.sort_order, c.id, f.sort_order, f.id'
        );
        $statement->execute([':product_id' => $productId]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $id = (int) $row['category_id'];
            if (!isset($result[$id])) $result[$id] = ['id' => $id, 'name' => (string) $row['category_name'], 'description' => $row['category_description'], 'items' => []];
            $result[$id]['items'][] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'description' => $row['short_description'], 'highlighted' => (int) $row['is_highlighted'] === 1];
        }
        return array_values($result);
    }

    private function media(int $productId): array
    {
        $statement = $this->database->prepare('SELECT id,media_type,url,thumbnail_url,poster_url,title,alt_text FROM product_media WHERE product_id=:product_id AND is_public=1 AND is_active=1 ORDER BY sort_order,id');
        $statement->execute([':product_id' => $productId]);
        return $statement->fetchAll();
    }

    private function documents(int $productId, ?int $userId): array
    {
        $levels = ['public'];
        if ($userId !== null && $this->hasPreviousAccess($productId, $userId)) $levels[] = 'previous_customer';
        if ($userId !== null && $this->hasActiveAccess($productId, $userId)) $levels[] = 'active_access';
        $quoted = implode(',', array_fill(0, count($levels), '?'));
        $statement = $this->database->prepare('SELECT slug, title, section, body, access_level, updated_at FROM product_documents WHERE product_id = ? AND is_published = 1 AND access_level IN (' . $quoted . ') ORDER BY sort_order, id');
        $statement->execute(array_merge([$productId], $levels));
        return $statement->fetchAll();
    }

    private function changelog(int $productId, int $limit): array
    {
        $statement = $this->database->prepare('SELECT version, summary, published_at FROM product_changelog WHERE product_id = :product_id AND is_public = 1 ORDER BY published_at DESC, id DESC LIMIT ' . $limit);
        $statement->execute([':product_id' => $productId]);
        return $statement->fetchAll();
    }

    private function faqs(int $productId): array
    {
        $statement = $this->database->prepare('SELECT question,answer FROM product_faqs WHERE product_id=:product_id AND is_public=1 AND is_active=1 ORDER BY sort_order,id');
        $statement->execute([':product_id' => $productId]);
        return $statement->fetchAll();
    }

    private function ownedAccess(int $productId, int $userId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT s.status, s.purchased_at, s.activation_deadline_at, s.activated_at, s.starts_at, s.expires_at, '
            . 's.bound_at, pl.name AS plan_name, pl.is_lifetime, d.display_name AS device_name '
            . 'FROM subscriptions s LEFT JOIN plans pl ON pl.id = s.plan_id LEFT JOIN devices d ON d.id = s.bound_device_id '
            . 'WHERE s.product_id = :product_id AND s.user_id = :user_id LIMIT 1'
        );
        $statement->execute([':product_id' => $productId, ':user_id' => $userId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function hasPreviousAccess(int $productId, int $userId): bool
    {
        $statement = $this->database->prepare('SELECT COUNT(*) FROM subscriptions WHERE product_id = :product_id AND user_id = :user_id');
        $statement->execute([':product_id' => $productId, ':user_id' => $userId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function hasActiveAccess(int $productId, int $userId): bool
    {
        $statement = $this->database->prepare("SELECT COUNT(*) FROM subscriptions WHERE product_id = :product_id AND user_id = :user_id AND status IN ('active','cancelled') AND (expires_at IS NULL OR expires_at > :now)");
        $statement->execute([':product_id' => $productId, ':user_id' => $userId, ':now' => gmdate('Y-m-d H:i:s')]);
        return (int) $statement->fetchColumn() > 0;
    }
}
