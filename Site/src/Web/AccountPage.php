<?php

declare(strict_types=1);

namespace Pericles\Web;

final class AccountPage
{
    public static function landing(): never
    {
        self::header('Secure product licensing', 'marketing-page');
        echo '<header class="marketing-header"><div class="marketing-nav">' . self::brand('/')
            . '<nav><a href="#products">Products</a><a href="#platform">Platform</a><a href="#how">How it works</a><a href="#faq">FAQ</a></nav>'
            . '<div class="marketing-actions"><a class="btn btn-ghost" href="' . self::e(self::url('/login')) . '">Sign in</a><a class="btn btn-primary" href="' . self::e(self::url('/register')) . '">Get access</a></div></div></header>'
            . '<main><section class="new-hero"><div class="hero-glow"></div><div class="hero-inner"><span class="system-pill"><i></i> Secure licensing platform</span>'
            . '<h1>Premium modules,<br><em>licensed the right way</em></h1><p>Pericles delivers hardware-bound access with instant activation, device management, and a workspace that shows exactly what belongs to your account.</p>'
            . '<div class="hero-actions"><a class="btn btn-primary btn-large" href="#products">Browse products</a><a class="btn btn-outline btn-large" href="' . self::e(self::url('/login')) . '">Open the dashboard</a></div>'
            . '<div class="trust-grid"><article><strong>Encrypted</strong><span>Authenticated sessions</span></article><article><strong>Hardware-bound</strong><span>Device identity</span></article><article><strong>Signed</strong><span>Secure module packages</span></article><article><strong>Audited</strong><span>Administrative access</span></article></div></div></section>'
            . '<section class="marketing-section" id="products"><div class="section-intro"><span>PRODUCTS</span><h2>Your Pericles catalog</h2><p>Activate a license key to add a product to your secure workspace.</p></div><div class="offer-grid">'
            . self::offer('Deadlock', 'Main module', 'Hardware-bound access and secure launcher delivery.', 'DL')
            . self::offer('Counter-Strike 2', 'Main module', 'Managed access, signed builds, and automatic authorization.', 'CS2')
            . '</div></section>'
            . '<section class="marketing-section" id="platform"><div class="section-intro"><span>PLATFORM</span><h2>Built as infrastructure</h2></div><div class="pillar-grid">'
            . self::pillar('shield', 'Hardware-bound licensing', 'Every active product can be linked to a verified device identity.')
            . self::pillar('download', 'Secure distribution', 'Short-lived tickets and signed, encrypted packages protect downloads.')
            . self::pillar('key', 'Instant activation', 'Redeem a valid key and see access reflected from the real license database.')
            . self::pillar('activity', 'Traceable administration', 'Sensitive management actions are permission-checked and audited.')
            . '</div></section>'
            . '<section class="marketing-section" id="how"><div class="section-intro"><span>HOW IT WORKS</span><h2>Access in three steps</h2></div><div class="steps-grid">'
            . '<article><b>01</b><h3>Create your account</h3><p>Set up one secure identity for products, licenses, and devices.</p></article>'
            . '<article><b>02</b><h3>Activate a key</h3><p>Redeem your license key from the member workspace.</p></article>'
            . '<article><b>03</b><h3>Link the launcher</h3><p>Verify your device and access the authorized module securely.</p></article></div></section>'
            . '<section class="marketing-section faq-section" id="faq"><div class="section-intro"><span>FAQ</span><h2>Frequently asked</h2></div><div class="faq-list">'
            . '<details><summary>Where are my products stored?</summary><p>Products and licenses are stored in the Pericles database and shown after you sign in.</p></details>'
            . '<details><summary>Can I unlink a device?</summary><p>Yes. An authorized product binding or registered device can be revoked from your workspace.</p></details>'
            . '<details><summary>How are downloads protected?</summary><p>The launcher uses authenticated, short-lived tickets and verifies every signed module package.</p></details></div></section></main>'
            . '<footer class="marketing-footer"><div>' . self::brand('/') . '<span>Secure by design</span><a href="mailto:support@pericles.gg">Support</a></div></footer>';
        self::footer();
    }

    public static function login(string $csrfToken, ?string $error = null): never { self::authPage('login', $csrfToken, $error); }
    public static function register(string $csrfToken, ?string $error = null): never { self::authPage('register', $csrfToken, $error); }

