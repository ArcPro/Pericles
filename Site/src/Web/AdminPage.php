<?php

declare(strict_types=1);

namespace Pericles\Web;

final class AdminPage
{
    public static function render(string $email, array $data, string $csrfToken, ?array $flash = null): never
    {
        $permissions = $data['permissions'];
        $canManageUsers = in_array('users.manage', $permissions, true);
        $canManageLicenses = in_array('licenses.manage', $permissions, true);
        $canManageDevices = in_array('devices.manage', $permissions, true);
        $canViewAudit = in_array('audit.view', $permissions, true);
        $initial = strtoupper(substr($email, 0, 1)) ?: 'A';
        self::header();
        echo '<div class="dashboard-shell admin-shell"><aside class="dashboard-sidebar"><a class="brand dashboard-brand" href="' . self::e(self::url('/')) . '">' . self::logo() . '<span>PERICLES</span><small>CONTROL</small></a><nav class="dashboard-nav"><p>ADMINISTRATION</p><a class="active" href="#overview">' . self::icon('grid') . '<span>Overview</span></a><a href="#users">' . self::icon('user') . '<span>Users</span><b>' . count($data['users']) . '</b></a><a href="#licenses">' . self::icon('key') . '<span>Licenses & HWID</span></a>' . ($canViewAudit ? '<a href="#audit">' . self::icon('clock') . '<span>Audit log</span></a>' : '') . '<p>NAVIGATION</p><a href="' . self::e(self::url('/account')) . '">' . self::icon('arrow-left') . '<span>Player workspace</span></a></nav>'
            . '<div class="sidebar-bottom"><div class="admin-role-card"><span class="avatar">' . self::e($initial) . '</span><p><strong>' . self::e((string) $data['role']['name']) . '</strong><small>' . self::e($email) . '</small></p></div><form method="post" action="' . self::e(self::url('/logout')) . '"><input type="hidden" name="csrf" value="' . self::e($csrfToken) . '"><button type="submit">' . self::icon('logout') . '<span>Sign out</span></button></form></div></aside>'
            . '<div class="dashboard-area"><header class="dashboard-topbar"><button class="mobile-menu" type="button" data-sidebar-toggle aria-label="Open menu">' . self::icon('menu') . '</button><div class="breadcrumbs"><span>Pericles</span>' . self::icon('chevron') . '<strong>Control Center</strong></div><div class="topbar-actions"><div class="admin-search">' . self::icon('search') . '<input type="search" placeholder="Filter users…" data-admin-search></div><span class="role-chip role-admin">' . self::e((string) $data['role']['name']) . '</span><span class="avatar">' . self::e($initial) . '</span></div></header><main class="dashboard-main admin-main" id="overview">';

        self::flash($flash);
        echo '<section class="dashboard-welcome"><div><p class="section-label">CONTROL CENTER</p><h1>Pericles Administration</h1><p>Manage accounts, licenses, devices, and permissions.</p></div><div class="service-health"><span class="status-dot"></span><p><small>ACCESS LEVEL</small><strong>' . self::e((string) $data['role']['name']) . '</strong></p><time>' . count($permissions) . ' permissions</time></div></section>'
            . '<section class="admin-metric-grid' . ($canManageLicenses ? '' : ' compact') . '"><article><span>' . self::icon('user') . '</span><div><small>USERS</small><strong>' . (int) $data['metrics']['users'] . '</strong></div></article><article><span>' . self::icon('library') . '</span><div><small>ACTIVE LICENSES</small><strong>' . (int) $data['metrics']['active_subscriptions'] . '</strong></div></article><article><span>' . self::icon('monitor') . '</span><div><small>LINKED PRODUCTS</small><strong>' . (int) $data['metrics']['bound_products'] . '</strong></div></article>' . ($canManageLicenses ? '<article><span>' . self::icon('key') . '</span><div><small>AVAILABLE KEYS</small><strong>' . (int) $data['metrics']['unused_keys'] . '</strong></div></article>' : '') . '</section>';

        echo '<section class="dashboard-panel admin-panel" id="users"><div class="panel-heading"><div><p class="section-label">ACCOUNTS</p><h2>Users</h2></div><span>LATEST 100 ACCOUNTS</span></div><div class="admin-table admin-users-table"><div class="admin-table-head"><span>USER</span><span>ROLE</span><span>PRODUCTS</span><span>DEVICES</span><span>STATUS</span><span>ACTIONS</span></div>';
        foreach ($data['users'] as $user) {
            echo self::userRow($user, $csrfToken, $canManageUsers);
        }
        echo '</div></section>';

        echo '<section class="admin-license-layout" id="licenses">';
        if ($canManageLicenses) {
            echo '<section class="dashboard-panel admin-create-card"><div class="panel-heading"><div><p class="section-label">ASSIGNMENT</p><h2>Create a license</h2></div><span class="panel-icon">' . self::icon('key') . '</span></div><p>Generate a unique key and immediately activate the product for the selected account.</p><form method="post" action="' . self::e(self::url('/admin/licenses/assign')) . '"><input type="hidden" name="csrf" value="' . self::e($csrfToken) . '"><label for="license-user">USER ACCOUNT</label><select id="license-user" name="user_id" required><option value="">Select an account</option>';
            foreach ($data['users'] as $user) {
                if ((string) $user['status'] === 'active') {
                    echo '<option value="' . (int) $user['id'] . '">' . self::e((string) $user['email']) . '</option>';
                }
            }
            echo '</select><label for="license-plan">PRODUCT AND DURATION</label><select id="license-plan" name="plan_ref" required><option value="">Select a plan</option>';
            foreach ($data['plans'] as $plan) {
                echo '<option value="' . self::e((string) $plan['product_slug'] . ':' . (string) $plan['plan_slug']) . '">' . self::e((string) $plan['product_name'] . ' · ' . (string) $plan['plan_name']) . '</option>';
            }
            echo '</select><button class="button button-primary" type="submit">' . self::icon('key') . ' Generate and assign</button></form></section>';
        }
        echo '<section class="dashboard-panel admin-panel subscriptions-panel"><div class="panel-heading"><div><p class="section-label">LICENSES</p><h2>Assigned products</h2></div><span>' . count($data['subscriptions']) . ' RECORDS</span></div><div class="admin-table admin-subscriptions-table"><div class="admin-table-head"><span>ACCOUNT / PRODUCT</span><span>STATUS</span><span>EXPIRATION</span><span>DEVICE / HWID</span><span>ACTION</span></div>';
        foreach ($data['subscriptions'] as $subscription) {
            echo self::subscriptionRow($subscription, $csrfToken, $canManageDevices, $canManageLicenses);
        }
        echo '</div></section></section>';

        if ($canViewAudit) {
            echo '<section class="dashboard-panel admin-panel audit-panel" id="audit"><div class="panel-heading"><div><p class="section-label">TRACEABILITY</p><h2>Audit log</h2></div><span>LATEST 30 ACTIONS</span></div><div class="audit-list">';
            if ($data['audit'] === []) {
                echo '<div class="compact-empty">No administrative actions recorded.</div>';
            }
            foreach ($data['audit'] as $entry) {
                $meta = json_decode((string) ($entry['metadata_json'] ?? ''), true);
                echo '<article><span class="audit-icon">' . self::icon('clock') . '</span><div><strong>' . self::e(self::actionLabel((string) $entry['action'])) . '</strong><small>by ' . self::e((string) ($entry['actor_email'] ?? 'system')) . ' · ' . self::e((string) $entry['target_type']) . ' #' . self::e((string) $entry['target_id']) . '</small>' . (is_array($meta) && isset($meta['email']) ? '<p>' . self::e((string) $meta['email']) . '</p>' : '') . '</div><time>' . self::e(self::formatDate((string) $entry['created_at'])) . '</time></article>';
            }
            echo '</div></section>';
        }

        echo '<footer class="dashboard-footer"><span>Pericles Control Center · audited access</span><span><i class="status-dot"></i> Server-side permission checks</span></footer></main></div><div class="sidebar-scrim" data-sidebar-toggle></div></div></body></html>';
        exit;
    }

