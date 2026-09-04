<?php

declare(strict_types=1);

namespace Pericles\Web;

final class AdminPage
{
    public static function render(string $email, array $data, string $csrfToken, ?array $flash = null, string $section = 'overview'): never
    {
        $titles = [
            'overview' => ['Overview', 'Manage accounts, licenses, products, and security.'],
            'users' => ['Users', 'Review accounts, roles, licenses, and platform access.'],
            'licenses' => ['Licenses & HWID', 'Generate keys, follow activations, and reset hardware bindings.'],
            'products' => ['Products & versions', 'Review the products and builds stored in the Pericles database.'],
            'payments' => ['Payments', 'Track transactions and revenue when a payment provider is connected.'],
            'support' => ['Support tickets', 'Review customer requests when ticket storage is configured.'],
            'audit' => ['Audit log', 'Every recorded sensitive action performed on the platform.'],
            'settings' => ['Global settings', 'Platform-wide configuration and safeguards.'],
        ];
        if (!isset($titles[$section])) $section = 'overview';
        $permissions = $data['permissions'];
        $roleName = (string) $data['role']['name'];
        self::header($titles[$section][0]);
        echo '<div class="app-shell admin-ui"><aside class="app-sidebar"><div class="sidebar-brand">' . self::brand('/admin') . '<button type="button" data-sidebar-toggle aria-label="Close menu">×</button></div><nav class="app-nav"><p>Administration</p>';
        $nav = [['overview', 'Overview', 'grid'], ['users', 'Users', 'users'], ['licenses', 'Licenses & HWID', 'key'], ['products', 'Products & versions', 'package'], ['payments', 'Payments', 'receipt'], ['support', 'Support tickets', 'headphones'], ['audit', 'Audit log', 'activity'], ['settings', 'Global settings', 'settings']];
        foreach ($nav as [$key, $label, $icon]) {
            if ($key === 'audit' && !in_array('audit.view', $permissions, true)) continue;
            echo '<a class="' . ($section === $key ? 'active' : '') . '" href="' . self::e(self::url($key === 'overview' ? '/admin' : '/admin/' . $key)) . '">' . self::icon($icon) . '<span>' . self::e($label) . '</span></a>';
        }
        echo '<p>Navigation</p><a href="' . self::e(self::url('/account')) . '">' . self::icon('arrow-left') . '<span>Player workspace</span></a></nav><div class="sidebar-account"><span class="avatar">' . self::e(strtoupper(substr($email, 0, 1))) . '</span><div><strong>' . self::e($roleName) . '</strong><small>' . self::e($email) . '</small></div></div></aside>'
            . '<div class="app-area"><header class="app-topbar"><button class="mobile-menu" type="button" data-sidebar-toggle aria-label="Open menu">' . self::icon('menu') . '</button><div class="breadcrumb"><span>Administration</span><i>/</i><strong>' . self::e($titles[$section][0]) . '</strong></div><label class="global-search">' . self::icon('search') . '<input type="search" placeholder="Search" data-global-search></label><span class="operational"><i></i> Operational</span><span class="role-label">' . self::e($roleName) . '</span><details class="account-menu"><summary><span class="avatar">' . self::e(strtoupper(substr($email, 0, 1))) . '</span></summary><div><strong>' . self::e($roleName) . '</strong><small>' . self::e($email) . '</small><hr><a href="' . self::e(self::url('/account')) . '">Player workspace</a><form method="post" action="' . self::e(self::url('/logout')) . '"><input type="hidden" name="csrf" value="' . self::e($csrfToken) . '"><button type="submit">Sign out</button></form></div></details></header><main class="app-main">';
        self::flash($flash);
        echo '<div class="page-heading"><div><h1>' . self::e($titles[$section][0]) . '</h1><p>' . self::e($titles[$section][1]) . '</p></div>';
        if ($section === 'users') echo '<span class="role-label">Latest 100 accounts</span>';
        if ($section === 'licenses' && in_array('licenses.manage', $permissions, true)) echo '<a class="btn btn-primary" href="#generate-license">Generate key</a>';
        echo '</div>';

        match ($section) {
            'users' => self::users($data, $csrfToken),
            'licenses' => self::licenses($data, $csrfToken),
            'products' => self::products($data, $csrfToken),
            'payments' => self::payments($data),
            'support' => self::support($data),
            'audit' => self::audit($data),
            'settings' => self::emptyState('settings', 'Settings are environment-managed', 'Platform configuration currently comes from the server environment and cannot be edited from this screen.'),
            default => self::overview($data),
        };
        echo '</main></div><button class="sidebar-scrim" type="button" data-sidebar-toggle aria-label="Close menu"></button></div></body></html>';
        exit;
    }