    public static function recovery(string $csrfToken = '', ?string $message = null, ?string $error = null): never
    {
        self::header('Account recovery', 'auth-page new-auth-page');
        echo self::authAside() . '<section class="new-auth-panel"><div class="auth-card"><a class="mobile-auth-brand" href="' . self::e(self::url('/')) . '">P <span>PERICLES</span></a>'
            . '<div class="auth-icon">' . self::icon('key') . '</div><p class="auth-kicker">ACCOUNT RECOVERY</p><h1>Reset password</h1><p class="auth-description">Enter your account email. If it matches an active account, we will send a secure single-use link.</p>';
        if ($message !== null) echo '<div class="empty-inline"><strong>Request received</strong><span>' . self::e($message) . '</span></div>';
        if ($error !== null) echo '<div class="form-alert" role="alert"><strong>Unable to continue</strong><span>' . self::e($error) . '</span></div>';
        echo '<form class="new-auth-form" method="post" action="' . self::e(self::url('/forgot-password')) . '"><input type="hidden" name="csrf" value="' . self::e($csrfToken) . '"><label>Email address<input class="field" type="email" name="email" maxlength="254" required autocomplete="email"></label><button class="btn btn-primary btn-block" type="submit">Send recovery link</button></form><a class="auth-back" href="' . self::e(self::url('/login')) . '">Back to sign in</a></div></section></main>';
        self::footer();
    }

    public static function resetPassword(string $csrfToken, string $token, ?string $error = null): never
    {
        self::header('Choose a new password', 'auth-page new-auth-page');
        echo self::authAside() . '<section class="new-auth-panel"><div class="auth-card"><a class="mobile-auth-brand" href="' . self::e(self::url('/')) . '">P <span>PERICLES</span></a><div class="auth-icon">' . self::icon('shield') . '</div><p class="auth-kicker">SECURE RECOVERY</p><h1>Choose a new password</h1><p class="auth-description">This link is single-use. Updating the password revokes active launcher sessions.</p>';
        if ($error !== null) echo '<div class="form-alert" role="alert"><strong>Unable to continue</strong><span>' . self::e($error) . '</span></div>';
        echo '<form class="new-auth-form" method="post" action="' . self::e(self::url('/reset-password')) . '"><input type="hidden" name="csrf" value="' . self::e($csrfToken) . '"><input type="hidden" name="token" value="' . self::e($token) . '"><label>New password<span class="password-field"><input class="field" type="password" name="password" minlength="10" maxlength="1024" required autocomplete="new-password"><button type="button" data-password-toggle aria-label="Show password">' . self::icon('eye') . '</button></span></label><label>Confirm password<span class="password-field"><input class="field" type="password" name="password_confirm" minlength="10" maxlength="1024" required autocomplete="new-password"><button type="button" data-password-toggle aria-label="Show password">' . self::icon('eye') . '</button></span></label><button class="btn btn-primary btn-block" type="submit">Update password</button></form><a class="auth-back" href="' . self::e(self::url('/login')) . '">Back to sign in</a></div></section></main>';
        self::footer();
    }

    public static function account(
        string $email, array $subscriptions, array $devices, array $profile, array $role,
        bool $canAccessAdmin, string $csrfToken, ?string $message = null, ?string $error = null,
        array $workspace = [], string $page = 'overview'
    ): never {
        $pages = [
            'overview' => ['Overview', 'Here is the current state of your Pericles account.'],
            'products' => ['My Enhancements', 'Manage your available Enhancements and access status.'],
            'downloads' => ['Downloads', 'Review the secure builds available through the Pericles launcher.'],
            'documentation' => ['Documentation', 'Read documentation available for your current access.'],
            'changelog' => ['Changelog', 'Review published Enhancement updates.'],
            'licenses' => ['Access', 'Review and activate Access Keys linked to your account.'],
            'devices' => ['My devices', 'Control authorized devices and revoke access when needed.'],
            'activity' => ['Activity history', 'Review real product, device, and download events for your account.'],
            'billing' => ['Invoices & payments', 'Review billing records linked to your account.'],
            'support' => ['Support tickets', 'Get help from the Pericles support team.'],
            'settings' => ['Profile & settings', 'Manage your identity and account details.'],
            'security' => ['Account security', 'Change your password and review launcher sessions.'],
        ];
        if (!isset($pages[$page])) $page = 'overview';
        $title = $pages[$page][0];
        $displayName = trim((string) ($profile['display_name'] ?? '')) ?: self::displayName($email);
        self::header($title, 'dashboard-page new-dashboard-page');
        self::memberShellStart($title, $displayName, $email, $role, $canAccessAdmin, $csrfToken, $page);
        self::flash($message, $error);
        echo '<div class="page-heading"><div><h1>' . self::e($title) . '</h1><p>' . self::e($pages[$page][1]) . '</p></div>';
        if (in_array($page, ['overview', 'licenses'], true)) echo '<a class="btn btn-primary" href="#activate-key">Redeem Access Key ' . self::icon('arrow') . '</a>';
        elseif ($page === 'support') echo '<a class="btn btn-primary" href="mailto:support@pericles.gg">New request</a>';
        echo '</div>';

        match ($page) {
            'products' => self::productsPage($subscriptions, $csrfToken),
            'downloads' => self::downloadsPage($subscriptions, $workspace),
            'licenses' => self::licensesPage($subscriptions, $csrfToken),
            'devices' => self::devicesPage($devices, $csrfToken),
            'activity' => self::activityPage($subscriptions, $devices, $profile, $workspace),
            'documentation' => self::documentationPage($workspace['documents'] ?? []),
            'changelog' => self::changelogPage($workspace['changelog'] ?? []),
            'billing' => self::billingPage($workspace['orders'] ?? []),
            'support' => self::supportPage($workspace['tickets'] ?? [], $csrfToken),
            'settings' => self::settingsPage($profile, $csrfToken),
            'security' => self::securityPage($workspace, $csrfToken),
            default => self::overviewPage($subscriptions, $devices, $role, $workspace, $csrfToken),
        };
        echo '</main></div><button class="sidebar-scrim" type="button" data-sidebar-toggle aria-label="Close menu"></button></div>';
        self::footer();
    }