    private static function userRow(array $user, string $csrf, bool $canManage): string
    {
        $name = trim((string) ($user['display_name'] ?? '')) ?: explode('@', (string) $user['email'])[0];
        $initial = strtoupper(substr($name, 0, 1));
        $status = (string) $user['status'];
        $role = (string) $user['role_slug'];
        $actions = '<span class="muted-action">Read only</span>';
        if ($canManage) {
            $actions = '<details class="row-actions admin-row-actions"><summary class="row-menu">' . self::icon('more') . '</summary><div><strong>Manage account</strong><form method="post" action="' . self::e(self::url('/admin/users/' . (int) $user['id'] . '/role')) . '"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><label>Role<select name="role"><option value="player"' . ($role === 'player' ? ' selected' : '') . '>Player</option><option value="moderator"' . ($role === 'moderator' ? ' selected' : '') . '>Moderator</option><option value="admin"' . ($role === 'admin' ? ' selected' : '') . '>Administrator</option></select></label><button type="submit">Save role</button></form><form method="post" action="' . self::e(self::url('/admin/users/' . (int) $user['id'] . '/status')) . '" data-confirm="Change this account status?"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><input type="hidden" name="status" value="' . ($status === 'active' ? 'disabled' : 'active') . '"><button type="submit" class="' . ($status === 'active' ? 'danger-action' : '') . '">' . ($status === 'active' ? 'Disable account' : 'Reactivate account') . '</button></form></div></details>';
        }
        return '<div class="admin-table-row" data-admin-user="' . self::e(strtolower((string) $user['email'] . ' ' . $name)) . '"><div class="admin-user"><span class="avatar">' . self::e($initial) . '</span><div><strong>' . self::e($name) . '</strong><small>' . self::e((string) $user['email']) . '</small></div></div><div><span class="role-chip role-' . self::e($role) . '">' . self::e((string) $user['role_name']) . '</span></div><div class="admin-count">' . (int) $user['product_count'] . '</div><div class="admin-count">' . (int) $user['device_count'] . '</div><div><span class="state-badge state-' . ($status === 'active' ? 'active' : 'expired') . '"><i></i>' . ($status === 'active' ? 'Active' : 'Disabled') . '</span></div><div>' . $actions . '</div></div>';
    }

