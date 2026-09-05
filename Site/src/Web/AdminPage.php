<?php

declare(strict_types=1);

namespace Pericles\Web;

final class AdminPage
{
    public static function render(string $email, array $data, string $csrfToken, ?array $flash = null, string $section = 'overview', array $context = []): never
    {
        $titles = [
            'overview' => ['Overview', 'Revenue, customers, Access, support, and product health.'],
            'enhancements' => ['Enhancements', 'Manage commercial products and open a focused editor.'],
            'product_editor' => ['Enhancement Editor', 'Manage one Enhancement without loading every editor at once.'],
            'orders' => ['Orders & Payments', 'Review orders, payment state, providers, and Access assignment.'],
            'order_detail' => ['Order Detail', 'Verified payment and commercial Access details.'],
            'coupons' => ['Coupons', 'Review configured commercial promotion codes.'],
            'access_keys' => ['Access Keys', 'Generate keys for promotions, partners, support, and distribution.'],
            'users' => ['Users', 'Review accounts, roles, commercial activity, and platform access.'],
            'user_detail' => ['User Detail', 'Commercial account summary, Access, orders, devices, and tickets.'],
            'customer_access' => ['Customer Access', 'Grant, extend, convert, revoke, and reset commercial Access.'],
            'devices' => ['Devices', 'Review Authorized Devices linked to customer accounts.'],
            'support' => ['Support', 'Prioritized customer support inbox.'],
            'support_ticket' => ['Support Ticket', 'Conversation, customer context, assignment, and audited actions.'],
            'documentation' => ['Documentation', 'Manage product guides from each Enhancement editor.'],
            'changelog' => ['Changelog', 'Manage public product updates from each Enhancement editor.'],
            'status' => ['Status & Incidents', 'Manage public service and Enhancement status.'],
            'builds' => ['Builds', 'Review published Enhancement components and release state.'],
            'audit' => ['Audit Log', 'Human-readable sensitive activity recorded by the platform.'],
            'settings' => ['Settings', 'Safe application configuration; secrets remain environment-managed.'],
        ];
        if (!isset($titles[$section])) $section = 'overview';
        $permissions = $data['permissions'];
        $roleName = (string) $data['role']['name'];
        self::header($titles[$section][0]);
        echo '<div class="app-shell admin-ui"><aside class="app-sidebar"><div class="sidebar-brand">' . self::brand('/admin') . '<button type="button" data-sidebar-toggle aria-label="Close menu">×</button></div><nav class="app-nav">';
        $nav = [
            'Overview'=>[['overview','Overview','grid','/admin']],
            'Catalog'=>[['enhancements','Enhancements','package','/admin/enhancements']],
            'Commerce'=>[['orders','Orders & Payments','receipt','/admin/orders'],['coupons','Coupons','key','/admin/coupons'],['access_keys','Access Keys','key','/admin/access-keys']],
            'Customers'=>[['users','Users','users','/admin/users'],['customer_access','Customer Access','shield','/admin/customer-access'],['devices','Devices','monitor','/admin/devices'],['support','Support','headphones','/admin/support']],
            'Content'=>[['documentation','Documentation','receipt','/admin/documentation'],['changelog','Changelog','activity','/admin/changelog']],
            'System'=>[['status','Status & Incidents','activity','/admin/status'],['builds','Builds','package','/admin/builds'],['audit','Audit Log','shield','/admin/audit'],['settings','Settings','settings','/admin/settings']],
        ];
        foreach($nav as $group=>$items){echo '<p>'.self::e(strtoupper($group)).'</p>';foreach($items as [$key,$label,$icon,$path]){
            if ($key === 'audit' && !in_array('audit.view', $permissions, true)) continue;
            if ($key === 'users' && !in_array('users.view', $permissions, true)) continue;
            if (in_array($key,['orders','coupons'],true) && !in_array('commerce.view',$permissions,true)) continue;
            if ($key === 'access_keys' && !in_array('licenses.manage',$permissions,true)) continue;
            if ($key === 'devices' && !in_array('devices.manage',$permissions,true)) continue;
            if ($key === 'support' && !in_array('support.manage',$permissions,true)) continue;
            if ($key === 'builds' && !in_array('audit.view',$permissions,true)) continue;
            if(in_array($key,['enhancements','documentation','changelog','status','settings'],true)&&!in_array('content.manage',$permissions,true)&&!in_array('commerce.manage',$permissions,true))continue;
            echo '<a class="' . (($section === $key||($section==='product_editor'&&$key==='enhancements')||($section==='support_ticket'&&$key==='support')||($section==='user_detail'&&$key==='users')||($section==='order_detail'&&$key==='orders')) ? 'active' : '') . '" href="' . self::e(self::url($path)) . '">' . self::icon($icon) . '<span>' . self::e($label) . '</span></a>';
        }}
        echo '<p>Navigation</p><a href="' . self::e(self::url('/account')) . '">' . self::icon('arrow-left') . '<span>Player workspace</span></a></nav><div class="sidebar-account"><span class="avatar">' . self::e(strtoupper(substr($email, 0, 1))) . '</span><div><strong>' . self::e($roleName) . '</strong><small>' . self::e($email) . '</small></div></div></aside>'
            . '<div class="app-area"><header class="app-topbar"><button class="mobile-menu" type="button" data-sidebar-toggle aria-label="Open menu">' . self::icon('menu') . '</button><div class="breadcrumb"><span>Administration</span><i>/</i><strong>' . self::e($titles[$section][0]) . '</strong></div><span class="operational"><i></i> Server-side permissions</span><span class="role-label">' . self::e($roleName) . '</span><details class="account-menu"><summary><span class="avatar">' . self::e(strtoupper(substr($email, 0, 1))) . '</span></summary><div><strong>' . self::e($roleName) . '</strong><small>' . self::e($email) . '</small><hr><a href="' . self::e(self::url('/account')) . '">Player Workspace</a><form method="post" action="' . self::e(self::url('/logout')) . '"><input type="hidden" name="csrf" value="' . self::e($csrfToken) . '"><button type="submit">Sign Out</button></form></div></details></header><main class="app-main">';
        self::flash($flash);
        echo '<div class="page-heading"><div><h1>' . self::e($titles[$section][0]) . '</h1><p>' . self::e($titles[$section][1]) . '</p></div>';
        if ($section === 'users') echo '<span class="role-label">Latest 100 accounts</span>';
        if ($section === 'customer_access' && in_array('licenses.manage', $permissions, true)) echo '<a class="btn btn-primary" href="#grant-access">Grant Access</a>';
        if ($section === 'access_keys' && in_array('licenses.manage', $permissions, true)) echo '<a class="btn btn-primary" href="#generate-key">Generate Key</a>';
        echo '</div>';

        match ($section) {
            'users' => self::users($data, $csrfToken),
            'user_detail' => self::userDetail($data,$csrfToken,$context),
            'customer_access' => self::customerAccess($data, $csrfToken),
            'access_keys' => self::accessKeys($data,$csrfToken),
            'enhancements' => self::enhancements($data),
            'product_editor' => self::productEditor($data,$csrfToken,$context),
            'orders' => self::orders($data),
            'order_detail' => self::orderDetail($context),
            'coupons' => self::coupons($data),
            'devices' => self::devices($data),
            'support' => self::support($data),
            'support_ticket' => self::supportTicket($data,$csrfToken,$context),
            'documentation' => self::contentDirectory($data,'documentation'),
            'changelog' => self::contentDirectory($data,'changelog'),
            'status' => self::status($data,$csrfToken),
            'builds' => self::builds($data),
            'audit' => self::audit($data),
            'settings' => self::settings($data,$csrfToken),
            default => self::overview($data),
        };
        echo '</main></div><button class="sidebar-scrim" type="button" data-sidebar-toggle aria-label="Close menu"></button></div></body></html>';
        exit;
    }

    private static function overview(array $data): void
    {
        echo '<section class="metric-strip">'.self::metric('Revenue — 30 Days',self::money((int)($data['metrics']['revenue_30_days_cents']??0),'EUR'),'verified paid orders').self::metric('Orders — 30 Days',(string)($data['metrics']['orders_30_days']??0),'all commercial states').self::metric('Active Customers',(string)($data['metrics']['active_customers']??0),'customers with active Access').self::metric('Active Access',(string)$data['metrics']['active_subscriptions'],'current Enhancements').'</section><section class="admin-overview-grid">';
        echo '<div class="data-panel"><div class="panel-header"><h2>Recent Orders</h2><a href="'.self::e(self::url('/admin/orders')).'">View all</a></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th></tr></thead><tbody>';foreach(array_slice($data['payments'],0,5) as $order)echo '<tr><td class="mono">'.self::e((string)$order['order_number']).'</td><td>'.self::e((string)$order['email']).'</td><td>'.self::money((int)$order['amount_cents'],(string)$order['currency']).'</td><td>'.self::badge((string)$order['status']).'</td></tr>';if($data['payments']===[])self::emptyRow(4,'No order has been recorded.');echo '</tbody></table></div></div>';
        echo '<div class="data-panel"><div class="panel-header"><h2>Support Queue</h2><a href="'.self::e(self::url('/admin/support')).'">Open inbox</a></div><ul class="support-queue-summary">';foreach(['urgent','high','open','awaiting_user'] as $key){$count=count(array_filter($data['support_tickets'],static fn(array$t):bool=>$key==='open'?(string)$t['status']==='open':($key==='awaiting_user'?(string)$t['status']==='awaiting_user':(string)($t['priority']??'normal')===$key)));echo '<li><span>'.self::e(strtoupper(str_replace('_',' ',$key))).'</span><strong>'.$count.'</strong></li>';}echo '</ul></div>';
        echo '<div class="data-panel product-health-panel"><div class="panel-header"><h2>Product Health</h2><a href="'.self::e(self::url('/admin/status')).'">Manage Status</a></div><div class="product-health-list">';foreach($data['products'] as $product)echo '<article><div><strong>'.self::e((string)($product['game_name']??$product['name'])).' Enhancement</strong><small>Latest version '.self::e((string)($product['latest_version']??'not published')).'</small></div>'.self::badge((string)($product['commercial_status']??'unavailable')).'<span>'.(int)($product['active_access_count']??0).' active</span></article>';if($data['products']===[])echo '<p class="empty-list">No Enhancement configured.</p>';echo '</div></div>';
        $planSales=[];$productRevenue=[];foreach($data['payments'] as $order)if((string)$order['status']==='paid'){$plan=(string)$order['plan_name_snapshot'];$product=(string)$order['product_name_snapshot'];$planSales[$plan]=($planSales[$plan]??0)+1;$productRevenue[$product]=($productRevenue[$product]??0)+(int)$order['amount_cents'];}echo '<div class="data-panel"><div class="panel-header"><h2>Plan Sales Breakdown</h2><span>Paid orders</span></div><ul class="summary-list">';foreach($planSales as $plan=>$count)echo '<li><span>'.self::e($plan).'</span><strong>'.(int)$count.'</strong></li>';if($planSales===[])echo '<li class="empty-list">No paid plan sale recorded.</li>';echo '</ul></div><div class="data-panel"><div class="panel-header"><h2>Revenue by Enhancement</h2><span>Loaded paid orders</span></div><ul class="summary-list">';arsort($productRevenue);foreach($productRevenue as $product=>$cents)echo '<li><span>'.self::e($product).'</span><strong>'.self::money((int)$cents,'EUR').'</strong></li>';if($productRevenue===[])echo '<li class="empty-list">No paid product revenue recorded.</li>';echo '</ul></div></section>';
    }