    private static function overviewPage(array $subscriptions, array $devices, array $role, array $workspace, string $csrf): void
    {
        $active = count(array_filter($subscriptions, static fn (array $s): bool => (string) $s['status'] === 'active'));
        $versions = array_values(array_filter($workspace['versions'] ?? [], static fn (array $v): bool => is_string($v['version'] ?? null)));
        $latest = $versions[0]['version'] ?? '—';
        echo '<section class="metric-strip">' . self::metric('Active Enhancements', (string) $active, count($subscriptions) . ' on your account')
            . self::metric('Devices', (string) count($devices), 'registered identities')
            . self::metric('Account status', 'Protected', (string) ($role['name'] ?? 'Player'))
            . self::metric('Latest build', $latest === '—' ? '—' : 'v' . (string) $latest, $latest === '—' ? 'No active build' : 'available in the launcher') . '</section>'
            . '<section class="dashboard-columns"><div class="data-panel"><div class="panel-header"><h2>My Enhancements</h2><a href="' . self::e(self::url('/products')) . '">View all</a></div>';
        self::subscriptionsTable($subscriptions, $csrf, 5, false);
        echo '</div><div class="side-stack"><section class="data-panel activation-card" id="activate-key"><div class="panel-body"><h2>Redeem Access Key</h2><p>A valid Access Key can only be redeemed by one account.</p>' . self::activationForm($csrf) . '</div></section>'
            . '<section class="data-panel"><div class="panel-header"><h2>Account</h2></div><ul class="summary-list"><li><span>Enhancements</span><strong>' . count($subscriptions) . '</strong></li><li><span>Devices</span><strong>' . count($devices) . '</strong></li><li><span>Launcher sessions</span><strong>' . count($workspace['sessions'] ?? []) . '</strong></li></ul></section></div></section>';
    }

    private static function productsPage(array $subscriptions, string $csrf): void
    {
        echo '<section class="data-panel"><div class="table-toolbar"><label>' . self::icon('search') . '<input type="search" placeholder="Search records" data-table-search></label><span>' . count($subscriptions) . ' records</span></div>';
        self::subscriptionsTable($subscriptions, $csrf, null, true);
        echo '</section>';
    }

    private static function licensesPage(array $subscriptions, string $csrf): void
    {
        echo '<section class="content-grid"><div class="data-panel"><div class="panel-header"><h2>Your Access</h2><span>' . count($subscriptions) . ' records</span></div>';
        self::subscriptionsTable($subscriptions, $csrf, null, true);
        echo '</div><aside class="data-panel activation-card" id="activate-key"><div class="panel-body"><div class="auth-icon">' . self::icon('key') . '</div><h2>Redeem Access Key</h2><p>The Enhancement will appear here immediately after successful redemption.</p>' . self::activationForm($csrf) . '</div></aside></section>';
    }

    private static function downloadsPage(array $subscriptions, array $workspace): void
    {
        $versionsBySlug = [];
        foreach ($workspace['versions'] ?? [] as $version) {
            $slug = (string) ($version['product_slug'] ?? '');
            if ($slug !== '' && !isset($versionsBySlug[$slug])) $versionsBySlug[$slug] = $version;
        }
        echo '<section class="data-panel"><div class="panel-header"><h2>Available builds</h2><span>Secure launcher delivery</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Status</th><th>Latest version</th><th>Released</th><th>Size</th><th>Delivery</th></tr></thead><tbody>';
        foreach ($subscriptions as $subscription) {
            $slug = (string) $subscription['product']['slug']; $version = $versionsBySlug[$slug] ?? null;
            echo '<tr data-search-row><td><strong>' . self::e((string) $subscription['product']['name']) . '</strong><small>Game Enhancement</small></td><td>' . self::badge((string) $subscription['status']) . '</td><td class="mono">' . ($version ? 'v' . self::e((string) $version['version']) : '—') . '</td><td>' . self::date($version['published_at'] ?? null) . '</td><td>' . ($version ? self::bytes((int) $version['file_size']) : '—') . '</td><td><span class="muted-note">Available in launcher</span></td></tr>';
        }
        if ($subscriptions === []) self::emptyRow(6, 'No Enhancement is available on this account.');
        echo '</tbody></table></div></section>';
        self::downloadHistory($workspace['downloads'] ?? []);
    }

