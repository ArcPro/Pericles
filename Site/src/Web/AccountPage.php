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

    public static function ticket(array $profile,array $role,bool $canAccessAdmin,string $csrf,array $ticket,?string $message=null,?string $error=null): never
    {
        $name=trim((string)($profile['display_name']??''))?:self::displayName((string)$profile['email']);self::header((string)$ticket['ticket_number'],'dashboard-page new-dashboard-page');self::memberShellStart('Support',$name,(string)$profile['email'],$role,$canAccessAdmin,$csrf,'support');self::flash($message,$error);
        echo '<div class="page-heading"><div><a class="eyebrow-link" href="'.self::e(self::url('/account/support')).'">← My Tickets</a><h1>'.self::e((string)$ticket['subject']).'</h1><p class="mono">'.self::e((string)$ticket['ticket_number']).'</p></div><div class="ticket-heading-badges">'.self::priorityBadge((string)$ticket['priority']).self::badge((string)$ticket['status']).'</div></div><div class="ticket-detail-layout"><section class="data-panel ticket-conversation"><div class="panel-header"><h2>Conversation</h2><span>Updated '.self::friendlyDate($ticket['updated_at']).'</span></div><div class="ticket-thread">';
        foreach($ticket['messages'] as $entry){$staff=(int)$entry['is_staff_reply']===1;$author=$staff?'Pericles Support':(trim((string)($entry['display_name']??''))?:'You');echo '<article class="ticket-message '.($staff?'staff':'customer').'"><header><strong>'.self::e($author).'</strong><time>'.self::friendlyDate($entry['created_at']).'</time></header><p>'.nl2br(self::e((string)$entry['message'])).'</p>';if(($entry['attachments']??[])!==[]){echo '<div class="ticket-attachments">';foreach($entry['attachments'] as $attachment)echo '<a href="'.self::e(self::url('/support-attachments/'.(int)$attachment['id'])).'">'.self::icon('receipt').self::e((string)$attachment['original_name']).' <small>'.self::bytes((int)$attachment['file_size']).'</small></a>';echo '</div>';}echo '</article>';}
        echo '</div>'.((string)$ticket['status']==='closed'?'<div class="panel-note">This ticket is closed. Create a new ticket if you need more help.</div>':'<form class="ticket-reply" method="post" enctype="multipart/form-data" action="'.self::e(self::url('/account/support/'.(string)$ticket['ticket_number'].'/reply')).'"><input type="hidden" name="csrf" value="'.self::e($csrf).'"><label>Reply<textarea class="field" name="message" minlength="2" maxlength="10000" rows="5" required></textarea></label><label>Attachments <small>PNG, JPG, WebP, GIF, PDF or text · 5 MB each</small><input class="field" type="file" name="attachments[]" multiple accept="image/png,image/jpeg,image/webp,image/gif,application/pdf,text/plain"></label><button class="btn btn-primary" type="submit">Send Reply</button></form>').'</section><aside class="data-panel ticket-meta"><h2>Ticket Details</h2><dl><div><dt>Reference</dt><dd class="mono">'.self::e((string)$ticket['ticket_number']).'</dd></div><div><dt>Priority</dt><dd>'.self::e(ucfirst((string)$ticket['priority'])).'</dd></div><div><dt>Status</dt><dd>'.self::statusLabel((string)$ticket['status']).'</dd></div><div><dt>Category</dt><dd>'.self::e(ucfirst((string)$ticket['category'])).'</dd></div><div><dt>Enhancement</dt><dd>'.self::e((string)($ticket['game_name']??'General')).'</dd></div><div><dt>Order</dt><dd>'.self::e((string)($ticket['order_number']??'Not linked')).'</dd></div><div><dt>Created</dt><dd>'.self::friendlyDate($ticket['created_at']).'</dd></div></dl>';
        if(!in_array((string)$ticket['status'],['resolved','closed'],true))echo '<form method="post" action="'.self::e(self::url('/account/support/'.(string)$ticket['ticket_number'].'/solve')).'" data-confirm="Mark this ticket as resolved?"><input type="hidden" name="csrf" value="'.self::e($csrf).'"><button class="btn btn-outline btn-block" type="submit">Mark as Solved</button></form>';
        echo '</aside></div></main></div><button class="sidebar-scrim" type="button" data-sidebar-toggle aria-label="Close menu"></button></div>';self::footer();
    }

    public static function order(array $profile,array $role,bool $canAccessAdmin,string $csrf,array $order): never
    {
        $name=trim((string)($profile['display_name']??''))?:self::displayName((string)$profile['email']);self::header('Order '.(string)$order['order_number'],'dashboard-page new-dashboard-page');self::memberShellStart('Billing',$name,(string)$profile['email'],$role,$canAccessAdmin,$csrf,'billing');
        echo '<div class="page-heading"><div><a class="eyebrow-link" href="'.self::e(self::url('/billing')).'">← Orders &amp; Payments</a><h1>Order '.self::e((string)$order['order_number']).'</h1><p>Receipt and Access confirmation.</p></div>'.self::badge((string)$order['status']).'</div><section class="data-panel order-receipt"><div class="receipt-heading"><div><span>PERICLES</span><h2>'.self::e((string)$order['product_name_snapshot']).'</h2><p>'.self::e((string)$order['plan_name_snapshot']).' Access</p></div><strong>'.self::money((int)$order['amount_cents'],(string)$order['currency']).'</strong></div><dl><div><dt>Order Number</dt><dd class="mono">'.self::e((string)$order['order_number']).'</dd></div><div><dt>Status</dt><dd>'.self::statusLabel((string)$order['status']).'</dd></div><div><dt>Created</dt><dd>'.self::friendlyDate($order['created_at']).'</dd></div><div><dt>Paid At</dt><dd>'.self::friendlyDate($order['paid_at']??null).'</dd></div><div><dt>Payment Provider</dt><dd>'.self::e(trim((string)($order['provider']??''))?:'Not configured').'</dd></div><div><dt>Provider Reference</dt><dd class="mono">'.self::e(trim((string)($order['provider_reference']??''))?:'—').'</dd></div><div><dt>Access Granted</dt><dd>'.((string)$order['status']==='paid'?'Yes':'No').'</dd></div></dl><p class="panel-note">This page contains no payment card details. Payment status is accepted only after server-side provider confirmation.</p></section></main></div><button class="sidebar-scrim" type="button" data-sidebar-toggle aria-label="Close menu"></button></div>';self::footer();
    }

    public static function account(
        string $email, array $subscriptions, array $devices, array $profile, array $role,
        bool $canAccessAdmin, string $csrfToken, ?string $message = null, ?string $error = null,
        array $workspace = [], string $page = 'overview'
    ): never {
        $pages = [
            'overview' => ['Overview', 'Your owned Enhancements, latest updates, and useful shortcuts.'],
            'products' => ['My Enhancements', 'Manage your available Enhancements and access status.'],
            'downloads' => ['Downloads', 'Review the secure builds available through the Pericles launcher.'],
            'documentation' => ['Documentation', 'Read documentation available for your current access.'],
            'changelog' => ['Changelog', 'Review published Enhancement updates.'],
            'licenses' => ['Access', 'Review commercial Access details or redeem an Access Key.'],
            'devices' => ['Authorized Devices', 'Control devices linked to your account.'],
            'activity' => ['Activity', 'Review useful account, security, device, download, and purchase events.'],
            'billing' => ['Billing', 'Review orders and payments linked to your account.'],
            'support' => ['Support', 'Create and follow requests with the Pericles support team.'],
            'settings' => ['Profile & Security', 'Manage your identity and account security.'],
            'security' => ['Account security', 'Change your password and review launcher sessions.'],
        ];
        if (!isset($pages[$page])) $page = 'overview';
        $title = $pages[$page][0];
        $displayName = trim((string) ($profile['display_name'] ?? '')) ?: self::displayName($email);
        self::header($title, 'dashboard-page new-dashboard-page');
        self::memberShellStart($title, $displayName, $email, $role, $canAccessAdmin, $csrfToken, $page, ($workspace['changelog'] ?? []) !== []);
        self::flash($message, $error);
        echo '<div class="page-heading"><div><h1>' . self::e($title) . '</h1><p>' . self::e($pages[$page][1]) . '</p></div>';
        if ($page === 'licenses') echo '<a class="btn btn-outline" href="#activate-key">Redeem Access Key ' . self::icon('arrow') . '</a>';
        elseif ($page === 'support') echo '<a class="btn btn-primary" href="#new-ticket">Create Ticket</a>';
        echo '</div>';

        match ($page) {
            'products' => self::productsPage($subscriptions, $workspace, $csrfToken),
            'downloads' => self::downloadsPage($subscriptions, $workspace),
            'licenses' => self::licensesPage($subscriptions, $csrfToken),
            'devices' => self::devicesPage($devices, $subscriptions, $csrfToken),
            'activity' => self::activityPage($subscriptions, $devices, $profile, $workspace),
            'documentation' => self::documentationPage($workspace['documents'] ?? []),
            'changelog' => self::changelogPage($workspace['changelog'] ?? []),
            'billing' => self::billingPage($workspace['orders'] ?? []),
            'support' => self::supportPage($workspace['tickets'] ?? [], $workspace, $csrfToken),
            'settings' => self::settingsPage($profile, $workspace, $csrfToken),
            'security' => self::securityPage($workspace, $csrfToken),
            default => self::overviewPage($displayName, $subscriptions, $workspace, $csrfToken),
        };
        echo '</main></div><button class="sidebar-scrim" type="button" data-sidebar-toggle aria-label="Close menu"></button></div>';
        self::footer();
    }

    private static function overviewPage(string $displayName, array $subscriptions, array $workspace, string $csrf): void
    {
        $versions = array_values(array_filter($workspace['versions'] ?? [], static fn (array $v): bool => is_string($v['version'] ?? null)));
        $versionsBySlug=[];foreach($versions as $version){$slug=(string)($version['product_slug']??'');if($slug!==''&&!isset($versionsBySlug[$slug]))$versionsBySlug[$slug]=$version;}
        echo '<section class="welcome-heading"><span>PLAYER DASHBOARD</span><h2>Welcome back, '.self::e($displayName).'</h2><p>Everything you own, ready when you are.</p></section><section class="owned-dashboard"><div class="panel-header"><h2>Your Enhancements</h2><a href="'.self::e(self::url('/products')).'">View all</a></div><div class="owned-enhancement-grid">';
        foreach(array_slice($subscriptions,0,4) as $subscription)echo self::enhancementCard($subscription,$versionsBySlug[(string)$subscription['product']['slug']]??null,$csrf,true);
        if($subscriptions===[])echo '<div class="empty-state commercial-empty"><h3>No Enhancements yet</h3><p>Explore live products and choose an Access Plan that fits you.</p><a class="btn btn-primary" href="'.self::e(self::url('/enhancements')).'">Explore Enhancements</a></div>';
        echo '</div></section><section class="dashboard-lower"><div class="data-panel"><div class="panel-header"><h2>Latest Updates</h2>'.(($workspace['changelog']??[])===[]?'':'<a href="'.self::e(self::url('/account/changelog')).'">View all</a>').'</div><ul class="activity-list compact-activity">';
        foreach(array_slice($workspace['changelog']??[],0,3) as $update)echo '<li><span class="activity-dot"></span><div><strong>'.self::e((string)($update['game_name']??'Pericles')).' · v'.self::e((string)$update['version']).'</strong><small>'.self::e((string)$update['summary']).'</small></div><time>'.self::friendlyDate($update['published_at']??null).'</time></li>';
        if(($workspace['changelog']??[])===[])echo '<li class="empty-list">No public product update has been published.</li>';
        echo '</ul></div><aside class="data-panel support-shortcut"><div class="auth-icon">'.self::icon('headphones').'</div><h2>Need help?</h2><p>Open a ticket for Access, payments, installation, or an Authorized Device.</p><a class="btn btn-outline" href="'.self::e(self::url('/account/support')).'">Contact Support</a></aside></section>';
        $ownedSlugs=array_map(static fn(array$s):string=>(string)$s['product']['slug'],$subscriptions);$discover=array_values(array_filter($workspace['catalog']??[],static fn(array$p):bool=>!in_array((string)$p['game_slug'],$ownedSlugs,true)));
        if($discover!==[]){echo '<section class="discover-strip"><div><span>DISCOVER</span><h2>More Enhancements</h2><p>Browse another currently available product.</p></div><a class="btn btn-outline" href="'.self::e(self::url('/enhancements/'.(string)$discover[0]['game_slug'])).'">View '.self::e((string)$discover[0]['game_name']).'</a></section>';}
    }

    private static function productsPage(array $subscriptions, array $workspace, string $csrf): void
    {
        $versionsBySlug=[];foreach($workspace['versions']??[] as $version){$slug=(string)($version['product_slug']??'');if($slug!==''&&!isset($versionsBySlug[$slug]))$versionsBySlug[$slug]=$version;}
        echo '<section class="owned-enhancement-grid full">';foreach($subscriptions as $subscription)echo self::enhancementCard($subscription,$versionsBySlug[(string)$subscription['product']['slug']]??null,$csrf,false);
        if($subscriptions===[])echo '<div class="empty-state commercial-empty"><div class="auth-icon">'.self::icon('package').'</div><h2>No Enhancements yet</h2><p>Purchased Enhancements and redeemed Access Keys will appear here.</p><a class="btn btn-primary" href="'.self::e(self::url('/enhancements')).'">Explore Enhancements</a></div>';
        echo '</section>';
    }

    private static function licensesPage(array $subscriptions, string $csrf): void
    {
        echo '<section class="data-panel"><div class="panel-header"><h2>Commercial Access</h2><span>'.count($subscriptions).' record'.(count($subscriptions)===1?'':'s').'</span></div><div class="table-wrap"><table class="data-table admin-table-wide"><thead><tr><th>Enhancement</th><th>Access Plan</th><th>Purchased</th><th>Activation</th><th>Expiration</th><th>Time Remaining</th><th>Status</th><th>Order</th><th>Action</th></tr></thead><tbody>';
        foreach($subscriptions as $subscription){$lifetime=!empty($subscription['is_lifetime']);$expired=(string)$subscription['status']==='expired';$order=is_string($subscription['order_number']??null)&&$subscription['order_number']!==''?'<a class="table-link mono" href="'.self::e(self::url('/billing/orders/'.rawurlencode((string)$subscription['order_number']))).'">'.self::e((string)$subscription['order_number']).'</a>':'Direct grant / key';echo '<tr><td><strong>'.self::e((string)$subscription['product']['name']).'</strong></td><td>'.self::e((string)($subscription['plan_name']??($lifetime?'Lifetime':'Access Plan'))).'</td><td>'.self::friendlyDate($subscription['purchased_at']??$subscription['started_at']).'</td><td>'.($subscription['activated_at']===null?'Pending':self::friendlyDate($subscription['activated_at'])).'</td><td>'.($lifetime?'Lifetime Access':self::friendlyDate($subscription['expires_at'])).'</td><td>'.($lifetime?'Does not expire':self::remaining($subscription['expires_at'])).'</td><td>'.self::badge((string)$subscription['status']).'</td><td>'.$order.'</td><td><a class="btn '.($expired?'btn-primary':'btn-outline').' btn-small" href="'.self::e(self::url('/enhancements/'.(string)$subscription['product']['slug'].'#pricing')).'">'.($expired?'Renew':'Extend').'</a></td></tr>';}
        if($subscriptions===[])self::emptyRow(9,'No commercial Access has been recorded.');echo '</tbody></table></div></section><section class="data-panel activation-card secondary-panel" id="activate-key"><div class="panel-body"><div class="auth-icon">'.self::icon('key').'</div><div><h2>Have an Access Key?</h2><p>Redeem a promotion, partner, or support key. Purchased Access appears automatically.</p></div>'.self::activationForm($csrf).'</div></section>';
    }

    private static function downloadsPage(array $subscriptions, array $workspace): void
    {
        $versionsBySlug = [];
        foreach ($workspace['versions'] ?? [] as $version) {
            $slug = (string) ($version['product_slug'] ?? '');
            if ($slug !== '' && !isset($versionsBySlug[$slug])) $versionsBySlug[$slug] = $version;
        }
        $launcherUrl=trim((string)($workspace['launcher_download_url']??''));
        echo '<section class="launcher-download-card"><div class="launcher-mark">P</div><div><span>PERICLES LAUNCHER</span><h2>Download for Windows</h2><p>Use the launcher to authenticate, authorize your device, and securely download owned Enhancements.</p><ul><li>Windows 10 / 11</li><li>Signed account delivery</li><li>Automatic authorization</li></ul></div><div class="launcher-action">'.($launcherUrl!==''?'<a class="btn btn-primary" href="'.self::e($launcherUrl).'">Download for Windows '.self::icon('download').'</a>':'<button class="btn btn-primary" type="button" disabled>Download unavailable</button><small>Launcher download URL is not configured.</small>').'</div></section><section class="data-panel"><div class="panel-header"><h2>Your Enhancements</h2><span>'.count($subscriptions).' available</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Enhancement</th><th>Access</th><th>Version</th><th>Updated</th><th>Actions</th></tr></thead><tbody>';
        foreach ($subscriptions as $subscription) {
            $slug = (string) $subscription['product']['slug']; $version = $versionsBySlug[$slug] ?? null;
            echo '<tr data-search-row><td><strong>' . self::e((string) $subscription['product']['name']) . '</strong><small>Game Enhancement</small></td><td>' . self::badge((string) $subscription['status']) . '</td><td class="mono">' . ($version ? 'v' . self::e((string) $version['version']) : 'Not published') . '</td><td>' . self::friendlyDate($version['published_at'] ?? null) . '</td><td><div class="inline-actions">'.($launcherUrl!==''?'<a class="btn btn-primary btn-small" href="'.self::e($launcherUrl).'">Download</a>':'<span class="muted-note">Available in launcher</span>').'<a class="btn btn-outline btn-small" href="'.self::e(self::url('/documentation')).'">Documentation</a></div></td></tr>';
        }
        if ($subscriptions === []) self::emptyRow(5, 'No Enhancement is available. Explore Enhancements to choose Access.');
        echo '</tbody></table></div></section>';
        self::downloadHistory(array_slice($workspace['downloads'] ?? [],0,5),count($workspace['downloads']??[]));
    }

    private static function devicesPage(array $devices, array $subscriptions, string $csrf): void
    {
        $accessByDevice=[];foreach($subscriptions as $subscription){$name=(string)($subscription['device_binding']['name']??'');if($name!=='')$accessByDevice[$name][]=$subscription;}
        echo '<section class="data-panel"><div class="panel-header"><h2>Authorized Devices</h2><span>' . count($devices) . ' device'.(count($devices)===1?'':'s').'</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Device Name</th><th>Status</th><th>Linked At</th><th>Last Seen</th><th>Next Reset Available</th><th>Actions</th></tr></thead><tbody>';
        foreach ($devices as $device) {
            $linked=$accessByDevice[(string)$device['display_name']]??[];$reset=null;foreach($linked as $access){$candidate=$access['device_binding']['reset_available_at']??null;if(is_string($candidate)&&($reset===null||$candidate>$reset))$reset=$candidate;}
            echo '<tr data-search-row><td><strong>' . self::e((string) $device['display_name']) . '</strong><small>' . ($device['is_current'] ? 'Current device' : 'Registered device') . '</small></td><td>' . ($device['verified_at'] ? self::badge('verified') : self::badge('pending')) . '</td><td>' . self::friendlyDate($device['created_at']) . '</td><td>' . self::friendlyDate($device['last_seen_at']) . '</td><td>' . ($reset===null?'Available now':self::friendlyDate($reset)) . '</td><td><div class="inline-actions">';
            if($linked!==[]){$access=$linked[0];echo '<form method="post" action="'.self::e(self::url('/account/products/'.(string)$access['product']['slug'].'/unbind')).'" data-confirm="Reset this Authorized Device? Self-service reset is limited to once every seven days."><input type="hidden" name="csrf" value="'.self::e($csrf).'"><button class="btn btn-outline btn-small" type="submit">Reset Device</button></form>';}
            else echo '<form method="post" action="' . self::e(self::url('/account/devices/' . (string) $device['device_id'] . '/revoke')) . '" data-confirm="Remove this Authorized Device?"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><button class="btn btn-outline btn-small danger" type="submit">Remove</button></form>';
            echo '</div></td></tr>';
        }
        if ($devices === []) self::emptyRow(6, 'No device has been registered by the launcher.');
        echo '</tbody></table></div></section>';
    }

    private static function activityPage(array $subscriptions, array $devices, array $profile, array $workspace): void
    {
        $events = [['category'=>'account','time' => $profile['created_at'] ?? null, 'title' => 'Account created', 'detail' => (string) ($profile['email'] ?? '')]];
        foreach ($subscriptions as $s) $events[] = ['category'=>'purchases','time' => $s['purchased_at']??$s['started_at'], 'title' => 'Enhancement Access added', 'detail' => (string) $s['product']['name'].' · '.(string)($s['plan_name']??'Access')];
        foreach ($devices as $d) {
            $events[] = ['category'=>'devices','time' => $d['created_at'], 'title' => 'Authorized Device registered', 'detail' => (string) $d['display_name']];
            if ($d['last_seen_at']) $events[] = ['category'=>'security','time' => $d['last_seen_at'], 'title' => 'Device verification', 'detail' => (string) $d['display_name']];
        }
        $downloadGroups=[];foreach($workspace['downloads']??[] as $download){$key=(string)$download['product_name'].'|'.substr((string)$download['requested_at'],0,10);if(!isset($downloadGroups[$key]))$downloadGroups[$key]=['category'=>'downloads','time'=>$download['requested_at'],'title'=>(string)$download['product_name'].' downloaded','detail'=>'v'.(string)$download['version'],'count'=>0];$downloadGroups[$key]['count']++;}
        foreach($downloadGroups as $download){if($download['count']>1)$download['title'].=' '.(int)$download['count'].' times';unset($download['count']);$events[]=$download;}
        foreach($workspace['orders']??[] as $order)$events[]=['category'=>'payments','time'=>$order['paid_at']??$order['created_at'],'title'=>'Order '.(string)$order['status'],'detail'=>(string)$order['product_name_snapshot'].' · '.self::money((int)$order['amount_cents'],(string)$order['currency'])];
        usort($events, static fn (array $a, array $b): int => strcmp((string) ($b['time'] ?? ''), (string) ($a['time'] ?? '')));
        echo '<section class="data-panel"><div class="panel-header activity-toolbar"><h2>Account Activity</h2><div class="filter-pills" data-activity-filters><button class="active" type="button" data-activity-filter="all">All</button><button type="button" data-activity-filter="security">Security</button><button type="button" data-activity-filter="devices">Devices</button><button type="button" data-activity-filter="downloads">Downloads</button><button type="button" data-activity-filter="payments">Payments</button></div></div><ul class="activity-list">';
        foreach ($events as $event) echo '<li data-activity-category="'.self::e((string)$event['category']).'"><span class="activity-dot"></span><div><strong>' . self::e((string) $event['title']) . '</strong><small>' . self::e((string) $event['detail']) . '</small></div><time>' . self::friendlyDate($event['time']) . '</time></li>';
        echo '</ul></section>';
    }

    private static function settingsPage(array $profile, array $workspace, string $csrf): void
    {
        echo '<section class="settings-layout"><aside><div class="auth-icon">' . self::icon('settings') . '</div><h2>Profile &amp; Security</h2><p>Manage your public identity, password, and active launcher sessions.</p></aside><div class="settings-stack"><form class="data-panel settings-form" method="post" action="' . self::e(self::url('/account/profile')) . '"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><h2>Profile Details</h2><label>Display name<input class="field" name="display_name" maxlength="80" required value="' . self::e((string) ($profile['display_name'] ?? '')) . '"></label><label>Email address<input class="field" type="email" value="' . self::e((string) $profile['email']) . '" disabled><small>Email changes are not enabled.</small></label><label>Member since<input class="field" value="' . self::friendlyDate($profile['created_at'] ?? null) . '" disabled></label><button class="btn btn-primary" type="submit">Save Changes</button></form>';
        echo '<form class="data-panel settings-form" method="post" action="'.self::e(self::url('/account/security/password')).'"><input type="hidden" name="csrf" value="'.self::e($csrf).'"><h2>Change Password</h2><label>Current password<input class="field" type="password" name="current_password" required autocomplete="current-password"></label><label>New password<input class="field" type="password" name="new_password" minlength="10" required autocomplete="new-password"><small>Use at least 10 characters.</small></label><button class="btn btn-primary" type="submit">Update Password</button></form><section class="data-panel"><div class="panel-header"><h2>Active Launcher Sessions</h2><span>'.count($workspace['sessions']??[]).' active</span></div><ul class="session-list">';foreach($workspace['sessions']??[] as $session)echo '<li><div><strong>'.self::e((string)($session['device_name']?:'Unlinked session')).'</strong><small>'.self::e((string)($session['created_ip']?:'Unknown IP')).'</small></div><span>Last seen '.self::friendlyDate($session['last_seen_at']??$session['created_at']).'</span></li>';if(($workspace['sessions']??[])===[])echo '<li class="empty-list">No active launcher sessions.</li>';echo '</ul></section></div></section>';
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
        $grouped=[];foreach($documents as $document)$grouped[(string)($document['game_name']??'Pericles')][(string)($document['section']??'Guide')][]=$document;
        echo '<section class="documentation-layout">';foreach($grouped as $product=>$sections){echo '<article class="data-panel documentation-product"><div class="panel-header"><div><span>ENHANCEMENT DOCUMENTATION</span><h2>'.self::e($product).'</h2></div><small>'.array_sum(array_map('count',$sections)).' pages</small></div><div class="documentation-sections">';foreach($sections as $section=>$pages){echo '<details'.($section===array_key_first($sections)?' open':'').'><summary><span>'.self::e(self::sectionLabel($section)).'</span><b>'.count($pages).'</b></summary>';foreach($pages as $page)echo '<article data-search-row><h3>'.self::e((string)$page['title']).'</h3><div>'.nl2br(self::e((string)$page['body'])).'</div><small>Updated '.self::friendlyDate($page['updated_at']??null).'</small></article>';echo '</details>';}echo '</div></article>';}
        if($documents===[])echo '<section class="data-panel empty-state commercial-empty"><div class="auth-icon">'.self::icon('receipt').'</div><h2>Documentation is being prepared</h2><p>Installation help and customer support are still available for your Enhancement.</p><div class="inline-actions"><a class="btn btn-outline" href="'.self::e(self::url('/support')).'">Installation Help</a><a class="btn btn-primary" href="'.self::e(self::url('/account/support')).'">Contact Support</a></div></section>';
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
        echo '<section class="data-panel"><div class="panel-header"><h2>Orders &amp; Payments</h2><span>' . count($orders) . ' order'.(count($orders)===1?'':'s').'</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Order</th><th>Enhancement</th><th>Access Plan</th><th>Total</th><th>Status</th><th>Date</th><th>Receipt</th></tr></thead><tbody>';
        foreach ($orders as $order) echo '<tr data-search-row><td class="mono">' . self::e((string) $order['order_number']) . '</td><td><strong>' . self::e((string) $order['product_name_snapshot']) . '</strong></td><td>' . self::e((string) $order['plan_name_snapshot']) . '</td><td>' . self::money((int)$order['amount_cents'],(string)$order['currency']) . '</td><td>' . self::badge((string) $order['status']) . '</td><td>' . self::friendlyDate($order['paid_at'] ?? $order['created_at']) . '</td><td><a class="btn btn-outline btn-small" href="'.self::e(self::url('/billing/orders/'.rawurlencode((string)$order['order_number']))).'">View</a></td></tr>';
        if ($orders === []) echo '<tr><td class="empty-cell" colspan="7">No order recorded. <a href="'.self::e(self::url('/enhancements')).'">Explore Enhancements</a>.</td></tr>';
        echo '</tbody></table></div></section>';
    }

    private static function supportPage(array $tickets, array $workspace, string $csrf): void
    {
        echo '<section class="support-layout"><form class="data-panel settings-form" id="new-ticket" method="post" enctype="multipart/form-data" action="' . self::e(self::url('/account/support')) . '"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><h2>Create Ticket</h2><label>Category<select class="field" name="category" required><option value="account">Account</option><option value="installation">Installation</option><option value="payment">Payment</option><option value="access">Access</option><option value="device">Authorized Device</option><option value="product">Enhancement Issue</option><option value="other">Other</option></select></label><label>Enhancement<select class="field" name="product_id"><option value="">General / not applicable</option>';
        foreach($workspace['catalog']??[] as $product)echo '<option value="'.(int)$product['product_id'].'">'.self::e((string)$product['game_name']).' Enhancement</option>';
        echo '</select></label><label>Order<select class="field" name="order_id"><option value="">Not related to an order</option>';foreach($workspace['orders']??[] as $order)echo '<option value="'.(int)($order['id']??0).'">'.self::e((string)$order['order_number']).' · '.self::e((string)$order['product_name_snapshot']).'</option>';
        echo '</select></label><label>Priority<select class="field" name="priority" data-ticket-priority><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select><small class="urgent-warning" data-urgent-warning hidden>Use Urgent only for missing paid Access, critical security, or a major commercial access failure.</small></label><label>Subject<input class="field" name="subject" maxlength="180" required></label><label>Message<textarea class="field" name="message" minlength="10" maxlength="10000" rows="6" required></textarea></label><label>Attachments <small>PNG, JPG, WebP, GIF, PDF or text · 5 MB each</small><input class="field" type="file" name="attachments[]" multiple accept="image/png,image/jpeg,image/webp,image/gif,application/pdf,text/plain"></label><button class="btn btn-primary" type="submit">Create Ticket</button></form><section class="data-panel"><div class="panel-header support-list-header"><div><h2>My Tickets</h2><span>'.count($tickets).' ticket'.(count($tickets)===1?'':'s').'</span></div><div class="filter-pills" data-ticket-filters><button class="active" type="button" data-ticket-filter="all">All</button><button type="button" data-ticket-filter="open">Open</button><button type="button" data-ticket-filter="in_progress">In Progress</button><button type="button" data-ticket-filter="awaiting_user">Awaiting You</button><button type="button" data-ticket-filter="resolved">Resolved</button><button type="button" data-ticket-filter="closed">Closed</button></div></div><div class="ticket-list">';
        foreach($tickets as $ticket)echo '<a class="ticket-row" data-ticket-status="'.self::e((string)$ticket['status']).'" href="'.self::e(self::url('/account/support/'.(string)$ticket['ticket_number'])).'"><span class="mono">'.self::e((string)$ticket['ticket_number']).'</span><div><strong>'.self::e((string)$ticket['subject']).'</strong><small>'.self::e((string)($ticket['game_name']??'General')).'</small></div>'.self::priorityBadge((string)($ticket['priority']??'normal')).self::badge((string)$ticket['status']).'<time>Updated '.self::relativeTime($ticket['updated_at']).'</time></a>';
        if($tickets===[])echo '<div class="empty-state commercial-empty"><h3>No tickets</h3><p>Create a ticket whenever you need help with Access, billing, downloads, or an Authorized Device.</p><a href="#new-ticket" class="btn btn-primary">Create Ticket</a></div>';echo '</div></section></section>';
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

    private static function downloadHistory(array $downloads, ?int $total = null): void
    {
        $total??=count($downloads);echo '<section class="data-panel secondary-panel"><div class="panel-header"><h2>Recent Downloads</h2><a href="'.self::e(self::url('/activity')).'">View Full History</a></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Enhancement</th><th>Version</th><th>Status</th><th>Requested</th></tr></thead><tbody>';
        foreach ($downloads as $download) echo '<tr><td><strong>' . self::e((string) $download['product_name']) . '</strong></td><td class="mono">v' . self::e((string) $download['version']) . '</td><td>' . self::badge((string) $download['status']) . '</td><td>' . self::friendlyDate($download['requested_at']) . '</td></tr>';
        if ($downloads === []) self::emptyRow(4, 'No download yet. Download Pericles or open an owned Enhancement.');
        echo '</tbody></table></div>'.($total>count($downloads)?'<div class="panel-note">Showing the 5 most recent of '.(int)$total.' downloads.</div>':'').'</section>';
    }

    private static function enhancementCard(array $access,?array $version,string $csrf,bool $compact): string
    {
        $slug=(string)$access['product']['slug'];$image=trim((string)($access['product']['image_url']??''));$lifetime=!empty($access['is_lifetime']);$status=(string)$access['status'];$expired=$status==='expired'||(!$lifetime&&is_string($access['expires_at']??null)&&strtotime((string)$access['expires_at'])<=time());
        $cover=$image===''?'<span class="enhancement-card-fallback">'.self::e(strtoupper(substr((string)$access['product']['name'],0,2))).'</span>':'<img loading="lazy" src="'.self::e($image).'" alt="'.self::e((string)$access['product']['name'].' cover').'">';
        $accessLabel=$lifetime?'Lifetime Access':self::e((string)($access['plan_name']??'Timed Access'));$remaining=$lifetime?'Does not expire':self::remaining($access['expires_at']??null);$device=trim((string)($access['device_binding']['name']??''));$productStatus=(string)($access['product_status']??'operational');
        $html='<article class="owned-enhancement-card'.($compact?' compact':'').'" data-search-row><div class="owned-cover">'.$cover.'<span class="status-badge status-'.self::e($productStatus).'"><i></i>'.self::statusLabel($productStatus).'</span></div><div class="owned-card-body"><div><span>GAME ENHANCEMENT</span><h3>'.self::e((string)$access['product']['name']).'</h3></div><dl><div><dt>Access</dt><dd>'.$accessLabel.'</dd></div><div><dt>'.($lifetime?'Availability':'Time Remaining').'</dt><dd>'.self::e($remaining).'</dd></div>'.(!$lifetime&&$access['expires_at']!==null?'<div><dt>Expires</dt><dd>'.self::friendlyDate($access['expires_at']).'</dd></div>':'').'<div><dt>Latest Version</dt><dd>'.($version?'v'.self::e((string)$version['version']):'Not published').'</dd></div><div><dt>Authorized Device</dt><dd>'.self::e($device!==''?$device:'Not linked').'</dd></div></dl><div class="owned-card-actions">';
        if(!$expired&&in_array($status,['active','pending','cancelled'],true))$html.='<a class="btn btn-primary btn-small" href="'.self::e(self::url('/downloads')).'">Download</a><a class="btn btn-outline btn-small" href="'.self::e(self::url('/documentation')).'">Documentation</a><a class="btn btn-ghost btn-small" href="'.self::e(self::url('/enhancements/'.$slug.'#pricing')).'">Extend Access</a>';
        else$html.='<a class="btn btn-primary btn-small" href="'.self::e(self::url('/enhancements/'.$slug.'#pricing')).'">Renew Access</a>';
        if($device!=='')$html.='<a class="btn btn-ghost btn-small" href="'.self::e(self::url('/devices')).'">Manage Device</a>';$html.='<a class="btn btn-ghost btn-small" href="'.self::e(self::url('/enhancements/'.$slug)).'">View Product</a></div></div></article>';return$html;
    }

    private static function emptyPage(string $icon, string $title, string $description): void { echo '<section class="data-panel empty-state"><div class="auth-icon">' . self::icon($icon) . '</div><h2>' . self::e($title) . '</h2><p>' . self::e($description) . '</p></section>'; }
    private static function activationForm(string $csrf): string { return '<form class="activation-form" method="post" action="' . self::e(self::url('/account/activate')) . '"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><label>Access Key<input class="field mono" name="key" required maxlength="128" placeholder="PERI-XXXX-XXXX-XXXX"></label><button class="btn btn-primary btn-block" type="submit">Redeem Access Key</button></form>'; }

    private static function memberShellStart(string $title, string $name, string $email, array $role, bool $admin, string $csrf, string $active, bool $hasChangelog = false): void
    {
        $main=[['overview','Overview','grid'],['products','My Enhancements','package'],['downloads','Downloads','download'],['documentation','Documentation','receipt']];if($hasChangelog)$main[]=['changelog','Changelog','activity'];$main[]=['billing','Billing','receipt'];$main[]=['support','Support','headphones'];
        $nav=['Main'=>$main,'Account'=>[['licenses','Access','key'],['devices','Devices','monitor'],['activity','Activity','activity'],['settings','Profile & Security','settings']]];
        echo '<div class="app-shell"><aside class="app-sidebar"><div class="sidebar-brand">' . self::brand('/') . '<button type="button" data-sidebar-toggle aria-label="Close menu">×</button></div><nav class="app-nav">';
        foreach ($nav as $group => $items) {
            echo '<p>' . self::e($group) . '</p>';
            foreach ($items as [$key, $label, $icon]) {
                $path = $key === 'overview' ? '/account' : ($key === 'changelog' ? '/account/changelog' : ($key === 'support' ? '/account/support' : ($key === 'licenses' ? '/access' : '/' . $key)));
                echo '<a class="' . ($active === $key ? 'active' : '') . '" href="' . self::e(self::url($path)) . '">' . self::icon($icon) . '<span>' . self::e($label) . '</span></a>';
            }
        }
        if ($admin) echo '<p>Management</p><a href="' . self::e(self::url('/admin')) . '">' . self::icon('users') . '<span>Administration</span></a>';
        echo '</nav><div class="sidebar-account"><span class="avatar">' . self::e(strtoupper(substr($name, 0, 1))) . '</span><div><strong>' . self::e($name) . '</strong><small>' . self::e((string) ($role['name'] ?? 'Player')) . '</small></div></div></aside><div class="app-area"><header class="app-topbar"><button class="mobile-menu" type="button" data-sidebar-toggle aria-label="Open menu">' . self::icon('menu') . '</button><div class="breadcrumb"><span>Account</span><i>/</i><strong>' . self::e($title) . '</strong></div><span class="operational"><i></i> Operational</span><details class="account-menu"><summary aria-label="Open account menu"><span class="avatar">' . self::e(strtoupper(substr($name, 0, 1))) . '</span></summary><div><strong>' . self::e($name) . '</strong><small>' . self::e($email) . '</small><hr><a href="' . self::e(self::url('/activity')) . '">' . self::icon('activity') . 'Activity</a><a href="' . self::e(self::url('/billing')) . '">' . self::icon('receipt') . 'Billing</a><a href="' . self::e(self::url('/account/support')) . '">' . self::icon('headphones') . 'Support</a><a href="' . self::e(self::url('/settings')) . '">' . self::icon('settings') . 'Profile & Settings</a><a href="' . self::e(self::url('/security')) . '">' . self::icon('shield') . 'Security</a><hr><form method="post" action="' . self::e(self::url('/logout')) . '"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><button type="submit">Sign Out</button></form></div></details></header><main class="app-main">';
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
    private static function header(string $title, string $bodyClass): void { header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#13111a"><title>' . self::e($title) . ' — Pericles</title><meta name="description" content="Secure Enhancement, Access Plan, and device management."><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&amp;family=Outfit:wght@500;600;700&amp;family=Roboto+Mono:wght@400;500;600&amp;display=swap" rel="stylesheet"><link rel="stylesheet" href="' . self::e(self::url('/assets/pericles.css?v=20260905-1')) . '"><script defer src="' . self::e(self::url('/assets/pericles.js?v=20260905-1')) . '"></script></head><body class="' . self::e($bodyClass) . '">'; }
    private static function flash(?string $message, ?string $error): void { $value = $error ?? $message; if ($value === null) return; echo '<div class="toast ' . ($error === null ? 'toast-success' : 'toast-error') . '" role="status">' . self::icon($error === null ? 'check' : 'alert') . '<div><strong>' . ($error === null ? 'Action completed' : 'Action failed') . '</strong><span>' . self::e($value) . '</span></div><button type="button" data-dismiss aria-label="Close">×</button></div>'; }
    private static function metric(string $label, string $value, string $meta): string { return '<article><span>' . self::e($label) . '</span><strong>' . self::e($value) . '</strong><small>' . self::e($meta) . '</small></article>'; }
    private static function offer(string $name, string $module, string $description, string $mark): string { return '<article class="offer-card"><span class="offer-mark">' . self::e($mark) . '</span><h3>' . self::e($name) . '</h3><small>' . self::e($module) . '</small><p>' . self::e($description) . '</p><a class="btn btn-outline" href="' . self::e(self::url('/register')) . '">Create an account</a></article>'; }
    private static function pillar(string $icon, string $title, string $text): string { return '<article><span>' . self::icon($icon) . '</span><h3>' . self::e($title) . '</h3><p>' . self::e($text) . '</p></article>'; }
    private static function brand(string $path): string { return '<a class="brand" href="' . self::e(self::url($path)) . '"><b>P</b><span>Pericles</span></a>'; }
    private static function badge(string $status): string { $key = strtolower($status); return '<span class="status-badge status-' . self::e($key) . '"><i></i>' . self::e(ucfirst($key)) . '</span>'; }
    private static function priorityBadge(string $priority): string { $key=in_array(strtolower($priority),['low','normal','high','urgent'],true)?strtolower($priority):'normal';return '<span class="priority-badge priority-'.self::e($key).'">'.self::e(strtoupper($key)).'</span>'; }
    private static function statusLabel(string $status): string { return ucwords(str_replace('_',' ',strtolower($status))); }
    private static function money(int $cents,string $currency): string { $value=number_format($cents/100,2,'.',',');return strtoupper($currency)==='EUR'?'€'.$value:$value.' '.self::e(strtoupper($currency)); }
    private static function friendlyDate(mixed $value): string { if(!is_string($value)||trim($value)==='')return 'Not available';try{return(new \DateTimeImmutable($value))->format('M j, Y');}catch(\Throwable){return'Not available';} }
    private static function remaining(mixed $value): string { if(!is_string($value)||trim($value)==='')return 'Pending activation';$seconds=strtotime($value)-time();if($seconds<=0)return'Expired';$days=(int)ceil($seconds/86400);return$days.' day'.($days===1?'':'s').' remaining'; }
    private static function relativeTime(mixed $value): string { if(!is_string($value)||strtotime($value)===false)return'not available';$seconds=max(0,time()-strtotime($value));if($seconds<60)return'just now';if($seconds<3600){$n=(int)floor($seconds/60);return$n.' min ago';}if($seconds<86400){$n=(int)floor($seconds/3600);return$n.' hr ago';}$n=(int)floor($seconds/86400);return$n.' day'.($n===1?'':'s').' ago'; }
    private static function sectionLabel(string $section): string { return ucwords(str_replace('-',' ',$section)); }
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