    private static function users(array $data, string $csrf): void
    {
        $canManage = in_array('users.manage', $data['permissions'], true);
        echo '<section class="data-panel"><div class="table-toolbar"><label>' . self::icon('search') . '<input type="search" placeholder="Search users" data-table-search></label><span>' . count($data['users']) . ' accounts</span></div><div class="table-wrap"><table class="data-table admin-table-wide"><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Active Enhancements</th><th>Devices</th><th>Orders</th><th>Total Spent</th><th>Joined</th><th>Actions</th></tr></thead><tbody>';
        foreach ($data['users'] as $user) {
            echo '<tr data-search-row><td><a class="table-link" href="'.self::e(self::url('/admin/users/'.(int)$user['id'])).'"><strong>' . self::e(self::userName($user)) . '</strong><small>' . self::e((string) $user['email']) . '</small></a></td><td><span class="role-label">' . self::e((string) $user['role_name']) . '</span></td><td>' . self::badge((string) $user['status']) . '</td><td>' . (int) ($user['active_access_count']??$user['product_count']) . '</td><td>' . (int) $user['device_count'] . '</td><td>'.(int)($user['order_count']??0).'</td><td>'.self::money((int)($user['total_spent_cents']??0),'EUR').'</td><td>' . self::date($user['created_at']) . '</td><td>';
            if ($canManage) {
                echo '<details class="row-actions"><summary class="btn btn-outline btn-small">Manage</summary><div><form method="post" action="' . self::e(self::url('/admin/users/' . (int) $user['id'] . '/role')) . '"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><label>Role<select name="role"><option value="player"' . ((string) $user['role_slug'] === 'player' ? ' selected' : '') . '>Player</option><option value="moderator"' . ((string) $user['role_slug'] === 'moderator' ? ' selected' : '') . '>Moderator</option><option value="admin"' . ((string) $user['role_slug'] === 'admin' ? ' selected' : '') . '>Administrator</option></select></label><button class="btn btn-primary btn-small" type="submit">Save role</button></form><form method="post" action="' . self::e(self::url('/admin/users/' . (int) $user['id'] . '/status')) . '" data-confirm="Change this account status?"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><input type="hidden" name="status" value="' . ((string) $user['status'] === 'active' ? 'disabled' : 'active') . '"><button class="btn btn-outline btn-small danger" type="submit">' . ((string) $user['status'] === 'active' ? 'Disable account' : 'Reactivate account') . '</button></form></div></details>';
            } else echo '<span class="muted-note">Read only</span>';
            echo '</td></tr>';
        }
        if ($data['users'] === []) self::emptyRow(9, 'No user is available for your permission level.');
        echo '</tbody></table></div></section>';
    }

    private static function userDetail(array $data,string $csrf,array $context): void
    {
        $detail=$context['user_detail']??null;if(!is_array($detail)){self::emptyState('users','User not found','Return to the Users list.');return;}$user=$detail['user'];$canManage=in_array('licenses.manage',$data['permissions'],true);$canExtend=$canManage||in_array('access.support_actions',$data['permissions'],true);$canDevices=in_array('devices.manage',$data['permissions'],true);
        echo '<section class="entity-heading"><a class="eyebrow-link" href="'.self::e(self::url('/admin/users')).'">← Users</a><div><span class="avatar">'.self::e(strtoupper(substr(self::userName($user),0,1))).'</span><div><h2>'.self::e(self::userName($user)).'</h2><p>'.self::e((string)$user['email']).' · Joined '.self::date($user['created_at']).'</p></div></div><div>'.self::badge((string)$user['status']).'<span class="role-label">'.self::e((string)$user['role_name']).'</span></div></section><section class="metric-strip user-metrics">'.self::metric('Total Spent',self::money((int)$detail['summary']['total_spent_cents'],'EUR'),'verified paid orders').self::metric('Orders',(string)$detail['summary']['orders'],'all states').self::metric('Active Enhancements',(string)$detail['summary']['active_enhancements'],'current Access').self::metric('Lifetime Enhancements',(string)$detail['summary']['lifetime_enhancements'],'does not expire').'</section><div class="detail-quick-actions"><a class="btn btn-primary" href="'.self::e(self::url('/admin/customer-access#grant-access')).'">Grant Access</a><a class="btn btn-outline" href="#user-orders">View Orders</a><a class="btn btn-outline" href="#user-tickets">View Tickets</a></div>';
        echo '<section class="data-panel secondary-panel"><div class="panel-header"><h2>Enhancements &amp; Access</h2><span>'.count($detail['access']).' records</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Enhancement</th><th>Plan</th><th>Status</th><th>Started</th><th>Expiration</th><th>Device</th><th>Actions</th></tr></thead><tbody>';foreach($detail['access'] as $access){$lifetime=(int)($access['is_lifetime']??0)===1||($access['expires_at']===null&&(string)$access['status']==='active');echo '<tr><td><strong>'.self::e((string)$access['product_name']).'</strong></td><td>'.self::e((string)($access['plan_name']??'Access')).'</td><td>'.self::badge((string)$access['status']).'</td><td>'.self::date($access['started_at']).'</td><td>'.($lifetime?'Lifetime Access':self::date($access['expires_at'])).'</td><td>'.self::e((string)($access['device_name']??'Not linked')).'</td><td><div class="inline-actions">'.($canExtend?'<a class="btn btn-outline btn-small" href="'.self::e(self::url('/admin/customer-access')).'">Manage Access</a>':'').($canDevices&&$access['bound_device_id']!==null?'<form method="post" action="'.self::e(self::url('/admin/subscriptions/'.(int)$access['id'].'/unbind')).'" data-confirm="Reset this Authorized Device?"><input type="hidden" name="csrf" value="'.self::e($csrf).'"><button class="btn btn-outline btn-small">Reset Device</button></form>':'').'</div></td></tr>';}if($detail['access']===[])self::emptyRow(7,'No commercial Access has been granted.');echo '</tbody></table></div></section>';
        echo '<section class="data-panel secondary-panel" id="user-orders"><div class="panel-header"><h2>Orders</h2><span>'.count($detail['orders']).'</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Order</th><th>Enhancement</th><th>Plan</th><th>Total</th><th>Status</th><th>Date</th></tr></thead><tbody>';foreach($detail['orders'] as $order)echo '<tr><td><a class="table-link mono" href="'.self::e(self::url('/admin/orders/'.rawurlencode((string)$order['order_number']))).'">'.self::e((string)$order['order_number']).'</a></td><td>'.self::e((string)$order['product_name_snapshot']).'</td><td>'.self::e((string)$order['plan_name_snapshot']).'</td><td>'.self::money((int)$order['amount_cents'],(string)$order['currency']).'</td><td>'.self::badge((string)$order['status']).'</td><td>'.self::date($order['paid_at']??$order['created_at']).'</td></tr>';if($detail['orders']===[])self::emptyRow(6,'No order has been recorded.');echo '</tbody></table></div></section>';
        echo '<section class="detail-split secondary-panel"><div class="data-panel"><div class="panel-header"><h2>Devices</h2><span>'.count($detail['devices']).'</span></div><div class="entity-list">';foreach($detail['devices'] as $device)echo '<article><div><strong>'.self::e((string)$device['display_name']).'</strong><small>Last seen '.self::date($device['last_seen_at']).'</small></div>'.self::badge($device['revoked_at']!==null?'revoked':($device['verified_at']!==null?'verified':'pending')).'</article>';if($detail['devices']===[])echo '<p class="empty-list">No Authorized Device.</p>';echo '</div></div><div class="data-panel" id="user-tickets"><div class="panel-header"><h2>Tickets</h2><span>'.count($detail['tickets']).'</span></div><div class="entity-list">';foreach($detail['tickets'] as $ticket)echo '<a href="'.self::e(self::url('/admin/support/'.(string)$ticket['ticket_number'])).'"><div><strong>'.self::e((string)$ticket['subject']).'</strong><small>'.self::e((string)$ticket['ticket_number']).' · '.self::relativeTime($ticket['updated_at']).'</small></div>'.self::badge((string)$ticket['status']).'</a>';if($detail['tickets']===[])echo '<p class="empty-list">No support ticket.</p>';echo '</div></div></section>';
    }