    private static function devicesPage(array $devices, string $csrf): void
    {
        echo '<section class="data-panel"><div class="panel-header"><h2>Authorized devices</h2><span>' . count($devices) . ' devices</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Device</th><th>Identifier</th><th>Verified</th><th>Last activity</th><th>Registered</th><th>Actions</th></tr></thead><tbody>';
        foreach ($devices as $device) {
            echo '<tr data-search-row><td><strong>' . self::e((string) $device['display_name']) . '</strong><small>' . ($device['is_current'] ? 'Current device' : 'Registered device') . '</small></td><td class="mono">' . self::e(substr((string) $device['device_id'], 0, 12)) . '••••</td><td>' . ($device['verified_at'] ? self::badge('verified') : self::badge('pending')) . '</td><td>' . self::date($device['last_seen_at']) . '</td><td>' . self::date($device['created_at']) . '</td><td><form method="post" action="' . self::e(self::url('/account/devices/' . (string) $device['device_id'] . '/revoke')) . '" data-confirm="Revoke this device?"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><button class="btn btn-outline btn-small danger" type="submit">Revoke</button></form></td></tr>';
        }
        if ($devices === []) self::emptyRow(6, 'No device has been registered by the launcher.');
        echo '</tbody></table></div></section>';
    }

    private static function activityPage(array $subscriptions, array $devices, array $profile, array $workspace): void
    {
        $events = [['time' => $profile['created_at'] ?? null, 'title' => 'Account created', 'detail' => (string) ($profile['email'] ?? '')]];
        foreach ($subscriptions as $s) $events[] = ['time' => $s['started_at'], 'title' => 'Product activated', 'detail' => (string) $s['product']['name']];
        foreach ($devices as $d) {
            $events[] = ['time' => $d['created_at'], 'title' => 'Device registered', 'detail' => (string) $d['display_name']];
            if ($d['last_seen_at']) $events[] = ['time' => $d['last_seen_at'], 'title' => 'Device verified', 'detail' => (string) $d['display_name']];
        }
        foreach ($workspace['downloads'] ?? [] as $d) $events[] = ['time' => $d['requested_at'], 'title' => 'Enhancement download ' . (string) $d['status'], 'detail' => (string) $d['product_name'] . ' v' . (string) $d['version']];
        usort($events, static fn (array $a, array $b): int => strcmp((string) ($b['time'] ?? ''), (string) ($a['time'] ?? '')));
        echo '<section class="data-panel"><div class="panel-header"><h2>Recorded events</h2><span>' . count($events) . ' events</span></div><ul class="activity-list">';
        foreach ($events as $event) echo '<li><span class="activity-dot"></span><div><strong>' . self::e((string) $event['title']) . '</strong><small>' . self::e((string) $event['detail']) . '</small></div><time>' . self::date($event['time']) . '</time></li>';
        echo '</ul></section>';
    }

    private static function settingsPage(array $profile, string $csrf): void
    {
        echo '<section class="settings-layout"><aside><div class="auth-icon">' . self::icon('settings') . '</div><h2>Profile details</h2><p>Changes are stored on your Pericles account.</p></aside><form class="data-panel settings-form" method="post" action="' . self::e(self::url('/account/profile')) . '"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><label>Display name<input class="field" name="display_name" maxlength="80" required value="' . self::e((string) ($profile['display_name'] ?? '')) . '"></label><label>Email address<input class="field" type="email" value="' . self::e((string) $profile['email']) . '" disabled><small>Email changes are not enabled.</small></label><label>Member since<input class="field" value="' . self::date($profile['created_at'] ?? null) . '" disabled></label><button class="btn btn-primary" type="submit">Save changes</button></form></section>';
    }

    private static function securityPage(array $workspace, string $csrf): void
    {
        echo '<section class="settings-layout"><aside><div class="auth-icon">' . self::icon('shield') . '</div><h2>Security controls</h2><p>Password changes revoke all active launcher sessions.</p></aside><div class="settings-stack"><form class="data-panel settings-form" method="post" action="' . self::e(self::url('/account/security/password')) . '"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><h2>Change password</h2><label>Current password<input class="field" type="password" name="current_password" required autocomplete="current-password"></label><label>New password<input class="field" type="password" name="new_password" minlength="10" required autocomplete="new-password"><small>Use at least 10 characters.</small></label><button class="btn btn-primary" type="submit">Update password</button></form><section class="data-panel"><div class="panel-header"><h2>Active launcher sessions</h2><span>' . count($workspace['sessions'] ?? []) . ' active</span></div><ul class="session-list">';
        foreach ($workspace['sessions'] ?? [] as $session) echo '<li><div><strong>' . self::e((string) ($session['device_name'] ?: 'Unlinked session')) . '</strong><small>' . self::e((string) ($session['created_ip'] ?: 'Unknown IP')) . '</small></div><span>Last seen ' . self::date($session['last_seen_at'] ?? $session['created_at']) . '</span></li>';
        if (($workspace['sessions'] ?? []) === []) echo '<li class="empty-list">No active launcher sessions.</li>';
        echo '</ul></section></div></section>';
    }

