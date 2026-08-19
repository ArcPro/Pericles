<?php

declare(strict_types=1);

namespace Pericles\Web;

final class AccountPage
{
    public static function landing(): never
    {
        self::header('Platform', 'landing-page');
        echo '<div class="site-shell">'
            . '<header class="site-header"><a class="brand" href="' . self::escape(self::url('/')) . '" aria-label="Pericles — Home">'
            . self::logo() . '<span>PERICLES</span><small>PLATFORM</small></a>'
            . '<nav class="site-nav" aria-label="Main navigation"><a class="active" href="#products">Products</a>'
            . '<a href="#platform">Platform</a><a href="#status">Status</a></nav>'
            . '<div class="header-actions"><a class="button button-quiet button-small" href="' . self::escape(self::url('/login')) . '">Sign in</a>'
            . '<a class="button button-secondary button-small" href="' . self::escape(self::url('/register')) . '">Create account</a>'
            . '<a class="button button-primary button-small" href="#products">Browse products</a></div></header>'
            . '<main><section class="hero" aria-labelledby="hero-title"><div class="hero-copy">'
            . '<div class="eyebrow"><span class="status-dot"></span>All services operational</div>'
            . '<p class="hero-kicker">PERICLES / DESKTOP PLATFORM</p>'
            . '<h1 id="hero-title">Your products.<br><span>One workspace.</span></h1>'
            . '<p class="hero-lead">Access your software, manage your licenses, and keep your environment up to date from a platform built for desktop.</p>'
            . '<div class="hero-actions"><a class="button button-primary" href="#products">Explore products ' . self::icon('arrow') . '</a>'
            . '<a class="button button-secondary" href="' . self::escape(self::url('/login')) . '">Open my workspace</a></div>'
            . '<div class="hero-meta"><span>' . self::icon('windows') . ' Windows 10/11</span><span>Version 0.1.0</span><span>Automatic updates</span></div></div>'
            . '<div class="product-preview" aria-label="Pericles application preview"><div class="preview-titlebar"><span class="preview-mark">' . self::logo(false) . '</span><span>Pericles</span><div class="window-controls"><i></i><i></i><i></i></div></div>'
            . '<div class="preview-body"><aside class="preview-sidebar"><span class="preview-nav active">' . self::icon('grid') . '</span><span class="preview-nav">' . self::icon('library') . '</span><span class="preview-nav">' . self::icon('download') . '</span><span class="preview-spacer"></span><span class="preview-avatar">P</span></aside>'
            . '<div class="preview-content"><div class="preview-toolbar"><div><small>LIBRARY</small><strong>My products</strong></div><span class="preview-online"><i></i> Online</span></div>'
            . '<div class="preview-feature"><img class="game-emblem product-thumb" src="' . self::escape(self::url('/assets/products/deadlock.jpg')) . '" alt=""><div><span class="tag">READY TO LAUNCH</span><h3>Deadlock</h3><p>Module installed · Up to date</p></div><span class="preview-launch">Launch ' . self::icon('play') . '</span></div>'
            . '<div class="preview-list"><div><img class="game-emblem product-thumb" src="' . self::escape(self::url('/assets/products/counter-strike-2.jpg')) . '" alt=""><div><strong>Counter-Strike 2</strong><small>Update available</small></div><span class="list-status">Update</span></div>'
            . '<div><span class="game-emblem muted-emblem">+</span><div><strong>Discover products</strong><small>Browse the Pericles catalog</small></div>' . self::icon('chevron') . '</div></div></div></div></div></section>'
            . '<section class="info-strip" id="status" aria-label="Platform information"><div><span class="status-dot"></span><p><small>SERVICE STATUS</small><strong>All systems operational</strong></p></div>'
            . '<div><span class="strip-icon">' . self::icon('shield') . '</span><p><small>CURRENT VERSION</small><strong>Pericles Desktop 0.1.0</strong></p></div>'
            . '<div><span class="strip-icon">' . self::icon('clock') . '</span><p><small>LAST CHECK</small><strong>Just now</strong></p></div></section>'
            . '<section class="catalog-section" id="products"><div class="section-heading"><div><p class="section-label">CATALOG</p><h2>Available products</h2><p>Your Pericles software library, centralized and secure.</p></div><span class="catalog-count">02 PRODUCTS</span></div>'
            . '<div class="catalog-grid">' . self::productCard('deadlock', 'Deadlock', 'Advanced tools for a controlled gaming experience.', 'Available', true)
            . self::productCard('counter-strike-2', 'Counter-Strike 2', 'A feature suite designed for competitive play.', 'Available', false) . '</div></section>'
            . '<section class="platform-section" id="platform"><div><p class="section-label">THE PLATFORM</p><h2>Everything that matters,<br>without distraction.</h2></div>'
            . '<div class="platform-features"><article><span>01</span><div>' . self::icon('key') . '<h3>Centralized licenses</h3><p>Activate and review your products from your account.</p></div></article>'
            . '<article><span>02</span><div>' . self::icon('refresh') . '<h3>Always up to date</h3><p>The latest versions are distributed automatically.</p></div></article>'
            . '<article><span>03</span><div>' . self::icon('monitor') . '<h3>Device-bound security</h3><p>A secure local identity protects your access.</p></div></article></div></section></main>'
            . '<footer class="site-footer"><a class="brand" href="' . self::escape(self::url('/')) . '">' . self::logo() . '<span>PERICLES</span></a><p>© 2026 Pericles. Software built for your games.</p><div><a href="#status">Service status</a><a href="' . self::escape(self::url('/login')) . '">My account</a></div></footer></div>';
        self::footer();
    }