    private static function customerAccess(array $data,string $csrf): void
    {
        $canManage=in_array('licenses.manage',$data['permissions'],true);$canExtend=$canManage||in_array('access.support_actions',$data['permissions'],true);$canDevices=in_array('devices.manage',$data['permissions'],true);
        if($canManage){echo '<section class="data-panel grant-access-panel" id="grant-access"><div><span>DIRECT CUSTOMER ACCESS</span><h2>Grant Access</h2><p>Grant commercial Access directly. No key is exposed or required.</p></div><form method="post" action="'.self::e(self::url('/admin/customer-access/grant')).'">'.self::csrf($csrf).'<label>User<select class="field" name="user_id" required><option value="">Select a customer</option>';foreach($data['users'] as $user)if((string)$user['status']==='active')echo '<option value="'.(int)$user['id'].'">'.self::e((string)$user['email']).'</option>';echo '</select></label><label>Enhancement &amp; Access Plan<select class="field" name="plan_id" required><option value="">Select an Access Plan</option>';foreach($data['plans'] as $plan)echo '<option value="'.(int)$plan['plan_id'].'">'.self::e((string)$plan['product_name']).' · '.self::e((string)$plan['plan_name']).'</option>';echo '</select></label><label>Start behavior<select class="field" name="start_behavior"><option value="first_activation">First activation</option><option value="immediate">Immediately</option></select></label><label>Reason<input class="field" name="reason" minlength="3" maxlength="500" required placeholder="Support case, promotion, or manual correction"></label><button class="btn btn-primary" type="submit">Grant Access</button></form></section>';}
        echo '<section class="data-panel secondary-panel"><div class="table-toolbar"><label>'.self::icon('search').'<input type="search" placeholder="Search customer, Enhancement, plan, or device" data-table-search></label><span>'.count($data['subscriptions']).' Access records</span></div><div class="table-wrap"><table class="data-table admin-table-wide"><thead><tr><th>Customer</th><th>Enhancement</th><th>Plan</th><th>Status</th><th>Started</th><th>Expiration</th><th>Device</th><th>Actions</th></tr></thead><tbody>';
        foreach($data['subscriptions'] as $access){$lifetime=(int)($access['is_lifetime']??0)===1||($access['expires_at']===null&&(string)$access['status']==='active');echo '<tr data-search-row><td>'.self::e((string)$access['email']).'</td><td><strong>'.self::e((string)$access['product_name']).'</strong></td><td>'.self::e((string)($access['plan_name']??($lifetime?'Lifetime':'Legacy Access'))).'</td><td>'.self::badge((string)$access['status']).'</td><td>'.self::date($access['started_at']).'</td><td>'.($lifetime?'Lifetime Access':self::date($access['expires_at'])).'</td><td>'.self::e((string)($access['device_name']?:'Not linked')).'</td><td><details class="row-actions"><summary class="btn btn-outline btn-small">Manage</summary><div>';
            if($canExtend){echo '<form method="post" action="'.self::e(self::url('/admin/customer-access/'.(int)$access['id'].'/extend')).'">'.self::csrf($csrf).'<label>Extend with<select name="plan_id" required>';foreach($data['plans'] as $plan)if((string)$plan['product_slug']===(string)$access['product_slug']&&(int)$plan['is_lifetime']!==1)echo '<option value="'.(int)$plan['plan_id'].'">'.self::e((string)$plan['plan_name']).'</option>';echo '</select></label><input name="reason" minlength="3" maxlength="500" required placeholder="Reason"><button class="btn btn-primary btn-small" type="submit">Extend Access</button></form>';}
            if($canManage){echo '<form method="post" action="'.self::e(self::url('/admin/customer-access/'.(int)$access['id'].'/lifetime')).'" data-confirm="Convert this Access to Lifetime?"><input type="hidden" name="csrf" value="'.self::e($csrf).'"><input name="reason" minlength="3" maxlength="500" required placeholder="Reason"><button class="btn btn-outline btn-small" type="submit">Convert to Lifetime</button></form><form method="post" action="'.self::e(self::url('/admin/customer-access/'.(int)$access['id'].'/revoke')).'" data-confirm="Revoke this customer Access?"><input type="hidden" name="csrf" value="'.self::e($csrf).'"><input name="reason" minlength="3" maxlength="500" required placeholder="Reason"><button class="btn btn-ghost btn-small danger" type="submit">Revoke Access</button></form>';}
            if($canDevices&&$access['bound_device_id']!==null)echo '<form method="post" action="'.self::e(self::url('/admin/subscriptions/'.(int)$access['id'].'/unbind')).'" data-confirm="Reset this Authorized Device?"><input type="hidden" name="csrf" value="'.self::e($csrf).'"><button class="btn btn-outline btn-small" type="submit">Reset Device</button></form>';if(!$canExtend&&!$canDevices)echo '<span class="muted-note">Read only</span>';echo '</div></details></td></tr>';}
        if($data['subscriptions']===[])self::emptyRow(8,'No Customer Access has been granted.');echo '</tbody></table></div></section>';
    }

    private static function accessKeys(array $data,string $csrf): void
    {
        $canManage=in_array('licenses.manage',$data['permissions'],true);if($canManage){echo '<section class="data-panel generate-panel" id="generate-key"><div><span>CONTROLLED DISTRIBUTION</span><h2>Generate Access Keys</h2><p>For promotions, partners, support, and giveaways. Full keys are shown once.</p></div><form method="post" action="'.self::e(self::url('/admin/access-keys/generate')).'">'.self::csrf($csrf).'<label>Enhancement &amp; Plan<select class="field" name="plan_ref" required><option value="">Select a plan</option>';foreach($data['plans'] as $plan)echo '<option value="'.self::e((string)$plan['product_slug'].':'.(string)$plan['plan_slug']).'">'.self::e((string)$plan['product_name']).' · '.self::e((string)$plan['plan_name']).'</option>';echo '</select></label><label>Quantity<input class="field" type="number" name="quantity" min="1" max="100" value="1" required></label><label>Expires before redemption<input class="field" type="datetime-local" name="expires_at"></label><label>Reason<input class="field" name="reason" minlength="3" maxlength="500" required></label><button class="btn btn-primary" type="submit">Generate</button></form></section>';}
        echo '<section class="data-panel secondary-panel"><div class="table-toolbar"><label>'.self::icon('search').'<input type="search" placeholder="Search keys, products, or customers" data-table-search></label><span>'.count($data['access_keys']).' keys</span></div><div class="table-wrap"><table class="data-table admin-table-wide"><thead><tr><th>Key</th><th>Enhancement</th><th>Plan</th><th>Status</th><th>Created</th><th>Redeemed By</th><th>Redeemed At</th></tr></thead><tbody>';foreach($data['access_keys'] as $key)echo '<tr data-search-row><td class="mono">PERI-••••-'.self::e(ltrim((string)$key['key_hint'],'.')).'</td><td>'.self::e((string)$key['product_name']).'</td><td>'.self::e((string)$key['plan_name']).'</td><td>'.self::badge((string)$key['status']).'</td><td>'.self::date($key['created_at']).'</td><td>'.self::e((string)($key['redeemed_by']??'—')).'</td><td>'.self::date($key['redeemed_at']??null).'</td></tr>';if($data['access_keys']===[])self::emptyRow(7,'No Access Key has been generated.');echo '</tbody></table></div></section>';
    }

    private static function enhancements(array $data): void
    {
        echo '<section class="admin-enhancement-list">';foreach($data['products'] as $product)echo '<article class="data-panel admin-enhancement-row"><div class="admin-enhancement-cover">'.self::cover($product).'</div><div><span>'.self::e(strtoupper((string)($product['game_name']??$product['name']))).'</span><h2>'.self::e((string)$product['name']).'</h2><p>'.self::e((string)($product['short_description']??'No commercial description yet.')).'</p><ul><li>'.(int)($product['plan_count']??count($product['commerce_plans']??[])).' Access Plans</li><li>'.count($product['feature_categories']??[]).' Feature Categories</li><li>'.(int)($product['media_count']??count($product['media']??[])).' Media</li><li>v'.self::e((string)($product['latest_version']??'—')).'</li></ul></div><div class="admin-enhancement-state">'.self::badge((string)($product['commercial_status']??'unavailable')).'<a class="btn btn-primary" href="'.self::e(self::url('/admin/enhancements/'.(int)$product['id'])).'">Edit</a></div></article>';if($data['products']===[])self::emptyState('package','No Enhancements','No product is configured.');echo '</section>';
    }