    private static function documentationPage(array $documents): void
    {
        echo '<section class="documentation-grid">';
        foreach ($documents as $document) echo '<article class="data-panel document-card" data-search-row><span>' . self::e((string) ($document['game_name'] ?? 'Pericles')) . ' · ' . self::e((string) ($document['section'] ?? 'Guide')) . '</span><h2>' . self::e((string) $document['title']) . '</h2><div>' . nl2br(self::e((string) $document['body'])) . '</div><small>Updated ' . self::date($document['updated_at'] ?? null) . '</small></article>';
        if ($documents === []) self::emptyPage('receipt', 'No documentation available', 'Documentation is shown when it has been published for your current access.');
        echo '</section>';
    }

    private static function changelogPage(array $updates): void
    {
        echo '<section class="data-panel"><div class="panel-header"><h2>Published updates</h2><span>' . count($updates) . ' entries</span></div><ul class="activity-list">';
        foreach ($updates as $update) echo '<li data-search-row><span class="activity-dot"></span><div><strong>' . self::e((string) ($update['game_name'] ?? 'Pericles')) . ' · ' . self::e((string) $update['version']) . '</strong><small>' . self::e((string) $update['summary']) . '</small></div><time>' . self::date($update['published_at'] ?? null) . '</time></li>';
        if ($updates === []) echo '<li class="empty-list">No public update has been published yet.</li>';
        echo '</ul></section>';
    }

    private static function billingPage(array $orders): void
    {
        echo '<section class="data-panel"><div class="panel-header"><h2>Orders &amp; payments</h2><span>' . count($orders) . ' orders</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Order</th><th>Enhancement</th><th>Access Plan</th><th>Total</th><th>Status</th><th>Date</th></tr></thead><tbody>';
        foreach ($orders as $order) echo '<tr data-search-row><td class="mono">' . self::e((string) $order['order_number']) . '</td><td><strong>' . self::e((string) $order['product_name_snapshot']) . '</strong></td><td>' . self::e((string) $order['plan_name_snapshot']) . '</td><td>' . number_format((int) $order['amount_cents'] / 100, 2, '.', ',') . ' ' . self::e((string) $order['currency']) . '</td><td>' . self::badge((string) $order['status']) . '</td><td>' . self::date($order['paid_at'] ?? $order['created_at']) . '</td></tr>';
        if ($orders === []) self::emptyRow(6, 'No order has been created for this account.');
        echo '</tbody></table></div></section>';
    }

    private static function supportPage(array $tickets, string $csrf): void
    {
        echo '<section class="support-layout"><form class="data-panel settings-form" method="post" action="' . self::e(self::url('/account/support')) . '"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><h2>New support request</h2><label>Subject<input class="field" name="subject" maxlength="180" required></label><label>Category<select class="field" name="category" required><option value="account">Account</option><option value="payment">Payment</option><option value="access">Access</option><option value="device">Device</option><option value="installation">Installation</option><option value="product">Enhancement</option><option value="other">Other</option></select></label><label>Message<textarea class="field" name="message" minlength="10" maxlength="10000" rows="6" required></textarea></label><button class="btn btn-primary" type="submit">Create ticket</button></form><section class="data-panel"><div class="panel-header"><h2>Support tickets</h2><span>' . count($tickets) . ' tickets</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Reference</th><th>Subject</th><th>Scope</th><th>Category</th><th>Status</th><th>Updated</th></tr></thead><tbody>';
        foreach ($tickets as $ticket) echo '<tr data-search-row><td class="mono">' . self::e((string) $ticket['ticket_number']) . '</td><td><strong>' . self::e((string) $ticket['subject']) . '</strong></td><td>General</td><td>' . self::e(ucfirst((string) $ticket['category'])) . '</td><td>' . self::badge((string) $ticket['status']) . '</td><td>' . self::date($ticket['updated_at']) . '</td></tr>';
        if ($tickets === []) self::emptyRow(6, 'No support ticket has been created.');
        echo '</tbody></table></div></section></section>';
    }