    private static function overview(array $data): void
    {
        echo '<section class="metric-strip">' . self::metric('Users', (string) $data['metrics']['users'], 'registered accounts') . self::metric('Active licenses', (string) $data['metrics']['active_subscriptions'], 'current subscriptions') . self::metric('Linked products', (string) $data['metrics']['bound_products'], 'hardware bindings') . self::metric('Available keys', $data['metrics']['unused_keys'] === null ? '—' : (string) $data['metrics']['unused_keys'], 'ready to assign') . '</section><section class="admin-overview-grid">';
        echo '<div class="data-panel"><div class="panel-header"><h2>Latest users</h2><a href="' . self::e(self::url('/admin/users')) . '">View all</a></div><div class="table-wrap"><table class="data-table"><thead><tr><th>User</th><th>Status</th><th>Joined</th></tr></thead><tbody>';
        foreach (array_slice($data['users'], 0, 5) as $user) echo '<tr><td><strong>' . self::e(self::userName($user)) . '</strong><small>' . self::e((string) $user['email']) . '</small></td><td>' . self::badge((string) $user['status']) . '</td><td>' . self::date($user['created_at']) . '</td></tr>';
        if ($data['users'] === []) self::emptyRow(3, 'No user is available for your permission level.');
        echo '</tbody></table></div></div><div class="data-panel"><div class="panel-header"><h2>Recent licenses</h2><a href="' . self::e(self::url('/admin/licenses')) . '">View all</a></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Owner</th><th>Expiration</th></tr></thead><tbody>';
        foreach (array_slice($data['subscriptions'], 0, 5) as $license) echo '<tr><td><strong>' . self::e((string) $license['product_name']) . '</strong></td><td>' . self::e((string) $license['email']) . '</td><td>' . ($license['expires_at'] === null ? 'Lifetime' : self::date($license['expires_at'])) . '</td></tr>';
        if ($data['subscriptions'] === []) self::emptyRow(3, 'No license has been assigned.');
        echo '</tbody></table></div></div><div class="data-panel"><div class="panel-header"><h2>Products</h2><a href="' . self::e(self::url('/admin/products')) . '">View all</a></div><ul class="summary-list">';
        foreach (array_slice($data['products'], 0, 5) as $product) echo '<li><span>' . self::e((string) $product['name']) . '</span><strong>' . (int) $product['license_count'] . ' licenses</strong></li>';
        echo '</ul></div><div class="data-panel"><div class="panel-header"><h2>System & security</h2><span>Platform health</span></div><ul class="summary-list"><li><span>API</span><strong class="success-text">Operational</strong></li><li><span>Downloads</span><strong class="success-text">Operational</strong></li><li><span>Permission checks</span><strong>Server-side</strong></li><li><span>Audit events</span><strong>' . count($data['audit']) . ' loaded</strong></li></ul></div></section>';
    }