    public static function login(string $csrfToken, ?string $error = null): never
    {
        self::header('Sign in', 'auth-page');
        echo '<main class="auth-shell"><section class="auth-product"><a class="brand auth-brand" href="' . self::escape(self::url('/')) . '">' . self::logo() . '<span>PERICLES</span><small>PLATFORM</small></a>'
            . '<div class="auth-product-copy"><p class="section-label">MEMBER AREA</p><h1>Your library,<br>always within reach.</h1><p>Manage your licenses, products, and devices from one secure workspace.</p></div>'
            . '<div class="auth-product-card"><div class="auth-card-heading"><span class="game-emblem game-emblem-deadlock">P</span><div><strong>Pericles Desktop</strong><small>Ready to start</small></div><span class="online-pill"><i></i> Online</span></div><div class="auth-card-progress"><span></span></div><div class="auth-card-footer"><span>Version 0.1.0</span><span>Last check: now</span></div></div>'
            . '<div class="auth-status"><span class="status-dot"></span><span>All services operational</span><small>STATUS / 00:04</small></div></section>'
            . '<section class="auth-panel"><div class="auth-panel-inner"><a class="mobile-brand brand" href="' . self::escape(self::url('/')) . '">' . self::logo() . '<span>PERICLES</span></a>'
            . '<div class="auth-heading"><p class="section-label">SECURE SIGN IN</p><h2>Welcome back.</h2><p>Enter your credentials to access your workspace.</p></div>';
        if ($error !== null) {
            echo '<div class="notice notice-error" role="alert">' . self::icon('alert') . '<div><strong>Unable to sign in</strong><span>' . self::escape($error) . '</span></div></div>';
        }
        echo '<form class="auth-form" method="post" action="' . self::escape(self::url('/login')) . '"><input type="hidden" name="csrf" value="' . self::escape($csrfToken) . '">'
            . '<label for="email">Email address</label><div class="input-wrap">' . self::icon('mail') . '<input id="email" type="email" name="email" required autocomplete="username" placeholder="you@example.com"></div>'
            . '<div class="label-row"><label for="password">Password</label><a href="#support" title="Contact support to reset your access">Forgot your access?</a></div>'
            . '<div class="input-wrap">' . self::icon('lock') . '<input id="password" type="password" name="password" required autocomplete="current-password" placeholder="Your password"><button class="password-toggle" type="button" data-password-toggle aria-label="Show password">' . self::icon('eye') . '</button></div>'
            . '<button class="button button-primary auth-submit" type="submit">Sign in ' . self::icon('arrow') . '</button></form>'
            . '<div class="auth-security">' . self::icon('shield') . '<p><strong>Protected connection</strong><span>Your information is encrypted in transit.</span></p></div>'
            . '<p class="auth-help">No account yet? <a href="' . self::escape(self::url('/register')) . '">Create one</a></p>'
            . '<p class="auth-help" id="support">Need help? <a href="mailto:support@pericles.gg">Contact support</a></p></div></section></main>';
        self::footer();
    }