    private static function subscriptionsTable(array $subscriptions, string $csrf, ?int $limit, bool $actions): void
    {
        $items = $limit === null ? $subscriptions : array_slice($subscriptions, 0, $limit);
        echo '<div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Status</th><th>Started</th><th>Expiration</th><th>Device</th>' . ($actions ? '<th>Actions</th>' : '') . '</tr></thead><tbody>';
        foreach ($items as $subscription) {
            $bound = (string) $subscription['device_binding']['state'] !== 'unbound';
            $image = trim((string) ($subscription['product']['image_url'] ?? ''));
            $cover = $image === '' ? '<span class="table-cover-fallback">' . self::e(strtoupper(substr((string) $subscription['product']['name'], 0, 2))) . '</span>' : '<img class="table-cover" loading="lazy" src="' . self::e($image) . '" alt="" onerror="this.hidden=true">';
            $pending = (string) $subscription['status'] === 'pending';
            $started = $pending ? 'Pending activation' : self::date($subscription['started_at']);
            $expiration = $pending ? 'Starts by ' . self::date($subscription['activation_deadline_at'] ?? null) : (!empty($subscription['is_lifetime']) ? 'Lifetime' : self::date($subscription['expires_at']));
            echo '<tr data-search-row><td><div class="table-product">' . $cover . '<span><strong>' . self::e((string) $subscription['product']['name']) . '</strong><small>' . self::e((string) ($subscription['plan_name'] ?? 'Game Enhancement')) . '</small></span></div></td><td>' . self::badge((string) $subscription['status']) . '</td><td>' . $started . '</td><td>' . $expiration . '</td><td>' . ($bound ? '<span class="device-state">Linked</span>' : '<span class="muted-note">Not linked</span>') . '</td>';
            if ($actions) {
                $action = '<span class="muted-note">—</span>';
                if ($bound) $action = '<form method="post" action="' . self::e(self::url('/account/products/' . (string) $subscription['product']['slug'] . '/unbind')) . '" data-confirm="Reset this Authorized Device? Self-service reset is limited to once every seven days."><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><button class="btn btn-outline btn-small" type="submit">Reset device</button></form>';
                elseif (in_array((string)$subscription['status'], ['expired','cancelled'], true)) $action = '<a class="btn btn-primary btn-small" href="' . self::e(self::url('/enhancements/' . (string)$subscription['product']['slug'] . '#pricing')) . '">Renew</a>';
                echo '<td>' . $action . '</td>';
            }
            echo '</tr>';
        }
        if ($items === []) self::emptyRow($actions ? 6 : 5, 'No product is linked to this account.');
        echo '</tbody></table></div>';
    }

    private static function downloadHistory(array $downloads): void
    {
        echo '<section class="data-panel secondary-panel"><div class="panel-header"><h2>Download history</h2><span>' . count($downloads) . ' records</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Version</th><th>Device</th><th>Status</th><th>Requested</th></tr></thead><tbody>';
        foreach ($downloads as $download) echo '<tr><td><strong>' . self::e((string) $download['product_name']) . '</strong></td><td class="mono">v' . self::e((string) $download['version']) . '</td><td>' . self::e((string) $download['device_name']) . '</td><td>' . self::badge((string) $download['status']) . '</td><td>' . self::date($download['requested_at']) . '</td></tr>';
        if ($downloads === []) self::emptyRow(5, 'No Enhancement download has been recorded.');
        echo '</tbody></table></div></section>';
    }

    private static function emptyPage(string $icon, string $title, string $description): void { echo '<section class="data-panel empty-state"><div class="auth-icon">' . self::icon($icon) . '</div><h2>' . self::e($title) . '</h2><p>' . self::e($description) . '</p></section>'; }
    private static function activationForm(string $csrf): string { return '<form class="activation-form" method="post" action="' . self::e(self::url('/account/activate')) . '"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><label>Access Key<input class="field mono" name="key" required maxlength="128" placeholder="PERI-XXXX-XXXX-XXXX"></label><button class="btn btn-primary btn-block" type="submit">Redeem Access Key</button></form>'; }