    private static function subscriptionRow(
        array $subscription,
        string $csrf,
        bool $canManageDevices,
        bool $canManageLicenses
    ): string {
        $bound = $subscription['bound_device_id'] !== null;
        $expiry = $subscription['expires_at'] === null ? 'Lifetime' : (new \DateTimeImmutable((string) $subscription['expires_at']))->format('M j, Y');
        $actions = [];
        if ($bound && $canManageDevices) {
            $actions[] = '<form method="post" action="' . self::e(self::url('/admin/subscriptions/' . (int) $subscription['id'] . '/unbind')) . '" data-confirm="Unlink this license from the HWID?"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><button class="button button-secondary button-small" type="submit">Unlink</button></form>';
        }
        if ($canManageLicenses) {
            $actions[] = '<form method="post" action="' . self::e(self::url('/admin/subscriptions/' . (int) $subscription['id'] . '/delete')) . '" data-confirm="Permanently remove this license from the account? This cannot be undone."><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><button class="button button-secondary button-small danger-action" type="submit">Delete</button></form>';
        }
        $action = $actions === [] ? '<span class="muted-action">—</span>' : '<div class="admin-row-actions">' . implode('', $actions) . '</div>';
        return '<div class="admin-table-row"><div class="admin-product"><img src="' . self::e(self::url('/assets/products/' . (string) $subscription['product_slug'] . '.jpg')) . '" alt=""><div><strong>' . self::e((string) $subscription['product_name']) . '</strong><small>' . self::e((string) $subscription['email']) . '</small></div></div><div><span class="state-badge state-' . self::e((string) $subscription['status']) . '"><i></i>' . self::e(ucfirst((string) $subscription['status'])) . '</span></div><div class="admin-value">' . self::e($expiry) . '</div><div class="device-identity"><strong>' . ($bound ? self::e((string) ($subscription['device_name'] ?: 'Device')) : 'Not linked') . '</strong><code>' . ($bound ? self::e(substr((string) $subscription['device_id'], 0, 12)) . '••••' : '—') . '</code></div><div>' . $action . '</div></div>';
    }