    public static function register(string $csrfToken, ?string $error = null): never
    {
        self::header('Create account', 'auth-page');
        echo '<main class="auth-shell"><section class="auth-product"><a class="brand auth-brand" href="' . self::escape(self::url('/')) . '">' . self::logo() . '<span>PERICLES</span><small>PLATFORM</small></a>'
            . '<div class="auth-product-copy"><p class="section-label">MEMBER AREA</p><h1>Create your<br>workspace.</h1><p>One account for your licenses, products, and devices.</p></div>'
            . '<div class="auth-product-card"><div class="auth-card-heading"><span class="game-emblem game-emblem-deadlock">P</span><div><strong>Pericles Desktop</strong><small>Ready to start</small></div><span class="online-pill"><i></i> Online</span></div><div class="auth-card-progress"><span></span></div><div class="auth-card-footer"><span>Version 0.1.0</span><span>Last check: now</span></div></div>'
            . '<div class="auth-status"><span class="status-dot"></span><span>All services operational</span><small>STATUS / 00:04</small></div></section>'
            . '<section class="auth-panel"><div class="auth-panel-inner"><a class="mobile-brand brand" href="' . self::escape(self::url('/')) . '">' . self::logo() . '<span>PERICLES</span></a>'
            . '<div class="auth-heading"><p class="section-label">NEW ACCOUNT</p><h2>Get started.</h2><p>Create your credentials to access your workspace.</p></div>';
        if ($error !== null) {
            echo '<div class="notice notice-error" role="alert">' . self::icon('alert') . '<div><strong>Unable to create account</strong><span>' . self::escape($error) . '</span></div></div>';
        }
        echo '<form class="auth-form" method="post" action="' . self::escape(self::url('/register')) . '"><input type="hidden" name="csrf" value="' . self::escape($csrfToken) . '">'
            . '<label for="email">Email address</label><div class="input-wrap">' . self::icon('mail') . '<input id="email" type="email" name="email" required autocomplete="username" maxlength="254" placeholder="you@example.com"></div>'
            . '<div class="label-row"><label for="display-name">Display name</label><span>Optional</span></div>'
            . '<div class="input-wrap">' . self::icon('user') . '<input id="display-name" type="text" name="display_name" maxlength="80" autocomplete="nickname" placeholder="How we should greet you"></div>'
            . '<div class="label-row"><label for="password">Password</label><span>At least 10 characters</span></div>'
            . '<div class="input-wrap">' . self::icon('lock') . '<input id="password" type="password" name="password" required minlength="10" maxlength="1024" autocomplete="new-password" placeholder="Choose a password"><button class="password-toggle" type="button" data-password-toggle aria-label="Show password">' . self::icon('eye') . '</button></div>'
            . '<div class="label-row"><label for="password-confirm">Confirm password</label></div>'
            . '<div class="input-wrap">' . self::icon('lock') . '<input id="password-confirm" type="password" name="password_confirm" required minlength="10" maxlength="1024" autocomplete="new-password" placeholder="Repeat your password"><button class="password-toggle" type="button" data-password-toggle aria-label="Show password">' . self::icon('eye') . '</button></div>'
            . '<button class="button button-primary auth-submit" type="submit">Create account ' . self::icon('arrow') . '</button></form>'
            . '<div class="auth-security">' . self::icon('shield') . '<p><strong>Protected connection</strong><span>Your information is encrypted in transit.</span></p></div>'
            . '<p class="auth-help">Already have an account? <a href="' . self::escape(self::url('/login')) . '">Sign in</a></p></div></section></main>';
        self::footer();
    }