    private static function memberShellStart(string $title, string $name, string $email, array $role, bool $admin, string $csrf, string $active): void
    {
        $nav = ['Access' => [['overview', 'Overview', 'grid'], ['products', 'My Enhancements', 'package'], ['downloads', 'Downloads', 'download'], ['documentation', 'Documentation', 'receipt'], ['changelog', 'Changelog', 'activity'], ['licenses', 'Access', 'key'], ['devices', 'My devices', 'monitor']]];
        echo '<div class="app-shell"><aside class="app-sidebar"><div class="sidebar-brand">' . self::brand('/') . '<button type="button" data-sidebar-toggle aria-label="Close menu">×</button></div><nav class="app-nav">';
        foreach ($nav as $group => $items) {
            echo '<p>' . self::e($group) . '</p>';
            foreach ($items as [$key, $label, $icon]) {
                $path = $key === 'overview' ? '/account' : ($key === 'changelog' ? '/account/changelog' : '/' . $key);
                echo '<a class="' . ($active === $key ? 'active' : '') . '" href="' . self::e(self::url($path)) . '">' . self::icon($icon) . '<span>' . self::e($label) . '</span></a>';
            }
        }
        if ($admin) echo '<p>Management</p><a href="' . self::e(self::url('/admin')) . '">' . self::icon('users') . '<span>Administration</span></a>';
        echo '</nav><div class="sidebar-account"><span class="avatar">' . self::e(strtoupper(substr($name, 0, 1))) . '</span><div><strong>' . self::e($name) . '</strong><small>' . self::e((string) ($role['name'] ?? 'Player')) . '</small></div></div></aside><div class="app-area"><header class="app-topbar"><button class="mobile-menu" type="button" data-sidebar-toggle aria-label="Open menu">' . self::icon('menu') . '</button><div class="breadcrumb"><span>Account</span><i>/</i><strong>' . self::e($title) . '</strong></div><label class="global-search">' . self::icon('search') . '<input type="search" placeholder="Search" data-global-search></label><span class="operational"><i></i> Operational</span><details class="account-menu"><summary aria-label="Open account menu"><span class="avatar">' . self::e(strtoupper(substr($name, 0, 1))) . '</span></summary><div><strong>' . self::e($name) . '</strong><small>' . self::e($email) . '</small><hr><a href="' . self::e(self::url('/activity')) . '">' . self::icon('activity') . 'Activity</a><a href="' . self::e(self::url('/billing')) . '">' . self::icon('receipt') . 'Billing</a><a href="' . self::e(self::url('/support')) . '">' . self::icon('headphones') . 'Support</a><a href="' . self::e(self::url('/settings')) . '">' . self::icon('settings') . 'Profile & settings</a><a href="' . self::e(self::url('/security')) . '">' . self::icon('shield') . 'Security</a><hr><form method="post" action="' . self::e(self::url('/logout')) . '"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><button type="submit">Sign out</button></form></div></details></header><main class="app-main">';
    }

    private static function authPage(string $mode, string $csrf, ?string $error): never
    {
        $register = $mode === 'register'; self::header($register ? 'Create Your Pericles Account' : 'Welcome Back', 'auth-page new-auth-page');
        echo self::authAside() . '<section class="new-auth-panel"><div class="auth-card"><a class="mobile-auth-brand" href="' . self::e(self::url('/')) . '">P <span>PERICLES</span></a><div class="auth-icon">' . self::icon('lock') . '</div><p class="auth-kicker">' . ($register ? 'CREATE ACCOUNT' : 'WELCOME BACK') . '</p><h1>' . ($register ? 'Create Your Pericles Account' : 'Welcome Back') . '</h1><p class="auth-description">' . ($register ? 'Purchase and manage your Enhancements from one place.' : 'Access your Enhancements, downloads and documentation.') . '</p>';
        if ($error !== null) echo '<div class="form-alert" role="alert"><strong>Unable to continue</strong><span>' . self::e($error) . '</span></div>';
        echo '<form class="new-auth-form" method="post" action="' . self::e(self::url($register ? '/register' : '/login')) . '"><input type="hidden" name="csrf" value="' . self::e($csrf) . '">';
        if ($register) echo '<label>Username<input class="field" name="display_name" maxlength="80" required autocomplete="nickname" placeholder="Your username"></label>';
        echo '<label>Email Address<input class="field" type="email" name="email" required maxlength="254" autocomplete="username" placeholder="you@example.com"></label>';
        echo '<label>Password' . (!$register ? '<a href="' . self::e(self::url('/forgot-password')) . '">Forgot password?</a>' : '<small>At least 10 characters</small>') . '<span class="password-field"><input class="field" type="password" name="password" required minlength="10" maxlength="1024" autocomplete="' . ($register ? 'new-password' : 'current-password') . '" placeholder="Your password"><button type="button" data-password-toggle aria-label="Show password">' . self::icon('eye') . '</button></span></label>';
        if ($register) echo '<label>Confirm Password<span class="password-field"><input class="field" type="password" name="password_confirm" required minlength="10" maxlength="1024" autocomplete="new-password" placeholder="Repeat your password"><button type="button" data-password-toggle aria-label="Show password">' . self::icon('eye') . '</button></span></label><label class="terms-check"><input type="checkbox" name="accept_terms" value="1" required><span>I accept the <a href="' . self::e(self::url('/terms')) . '">Terms</a> and <a href="' . self::e(self::url('/privacy')) . '">Privacy Policy</a>.</span></label>';
        echo '<button class="btn btn-primary btn-block auth-submit" type="submit">' . ($register ? 'Create account' : 'Sign in') . self::icon('arrow') . '</button></form><div class="auth-switch">' . ($register ? 'Already have an account? <a href="' . self::e(self::url('/login')) . '">Sign in</a>' : 'No account yet? <a href="' . self::e(self::url('/register')) . '">Create account</a>') . '</div></div></section></main>';
        self::footer();
    }