    private static function users(array $data, string $csrf): void
    {
        $canManage = in_array('users.manage', $data['permissions'], true);
        echo '<section class="data-panel"><div class="table-toolbar"><label>' . self::icon('search') . '<input type="search" placeholder="Search users" data-table-search></label><span>' . count($data['users']) . ' accounts</span></div><div class="table-wrap"><table class="data-table admin-table-wide"><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Licenses</th><th>Devices</th><th>Joined</th><th>Actions</th></tr></thead><tbody>';
        foreach ($data['users'] as $user) {
            echo '<tr data-search-row><td><strong>' . self::e(self::userName($user)) . '</strong><small>' . self::e((string) $user['email']) . '</small></td><td><span class="role-label">' . self::e((string) $user['role_name']) . '</span></td><td>' . self::badge((string) $user['status']) . '</td><td>' . (int) $user['product_count'] . '</td><td>' . (int) $user['device_count'] . '</td><td>' . self::date($user['created_at']) . '</td><td>';
            if ($canManage) {
                echo '<details class="row-actions"><summary class="btn btn-outline btn-small">Manage</summary><div><form method="post" action="' . self::e(self::url('/admin/users/' . (int) $user['id'] . '/role')) . '"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><label>Role<select name="role"><option value="player"' . ((string) $user['role_slug'] === 'player' ? ' selected' : '') . '>Player</option><option value="moderator"' . ((string) $user['role_slug'] === 'moderator' ? ' selected' : '') . '>Moderator</option><option value="admin"' . ((string) $user['role_slug'] === 'admin' ? ' selected' : '') . '>Administrator</option></select></label><button class="btn btn-primary btn-small" type="submit">Save role</button></form><form method="post" action="' . self::e(self::url('/admin/users/' . (int) $user['id'] . '/status')) . '" data-confirm="Change this account status?"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><input type="hidden" name="status" value="' . ((string) $user['status'] === 'active' ? 'disabled' : 'active') . '"><button class="btn btn-outline btn-small danger" type="submit">' . ((string) $user['status'] === 'active' ? 'Disable account' : 'Reactivate account') . '</button></form></div></details>';
            } else echo '<span class="muted-note">Read only</span>';
            echo '</td></tr>';
        }
        if ($data['users'] === []) self::emptyRow(7, 'No user is available for your permission level.');
        echo '</tbody></table></div></section>';
    }