    public static function account(
        string $email,
        array $subscriptions,
        array $devices,
        array $profile,
        array $role,
        bool $canAccessAdmin,
        string $csrfToken,
        ?string $message = null,
        ?string $error = null
    ): never {
        $activeCount = count(array_filter($subscriptions, static fn (array $item): bool => $item['status'] === 'active'));
        $boundCount = count(array_filter($subscriptions, static fn (array $item): bool => $item['device_binding']['state'] !== 'unbound'));
        $displayName = trim((string) ($profile['display_name'] ?? '')) ?: self::displayName($email);
        $initial = strtoupper(substr($displayName, 0, 1)) ?: 'P';
        self::header('My workspace', 'dashboard-page');
        echo '<div class="dashboard-shell"><aside class="dashboard-sidebar"><a class="brand dashboard-brand" href="' . self::escape(self::url('/')) . '">' . self::logo() . '<span>PERICLES</span><small>PLATFORM</small></a>'
            . '<nav class="dashboard-nav" aria-label="Member area"><p>WORKSPACE</p><a class="active" href="#overview">' . self::icon('grid') . '<span>Overview</span></a><a href="#products">' . self::icon('library') . '<span>My products</span><b>' . count($subscriptions) . '</b></a><a href="#activation">' . self::icon('key') . '<span>Activate a key</span></a><a href="#devices">' . self::icon('monitor') . '<span>My devices</span><b>' . count($devices) . '</b></a><p>ACCOUNT</p><a href="#profile">' . self::icon('user') . '<span>Profile</span></a><a href="#security">' . self::icon('shield') . '<span>Security</span></a>'
            . ($canAccessAdmin ? '<p>MANAGEMENT</p><a href="' . self::escape(self::url('/admin')) . '">' . self::icon('shield') . '<span>Administration</span></a>' : '') . '</nav>'
            . '<div class="sidebar-bottom"><div class="sidebar-version"><span>' . self::icon('download') . '</span><p><strong>Pericles Desktop</strong><small>Version 0.1.0</small></p><i class="status-dot"></i></div><form method="post" action="' . self::escape(self::url('/logout')) . '"><input type="hidden" name="csrf" value="' . self::escape($csrfToken) . '"><button type="submit">' . self::icon('logout') . '<span>Sign out</span></button></form></div></aside>'
            . '<div class="dashboard-area"><header class="dashboard-topbar"><button class="mobile-menu" type="button" data-sidebar-toggle aria-label="Open menu">' . self::icon('menu') . '</button><div class="breadcrumbs"><span>Pericles</span>' . self::icon('chevron') . '<strong>Overview</strong></div>'
            . '<div class="topbar-actions"><button class="topbar-search" type="button" data-command-open>' . self::icon('search') . '<span>Search</span><kbd>Ctrl K</kbd></button><details class="notification-menu"><summary class="icon-button" title="Notifications">' . self::icon('bell') . '</summary><div><strong>Notifications</strong><p>You are up to date. No unread messages.</p></div></details>'
            . '<details class="user-menu"><summary><span class="avatar">' . self::escape($initial) . '</span><span class="user-name">' . self::escape($displayName) . '</span>' . self::icon('chevron-down') . '</summary><div class="user-dropdown"><small>SIGNED IN AS</small><strong>' . self::escape($email) . '</strong><span class="role-chip">' . self::escape((string) $role['name']) . '</span><hr><a href="#profile">' . self::icon('user') . ' My profile</a>' . ($canAccessAdmin ? '<a href="' . self::escape(self::url('/admin')) . '">' . self::icon('shield') . ' Administration</a>' : '') . '<form method="post" action="' . self::escape(self::url('/logout')) . '"><input type="hidden" name="csrf" value="' . self::escape($csrfToken) . '"><button type="submit">' . self::icon('logout') . ' Sign out</button></form></div></details></div></header><main class="dashboard-main" id="overview">';
        self::toast($message, $error);
        echo '<section class="dashboard-welcome"><div><p class="section-label">OVERVIEW</p><h1>Hello, ' . self::escape($displayName) . '.</h1><p>Here is the current state of your Pericles workspace.</p></div><div class="service-health"><span class="status-dot"></span><p><small>SERVICE STATUS</small><strong>Operational</strong></p><time>' . date('H:i') . '</time></div></section>'
            . '<section class="metric-grid" aria-label="Account summary"><article><span class="metric-icon purple">' . self::icon('library') . '</span><div><small>ACTIVE PRODUCTS</small><strong>' . $activeCount . '</strong><p>of ' . count($subscriptions) . ' in your workspace</p></div></article><article><span class="metric-icon">' . self::icon('monitor') . '</span><div><small>DEVICES</small><strong>' . count($devices) . '</strong><p>' . $boundCount . ' linked license' . ($boundCount === 1 ? '' : 's') . '</p></div></article><article><span class="metric-icon green">' . self::icon('shield') . '</span><div><small>ACCOUNT STATUS</small><strong class="metric-text">Protected</strong><p>' . self::escape((string) $role['name']) . '</p></div></article></section>'
            . '<div class="dashboard-columns"><section class="dashboard-panel products-panel" id="products" data-search-section><div class="panel-heading"><div><p class="section-label">LIBRARY</p><h2>My products</h2></div><span>' . count($subscriptions) . ' TOTAL</span></div>';
        if ($subscriptions === []) {
            echo '<div class="empty-state"><span>' . self::icon('library') . '</span><h3>No products yet</h3><p>Activate a product key to add it to your library.</p><a href="#activation" class="button button-secondary button-small">Activate a key</a></div>';
        } else {
            echo '<div class="product-table" role="table"><div class="product-table-head" role="row"><span>PRODUCT</span><span>STATUS</span><span>EXPIRATION</span><span>DEVICE</span><span></span></div>';
            foreach ($subscriptions as $subscription) { echo self::subscriptionRow($subscription, $csrfToken); }
            echo '</div>';
        }
        echo '</section><aside class="dashboard-side"><section class="dashboard-panel activation-panel" id="activation"><div class="panel-heading"><div><p class="section-label">LICENSE</p><h2>Activate a key</h2></div><span class="panel-icon">' . self::icon('key') . '</span></div><p>Add a product or extend an existing license.</p><form method="post" action="' . self::escape(self::url('/account/activate')) . '"><input type="hidden" name="csrf" value="' . self::escape($csrfToken) . '"><label for="activation-key">PRODUCT KEY</label><div class="activation-input"><input id="activation-key" name="key" placeholder="PERI-XXXX-XXXX-XXXX" required autocomplete="off" spellcheck="false"><span>' . self::icon('key') . '</span></div><button class="button button-primary" type="submit">Activate key ' . self::icon('arrow') . '</button></form><small>A key can only be linked to one account.</small></section>'
            . '<section class="dashboard-panel quick-panel"><div class="panel-heading"><div><p class="section-label">QUICK ACCESS</p><h2>Settings</h2></div></div><a href="#profile">' . self::icon('user') . '<span><strong>Edit profile</strong><small>Display name and identity</small></span>' . self::icon('chevron') . '</a><a href="#security">' . self::icon('shield') . '<span><strong>Security</strong><small>Change your password</small></span>' . self::icon('chevron') . '</a></section></aside></div>';

        echo '<section class="dashboard-panel devices-panel" id="devices" data-search-section><div class="panel-heading"><div><p class="section-label">DEVICES</p><h2>My devices</h2></div><span>' . count($devices) . ' REGISTERED</span></div>';
        if ($devices === []) {
            echo '<div class="compact-empty">No active devices. Sign in through the launcher to register this machine.</div>';
        } else {
            echo '<div class="device-list">';
            foreach ($devices as $device) { echo self::deviceRow($device, $csrfToken); }
            echo '</div>';
        }
        echo '</section><div class="settings-grid"><section class="dashboard-panel settings-panel" id="profile"><div class="panel-heading"><div><p class="section-label">PROFILE</p><h2>Account information</h2></div><span class="panel-icon">' . self::icon('user') . '</span></div><form method="post" action="' . self::escape(self::url('/account/profile')) . '"><input type="hidden" name="csrf" value="' . self::escape($csrfToken) . '"><label for="display-name">DISPLAY NAME</label><input id="display-name" name="display_name" maxlength="80" required value="' . self::escape($displayName) . '"><label>EMAIL ADDRESS</label><input value="' . self::escape($email) . '" disabled><button class="button button-secondary" type="submit">Save profile</button></form></section>'
            . '<section class="dashboard-panel settings-panel" id="security"><div class="panel-heading"><div><p class="section-label">SECURITY</p><h2>Password</h2></div><span class="panel-icon">' . self::icon('shield') . '</span></div><form method="post" action="' . self::escape(self::url('/account/security/password')) . '"><input type="hidden" name="csrf" value="' . self::escape($csrfToken) . '"><label for="current-password">CURRENT PASSWORD</label><input id="current-password" name="current_password" type="password" required autocomplete="current-password"><label for="new-password">NEW PASSWORD</label><input id="new-password" name="new_password" type="password" minlength="10" required autocomplete="new-password"><button class="button button-secondary" type="submit">Change password</button></form></section></div>'
            . '<footer class="dashboard-footer"><span>Pericles Platform · v0.1.0</span><span><i class="status-dot"></i> Systems operational</span></footer></main></div><div class="sidebar-scrim" data-sidebar-toggle></div></div>'
            . '<dialog class="command-dialog" data-command-dialog><form method="dialog"><button aria-label="Close">×</button></form><div class="command-input">' . self::icon('search') . '<input type="search" placeholder="Search for a section or product…" data-command-input autocomplete="off"></div><nav><a href="#products" data-command-item>My products</a><a href="#devices" data-command-item>My devices</a><a href="#activation" data-command-item>Activate a key</a><a href="#profile" data-command-item>Edit profile</a><a href="#security" data-command-item>Account security</a>' . ($canAccessAdmin ? '<a href="' . self::escape(self::url('/admin')) . '" data-command-item>Administration</a>' : '') . '</nav><p data-command-empty hidden>No results.</p></dialog>';
        self::footer();
    }