    private static function flash(?array $flash): void
    {
        if ($flash === null) return;
        $success = ($flash['type'] ?? '') === 'success';
        echo '<div class="toast ' . ($success ? 'toast-success' : 'toast-error') . '" role="status">' . self::icon($success ? 'check' : 'alert') . '<div><strong>' . ($success ? 'Action completed' : 'Action failed') . '</strong><span>' . self::e((string) ($flash['message'] ?? '')) . '</span>';
        if (is_string($flash['key'] ?? null) && $flash['key'] !== '') {
            echo '<code class="generated-key" data-copy-value="' . self::e($flash['key']) . '">' . self::e($flash['key']) . '</code><button class="copy-key" type="button" data-copy>Copy key</button>';
        }
        echo '</div><button type="button" data-dismiss aria-label="Close">×</button></div>';
    }

    private static function header(): void
    {
        header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#09090b"><title>Administration · Pericles</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&amp;family=Space+Grotesk:wght@500;600;700&amp;display=swap" rel="stylesheet"><link rel="stylesheet" href="' . self::e(self::url('/assets/pericles.css')) . '"><script defer src="' . self::e(self::url('/assets/pericles.js')) . '"></script></head><body class="dashboard-page admin-page">';
    }

    private static function actionLabel(string $action): string
    {
        return ['license.assigned' => 'License assigned', 'subscription.unbound' => 'License unlinked', 'subscription.deleted' => 'License removed', 'user.status_changed' => 'Status changed', 'user.role_changed' => 'Role changed'][$action] ?? $action;
    }

    private static function formatDate(string $value): string { return (new \DateTimeImmutable($value))->format('M j, Y H:i'); }
    private static function logo(): string { return '<svg class="logo-mark" viewBox="0 0 32 32" aria-hidden="true"><path d="M7.5 5.5h10.8c4.4 0 7.2 2.5 7.2 6.5 0 4.1-2.8 6.6-7.2 6.6h-5.1v7.9H7.5v-21Zm5.7 4.6V14h4.2c1.5 0 2.4-.7 2.4-2s-.9-1.9-2.4-1.9h-4.2Z" fill="currentColor"/><path d="M18.7 20.5h6.1v6h-6.1z" fill="currentColor" opacity=".45"/></svg>'; }
    private static function icon(string $name): string { $p = ['alert'=>'<path d="M12 9v4m0 4h.01M10.3 3.9 2.2 18a2 2 0 0 0 1.7 3h16.2a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>','arrow-left'=>'<path d="M19 12H5m5 5-5-5 5-5"/>','check'=>'<path d="m5 12 4 4L19 6"/>','chevron'=>'<path d="m9 18 6-6-6-6"/>','clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>','grid'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>','key'=>'<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8m-3 3 3 3m-6 0 3 3"/>','library'=>'<path d="M4 19V5m5 14V5m5 14V5m5 14V5M2 21h20M2 3h20"/>','logout'=>'<path d="M10 17l5-5-5-5m5 5H3m11-9h6a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1h-6"/>','menu'=>'<path d="M4 7h16M4 12h16M4 17h16"/>','monitor'=>'<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8m-4-4v4"/>','more'=>'<circle cx="5" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1" fill="currentColor" stroke="none"/>','search'=>'<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>','user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>']; return '<svg class="icon icon-' . self::e($name) . '" viewBox="0 0 24 24" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>'; }
    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private static function url(string $path): string { $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')); $base = rtrim(str_replace('\\', '/', dirname($script)), '/.'); return ($base === '' ? '' : $base) . '/' . ltrim($path, '/'); }
}