    private static function productEditor(array $data,string $csrf,array $context): void
    {
        $product=null;foreach($data['products'] as $candidate)if((int)$candidate['id']===(int)($context['product_id']??0)){$product=$candidate;break;}if(!is_array($product)){self::emptyState('package','Enhancement not found','Return to the Enhancement catalogue and choose a valid product.');return;}
        $tabs=['general'=>'General','media'=>'Media','features'=>'Features','pricing'=>'Pricing','compatibility'=>'Compatibility','documentation'=>'Documentation','changelog'=>'Changelog','faq'=>'FAQ','builds'=>'Builds','seo'=>'SEO'];$tab=(string)($context['tab']??'general');if(!isset($tabs[$tab]))$tab='general';$id=(int)$product['id'];$base='/admin/enhancements/'.$id;
        echo '<section class="product-editor-heading"><a href="'.self::e(self::url('/admin/enhancements')).'">← Enhancements</a><div><span>'.self::e(strtoupper((string)($product['game_name']??$product['name']))).'</span><h2>'.self::e((string)$product['name']).'</h2></div>'.self::badge((string)($product['commercial_status']??'unavailable')).'</section><nav class="editor-tabs" aria-label="Enhancement editor">';foreach($tabs as $key=>$label)echo '<a class="'.($tab===$key?'active':'').'" href="'.self::e(self::url($base.'?tab='.$key)).'">'.self::e($label).'</a>';echo '</nav><section class="data-panel editor-panel">';
        if(in_array($tab,['general','compatibility','seo'],true)){
            $visible=$tab==='general'?['name','image_url','short_description','description','is_public','is_featured']:($tab==='compatibility'?['operating_systems','compatibility','requirements']:['seo_title','seo_description']);
            echo '<form class="admin-content-form product-tab-form" method="post" action="'.self::e(self::url('/admin/products/'.$id.'/content')).'">'.self::csrf($csrf).self::contentHiddenFields($product,$visible);
            if($tab==='general')echo self::input('Enhancement Name','name',(string)$product['name'],true).'<label>Game Name<input class="field" value="'.self::e((string)($product['game_name']??'')).'" readonly></label><label>Slug<input class="field" value="'.self::e((string)($product['game_slug']??$product['slug'])).'" readonly></label><label>Current Version<input class="field" value="'.self::e((string)($product['latest_version']??'Not published')).'" readonly></label><label>Last Updated<input class="field" value="'.self::date($product['updated_at']??$product['status_updated_at']??null).'" readonly></label>'.self::input('Cover Image URL','image_url',(string)($product['image_url']??'')).self::input('Short Description','short_description',(string)($product['short_description']??'')).'<label>Long Description<textarea class="field" name="description" rows="8">'.self::e((string)($product['description']??'')).'</textarea></label><label class="admin-check"><input type="checkbox" name="is_public" value="1"'.((int)($product['is_public']??0)===1?' checked':'').'> Public</label><label class="admin-check"><input type="checkbox" name="is_featured" value="1"'.((int)($product['is_featured']??0)===1?' checked':'').'> Featured</label>';
            elseif($tab==='compatibility')echo self::input('Operating Systems','operating_systems',(string)($product['operating_systems']??'')).self::input('Compatibility Notes','compatibility',(string)($product['compatibility']??'')).'<label>Requirements<textarea class="field" name="requirements" rows="8">'.self::e((string)($product['requirements']??'')).'</textarea></label>';
            else echo self::input('SEO Title','seo_title',(string)($product['seo_title']??'')).'<label>Meta Description<textarea class="field" name="seo_description" maxlength="500" rows="5">'.self::e((string)($product['seo_description']??'')).'</textarea><small>Titles automatically avoid a duplicate Pericles suffix.</small></label><div class="seo-preview"><span>'.self::e((string)($product['game_slug']??$product['slug'])).'</span><strong>'.self::e((string)($product['seo_title']??$product['name'].' | Pericles')).'</strong><p>'.self::e((string)($product['seo_description']??$product['short_description']??'')).'</p></div>';
            echo '<button class="btn btn-primary" type="submit">Save Changes</button></form>';
            if($tab==='general')echo '<form class="admin-status-form secondary-panel" method="post" action="'.self::e(self::url('/admin/enhancements/'.(string)($product['game_slug']??$product['slug']).'/status')).'">'.self::csrf($csrf).'<label>Public Status<select name="status">'.self::statusOptions((string)($product['commercial_status']??'unavailable')).'</select></label><label class="admin-check"><input type="checkbox" name="purchases_allowed" value="1"'.((int)($product['purchases_allowed']??0)===1?' checked':'').'> Purchases allowed</label><label class="admin-check"><input type="checkbox" name="freeze_access" value="1"> Extend timed Access by this downtime</label><label>Incident message<input class="field" name="reason" maxlength="255"></label><button class="btn btn-outline" type="submit">Update Status</button></form>';
        }elseif($tab==='pricing'){
            echo '<form class="pricing-editor" method="post" action="'.self::e(self::url('/admin/products/'.$id.'/pricing')).'">'.self::csrf($csrf).'<div class="pricing-table"><div class="pricing-head"><span>Plan</span><span>Price</span><span>Sale Price</span><span>Badge</span><span>Active</span></div>';foreach($product['commerce_plans']??[] as $plan){echo '<div class="pricing-row"><strong>'.self::e((string)$plan['name']).'</strong><label><span>Price</span><div class="money-input"><b>'.self::currencySymbol((string)$plan['currency']).'</b><input name="plans['.(int)$plan['id'].'][price]" value="'.number_format((int)$plan['price_cents']/100,2,'.','').'" inputmode="decimal" required></div></label><label><span>Sale Price</span><div class="money-input"><b>'.self::currencySymbol((string)$plan['currency']).'</b><input name="plans['.(int)$plan['id'].'][sale_price]" value="'.($plan['sale_price_cents']===null?'':number_format((int)$plan['sale_price_cents']/100,2,'.','')).'" inputmode="decimal"></div></label><label><span>Badge</span><input class="field" name="plans['.(int)$plan['id'].'][badge]" maxlength="40" value="'.self::e((string)($plan['badge']??'')).'"></label><label class="admin-check"><input type="checkbox" name="plans['.(int)$plan['id'].'][is_active]" value="1"'.((int)$plan['is_active']===1?' checked':'').'> Active</label></div>';}echo '</div><button class="btn btn-primary" type="submit">Save Changes</button><p class="panel-note">Public savings are calculated automatically. Sale price must be lower than the regular price.</p></form>';
        }elseif($tab==='media'){
            echo '<div class="editor-list">';foreach($product['media']??[] as $media){$preview=(string)($media['thumbnail_url']?:$media['poster_url']?:$media['url']);echo '<article class="media-admin-item"><div class="media-admin-preview">'.(((string)$media['media_type']==='video')?'<span>VIDEO</span>':'<img loading="lazy" src="'.self::e($preview).'" alt="">').'</div><div><strong>'.self::e((string)($media['title']?:ucfirst((string)$media['media_type']))).'</strong><small>'.((int)($media['is_primary']??0)===1?'Primary · ':'').((int)$media['is_public']===1?'Public':'Hidden').'</small></div><details><summary class="btn btn-outline btn-small">Edit</summary><form class="admin-content-form" method="post" action="'.self::e(self::url('/admin/products/'.$id.'/media/'.(int)$media['id'])).'">'.self::csrf($csrf).self::mediaFields($media).'<button class="btn btn-primary btn-small" type="submit">Save Media</button></form>'.self::deleteForm('/admin/media/'.(int)$media['id'].'/delete',$csrf,'Delete').'</details></article>';}
            echo '<details class="add-editor-item"><summary class="btn btn-primary">Add Media</summary><form class="admin-content-form" method="post" action="'.self::e(self::url('/admin/products/'.$id.'/media')).'">'.self::csrf($csrf).self::mediaFields(['media_type'=>'image','url'=>'','thumbnail_url'=>'','poster_url'=>'','title'=>'','alt_text'=>'','sort_order'=>count($product['media']??[])*10+10,'is_public'=>1,'is_active'=>1,'is_primary'=>0]).'<button class="btn btn-primary" type="submit">Add Media</button></form></details></div>';
        }elseif($tab==='features'){
            echo '<div class="feature-admin-categories">';foreach($product['feature_categories']??[] as $category){$categoryId=(int)$category['id'];echo '<article class="feature-admin-category"><header><div><h3>'.self::e((string)$category['name']).'</h3><span>'.count($category['features']??[]).' Features</span></div><details><summary class="btn btn-outline btn-small">Edit Category</summary><form class="admin-content-form" method="post" action="'.self::e(self::url('/admin/products/'.$id.'/feature-categories/'.$categoryId)).'">'.self::csrf($csrf).self::input('Category','name',(string)$category['name'],true).self::input('Description','description',(string)($category['description']??'')).self::numberInput('Order',(int)$category['sort_order']).'<label class="admin-check"><input type="checkbox" name="is_active" value="1"'.((int)$category['is_active']===1?' checked':'').'> Active</label><button class="btn btn-primary btn-small">Save</button></form>'.self::deleteForm('/admin/feature-categories/'.$categoryId.'/delete',$csrf,'Delete Category').'</details></header><div class="feature-admin-list">';foreach($category['features']??[] as $feature)echo '<details><summary><span class="drag-handle" aria-hidden="true">↕</span><strong>'.self::e((string)$feature['name']).'</strong><span class="admin-badges">'.((int)$feature['is_public']===1?'<i>Public</i>':'<i>Hidden</i>').((int)$feature['is_highlighted']===1?'<i>Highlighted</i>':'').((int)$feature['is_active']===1?'<i>Active</i>':'<i>Inactive</i>').'</span></summary><form class="admin-content-form" method="post" action="'.self::e(self::url('/admin/feature-categories/'.$categoryId.'/features/'.(int)$feature['id'])).'">'.self::csrf($csrf).self::input('Feature','name',(string)$feature['name'],true).self::input('Description','short_description',(string)($feature['short_description']??'')).self::numberInput('Order',(int)$feature['sort_order']).self::featureFlags($feature).'<button class="btn btn-primary btn-small">Save Feature</button></form>'.self::deleteForm('/admin/features/'.(int)$feature['id'].'/delete',$csrf,'Delete Feature').'</details>';echo '<details class="add-feature"><summary>+ Add Feature</summary><form class="admin-content-form" method="post" action="'.self::e(self::url('/admin/feature-categories/'.$categoryId.'/features')).'">'.self::csrf($csrf).self::input('Feature','name','',true).self::input('Description','short_description').self::numberInput('Order',count($category['features']??[])*10+10).self::featureFlags(['is_public'=>1,'is_active'=>1]).'<button class="btn btn-primary btn-small">Add Feature</button></form></details></div></article>';}
            echo '<details class="add-editor-item"><summary class="btn btn-primary">Add Category</summary><form class="admin-content-form" method="post" action="'.self::e(self::url('/admin/products/'.$id.'/feature-categories')).'">'.self::csrf($csrf).self::input('Category','name','',true).self::input('Description','description').self::numberInput('Order',count($product['feature_categories']??[])*10+10).'<label class="admin-check"><input type="checkbox" name="is_active" checked> Active</label><button class="btn btn-primary">Add Category</button></form></details></div>';
        }elseif($tab==='documentation')self::documentationEditor($product,$csrf);
        elseif($tab==='changelog')self::changelogEditor($product,$csrf);
        elseif($tab==='faq')self::faqEditor($product,$csrf);
        else self::buildTable(array_values(array_filter($data['versions'],static fn(array$v):bool=>(string)$v['product_name']===(string)($product['game_name']??$product['name']))));
        echo '</section>';
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

    private static function orders(array $data): void
    {
        $states=['paid'=>0,'pending'=>0,'failed'=>0,'refunded'=>0];foreach($data['payments'] as $payment)if(isset($states[(string)$payment['status']]))$states[(string)$payment['status']]++;echo '<section class="commerce-kpis">'.self::metric('Revenue Today',self::money((int)array_sum(array_map(static fn(array$o):int=>(string)$o['status']==='paid'&&substr((string)$o['paid_at'],0,10)===gmdate('Y-m-d')?(int)$o['amount_cents']:0,$data['payments'])),'EUR'),'verified payments').self::metric('Revenue — 30 Days',self::money((int)($data['metrics']['revenue_30_days_cents']??0),'EUR'),'verified payments').self::metric('Paid Orders',(string)$states['paid'],'confirmed').self::metric('Pending Orders',(string)$states['pending'],'no Access granted').self::metric('Failed',(string)$states['failed'],'no Access granted').self::metric('Refunded',(string)$states['refunded'],'recorded refunds').'</section><section class="data-panel"><div class="panel-header"><h2>Orders &amp; Payments</h2><span>' . count($data['payments']) . ' records</span></div><div class="table-toolbar"><label>'.self::icon('search').'<input type="search" placeholder="Search order, customer, email, or provider" data-table-search></label></div><div class="table-wrap"><table class="data-table admin-table-wide"><thead><tr><th>Order</th><th>Customer</th><th>Enhancement</th><th>Plan</th><th>Total</th><th>Provider</th><th>Reference</th><th>Status</th><th>Date</th></tr></thead><tbody>';
        foreach ($data['payments'] as $payment) echo '<tr data-search-row><td><a class="table-link mono" href="'.self::e(self::url('/admin/orders/'.rawurlencode((string)$payment['order_number']))).'">' . self::e((string)$payment['order_number']) . '</a></td><td>' . self::e((string)$payment['email']) . '</td><td>' . self::e((string)$payment['product_name_snapshot']) . '</td><td>' . self::e((string)$payment['plan_name_snapshot']) . '</td><td>' . self::money((int)$payment['amount_cents'],(string)$payment['currency']) . '</td><td>' . self::e(trim((string)($payment['provider'] ?? ''))?:'Not configured') . '</td><td class="mono">'.self::e(trim((string)($payment['provider_reference']??''))?:'—').'</td><td>' . self::badge((string)$payment['status']) . '</td><td>' . self::date($payment['paid_at'] ?? $payment['created_at']) . '</td></tr>';
        if ($data['payments'] === []) self::emptyRow(9, 'No order has been recorded. Payment provider may not be configured.');
        echo '</tbody></table></div></section>';
    }

    private static function orderDetail(array $context): void
    {
        $order=$context['order_detail']??null;if(!is_array($order)){self::emptyState('receipt','Order not found','Return to Orders & Payments.');return;}$access=$order['access']??null;
        echo '<section class="entity-heading"><a class="eyebrow-link" href="'.self::e(self::url('/admin/orders')).'">← Orders &amp; Payments</a><div><div><span>ORDER</span><h2 class="mono">'.self::e((string)$order['order_number']).'</h2><p>Created '.self::date($order['created_at']).'</p></div></div>'.self::badge((string)$order['status']).'</section><div class="admin-order-layout"><section class="data-panel order-detail-card"><div class="receipt-heading"><div><span>COMMERCIAL ORDER</span><h2>'.self::e((string)$order['product_name_snapshot']).'</h2><p>'.self::e((string)$order['plan_name_snapshot']).' Access</p></div><strong>'.self::money((int)$order['amount_cents'],(string)$order['currency']).'</strong></div><dl><div><dt>Customer</dt><dd><a class="table-link" href="'.self::e(self::url('/admin/users/'.(int)$order['user_id'])).'">'.self::e((string)$order['email']).'</a></dd></div><div><dt>Currency</dt><dd>'.self::e(strtoupper((string)$order['currency'])).'</dd></div><div><dt>Status</dt><dd>'.self::badge((string)$order['status']).'</dd></div><div><dt>Provider</dt><dd>'.self::e(trim((string)($order['provider']??''))?:'Payment provider not configured').'</dd></div><div><dt>Provider Reference</dt><dd class="mono">'.self::e(trim((string)($order['provider_reference']??''))?:'—').'</dd></div><div><dt>Payment Method</dt><dd>'.self::e(trim((string)($order['method']??''))?:'—').'</dd></div><div><dt>Paid At</dt><dd>'.self::date($order['paid_at']).'</dd></div><div><dt>Provider Confirmed</dt><dd>'.self::date($order['confirmed_at']).'</dd></div><div><dt>Terms Version</dt><dd>'.self::e((string)($order['terms_version']??'—')).'</dd></div><div><dt>Access Granted</dt><dd>'.(is_array($access)?'Yes':'No').'</dd></div></dl></section><aside class="data-panel order-access-card"><h2>Customer Access</h2>';
        if(is_array($access))echo '<dl><div><dt>Status</dt><dd>'.self::badge((string)$access['status']).'</dd></div><div><dt>Plan</dt><dd>'.self::e((string)($access['plan_name']??$order['plan_name_snapshot'])).'</dd></div><div><dt>Started</dt><dd>'.self::date($access['started_at']).'</dd></div><div><dt>Expiration</dt><dd>'.($access['expires_at']===null?'Lifetime or pending':self::date($access['expires_at'])).'</dd></div><div><dt>Device</dt><dd>'.self::e((string)($access['device_name']??'Not linked')).'</dd></div></dl><a class="btn btn-outline btn-block" href="'.self::e(self::url('/admin/customer-access')).'">View Customer Access</a>';
        else echo '<div class="empty-inline"><strong>No Access assigned</strong><span>Pending, failed, and refunded orders must not grant Access.</span></div>';
        echo '<a class="btn btn-ghost btn-block" href="'.self::e(self::url('/admin/users/'.(int)$order['user_id'])).'">View User</a></aside></div>';
    }

    private static function payments(array $data): void { self::orders($data); }

    private static function coupons(array $data): void
    {
        echo '<section class="data-panel"><div class="panel-header"><h2>Coupons</h2><span>'.count($data['coupons']??[]).' configured</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Code</th><th>Discount</th><th>Window</th><th>Max Uses</th><th>Status</th></tr></thead><tbody>';foreach($data['coupons']??[] as $coupon){$discount=(string)$coupon['discount_type']==='percentage'?(int)$coupon['discount_value'].'%':self::money((int)$coupon['discount_value'],'EUR');echo '<tr><td class="mono">'.self::e((string)$coupon['code']).'</td><td>'.$discount.'</td><td>'.self::date($coupon['starts_at']).' — '.self::date($coupon['ends_at']).'</td><td>'.self::e((string)($coupon['max_uses']??'Unlimited')).'</td><td>'.self::badge((int)$coupon['is_active']===1?'active':'inactive').'</td></tr>';}if(($data['coupons']??[])===[])self::emptyRow(5,'No coupon is configured.');echo '</tbody></table></div><p class="panel-note">Coupon creation is intentionally unavailable until checkout coupon validation is connected end to end.</p></section>';
    }

    private static function devices(array $data): void
    {
        echo '<section class="data-panel"><div class="table-toolbar"><label>'.self::icon('search').'<input type="search" placeholder="Search customer or device" data-table-search></label><span>'.count($data['devices']??[]).' devices</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Device</th><th>Customer</th><th>Status</th><th>Access</th><th>Linked</th><th>Last Seen</th></tr></thead><tbody>';foreach($data['devices']??[] as $device)echo '<tr data-search-row><td><strong>'.self::e((string)$device['display_name']).'</strong></td><td>'.self::e((string)$device['email']).'</td><td>'.self::badge($device['revoked_at']!==null?'revoked':($device['verified_at']!==null?'verified':'pending')).'</td><td>'.(int)$device['access_count'].'</td><td>'.self::date($device['created_at']).'</td><td>'.self::date($device['last_seen_at']).'</td></tr>';if(($data['devices']??[])===[])self::emptyRow(6,'No Authorized Device has been registered.');echo '</tbody></table></div></section>';
    }

    private static function contentDirectory(array $data,string $type): void
    {
        $label=$type==='documentation'?'Documentation':'Changelog';echo '<section class="admin-enhancement-list">';foreach($data['products'] as $product){$count=count($product[$type==='documentation'?'documents':'changelog']??[]);echo '<article class="data-panel content-directory-row"><div><span>'.self::e(strtoupper((string)($product['game_name']??$product['name']))).'</span><h2>'.self::e((string)$product['name']).'</h2><p>'.$count.' '.$label.' entr'.($count===1?'y':'ies').'</p></div><a class="btn btn-primary" href="'.self::e(self::url('/admin/enhancements/'.(int)$product['id'].'?tab='.$type)).'">Manage '.$label.'</a></article>';}if($data['products']===[])self::emptyState('receipt','No Enhancements','Create a product before adding content.');echo '</section>';
    }

    private static function status(array $data,string $csrf): void
    {
        echo '<section class="status-admin-grid"><div class="data-panel"><div class="panel-header"><h2>Platform Services</h2><span>Public status</span></div><div class="status-admin-list">';foreach($data['service_statuses']??[] as $service)echo '<form method="post" action="'.self::e(self::url('/admin/services/'.(string)$service['service_key'])).'">'.self::csrf($csrf).'<div><strong>'.self::e((string)$service['name']).'</strong><small>Updated '.self::date($service['updated_at']).'</small></div><select class="field" name="status">'.self::statusOptions((string)$service['status']).'</select><input class="field" name="incident" maxlength="500" value="'.self::e((string)($service['incident']??'')).'" placeholder="Public incident message"><button class="btn btn-outline btn-small">Save</button></form>';if(($data['service_statuses']??[])===[])echo '<p class="empty-list">No platform service is configured.</p>';echo '</div></div><div class="data-panel"><div class="panel-header"><h2>Enhancements</h2><span>Status &amp; downtime</span></div><div class="status-admin-list">';foreach($data['products'] as $product)echo '<form method="post" action="'.self::e(self::url('/admin/enhancements/'.(string)($product['game_slug']??$product['slug']).'/status')).'">'.self::csrf($csrf).'<div><strong>'.self::e((string)($product['game_name']??$product['name'])).'</strong><small>'.(int)($product['active_access_count']??0).' active Access</small></div><select class="field" name="status">'.self::statusOptions((string)($product['commercial_status']??'unavailable')).'</select><input class="field" name="reason" maxlength="255" placeholder="Incident or maintenance reason"><label class="admin-check"><input type="checkbox" name="purchases_allowed" value="1"'.((int)($product['purchases_allowed']??0)===1?' checked':'').'> Purchases</label><label class="admin-check"><input type="checkbox" name="freeze_access" value="1"> Freeze timed Access</label><button class="btn btn-outline btn-small">Save</button></form>';echo '</div><p class="panel-note">Enable downtime freeze when starting an incident to extend active timed Access on recovery. Extensions are applied once; Lifetime Access is unaffected.</p></div></section>';
    }

    private static function builds(array $data): void{self::buildTable($data['versions']);}

    private static function settings(array $data,string $csrf): void
    {
        echo '<section class="settings-admin-grid">';foreach($data['settings']??[] as $setting){$key=(string)$setting['setting_key'];$label=ucwords(str_replace('_',' ',$key));echo '<form class="data-panel setting-card" method="post" action="'.self::e(self::url('/admin/settings/'.$key)).'">'.self::csrf($csrf).'<label>'.self::e($label);if((string)$setting['value_type']==='boolean')echo '<select class="field" name="value"><option value="1"'.((string)$setting['setting_value']==='1'?' selected':'').'>Enabled</option><option value="0"'.((string)$setting['setting_value']==='0'?' selected':'').'>Disabled</option></select>';else echo '<input class="field" name="value" value="'.self::e((string)$setting['setting_value']).'">';echo '</label><small>Updated '.self::date($setting['updated_at']).'</small><button class="btn btn-outline btn-small">Save</button></form>';}if(($data['settings']??[])===[])self::emptyState('settings','No editable settings','Safe application settings appear here after migration 010. Secrets remain environment-managed.');echo '</section><p class="panel-note">Payment credentials, webhook secrets, database credentials, and signing keys are never editable here.</p>';
    }

    private static function support(array $data): void
    {
        $counts=['urgent'=>0,'high'=>0,'open'=>0,'awaiting_user'=>0];foreach($data['support_tickets'] as $ticket){if(isset($counts[(string)($ticket['priority']??'normal')]))$counts[(string)$ticket['priority']]++;if(isset($counts[(string)$ticket['status']]))$counts[(string)$ticket['status']]++;}echo '<section class="support-inbox-kpis">'.self::metric('Urgent',(string)$counts['urgent'],'highest priority').self::metric('High',(string)$counts['high'],'paid access blockers').self::metric('Open',(string)$counts['open'],'waiting for staff').self::metric('Waiting User',(string)$counts['awaiting_user'],'customer response').'</section><section class="data-panel"><div class="table-toolbar"><label>'.self::icon('search').'<input type="search" placeholder="Search tickets or customers" data-table-search></label><span>' . count($data['support_tickets']) . ' tickets</span></div><div class="table-wrap"><table class="data-table admin-table-wide"><thead><tr><th>Ticket</th><th>Priority</th><th>Customer</th><th>Enhancement</th><th>Category</th><th>Status</th><th>Assigned To</th><th>Waiting Time</th><th>Updated</th></tr></thead><tbody>';
        foreach ($data['support_tickets'] as $ticket) echo '<tr data-search-row><td><a class="table-link" href="'.self::e(self::url('/admin/support/'.(string)$ticket['ticket_number'])).'"><strong class="mono">' . self::e((string)$ticket['ticket_number']) . '</strong><small>'.self::e((string)$ticket['subject']).'</small></a></td><td>'.self::priorityBadge((string)($ticket['priority']??'normal')).'</td><td>' . self::e((string)$ticket['email']) . '</td><td>'.self::e((string)($ticket['game_name']??'General')).'</td><td>' . self::e(ucfirst((string)$ticket['category'])) . '</td><td>' . self::badge((string)$ticket['status']) . '</td><td>'.self::e((string)($ticket['assigned_email']??'Unassigned')).'</td><td>'.self::relativeTime($ticket['created_at']).'</td><td>' . self::relativeTime($ticket['updated_at']) . '</td></tr>';
        if ($data['support_tickets'] === []) self::emptyRow(9, 'No support ticket has been recorded.');
        echo '</tbody></table></div></section>';
    }

    private static function supportTicket(array $data,string $csrf,array $context): void
    {
        $ticket=$context['ticket']??null;if(!is_array($ticket)){self::emptyState('headphones','Ticket not found','Return to the Support inbox.');return;}echo '<div class="page-heading ticket-admin-heading"><div><a class="eyebrow-link" href="'.self::e(self::url('/admin/support')).'">← Support Inbox</a><h2>'.self::e((string)$ticket['subject']).'</h2><p class="mono">'.self::e((string)$ticket['ticket_number']).'</p></div><div>'.self::priorityBadge((string)$ticket['priority']).self::badge((string)$ticket['status']).'</div></div><div class="admin-ticket-layout"><section class="data-panel ticket-conversation"><div class="panel-header"><h2>Conversation</h2><span>Updated '.self::date($ticket['updated_at']).'</span></div><div class="ticket-thread">';foreach($ticket['messages'] as $message){$note=(int)$message['is_internal_note']===1;$staff=(int)$message['is_staff_reply']===1;$author=$note?'Internal Note':($staff?'Pericles Staff':(trim((string)($message['display_name']??''))?:trim((string)($message['author_email']??''))?:'Customer'));echo '<article class="ticket-message '.($note?'internal-note':($staff?'staff':'customer')).'"><header><strong>'.self::e($author).'</strong><time>'.self::date($message['created_at']).'</time></header><p>'.nl2br(self::e((string)$message['message'])).'</p>';if(($message['attachments']??[])!==[]){echo '<div class="ticket-attachments">';foreach($message['attachments'] as $attachment)echo '<a href="'.self::e(self::url('/support-attachments/'.(int)$attachment['id'])).'">'.self::e((string)$attachment['original_name']).'</a>';echo '</div>';}echo '</article>';}
        echo '</div><form class="ticket-reply admin-ticket-reply" method="post" enctype="multipart/form-data" action="'.self::e(self::url('/admin/support/'.(string)$ticket['ticket_number'].'/update')).'">'.self::csrf($csrf).'<input type="hidden" name="priority" value="'.self::e((string)$ticket['priority']).'"><input type="hidden" name="status" value="awaiting_user"><input type="hidden" name="assigned_to_user_id" value="'.(int)($ticket['assigned_to_user_id']??0).'"><label>Reply or Internal Note<textarea class="field" name="message" maxlength="10000" rows="6"></textarea></label><label>Attachments<input class="field" type="file" name="attachments[]" multiple accept="image/png,image/jpeg,image/webp,image/gif,application/pdf,text/plain"></label><label class="admin-check"><input type="checkbox" name="internal_note" value="1"> Internal Note — never shown to the customer</label><button class="btn btn-primary">Send &amp; Update</button></form></section><aside class="data-panel admin-ticket-sidebar"><h2>Ticket Details</h2><form method="post" action="'.self::e(self::url('/admin/support/'.(string)$ticket['ticket_number'].'/update')).'">'.self::csrf($csrf).'<label>Priority<select class="field" name="priority">'.self::priorityOptions((string)$ticket['priority']).'</select></label><label>Status<select class="field" name="status">'.self::ticketStatusOptions((string)$ticket['status']).'</select></label><label>Assigned Staff<select class="field" name="assigned_to_user_id"><option value="">Unassigned</option>';foreach($data['users'] as $user)if(in_array((string)$user['role_slug'],['admin','moderator'],true))echo '<option value="'.(int)$user['id'].'"'.((int)($ticket['assigned_to_user_id']??0)===(int)$user['id']?' selected':'').'>'.self::e((string)$user['email']).'</option>';echo '</select></label><div class="inline-actions"><button class="btn btn-primary btn-small" name="assign_to_me" value="1">Assign to Me</button><button class="btn btn-outline btn-small">Save Details</button></div></form><dl><div><dt>Customer</dt><dd>'.self::e((string)$ticket['customer_email']).'</dd></div><div><dt>Category</dt><dd>'.self::e(ucfirst((string)$ticket['category'])).'</dd></div><div><dt>Enhancement</dt><dd>'.self::e((string)($ticket['game_name']??'General')).'</dd></div><div><dt>Order</dt><dd>'.self::e((string)($ticket['order_number']??'Not linked')).'</dd></div><div><dt>Created</dt><dd>'.self::date($ticket['created_at']).'</dd></div><div><dt>Updated</dt><dd>'.self::date($ticket['updated_at']).'</dd></div></dl></aside></div>';
    }

    private static function audit(array $data): void
    {
        echo '<section class="data-panel"><div class="table-toolbar"><label>' . self::icon('search') . '<input type="search" placeholder="Search events" data-table-search></label><span>' . count($data['audit']) . ' events</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Actor</th><th>Action</th><th>Target</th><th>IP address</th><th>Date</th></tr></thead><tbody>';
        foreach ($data['audit'] as $entry) echo '<tr data-search-row><td><strong>' . self::e((string) ($entry['actor_email'] ?: 'System')) . '</strong></td><td>' . self::e(self::actionLabel((string) $entry['action'])) . '</td><td>' . self::e((string) $entry['target_type'] . ' #' . (string) $entry['target_id']) . '</td><td class="mono">' . self::e((string) ($entry['ip_address'] ?: '—')) . '</td><td>' . self::date($entry['created_at']) . '</td></tr>';
        if ($data['audit'] === []) self::emptyRow(5, 'No administrative event is available.');
        echo '</tbody></table></div></section>';
    }

    private static function contentHiddenFields(array $product, array $visible): string
    {
        $fields = [
            'name' => (string) ($product['name'] ?? ''),
            'image_url' => (string) ($product['image_url'] ?? ''),
            'short_description' => (string) ($product['short_description'] ?? ''),
            'description' => (string) ($product['description'] ?? ''),
            'compatibility' => (string) ($product['compatibility'] ?? ''),
            'operating_systems' => (string) ($product['operating_systems'] ?? ''),
            'requirements' => (string) ($product['requirements'] ?? ''),
            'seo_title' => (string) ($product['seo_title'] ?? ''),
            'seo_description' => (string) ($product['seo_description'] ?? ''),
        ];
        $html = '';
        foreach ($fields as $name => $value) {
            if (!in_array($name, $visible, true)) {
                $html .= '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';
            }
        }
        foreach (['is_public', 'is_featured'] as $flag) {
            if (!in_array($flag, $visible, true) && (int) ($product[$flag] ?? 0) === 1) {
                $html .= '<input type="hidden" name="' . self::e($flag) . '" value="1">';
            }
        }
        return $html;
    }

    private static function mediaFields(array $media): string
    {
        $type = (string) ($media['media_type'] ?? 'image');
        return '<label>Media Type<select class="field" name="media_type">'
            . '<option value="image"' . ($type === 'image' ? ' selected' : '') . '>Image</option>'
            . '<option value="gif"' . ($type === 'gif' ? ' selected' : '') . '>Animated GIF</option>'
            . '<option value="video"' . ($type === 'video' ? ' selected' : '') . '>Video</option></select></label>'
            . self::input('Media URL', 'url', (string) ($media['url'] ?? ''), true)
            . self::input('Thumbnail URL', 'thumbnail_url', (string) ($media['thumbnail_url'] ?? ''))
            . self::input('Poster URL', 'poster_url', (string) ($media['poster_url'] ?? ''))
            . self::input('Title', 'title', (string) ($media['title'] ?? ''))
            . self::input('Alt Text', 'alt_text', (string) ($media['alt_text'] ?? ''))
            . self::numberInput('Order', (int) ($media['sort_order'] ?? 0))
            . '<label class="admin-check"><input type="checkbox" name="is_primary" value="1"' . ((int) ($media['is_primary'] ?? 0) === 1 ? ' checked' : '') . '> Primary media</label>'
            . self::flags($media);
    }

    private static function documentationEditor(array $product, string $csrf): void
    {
        $productId = (int) $product['id'];
        echo '<div class="editor-list documentation-editor">';
        foreach ($product['documents'] ?? [] as $document) {
            echo '<details class="content-editor-item"><summary><div><strong>' . self::e((string) $document['title']) . '</strong><small>' . self::e(self::sectionLabel((string) $document['section'])) . ' · ' . self::e((string) $document['access_level']) . '</small></div>' . self::badge((int) $document['is_published'] === 1 ? 'published' : 'draft') . '</summary>'
                . '<form class="admin-content-form" method="post" action="' . self::e(self::url('/admin/products/' . $productId . '/documents/' . (int) $document['id'])) . '">' . self::csrf($csrf)
                . self::documentFields($document) . '<button class="btn btn-primary btn-small" type="submit">Save Page</button></form>'
                . self::deleteForm('/admin/documents/' . (int) $document['id'] . '/delete', $csrf, 'Delete Page') . '</details>';
        }
        echo '<details class="add-editor-item"><summary class="btn btn-primary">Add Documentation Page</summary><form class="admin-content-form" method="post" action="' . self::e(self::url('/admin/products/' . $productId . '/documents')) . '">' . self::csrf($csrf)
            . self::documentFields(['title'=>'','slug'=>'','summary'=>'','section'=>'getting-started','body'=>'','access_level'=>'previous_customer','sort_order'=>count($product['documents'] ?? []) * 10 + 10,'is_published'=>1])
            . '<button class="btn btn-primary" type="submit">Publish Page</button></form></details></div>';
    }

    private static function documentFields(array $document): string
    {
        $section = (string) ($document['section'] ?? 'getting-started');
        $level = (string) ($document['access_level'] ?? 'previous_customer');
        $sections = ['installation'=>'Installation','getting-started'=>'Getting Started','configuration'=>'Configuration','features'=>'Features','troubleshooting'=>'Troubleshooting','faq'=>'FAQ','changelog'=>'Changelog'];
        $levels = ['public'=>'Public','previous_customer'=>'Previous Customer','active_access'=>'Active Access Only'];
        $html = self::input('Title', 'title', (string) ($document['title'] ?? ''), true)
            . self::input('Slug', 'slug', (string) ($document['slug'] ?? ''), true)
            . self::input('Summary', 'summary', (string) ($document['summary'] ?? ''))
            . '<label>Section<select class="field" name="section">';
        foreach ($sections as $value => $label) $html .= '<option value="' . self::e($value) . '"' . ($section === $value ? ' selected' : '') . '>' . self::e($label) . '</option>';
        $html .= '</select></label><label>Access Level<select class="field" name="access_level">';
        foreach ($levels as $value => $label) $html .= '<option value="' . self::e($value) . '"' . ($level === $value ? ' selected' : '') . '>' . self::e($label) . '</option>';
        return $html . '</select></label><label>Page Content<textarea class="field editor-body" name="body" rows="14" required>' . self::e((string) ($document['body'] ?? '')) . '</textarea></label>'
            . self::numberInput('Order', (int) ($document['sort_order'] ?? 0))
            . '<label class="admin-check"><input type="checkbox" name="is_published" value="1"' . ((int) ($document['is_published'] ?? 0) === 1 ? ' checked' : '') . '> Published</label>';
    }

    private static function changelogEditor(array $product, string $csrf): void
    {
        $productId = (int) $product['id'];
        echo '<div class="editor-list changelog-editor">';
        foreach ($product['changelog'] ?? [] as $entry) {
            echo '<details class="content-editor-item"><summary><div><strong>v' . self::e((string) $entry['version']) . ' · ' . self::e((string) ($entry['title'] ?? 'Release Update')) . '</strong><small>' . self::date($entry['published_at']) . '</small></div>' . self::badge((int) $entry['is_public'] === 1 ? 'published' : 'draft') . '</summary>'
                . '<form class="admin-content-form" method="post" action="' . self::e(self::url('/admin/products/' . $productId . '/changelog/' . (int) $entry['id'])) . '">' . self::csrf($csrf)
                . self::changelogFields($entry) . '<button class="btn btn-primary btn-small" type="submit">Save Release</button></form>'
                . self::deleteForm('/admin/changelog/' . (int) $entry['id'] . '/delete', $csrf, 'Delete Release') . '</details>';
        }
        echo '<details class="add-editor-item"><summary class="btn btn-primary">Add Changelog Entry</summary><form class="admin-content-form" method="post" action="' . self::e(self::url('/admin/products/' . $productId . '/changelog')) . '">' . self::csrf($csrf)
            . self::changelogFields(['version'=>'','title'=>'','summary'=>'','body'=>'','published_at'=>gmdate('Y-m-d H:i:s'),'is_public'=>1])
            . '<button class="btn btn-primary" type="submit">Publish Release</button></form></details></div>';
    }

    private static function changelogFields(array $entry): string
    {
        $published = '';
        try { $published = (new \DateTimeImmutable((string) ($entry['published_at'] ?? 'now')))->format('Y-m-d\TH:i'); } catch (\Throwable) {}
        return self::input('Version', 'version', (string) ($entry['version'] ?? ''), true)
            . self::input('Title', 'title', (string) ($entry['title'] ?? ''))
            . '<label>Summary<textarea class="field" name="summary" rows="5" required>' . self::e((string) ($entry['summary'] ?? '')) . '</textarea></label>'
            . '<label>Full Notes<textarea class="field editor-body" name="body" rows="10">' . self::e((string) ($entry['body'] ?? '')) . '</textarea></label>'
            . '<label>Release Date<input class="field" type="datetime-local" name="published_at" value="' . self::e($published) . '"></label>'
            . '<label class="admin-check"><input type="checkbox" name="is_public" value="1"' . ((int) ($entry['is_public'] ?? 0) === 1 ? ' checked' : '') . '> Public</label>';
    }

    private static function faqEditor(array $product, string $csrf): void
    {
        $productId = (int) $product['id'];
        echo '<div class="editor-list faq-editor">';
        foreach ($product['faqs'] ?? [] as $faq) {
            echo '<details class="content-editor-item"><summary><strong>' . self::e((string) $faq['question']) . '</strong>' . self::badge((int) $faq['is_active'] === 1 ? 'active' : 'inactive') . '</summary><form class="admin-content-form" method="post" action="' . self::e(self::url('/admin/products/' . $productId . '/faqs/' . (int) $faq['id'])) . '">' . self::csrf($csrf)
                . self::faqFields($faq) . '<button class="btn btn-primary btn-small" type="submit">Save FAQ</button></form>' . self::deleteForm('/admin/faqs/' . (int) $faq['id'] . '/delete', $csrf, 'Delete FAQ') . '</details>';
        }
        echo '<details class="add-editor-item"><summary class="btn btn-primary">Add FAQ</summary><form class="admin-content-form" method="post" action="' . self::e(self::url('/admin/products/' . $productId . '/faqs')) . '">' . self::csrf($csrf)
            . self::faqFields(['question'=>'','answer'=>'','sort_order'=>count($product['faqs'] ?? []) * 10 + 10,'is_public'=>1,'is_active'=>1]) . '<button class="btn btn-primary" type="submit">Add FAQ</button></form></details></div>';
    }

    private static function faqFields(array $faq): string
    {
        return self::input('Question', 'question', (string) ($faq['question'] ?? ''), true)
            . '<label>Answer<textarea class="field" name="answer" rows="8" required>' . self::e((string) ($faq['answer'] ?? '')) . '</textarea></label>'
            . self::numberInput('Order', (int) ($faq['sort_order'] ?? 0)) . self::flags($faq);
    }

    private static function buildTable(array $versions): void
    {
        echo '<div class="table-wrap"><table class="data-table"><thead><tr><th>Enhancement</th><th>Component</th><th>Version</th><th>Size</th><th>Minimum Launcher</th><th>Released</th><th>Status</th></tr></thead><tbody>';
        foreach ($versions as $version) echo '<tr><td><strong>' . self::e((string) $version['product_name']) . '</strong></td><td>' . self::e((string) $version['module_name']) . '</td><td class="mono">v' . self::e((string) $version['version']) . '</td><td>' . self::bytes((int) $version['file_size']) . '</td><td class="mono">' . self::e((string) ($version['minimum_launcher_version'] ?: '—')) . '</td><td>' . self::date($version['published_at'] ?? $version['created_at']) . '</td><td>' . self::badge((string) $version['status']) . '</td></tr>';
        if ($versions === []) self::emptyRow(7, 'No build has been published for this Enhancement.');
        echo '</tbody></table></div><p class="panel-note">Publishing and activation remain managed by the signed server-side module tools.</p>';
    }

    private static function statusOptions(string $selected): string
    {
        $html = '';
        foreach (['operational'=>'Operational','updating'=>'Updating','maintenance'=>'Maintenance','unavailable'=>'Unavailable','discontinued'=>'Discontinued'] as $value => $label) {
            $html .= '<option value="' . self::e($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . self::e($label) . '</option>';
        }
        return $html;
    }

    private static function priorityOptions(string $selected): string
    {
        $html = '';
        foreach (['low'=>'Low','normal'=>'Normal','high'=>'High','urgent'=>'Urgent'] as $value => $label) $html .= '<option value="' . $value . '"' . ($selected === $value ? ' selected' : '') . '>' . $label . '</option>';
        return $html;
    }

    private static function ticketStatusOptions(string $selected): string
    {
        $html = '';
        foreach (['open'=>'Open','in_progress'=>'In Progress','awaiting_user'=>'Awaiting User','resolved'=>'Resolved','closed'=>'Closed'] as $value => $label) $html .= '<option value="' . $value . '"' . ($selected === $value ? ' selected' : '') . '>' . $label . '</option>';
        return $html;
    }

    private static function money(int $cents, string $currency): string
    {
        return self::e(self::currencySymbol($currency) . number_format($cents / 100, 2, '.', ','));
    }

    private static function currencySymbol(string $currency): string
    {
        return match (strtoupper($currency)) { 'EUR' => '€', 'USD' => '$', 'GBP' => '£', default => strtoupper($currency) . ' ' };
    }

    private static function relativeTime(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') return '—';
        try { $seconds = max(0, time() - (new \DateTimeImmutable($value))->getTimestamp()); } catch (\Throwable) { return '—'; }
        if ($seconds < 60) return 'Just now';
        if ($seconds < 3600) return (int) floor($seconds / 60) . 'm';
        if ($seconds < 86400) return (int) floor($seconds / 3600) . 'h';
        return (int) floor($seconds / 86400) . 'd';
    }

    private static function priorityBadge(string $priority): string
    {
        $priority = in_array($priority, ['low','normal','high','urgent'], true) ? $priority : 'normal';
        return '<span class="priority-badge priority-' . self::e($priority) . '">' . self::e(ucfirst($priority)) . '</span>';
    }

    private static function cover(array $product): string
    {
        $url = trim((string) ($product['image_url'] ?? ''));
        if ($url !== '') return '<img loading="lazy" src="' . self::e($url) . '" alt="">';
        return '<span>' . self::e(strtoupper(substr((string) ($product['game_name'] ?? $product['name'] ?? 'P'), 0, 1))) . '</span>';
    }

    private static function sectionLabel(string $section): string { return ucwords(str_replace('-', ' ', $section)); }

    private static function csrf(string $token): string { return '<input type="hidden" name="csrf" value="' . self::e($token) . '">'; }
    private static function input(string $label, string $name, string $value = '', bool $required = false): string { return '<label>' . self::e($label) . '<input class="field" name="' . self::e($name) . '" value="' . self::e($value) . '"' . ($required ? ' required' : '') . '></label>'; }
    private static function numberInput(string $label, int $value): string { return '<label>' . self::e($label) . '<input class="field" type="number" name="sort_order" value="' . $value . '"></label>'; }
    private static function flags(array $row): string { return '<label class="admin-check"><input type="checkbox" name="is_public" value="1"' . ((int) ($row['is_public'] ?? 0) === 1 ? ' checked' : '') . '> Public</label><label class="admin-check"><input type="checkbox" name="is_active" value="1"' . ((int) ($row['is_active'] ?? 0) === 1 ? ' checked' : '') . '> Active</label>'; }
    private static function featureFlags(array $row): string { return self::flags($row) . '<label class="admin-check"><input type="checkbox" name="is_highlighted" value="1"' . ((int) ($row['is_highlighted'] ?? 0) === 1 ? ' checked' : '') . '> Highlight</label>'; }
    private static function deleteForm(string $path, string $csrf, string $label): string { return '<form class="admin-delete-form" method="post" action="' . self::e(self::url($path)) . '" data-confirm="Permanently delete this content item?">' . self::csrf($csrf) . '<button class="btn btn-ghost btn-small danger" type="submit">' . self::e($label) . '</button></form>'; }

    private static function emptyState(string $icon, string $title, string $description): void { echo '<section class="data-panel empty-state"><div class="auth-icon">' . self::icon($icon) . '</div><h2>' . self::e($title) . '</h2><p>' . self::e($description) . '</p></section>'; }
    private static function flash(?array $flash): void { if ($flash === null) return; $success = ($flash['type'] ?? '') === 'success'; echo '<div class="toast ' . ($success ? 'toast-success' : 'toast-error') . '" role="status">' . self::icon($success ? 'check' : 'alert') . '<div><strong>' . ($success ? 'Action completed' : 'Action failed') . '</strong><span>' . self::e((string) ($flash['message'] ?? '')) . '</span>'; if (is_string($flash['key'] ?? null) && $flash['key'] !== '') echo '<code data-copy-value="' . self::e($flash['key']) . '">' . self::e($flash['key']) . '</code><button class="btn btn-outline btn-small" type="button" data-copy>Copy key</button>'; echo '</div><button type="button" data-dismiss aria-label="Close">×</button></div>'; }
    private static function header(string $title): void { header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#13111a"><title>' . self::e($title) . ' — Pericles Administration</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&amp;family=Outfit:wght@500;600;700&amp;family=Roboto+Mono:wght@400;500;600&amp;display=swap" rel="stylesheet"><link rel="stylesheet" href="' . self::e(self::url('/assets/pericles.css?v=20260905-1')) . '"><script defer src="' . self::e(self::url('/assets/pericles.js?v=20260905-1')) . '"></script></head><body class="dashboard-page new-dashboard-page">'; }
    private static function userName(array $user): string { return trim((string) ($user['display_name'] ?? '')) ?: (string) explode('@', (string) $user['email'])[0]; }
    private static function metric(string $label, string $value, string $meta): string { return '<article><span>' . self::e($label) . '</span><strong>' . self::e($value) . '</strong><small>' . self::e($meta) . '</small></article>'; }
    private static function badge(string $status): string { $key = strtolower($status); return '<span class="status-badge status-' . self::e($key) . '"><i></i>' . self::e(ucwords(str_replace('_', ' ', $key))) . '</span>'; }
    private static function brand(string $path): string { return '<a class="brand" href="' . self::e(self::url($path)) . '"><b>P</b><span>Pericles <small>Admin</small></span></a>'; }
    private static function emptyRow(int $span, string $text): void { echo '<tr><td class="empty-cell" colspan="' . $span . '">' . self::e($text) . '</td></tr>'; }
    private static function date(mixed $value): string { if (!is_string($value) || trim($value) === '') return '—'; try { return (new \DateTimeImmutable($value))->format('M j, Y H:i'); } catch (\Throwable) { return '—'; } }
    private static function bytes(int $bytes): string { if ($bytes <= 0) return '—'; return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format($bytes / 1024, 1) . ' KB'; }
    private static function actionLabel(string $action): string { return ['license.assigned' => 'Access assigned by key', 'access.granted' => 'Access granted', 'access.extended' => 'Access extended', 'access.converted_to_lifetime' => 'Access converted to Lifetime', 'access.downtime_compensated' => 'Downtime Access compensation applied', 'access.revoked' => 'Access revoked', 'access_key.generated' => 'Access Keys generated', 'subscription.unbound' => 'Authorized Device reset', 'subscription.deleted' => 'Access permanently removed', 'user.status_changed' => 'Account status changed', 'user.role_changed' => 'Account role changed', 'pricing.updated' => 'Pricing updated', 'product.content_updated' => 'Enhancement content updated', 'product.media_saved' => 'Media saved', 'product.media_deleted' => 'Media deleted', 'documentation.saved' => 'Documentation saved', 'documentation.deleted' => 'Documentation deleted', 'changelog.saved' => 'Changelog saved', 'changelog.deleted' => 'Changelog deleted', 'support.ticket_updated' => 'Support ticket updated', 'support.internal_note_added' => 'Internal support note added', 'support.ticket_resolved' => 'Support ticket resolved', 'enhancement.status_updated' => 'Enhancement status updated', 'service.status_updated' => 'Platform service status updated', 'setting.updated' => 'Application setting updated'][$action] ?? ucwords(str_replace(['.', '_'], ' ', $action)); }
    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private static function url(string $path): string { $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')); $base = rtrim(str_replace('\\', '/', dirname($script)), '/.'); return ($base === '' ? '' : $base) . '/' . ltrim($path, '/'); }

    private static function icon(string $name): string
    {
        $p = ['activity'=>'<path d="M4 13h3l2-6 4 12 2-6h5"/>','alert'=>'<path d="M12 9v4m0 4h.01M10.3 3.9 2.2 18a2 2 0 0 0 1.7 3h16.2a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>','arrow-left'=>'<path d="M19 12H5m5 5-5-5 5-5"/>','check'=>'<path d="m5 12 4 4L19 6"/>','grid'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>','headphones'=>'<path d="M4 14v-2a8 8 0 0 1 16 0v2M4 14h3v6H5a1 1 0 0 1-1-1v-5Zm16 0h-3v6h2a1 1 0 0 0 1-1v-5Z"/>','key'=>'<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8m-3 3 3 3m-6 0 3 3"/>','menu'=>'<path d="M4 7h16M4 12h16M4 17h16"/>','package'=>'<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>','receipt'=>'<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 8h6m-6 4h6"/>','search'=>'<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>','settings'=>'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6 1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>','users'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>'];
        return '<svg class="icon icon-' . self::e($name) . '" viewBox="0 0 24 24" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
    }
}