    private static function subscriptionRow(array $subscription, string $csrfToken): string
    {
        $product = $subscription['product']; $status = (string) $subscription['status'];
        $statusLabels = ['active' => 'Active', 'cancelled' => 'Cancelled', 'expired' => 'Expired', 'suspended' => 'Suspended'];
        $expiry = $subscription['expires_at'] === null ? 'Lifetime' : (new \DateTimeImmutable((string) $subscription['expires_at']))->format('M j, Y');
        $bindingLabels = ['unbound' => 'Not linked', 'current_device' => 'This device', 'other_device' => 'Another device'];
        $binding = $bindingLabels[$subscription['device_binding']['state']] ?? 'Unknown'; $slug = (string) ($product['slug'] ?? '');
        $image = self::url('/assets/products/' . $slug . '.jpg');
        $canUnbind = $subscription['device_binding']['state'] !== 'unbound';
        return '<div class="product-row" role="row" data-search-item="' . self::escape(strtolower((string) $product['name'])) . '"><div class="product-cell"><img class="game-emblem product-thumb" src="' . self::escape($image) . '" alt=""><div><strong>' . self::escape((string) $product['name']) . '</strong><small>Pericles Module</small></div></div><div><span class="state-badge state-' . self::escape($status) . '"><i></i>' . self::escape($statusLabels[$status] ?? ucfirst($status)) . '</span></div><div class="table-value"><small>EXPIRATION</small><span>' . self::escape($expiry) . '</span></div><div class="table-value"><small>DEVICE</small><span>' . self::icon('monitor') . self::escape($binding) . '</span></div><div><details class="row-actions"><summary class="row-menu" title="Product options">' . self::icon('more') . '</summary><div><strong>' . self::escape((string) $product['name']) . '</strong><span>' . self::escape($statusLabels[$status] ?? $status) . ' license</span>' . ($canUnbind ? '<form method="post" action="' . self::escape(self::url('/account/products/' . $slug . '/unbind')) . '" data-confirm="Unlink this product from its device?"><input type="hidden" name="csrf" value="' . self::escape($csrfToken) . '"><button type="submit">' . self::icon('monitor') . ' Unlink from device</button></form>' : '<small>No linked device</small>') . '</div></details></div></div>';
    }