    private static function licenses(array $data, string $csrf): void
    {
        $canLicenses = in_array('licenses.manage', $data['permissions'], true); $canDevices = in_array('devices.manage', $data['permissions'], true);
        if ($canLicenses) {
            echo '<section class="data-panel generate-panel" id="generate-license"><div><h2>Generate and assign a key</h2><p>The key is created in the real activation table and immediately redeemed for the selected account.</p></div><form method="post" action="' . self::e(self::url('/admin/licenses/assign')) . '"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><label>User<select name="user_id" required><option value="">Select an account</option>';
            foreach ($data['users'] as $user) if ((string) $user['status'] === 'active') echo '<option value="' . (int) $user['id'] . '">' . self::e((string) $user['email']) . '</option>';
            echo '</select></label><label>Product and duration<select name="plan_ref" required><option value="">Select a plan</option>';
            foreach ($data['plans'] as $plan) echo '<option value="' . self::e((string) $plan['product_slug'] . ':' . (string) $plan['plan_slug']) . '">' . self::e((string) $plan['product_name'] . ' · ' . (string) $plan['plan_name']) . '</option>';
            echo '</select></label><button class="btn btn-primary" type="submit">Generate and assign</button></form></section>';
        }
        echo '<section class="data-panel secondary-panel"><div class="table-toolbar"><label>' . self::icon('search') . '<input type="search" placeholder="Search licenses, owners, HWID" data-table-search></label><span>' . count($data['subscriptions']) . ' licenses</span></div><div class="table-wrap"><table class="data-table admin-table-wide"><thead><tr><th>License</th><th>Product</th><th>Owner</th><th>HWID / device</th><th>Expiration</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        foreach ($data['subscriptions'] as $license) {
            $key = is_string($license['key_hint'] ?? null) && $license['key_hint'] !== '' ? 'PERI-••••-' . self::e((string) $license['key_hint']) : '—';
            echo '<tr data-search-row><td class="mono">' . $key . '</td><td><strong>' . self::e((string) $license['product_name']) . '</strong></td><td>' . self::e((string) $license['email']) . '</td><td><strong>' . self::e((string) ($license['device_name'] ?: 'Not linked')) . '</strong><small class="mono">' . ($license['device_id'] ? self::e(substr((string) $license['device_id'], 0, 12)) . '••••' : '—') . '</small></td><td>' . ($license['expires_at'] === null ? 'Lifetime' : self::date($license['expires_at'])) . '</td><td>' . self::badge((string) $license['status']) . '</td><td><div class="inline-actions">';
            if ($canDevices && $license['bound_device_id'] !== null) echo '<form method="post" action="' . self::e(self::url('/admin/subscriptions/' . (int) $license['id'] . '/unbind')) . '" data-confirm="Reset this HWID binding?"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><button class="btn btn-outline btn-small" type="submit">Reset HWID</button></form>';
            if ($canLicenses) echo '<form method="post" action="' . self::e(self::url('/admin/subscriptions/' . (int) $license['id'] . '/delete')) . '" data-confirm="Permanently remove this license?"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><button class="btn btn-ghost btn-small danger" type="submit">Remove</button></form>';
            echo '</div></td></tr>';
        }
        if ($data['subscriptions'] === []) self::emptyRow(7, 'No licenses have been assigned.');
        echo '</tbody></table></div></section>';
    }

    private static function products(array $data, string $csrf): void
    {
        echo '<section class="commerce-admin-grid">';
        foreach ($data['products'] as $product) {
            $productId = (int) $product['id'];
            echo '<article class="data-panel commerce-admin-card"><div class="panel-header"><h2>' . self::e((string) ($product['game_name'] ?? $product['name'])) . '</h2><span>' . (int) $product['license_count'] . ' accesses</span></div>';
            echo '<details class="admin-content-section"><summary>General, Compatibility &amp; SEO <span>' . self::e((string) ($product['game_slug'] ?? $product['slug'])) . '</span></summary><form class="admin-content-form admin-product-general" method="post" action="' . self::e(self::url('/admin/products/' . $productId . '/content')) . '">' . self::csrf($csrf)
                . self::input('Product name', 'name', (string) $product['name'], true) . '<label>Game / public slug<input class="field" value="' . self::e((string) ($product['game_slug'] ?? $product['slug'])) . '" readonly></label>'
                . self::input('Cover URL (games.image_url)', 'image_url', (string) ($product['image_url'] ?? '')) . self::input('Short description', 'short_description', (string) ($product['short_description'] ?? ''))
                . '<label>Long description<textarea class="field" name="description">' . self::e((string) ($product['description'] ?? '')) . '</textarea></label>'
                . self::input('Operating systems', 'operating_systems', (string) ($product['operating_systems'] ?? '')) . self::input('Compatibility notes', 'compatibility', (string) ($product['compatibility'] ?? ''))
                . '<label>Requirements<textarea class="field" name="requirements">' . self::e((string) ($product['requirements'] ?? '')) . '</textarea></label>'
                . self::input('SEO title', 'seo_title', (string) ($product['seo_title'] ?? '')) . self::input('Meta description', 'seo_description', (string) ($product['seo_description'] ?? ''))
                . '<label class="admin-check"><input type="checkbox" name="is_public" value="1"' . ((int) ($product['is_public'] ?? 0) === 1 ? ' checked' : '') . '> Public</label><label class="admin-check"><input type="checkbox" name="is_featured" value="1"' . ((int) ($product['is_featured'] ?? 0) === 1 ? ' checked' : '') . '> Featured</label><button class="btn btn-primary btn-small" type="submit">Save product content</button></form></details>';
            echo '<form class="admin-status-form" method="post" action="' . self::e(self::url('/admin/enhancements/' . (string) ($product['game_slug'] ?? $product['slug']) . '/status')) . '"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><label>Status<select name="status"><option value="operational"' . (($product['commercial_status'] ?? '') === 'operational' ? ' selected' : '') . '>Operational</option><option value="updating"' . (($product['commercial_status'] ?? '') === 'updating' ? ' selected' : '') . '>Updating</option><option value="maintenance"' . (($product['commercial_status'] ?? '') === 'maintenance' ? ' selected' : '') . '>Maintenance</option><option value="discontinued"' . (($product['commercial_status'] ?? '') === 'discontinued' ? ' selected' : '') . '>Discontinued</option></select></label><label class="admin-check"><input type="checkbox" name="purchases_allowed" value="1"' . (!isset($product['purchases_allowed']) || (int)$product['purchases_allowed'] === 1 ? ' checked' : '') . '> Purchases allowed</label><label>Reason<input class="field" name="reason" maxlength="255" placeholder="Shown during an active incident"></label><button class="btn btn-primary btn-small" type="submit">Save status</button></form><div class="admin-plan-list">';
            foreach ($product['commerce_plans'] ?? [] as $plan) echo '<form method="post" action="' . self::e(self::url('/admin/plans/' . (int)$plan['id'])) . '"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><strong>' . self::e((string)$plan['name']) . '</strong><label>Price (cents)<input class="field" type="number" min="0" name="price_cents" value="' . (int)$plan['price_cents'] . '" required></label><label>Sale<input class="field" type="number" min="0" name="sale_price_cents" value="' . self::e((string)($plan['sale_price_cents'] ?? '')) . '"></label><label>Badge<input class="field" maxlength="40" name="badge" value="' . self::e((string)($plan['badge'] ?? '')) . '"></label><label class="admin-check"><input type="checkbox" name="is_active" value="1"' . ((int)$plan['is_active'] === 1 ? ' checked' : '') . '> Active</label><button class="btn btn-outline btn-small" type="submit">Update</button></form>';
            echo '</div>';

            echo '<details class="admin-content-section"><summary>Media <span>' . count($product['media'] ?? []) . '</span></summary><div class="admin-content-list">';
            foreach ($product['media'] ?? [] as $media) {
                echo '<form class="admin-content-form" method="post" action="' . self::e(self::url('/admin/products/' . $productId . '/media/' . (int) $media['id'])) . '">' . self::csrf($csrf)
                    . '<label>Type<select name="media_type"><option value="image"' . ((string)$media['media_type'] === 'image' ? ' selected' : '') . '>Image</option><option value="gif"' . ((string)$media['media_type'] === 'gif' ? ' selected' : '') . '>GIF</option><option value="video"' . ((string)$media['media_type'] === 'video' ? ' selected' : '') . '>Video</option></select></label>'
                    . self::input('URL', 'url', (string) $media['url'], true) . self::input('Thumbnail URL', 'thumbnail_url', (string) ($media['thumbnail_url'] ?? '')) . self::input('Poster URL', 'poster_url', (string) ($media['poster_url'] ?? '')) . self::input('Title', 'title', (string) ($media['title'] ?? '')) . self::input('Alt text', 'alt_text', (string) ($media['alt_text'] ?? '')) . self::numberInput('Order', (int) $media['sort_order'])
                    . self::flags($media) . '<button class="btn btn-outline btn-small" type="submit">Save media</button></form>'
                    . self::deleteForm('/admin/media/' . (int) $media['id'] . '/delete', $csrf, 'Delete media');
            }
            echo '<form class="admin-content-form add-content-form" method="post" action="' . self::e(self::url('/admin/products/' . $productId . '/media')) . '">' . self::csrf($csrf) . '<strong>Add media</strong><label>Type<select name="media_type"><option value="image">Image</option><option value="gif">GIF</option><option value="video">Video</option></select></label>' . self::input('URL', 'url', '', true) . self::input('Thumbnail URL', 'thumbnail_url') . self::input('Poster URL', 'poster_url') . self::input('Title', 'title') . self::input('Alt text', 'alt_text') . self::numberInput('Order', 0) . self::flags(['is_public'=>1,'is_active'=>1]) . '<button class="btn btn-primary btn-small" type="submit">Add media</button></form></div></details>';

            echo '<details class="admin-content-section"><summary>Features <span>' . count($product['feature_categories'] ?? []) . ' categories</span></summary><div class="admin-content-list">';
            foreach ($product['feature_categories'] ?? [] as $category) {
                $categoryId = (int) $category['id'];
                echo '<section class="admin-category"><form class="admin-content-form" method="post" action="' . self::e(self::url('/admin/products/' . $productId . '/feature-categories/' . $categoryId)) . '">' . self::csrf($csrf) . self::input('Category', 'name', (string) $category['name'], true) . self::input('Description', 'description', (string) ($category['description'] ?? '')) . self::numberInput('Order', (int) $category['sort_order']) . '<label class="admin-check"><input type="checkbox" name="is_active" value="1"' . ((int) $category['is_active'] === 1 ? ' checked' : '') . '> Active</label><button class="btn btn-outline btn-small" type="submit">Save category</button></form>' . self::deleteForm('/admin/feature-categories/' . $categoryId . '/delete', $csrf, 'Delete category');
                foreach ($category['features'] ?? [] as $feature) {
                    echo '<form class="admin-content-form admin-feature-form" method="post" action="' . self::e(self::url('/admin/feature-categories/' . $categoryId . '/features/' . (int) $feature['id'])) . '">' . self::csrf($csrf) . self::input('Feature', 'name', (string) $feature['name'], true) . self::input('Description', 'short_description', (string) ($feature['short_description'] ?? '')) . self::numberInput('Order', (int) $feature['sort_order']) . self::featureFlags($feature) . '<button class="btn btn-outline btn-small" type="submit">Save feature</button></form>' . self::deleteForm('/admin/features/' . (int) $feature['id'] . '/delete', $csrf, 'Delete feature');
                }
                echo '<form class="admin-content-form add-content-form" method="post" action="' . self::e(self::url('/admin/feature-categories/' . $categoryId . '/features')) . '">' . self::csrf($csrf) . '<strong>Add feature</strong>' . self::input('Feature', 'name', '', true) . self::input('Description', 'short_description') . self::numberInput('Order', 0) . self::featureFlags(['is_public'=>1,'is_active'=>1,'is_highlighted'=>0]) . '<button class="btn btn-primary btn-small" type="submit">Add feature</button></form></section>';
            }
            echo '<form class="admin-content-form add-content-form" method="post" action="' . self::e(self::url('/admin/products/' . $productId . '/feature-categories')) . '">' . self::csrf($csrf) . '<strong>Add category</strong>' . self::input('Category', 'name', '', true) . self::input('Description', 'description') . self::numberInput('Order', 0) . '<label class="admin-check"><input type="checkbox" name="is_active" value="1" checked> Active</label><button class="btn btn-primary btn-small" type="submit">Add category</button></form></div></details>';

            echo '<details class="admin-content-section"><summary>FAQ <span>' . count($product['faqs'] ?? []) . '</span></summary><div class="admin-content-list">';
            foreach ($product['faqs'] ?? [] as $faq) {
                echo '<form class="admin-content-form" method="post" action="' . self::e(self::url('/admin/products/' . $productId . '/faqs/' . (int) $faq['id'])) . '">' . self::csrf($csrf) . self::input('Question', 'question', (string) $faq['question'], true) . '<label>Answer<textarea class="field" name="answer" required>' . self::e((string) $faq['answer']) . '</textarea></label>' . self::numberInput('Order', (int) $faq['sort_order']) . self::flags($faq) . '<button class="btn btn-outline btn-small" type="submit">Save FAQ</button></form>' . self::deleteForm('/admin/faqs/' . (int) $faq['id'] . '/delete', $csrf, 'Delete FAQ');
            }
            echo '<form class="admin-content-form add-content-form" method="post" action="' . self::e(self::url('/admin/products/' . $productId . '/faqs')) . '">' . self::csrf($csrf) . '<strong>Add FAQ</strong>' . self::input('Question', 'question', '', true) . '<label>Answer<textarea class="field" name="answer" required></textarea></label>' . self::numberInput('Order', 0) . self::flags(['is_public'=>1,'is_active'=>1]) . '<button class="btn btn-primary btn-small" type="submit">Add FAQ</button></form></div></details>';
            echo '</article>';
        }
        if ($data['products'] === []) self::emptyState('package', 'No Enhancements', 'No product is configured.');
        echo '</section><section class="data-panel secondary-panel"><div class="panel-header"><h2>Build versions</h2><span>' . count($data['versions']) . ' builds</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Enhancement</th><th>Component</th><th>Version</th><th>Size</th><th>Minimum launcher</th><th>Released</th><th>Status</th></tr></thead><tbody>';
        foreach ($data['versions'] as $version) echo '<tr><td><strong>' . self::e((string) $version['product_name']) . '</strong></td><td>' . self::e((string) $version['module_name']) . '</td><td class="mono">v' . self::e((string) $version['version']) . '</td><td>' . self::bytes((int) $version['file_size']) . '</td><td class="mono">' . self::e((string) ($version['minimum_launcher_version'] ?: '—')) . '</td><td>' . self::date($version['published_at'] ?? $version['created_at']) . '</td><td>' . self::badge((string) $version['status']) . '</td></tr>';
        if ($data['versions'] === []) self::emptyRow(7, 'No module version has been published.');
        echo '</tbody></table></div><div class="panel-note">Publishing and activation remain managed by the signed server-side module tools.</div></section>';
    }

    private static function payments(array $data): void
    {
        echo '<section class="data-panel"><div class="panel-header"><h2>Orders &amp; payments</h2><span>' . count($data['payments']) . ' records</span></div><div class="table-wrap"><table class="data-table admin-table-wide"><thead><tr><th>Order</th><th>Customer</th><th>Enhancement</th><th>Plan</th><th>Total</th><th>Provider</th><th>Status</th><th>Date</th></tr></thead><tbody>';
        foreach ($data['payments'] as $payment) echo '<tr data-search-row><td class="mono">' . self::e((string)$payment['order_number']) . '</td><td>' . self::e((string)$payment['email']) . '</td><td>' . self::e((string)$payment['product_name_snapshot']) . '</td><td>' . self::e((string)$payment['plan_name_snapshot']) . '</td><td>' . number_format((int)$payment['amount_cents']/100,2,'.',',') . ' ' . self::e((string)$payment['currency']) . '</td><td>' . self::e((string)($payment['provider'] ?? '—')) . '</td><td>' . self::badge((string)$payment['status']) . '</td><td>' . self::date($payment['paid_at'] ?? $payment['created_at']) . '</td></tr>';
        if ($data['payments'] === []) self::emptyRow(8, 'No order has been recorded.');
        echo '</tbody></table></div></section>';
    }

    private static function support(array $data): void
    {
        echo '<section class="data-panel"><div class="panel-header"><h2>Support tickets</h2><span>' . count($data['support_tickets']) . ' tickets</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Reference</th><th>Customer</th><th>Subject</th><th>Category</th><th>Status</th><th>Updated</th></tr></thead><tbody>';
        foreach ($data['support_tickets'] as $ticket) echo '<tr data-search-row><td class="mono">' . self::e((string)$ticket['ticket_number']) . '</td><td>' . self::e((string)$ticket['email']) . '</td><td><strong>' . self::e((string)$ticket['subject']) . '</strong></td><td>' . self::e(ucfirst((string)$ticket['category'])) . '</td><td>' . self::badge((string)$ticket['status']) . '</td><td>' . self::date($ticket['updated_at']) . '</td></tr>';
        if ($data['support_tickets'] === []) self::emptyRow(6, 'No support ticket has been recorded.');
        echo '</tbody></table></div></section>';
    }

    private static function audit(array $data): void
    {
        echo '<section class="data-panel"><div class="table-toolbar"><label>' . self::icon('search') . '<input type="search" placeholder="Search events" data-table-search></label><span>' . count($data['audit']) . ' events</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Actor</th><th>Action</th><th>Target</th><th>IP address</th><th>Date</th></tr></thead><tbody>';
        foreach ($data['audit'] as $entry) echo '<tr data-search-row><td><strong>' . self::e((string) ($entry['actor_email'] ?: 'System')) . '</strong></td><td>' . self::e(self::actionLabel((string) $entry['action'])) . '</td><td>' . self::e((string) $entry['target_type'] . ' #' . (string) $entry['target_id']) . '</td><td class="mono">' . self::e((string) ($entry['ip_address'] ?: '—')) . '</td><td>' . self::date($entry['created_at']) . '</td></tr>';
        if ($data['audit'] === []) self::emptyRow(5, 'No administrative event is available.');
        echo '</tbody></table></div></section>';
    }

    private static function csrf(string $token): string { return '<input type="hidden" name="csrf" value="' . self::e($token) . '">'; }
    private static function input(string $label, string $name, string $value = '', bool $required = false): string { return '<label>' . self::e($label) . '<input class="field" name="' . self::e($name) . '" value="' . self::e($value) . '"' . ($required ? ' required' : '') . '></label>'; }
    private static function numberInput(string $label, int $value): string { return '<label>' . self::e($label) . '<input class="field" type="number" name="sort_order" value="' . $value . '"></label>'; }
    private static function flags(array $row): string { return '<label class="admin-check"><input type="checkbox" name="is_public" value="1"' . ((int) ($row['is_public'] ?? 0) === 1 ? ' checked' : '') . '> Public</label><label class="admin-check"><input type="checkbox" name="is_active" value="1"' . ((int) ($row['is_active'] ?? 0) === 1 ? ' checked' : '') . '> Active</label>'; }
    private static function featureFlags(array $row): string { return self::flags($row) . '<label class="admin-check"><input type="checkbox" name="is_highlighted" value="1"' . ((int) ($row['is_highlighted'] ?? 0) === 1 ? ' checked' : '') . '> Highlight</label>'; }
    private static function deleteForm(string $path, string $csrf, string $label): string { return '<form class="admin-delete-form" method="post" action="' . self::e(self::url($path)) . '" data-confirm="Permanently delete this content item?">' . self::csrf($csrf) . '<button class="btn btn-ghost btn-small danger" type="submit">' . self::e($label) . '</button></form>'; }

    private static function emptyState(string $icon, string $title, string $description): void { echo '<section class="data-panel empty-state"><div class="auth-icon">' . self::icon($icon) . '</div><h2>' . self::e($title) . '</h2><p>' . self::e($description) . '</p></section>'; }
    private static function flash(?array $flash): void { if ($flash === null) return; $success = ($flash['type'] ?? '') === 'success'; echo '<div class="toast ' . ($success ? 'toast-success' : 'toast-error') . '" role="status">' . self::icon($success ? 'check' : 'alert') . '<div><strong>' . ($success ? 'Action completed' : 'Action failed') . '</strong><span>' . self::e((string) ($flash['message'] ?? '')) . '</span>'; if (is_string($flash['key'] ?? null) && $flash['key'] !== '') echo '<code data-copy-value="' . self::e($flash['key']) . '">' . self::e($flash['key']) . '</code><button class="btn btn-outline btn-small" type="button" data-copy>Copy key</button>'; echo '</div><button type="button" data-dismiss aria-label="Close">×</button></div>'; }
    private static function header(string $title): void { header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#13111a"><title>' . self::e($title) . ' — Pericles Administration</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&amp;family=Outfit:wght@500;600;700&amp;family=Roboto+Mono:wght@400;500;600&amp;display=swap" rel="stylesheet"><link rel="stylesheet" href="' . self::e(self::url('/assets/pericles.css?v=20260830-1')) . '"><script defer src="' . self::e(self::url('/assets/pericles.js?v=20260830-1')) . '"></script></head><body class="dashboard-page new-dashboard-page">'; }
    private static function userName(array $user): string { return trim((string) ($user['display_name'] ?? '')) ?: (string) explode('@', (string) $user['email'])[0]; }
    private static function metric(string $label, string $value, string $meta): string { return '<article><span>' . self::e($label) . '</span><strong>' . self::e($value) . '</strong><small>' . self::e($meta) . '</small></article>'; }
    private static function badge(string $status): string { $key = strtolower($status); return '<span class="status-badge status-' . self::e($key) . '"><i></i>' . self::e(ucfirst($key)) . '</span>'; }
    private static function brand(string $path): string { return '<a class="brand" href="' . self::e(self::url($path)) . '"><b>P</b><span>Pericles <small>Admin</small></span></a>'; }
    private static function emptyRow(int $span, string $text): void { echo '<tr><td class="empty-cell" colspan="' . $span . '">' . self::e($text) . '</td></tr>'; }
    private static function date(mixed $value): string { if (!is_string($value) || trim($value) === '') return '—'; try { return (new \DateTimeImmutable($value))->format('M j, Y H:i'); } catch (\Throwable) { return '—'; } }
    private static function bytes(int $bytes): string { if ($bytes <= 0) return '—'; return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format($bytes / 1024, 1) . ' KB'; }
    private static function actionLabel(string $action): string { return ['license.assigned' => 'License assigned', 'subscription.unbound' => 'HWID reset', 'subscription.deleted' => 'License removed', 'user.status_changed' => 'Account status changed', 'user.role_changed' => 'Account role changed'][$action] ?? $action; }
    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private static function url(string $path): string { $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')); $base = rtrim(str_replace('\\', '/', dirname($script)), '/.'); return ($base === '' ? '' : $base) . '/' . ltrim($path, '/'); }

    private static function icon(string $name): string
    {
        $p = ['activity'=>'<path d="M4 13h3l2-6 4 12 2-6h5"/>','alert'=>'<path d="M12 9v4m0 4h.01M10.3 3.9 2.2 18a2 2 0 0 0 1.7 3h16.2a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>','arrow-left'=>'<path d="M19 12H5m5 5-5-5 5-5"/>','check'=>'<path d="m5 12 4 4L19 6"/>','grid'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>','headphones'=>'<path d="M4 14v-2a8 8 0 0 1 16 0v2M4 14h3v6H5a1 1 0 0 1-1-1v-5Zm16 0h-3v6h2a1 1 0 0 0 1-1v-5Z"/>','key'=>'<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8m-3 3 3 3m-6 0 3 3"/>','menu'=>'<path d="M4 7h16M4 12h16M4 17h16"/>','package'=>'<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>','receipt'=>'<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 8h6m-6 4h6"/>','search'=>'<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>','settings'=>'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6 1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>','users'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>'];
        return '<svg class="icon icon-' . self::e($name) . '" viewBox="0 0 24 24" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
    }
}