    private static function authAside(): string { return '<main class="new-auth-shell"><section class="auth-aside"><div class="auth-grid"></div>' . self::brand('/') . '<div class="auth-aside-copy"><span>GAME ENHANCEMENTS</span><h2>Everything you own.<br><em>Ready when you are.</em></h2><p>Manage your Enhancements, downloads, documentation and Authorized Device.</p><ul><li>' . self::icon('activity') . 'Live product status</li><li>' . self::icon('download') . 'Launcher downloads</li><li>' . self::icon('receipt') . 'Access and billing</li><li>' . self::icon('headphones') . 'Customer support</li></ul></div><small>PERICLES · GAME ENHANCEMENTS</small></section>'; }
    private static function header(string $title, string $bodyClass): void { header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#13111a"><title>' . self::e($title) . ' — Pericles</title><meta name="description" content="Secure Enhancement, Access Plan, and device management."><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&amp;family=Outfit:wght@500;600;700&amp;family=Roboto+Mono:wght@400;500;600&amp;display=swap" rel="stylesheet"><link rel="stylesheet" href="' . self::e(self::url('/assets/pericles.css?v=20260830-1')) . '"><script defer src="' . self::e(self::url('/assets/pericles.js?v=20260830-1')) . '"></script></head><body class="' . self::e($bodyClass) . '">'; }
    private static function flash(?string $message, ?string $error): void { $value = $error ?? $message; if ($value === null) return; echo '<div class="toast ' . ($error === null ? 'toast-success' : 'toast-error') . '" role="status">' . self::icon($error === null ? 'check' : 'alert') . '<div><strong>' . ($error === null ? 'Action completed' : 'Action failed') . '</strong><span>' . self::e($value) . '</span></div><button type="button" data-dismiss aria-label="Close">×</button></div>'; }
    private static function metric(string $label, string $value, string $meta): string { return '<article><span>' . self::e($label) . '</span><strong>' . self::e($value) . '</strong><small>' . self::e($meta) . '</small></article>'; }
    private static function offer(string $name, string $module, string $description, string $mark): string { return '<article class="offer-card"><span class="offer-mark">' . self::e($mark) . '</span><h3>' . self::e($name) . '</h3><small>' . self::e($module) . '</small><p>' . self::e($description) . '</p><a class="btn btn-outline" href="' . self::e(self::url('/register')) . '">Create an account</a></article>'; }
    private static function pillar(string $icon, string $title, string $text): string { return '<article><span>' . self::icon($icon) . '</span><h3>' . self::e($title) . '</h3><p>' . self::e($text) . '</p></article>'; }
    private static function brand(string $path): string { return '<a class="brand" href="' . self::e(self::url($path)) . '"><b>P</b><span>Pericles</span></a>'; }
    private static function badge(string $status): string { $key = strtolower($status); return '<span class="status-badge status-' . self::e($key) . '"><i></i>' . self::e(ucfirst($key)) . '</span>'; }
    private static function emptyRow(int $span, string $text): void { echo '<tr><td class="empty-cell" colspan="' . $span . '">' . self::e($text) . '</td></tr>'; }
    private static function bytes(int $bytes): string { if ($bytes <= 0) return '—'; return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format($bytes / 1024, 1) . ' KB'; }
    private static function date(mixed $value): string { if (!is_string($value) || trim($value) === '') return '—'; try { return (new \DateTimeImmutable($value))->format('M j, Y H:i'); } catch (\Throwable) { return '—'; } }
    private static function displayName(string $email): string { $name = explode('@', $email)[0] ?? 'Member'; return ucwords(str_replace(['.', '_', '-'], ' ', $name)); }
    private static function footer(): never { echo '</body></html>'; exit; }
    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private static function url(string $path): string { $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')); $base = rtrim(str_replace('\\', '/', dirname($script)), '/.'); return ($base === '' ? '' : $base) . '/' . ltrim($path, '/'); }

    private static function icon(string $name): string
    {
        $paths = [
            'activity' => '<path d="M4 13h3l2-6 4 12 2-6h5"/>', 'alert' => '<path d="M12 9v4m0 4h.01M10.3 3.9 2.2 18a2 2 0 0 0 1.7 3h16.2a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
            'arrow' => '<path d="M5 12h14m-5-5 5 5-5 5"/>', 'check' => '<path d="m5 12 4 4L19 6"/>', 'download' => '<path d="M12 3v12m-4-4 4 4 4-4M5 21h14"/>',
            'eye' => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/>', 'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
            'headphones' => '<path d="M4 14v-2a8 8 0 0 1 16 0v2M4 14h3v6H5a1 1 0 0 1-1-1v-5Zm16 0h-3v6h2a1 1 0 0 0 1-1v-5Z"/>', 'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8m-3 3 3 3m-6 0 3 3"/>',
            'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>', 'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>', 'monitor' => '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8m-4-4v4"/>',
            'package' => '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>', 'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 8h6m-6 4h6"/>',
            'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>', 'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6 1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>',
            'shield' => '<path d="M12 22s8-3.5 8-10V5l-8-3-8 3v7c0 6.5 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>', 'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
        ];
        return '<svg class="icon icon-' . self::e($name) . '" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
    }
}