    private static function deviceRow(array $device, string $csrfToken): string
    {
        $lastSeen = $device['last_seen_at'] === null ? 'Never used' : (new \DateTimeImmutable((string) $device['last_seen_at']))->format('M j, Y \a\t H:i');
        return '<article class="device-row" data-search-item="' . self::escape(strtolower((string) $device['display_name'])) . '"><span class="metric-icon">' . self::icon('monitor') . '</span><div><strong>' . self::escape((string) $device['display_name']) . '</strong><small>HWID · ' . self::escape(substr((string) $device['device_id'], 0, 8)) . '•••••••• · Last activity: ' . self::escape($lastSeen) . '</small></div><span class="state-badge state-active"><i></i>Active</span><form method="post" action="' . self::escape(self::url('/account/devices/' . (string) $device['device_id'] . '/revoke')) . '" data-confirm="Revoke this device? It will need to be registered again."><input type="hidden" name="csrf" value="' . self::escape($csrfToken) . '"><button class="button button-secondary button-small" type="submit">Revoke</button></form></article>';
    }

    private static function toast(?string $message, ?string $error): void
    {
        if ($message !== null) {
            echo '<div class="toast toast-success" role="status">' . self::icon('check') . '<div><strong>Action completed</strong><span>' . self::escape($message) . '</span></div><button type="button" data-dismiss aria-label="Close">×</button></div>';
        }
        if ($error !== null) {
            echo '<div class="toast toast-error" role="alert">' . self::icon('alert') . '<div><strong>Action failed</strong><span>' . self::escape($error) . '</span></div><button type="button" data-dismiss aria-label="Close">×</button></div>';
        }
    }

    private static function productCard(string $slug, string $name, string $description, string $status, bool $featured): string
    {
        return '<article class="catalog-card ' . ($featured ? 'featured' : '') . '"><div class="catalog-visual"><img src="' . self::escape(self::url('/assets/products/' . $slug . '.jpg')) . '" alt="' . self::escape($name) . ' artwork"><span class="available-badge"><i></i>' . self::escape($status) . '</span></div><div class="catalog-card-body"><div><p class="section-label">PERICLES MODULE</p><h3>' . self::escape($name) . '</h3><p>' . self::escape($description) . '</p></div><div class="catalog-card-footer"><span>DESKTOP MODULE</span><a href="' . self::escape(self::url('/login')) . '" aria-label="Access ' . self::escape($name) . '">' . self::icon('arrow') . '</a></div></div></article>';
    }

    private static function header(string $title, string $bodyClass): void
    {
        header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#09090b"><meta name="description" content="Pericles — a desktop platform for managing your products, licenses, and devices."><title>' . self::escape($title) . ' · Pericles</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&amp;family=Space+Grotesk:wght@500;600;700&amp;display=swap" rel="stylesheet"><link rel="stylesheet" href="' . self::escape(self::url('/assets/pericles.css')) . '"><script defer src="' . self::escape(self::url('/assets/pericles.js')) . '"></script></head><body class="' . self::escape($bodyClass) . '">';
    }

    private static function footer(): never { echo '</body></html>'; exit; }
    private static function logo(bool $labelled = true): string { return '<svg class="logo-mark" viewBox="0 0 32 32" aria-' . ($labelled ? 'hidden="true"' : 'label="Pericles" role="img"') . '><path d="M7.5 5.5h10.8c4.4 0 7.2 2.5 7.2 6.5 0 4.1-2.8 6.6-7.2 6.6h-5.1v7.9H7.5v-21Zm5.7 4.6V14h4.2c1.5 0 2.4-.7 2.4-2s-.9-1.9-2.4-1.9h-4.2Z" fill="currentColor"/><path d="M18.7 20.5h6.1v6h-6.1z" fill="currentColor" opacity=".45"/></svg>'; }

    private static function icon(string $name): string
    {
        $paths = [
            'alert' => '<path d="M12 9v4m0 4h.01M10.3 3.9 2.2 18a2 2 0 0 0 1.7 3h16.2a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>', 'arrow' => '<path d="M5 12h14m-5-5 5 5-5 5"/>', 'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>', 'check' => '<path d="m5 12 4 4L19 6"/>', 'chevron' => '<path d="m9 18 6-6-6-6"/>', 'chevron-down' => '<path d="m6 9 6 6 6-6"/>', 'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', 'download' => '<path d="M12 3v12m-4-4 4 4 4-4M5 21h14"/>', 'eye' => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/>', 'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>', 'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8m-3 3 3 3m-6 0 3 3"/>', 'library' => '<path d="M4 19V5m5 14V5m5 14V5m5 14V5M2 21h20M2 3h20"/>', 'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>', 'logout' => '<path d="M10 17l5-5-5-5m5 5H3m11-9h6a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1h-6"/>', 'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>', 'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>', 'monitor' => '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8m-4-4v4"/>', 'more' => '<circle cx="5" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1" fill="currentColor" stroke="none"/>', 'play' => '<path d="m9 6 9 6-9 6V6Z"/>', 'refresh' => '<path d="M20 6v5h-5M4 18v-5h5M6.1 9A7 7 0 0 1 18.4 6L20 11M4 13l1.6 5A7 7 0 0 0 18 15"/>', 'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>', 'shield' => '<path d="M12 22s8-3.5 8-10V5l-8-3-8 3v7c0 6.5 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>', 'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>', 'windows' => '<path d="M3 5.5 11 4.4v7H3v-5.9Zm9-1.2L21 3v8.4h-9V4.3ZM3 12.5h8v7L3 18.4v-5.9Zm9 0h9V21l-9-1.3v-7.2Z" fill="currentColor" stroke="none"/>'
        ];
        return '<svg class="icon icon-' . self::escape($name) . '" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
    }

    private static function displayName(string $email): string { $name = explode('@', $email)[0] ?? 'Membre'; return ucwords(str_replace(['.', '_', '-'], ' ', $name)); }
    private static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private static function url(string $path): string { $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')); $base = rtrim(str_replace('\\', '/', dirname($script)), '/.'); return ($base === '' ? '' : $base) . '/' . ltrim($path, '/'); }
}
