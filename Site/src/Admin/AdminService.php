<?php

declare(strict_types=1);

namespace Pericles\Admin;

use PDO;
use Pericles\Activation\ActivationKeyGenerator;
use Pericles\Activation\ActivationService;
use Pericles\Http\ApiException;
use Pericles\Security\AuthorizationService;
use Pericles\Support\SupportService;

final class AdminService
{
    private AuthorizationService $authorization;
    private array $schemaColumns = [];

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
                . "(SELECT COUNT(*) FROM subscriptions s WHERE s.user_id=u.id AND s.status IN ('active','cancelled') AND (s.expires_at IS NULL OR s.expires_at>UTC_TIMESTAMP())) AS active_access_count, "
                . '(SELECT COUNT(*) FROM devices d WHERE d.user_id = u.id AND d.revoked_at IS NULL) AS device_count, '
                . '(SELECT COUNT(*) FROM orders o WHERE o.user_id=u.id) AS order_count, '
                . "(SELECT COALESCE(SUM(o.amount_cents),0) FROM orders o WHERE o.user_id=u.id AND o.status='paid') AS total_spent_cents "
                . 'FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id '
                . 'ORDER BY u.created_at DESC, u.id DESC LIMIT 100'
            )->fetchAll();
        }
        $subscriptions = $this->database->query(
            'SELECT s.id, s.status, s.purchased_at, s.started_at, s.expires_at, s.device_reset_available_at, s.bound_device_id, u.id AS user_id, u.email, '
            . 'p.name AS product_name, p.slug AS product_slug, pl.name AS plan_name, pl.is_lifetime, d.device_id, d.display_name AS device_name, '
            . '(SELECT ak.key_hint FROM activation_keys ak WHERE ak.subscription_id = s.id '
            . 'ORDER BY ak.redeemed_at DESC, ak.id DESC LIMIT 1) AS key_hint '
            . 'FROM subscriptions s INNER JOIN users u ON u.id = s.user_id '
            . 'INNER JOIN products p ON p.id = s.product_id LEFT JOIN plans pl ON pl.id=s.plan_id LEFT JOIN devices d ON d.id = s.bound_device_id '
            . 'ORDER BY s.updated_at DESC LIMIT 100'
        )->fetchAll();
        $plans = [];
        if (in_array('licenses.manage', $permissions, true) || in_array('access.support_actions', $permissions, true)) {
            $plans = $this->database->query(
                'SELECT pl.id AS plan_id,p.slug AS product_slug, p.name AS product_name, pl.slug AS plan_slug, pl.name AS plan_name,pl.duration_days,pl.is_lifetime '
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
        $serviceStatuses = [];
        $settings = [];
        $accessKeys = [];
        $adminDevices = [];
        $coupons = [];
        try {
            $products = $this->database->query(
                'SELECT p.id, p.slug, p.name, p.type, p.short_description,p.description,p.compatibility,p.operating_systems,p.requirements,p.seo_title,p.seo_description,p.is_public,p.is_featured,p.is_active,p.updated_at, g.slug AS game_slug, g.name AS game_name, g.image_url, '
                . 'g.commercial_status, g.purchases_allowed,g.status_updated_at, (SELECT COUNT(*) FROM subscriptions s WHERE s.product_id = p.id) AS license_count, '
                . "(SELECT COUNT(*) FROM subscriptions s WHERE s.product_id=p.id AND s.status IN ('active','cancelled') AND (s.expires_at IS NULL OR s.expires_at>UTC_TIMESTAMP())) AS active_access_count, "
                . '(SELECT COUNT(*) FROM plans pl WHERE pl.product_id=p.id AND pl.is_active=1) AS plan_count, '
                . '(SELECT COUNT(*) FROM product_media pm WHERE pm.product_id=p.id) AS media_count, '
                . "(SELECT mv.version FROM modules m INNER JOIN module_versions mv ON mv.module_id=m.id WHERE m.game_id=g.id AND mv.status='active' ORDER BY mv.published_at DESC,mv.id DESC LIMIT 1) AS latest_version "
                . 'FROM products p LEFT JOIN product_games pg ON pg.product_id = p.id LEFT JOIN games g ON g.id = pg.game_id ORDER BY p.name'
            )->fetchAll();
            foreach ($products as &$product) {
                $plansQuery = $this->database->prepare('SELECT id, name, slug, price_cents, sale_price_cents, currency, badge, is_active FROM plans WHERE product_id = :id ORDER BY sort_order, duration_days, id');
                $plansQuery->execute([':id' => (int) $product['id']]);
                $product['commerce_plans'] = $plansQuery->fetchAll();
                $hasPrimaryMedia = $this->hasColumn('product_media', 'is_primary');
                $mediaQuery = $this->database->prepare('SELECT id,media_type,url,thumbnail_url,poster_url,title,alt_text,sort_order,is_public,is_active,'.($hasPrimaryMedia?'is_primary':'0 AS is_primary').' FROM product_media WHERE product_id=:id ORDER BY '.($hasPrimaryMedia?'is_primary DESC,':'').'sort_order,id');
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
                $documentQuery = $this->database->prepare('SELECT id,slug,title,'.($this->hasColumn('product_documents','summary')?'summary':'NULL AS summary').',section,body,access_level,sort_order,is_published,updated_at FROM product_documents WHERE product_id=:id ORDER BY sort_order,id');
                $documentQuery->execute([':id' => (int) $product['id']]);
                $product['documents'] = $documentQuery->fetchAll();
                $changelogQuery = $this->database->prepare('SELECT id,version,'.($this->hasColumn('product_changelog','title')?'title':'NULL AS title').',summary,'.($this->hasColumn('product_changelog','body')?'body':'NULL AS body').',published_at,is_public FROM product_changelog WHERE product_id=:id ORDER BY published_at DESC,id DESC');
                $changelogQuery->execute([':id' => (int) $product['id']]);
                $product['changelog'] = $changelogQuery->fetchAll();
            }
            unset($product);
            $payments = $this->database->query(
                'SELECT o.id,o.user_id,o.product_id,o.plan_id,o.order_number,o.product_name_snapshot,o.plan_name_snapshot,o.amount_cents,o.currency,o.status,o.created_at,o.paid_at,u.email,p.provider,p.provider_reference,p.method,p.confirmed_at '
                . 'FROM orders o INNER JOIN users u ON u.id=o.user_id LEFT JOIN payments p ON p.order_id=o.id ORDER BY o.created_at DESC LIMIT 200'
            )->fetchAll();
            $supportTickets = (new SupportService($this->database))->adminList();
            $serviceStatuses = $this->database->query('SELECT service_key,name,status,incident,updated_at FROM platform_service_status ORDER BY service_key')->fetchAll();
            $settings = $this->database->query('SELECT setting_key,setting_value,value_type,is_public,updated_at FROM application_settings ORDER BY setting_key')->fetchAll();
            $accessKeys = $this->database->query('SELECT ak.id,ak.key_hint,ak.status,ak.created_at,ak.expires_at,ak.redeemed_at,u.email AS redeemed_by,p.name AS product_name,pl.name AS plan_name FROM activation_keys ak INNER JOIN plans pl ON pl.id=ak.plan_id INNER JOIN products p ON p.id=pl.product_id LEFT JOIN users u ON u.id=ak.redeemed_by_user_id ORDER BY ak.id DESC LIMIT 200')->fetchAll();
            $adminDevices = $this->database->query('SELECT d.id,d.display_name,d.verified_at,d.last_seen_at,d.created_at,d.revoked_at,u.email,(SELECT COUNT(*) FROM subscriptions s WHERE s.bound_device_id=d.id) AS access_count FROM devices d INNER JOIN users u ON u.id=d.user_id ORDER BY COALESCE(d.last_seen_at,d.created_at) DESC LIMIT 200')->fetchAll();
            $coupons = $this->database->query('SELECT id,code,discount_type,discount_value,starts_at,ends_at,max_uses,uses_per_user,is_active,created_at FROM coupons ORDER BY id DESC LIMIT 200')->fetchAll();
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
            'service_statuses' => $serviceStatuses,
            'settings' => $settings,
            'access_keys' => $accessKeys,
            'devices' => $adminDevices,
            'coupons' => $coupons,
            'metrics' => [
                'users' => (int) $this->database->query('SELECT COUNT(*) FROM users')->fetchColumn(),
                'active_subscriptions' => (int) $this->database->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'")->fetchColumn(),
                'bound_products' => (int) $this->database->query('SELECT COUNT(*) FROM subscriptions WHERE bound_device_id IS NOT NULL')->fetchColumn(),
                'unused_keys' => in_array('licenses.manage', $permissions, true)
                    ? (int) $this->database->query("SELECT COUNT(*) FROM activation_keys WHERE status = 'unused'")->fetchColumn()
                    : null,
                'revenue_30_days_cents' => (int) $this->database->query("SELECT COALESCE(SUM(amount_cents),0) FROM orders WHERE status='paid' AND paid_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)")->fetchColumn(),
                'orders_30_days' => (int) $this->database->query("SELECT COUNT(*) FROM orders WHERE created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)")->fetchColumn(),
                'active_customers' => (int) $this->database->query("SELECT COUNT(DISTINCT user_id) FROM subscriptions WHERE status IN ('active','cancelled') AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())")->fetchColumn(),
            ],
        ];
    }

    public function userDetail(int $actorUserId, int $userId): array
    {
        $this->authorization->requirePermission($actorUserId, 'users.view');
        $userQuery=$this->database->prepare('SELECT u.id,u.email,u.display_name,u.status,u.created_at,COALESCE(r.slug,\'player\') AS role_slug,COALESCE(r.name,\'Player\') AS role_name FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id WHERE u.id=:id LIMIT 1');
        $userQuery->execute([':id'=>$userId]);$user=$userQuery->fetch();if(!is_array($user))throw new ApiException('user_not_found',404,'User not found.');
        $accessQuery=$this->database->prepare('SELECT s.id,s.status,s.purchased_at,s.started_at,s.activated_at,s.expires_at,s.bound_device_id,p.name AS product_name,p.slug AS product_slug,pl.name AS plan_name,pl.is_lifetime,d.display_name AS device_name FROM subscriptions s INNER JOIN products p ON p.id=s.product_id LEFT JOIN plans pl ON pl.id=s.plan_id LEFT JOIN devices d ON d.id=s.bound_device_id WHERE s.user_id=:id ORDER BY s.updated_at DESC');
        $accessQuery->execute([':id'=>$userId]);$access=$accessQuery->fetchAll();
        $ordersQuery=$this->database->prepare('SELECT o.id,o.order_number,o.product_name_snapshot,o.plan_name_snapshot,o.amount_cents,o.currency,o.status,o.created_at,o.paid_at FROM orders o WHERE o.user_id=:id ORDER BY o.created_at DESC');
        $ordersQuery->execute([':id'=>$userId]);$orders=$ordersQuery->fetchAll();
        $devicesQuery=$this->database->prepare('SELECT id,display_name,verified_at,last_seen_at,created_at,revoked_at FROM devices WHERE user_id=:id ORDER BY COALESCE(last_seen_at,created_at) DESC');
        $devicesQuery->execute([':id'=>$userId]);$devices=$devicesQuery->fetchAll();
        $tickets=array_values(array_filter((new SupportService($this->database))->adminList(),static fn(array $ticket):bool=>strtolower((string)($ticket['email']??''))===strtolower((string)$user['email'])));
        $paid=array_values(array_filter($orders,static fn(array $order):bool=>(string)$order['status']==='paid'));
        $active=array_values(array_filter($access,static fn(array $row):bool=>in_array((string)$row['status'],['active','cancelled'],true)&&($row['expires_at']===null||strtotime((string)$row['expires_at'])>time())));
        return ['user'=>$user,'access'=>$access,'orders'=>$orders,'devices'=>$devices,'tickets'=>$tickets,'summary'=>[
            'total_spent_cents'=>array_sum(array_map(static fn(array $order):int=>(int)$order['amount_cents'],$paid)),
            'orders'=>count($orders),'active_enhancements'=>count($active),'lifetime_enhancements'=>count(array_filter($active,static fn(array $row):bool=>(int)($row['is_lifetime']??0)===1||$row['expires_at']===null)),
        ]];
    }

    public function orderDetail(int $actorUserId, string $orderNumber): array
    {
        $this->authorization->requirePermission($actorUserId, 'commerce.view');
        $orderNumber=strtoupper(trim($orderNumber));
        if(!preg_match('/^PER-[A-Z0-9-]{8,32}$/',$orderNumber))throw new ApiException('order_not_found',404,'Order not found.');
        $query=$this->database->prepare('SELECT o.id,o.user_id,o.product_id,o.plan_id,o.order_number,o.product_name_snapshot,o.plan_name_snapshot,o.amount_cents,o.currency,o.status,o.terms_version,o.created_at,o.paid_at,u.email,u.display_name,p.provider,p.provider_reference,p.method,p.confirmed_at FROM orders o INNER JOIN users u ON u.id=o.user_id LEFT JOIN payments p ON p.order_id=o.id WHERE o.order_number=:number LIMIT 1');
        $query->execute([':number'=>$orderNumber]);$order=$query->fetch();if(!is_array($order))throw new ApiException('order_not_found',404,'Order not found.');
        $access=$this->database->prepare('SELECT s.id,s.status,s.started_at,s.expires_at,pl.name AS plan_name,d.display_name AS device_name FROM subscriptions s LEFT JOIN plans pl ON pl.id=s.plan_id LEFT JOIN devices d ON d.id=s.bound_device_id WHERE s.order_id=:id LIMIT 1');
        $access->execute([':id'=>(int)$order['id']]);$row=$access->fetch();$order['access']=is_array($row)?$row:null;return$order;
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

    public function grantAccess(
        int $actorUserId,
        int $targetUserId,
        int $planId,
        string $startBehavior,
        string $reason,
        string $ipAddress,
        string $auditAction = 'access.granted',
        string $requiredPermission = 'licenses.manage'
    ): array {
        $this->authorization->requirePermission($actorUserId, $requiredPermission);
        $target = $this->findUser($targetUserId);
        if ((string)$target['status'] !== 'active') throw new ApiException('account_disabled', 409, 'The target account is disabled.');
        if (!in_array($startBehavior, ['immediate','first_activation'], true)) throw new ApiException('validation_error', 400, 'Select when Access should begin.');
        $reason = trim($reason);
        if (strlen($reason) < 3 || strlen($reason) > 500) throw new ApiException('validation_error', 400, 'Enter a reason for this Access change.');
        $planQuery=$this->database->prepare('SELECT pl.id,pl.product_id,pl.name,pl.slug,pl.duration_days,pl.is_lifetime,p.name AS product_name,p.slug AS product_slug FROM plans pl INNER JOIN products p ON p.id=pl.product_id WHERE pl.id=:id AND pl.is_active=1 AND p.is_active=1 LIMIT 1');
        $planQuery->execute([':id'=>$planId]);$plan=$planQuery->fetch();
        if(!is_array($plan))throw new ApiException('invalid_plan',404,'Access Plan not found.');
        $driver=(string)$this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlite')$this->database->exec('BEGIN IMMEDIATE TRANSACTION');else$this->database->beginTransaction();
        try{
            $find=$this->database->prepare('SELECT * FROM subscriptions WHERE user_id=:user AND product_id=:product LIMIT 1'.($driver==='mysql'?' FOR UPDATE':''));
            $find->execute([':user'=>$targetUserId,':product'=>(int)$plan['product_id']]);$existing=$find->fetch();
            $now=gmdate('Y-m-d H:i:s');$nowDate=new \DateTimeImmutable($now,new \DateTimeZone('UTC'));
            $lifetime=(int)$plan['is_lifetime']===1;$duration=(int)($plan['duration_days']??0);
            if(!$lifetime&&$duration<1)throw new ApiException('invalid_plan',409,'This timed plan has no valid duration.');
            if(is_array($existing)&&$existing['expires_at']===null&&(string)$existing['status']==='active'&&!$lifetime)throw new ApiException('access_already_lifetime',409,'This customer already has Lifetime Access.');
            $activeTimed=is_array($existing)&&$existing['expires_at']!==null&&in_array((string)$existing['status'],['active','cancelled'],true)&&strtotime((string)$existing['expires_at'])>time();
            if($lifetime){$status='active';$startsAt=$existing['starts_at']??$now;$activatedAt=$existing['activated_at']??$now;$startedAt=$existing['started_at']??$now;$deadline=null;$expires=null;}
            elseif($activeTimed){$status='active';$startsAt=$existing['starts_at']??$existing['started_at'];$activatedAt=$existing['activated_at']??$existing['started_at'];$startedAt=$existing['started_at'];$deadline=null;$expires=(new \DateTimeImmutable((string)$existing['expires_at'],new \DateTimeZone('UTC')))->modify('+'.$duration.' days')->format('Y-m-d H:i:s');}
            elseif($startBehavior==='first_activation'){$status='pending';$startsAt=null;$activatedAt=null;$startedAt=$now;$deadline=$nowDate->modify('+7 days')->format('Y-m-d H:i:s');$expires=null;}
            else{$status='active';$startsAt=$now;$activatedAt=$now;$startedAt=$now;$deadline=null;$expires=$nowDate->modify('+'.$duration.' days')->format('Y-m-d H:i:s');}
            if(!is_array($existing)){
                $insert=$this->database->prepare('INSERT INTO subscriptions (user_id,product_id,plan_id,order_id,status,purchased_at,activation_deadline_at,activated_at,starts_at,started_at,expires_at,bound_device_id,bound_at,created_at,updated_at) VALUES (:user,:product,:plan,NULL,:status,:purchased,:deadline,:activated,:starts,:started,:expires,NULL,NULL,:created_at,:updated_at)');
                $insert->execute([':user'=>$targetUserId,':product'=>(int)$plan['product_id'],':plan'=>$planId,':status'=>$status,':purchased'=>$now,':deadline'=>$deadline,':activated'=>$activatedAt,':starts'=>$startsAt,':started'=>$startedAt,':expires'=>$expires,':created_at'=>$now,':updated_at'=>$now]);$subscriptionId=(int)$this->database->lastInsertId();
            }else{
                $subscriptionId=(int)$existing['id'];$update=$this->database->prepare('UPDATE subscriptions SET plan_id=:plan,order_id=NULL,status=:status,purchased_at=:purchased,activation_deadline_at=:deadline,activated_at=:activated,starts_at=:starts,started_at=:started,expires_at=:expires,cancelled_at=NULL,suspended_at=NULL,updated_at=:now WHERE id=:id');
                $update->execute([':plan'=>$planId,':status'=>$status,':purchased'=>$now,':deadline'=>$deadline,':activated'=>$activatedAt,':starts'=>$startsAt,':started'=>$startedAt,':expires'=>$expires,':now'=>$now,':id'=>$subscriptionId]);
            }
            if($driver==='sqlite')$this->database->exec('COMMIT');else$this->database->commit();
        }catch(\Throwable $exception){if($driver==='sqlite'){if($this->database->inTransaction())$this->database->exec('ROLLBACK');}elseif($this->database->inTransaction())$this->database->rollBack();throw$exception;}
        $this->audit($actorUserId,$auditAction,'subscription',(string)$subscriptionId,['user_id'=>$targetUserId,'email'=>(string)$target['email'],'product'=>(string)$plan['product_slug'],'plan'=>(string)$plan['slug'],'start_behavior'=>$startBehavior,'reason'=>$reason],$ipAddress);
        return ['subscription_id'=>$subscriptionId,'product_name'=>(string)$plan['product_name'],'plan_name'=>(string)$plan['name'],'email'=>(string)$target['email']];
    }

    public function extendAccess(int $actorUserId,int $subscriptionId,int $planId,string $reason,string $ipAddress): array
    {
        $permission=$this->authorization->can($actorUserId,'licenses.manage')?'licenses.manage':'access.support_actions';
        $this->authorization->requirePermission($actorUserId,$permission);
        $query=$this->database->prepare('SELECT user_id,product_id FROM subscriptions WHERE id=:id LIMIT 1');$query->execute([':id'=>$subscriptionId]);$access=$query->fetch();
        if(!is_array($access))throw new ApiException('subscription_not_found',404,'Customer Access not found.');
        $plan=$this->database->prepare('SELECT product_id FROM plans WHERE id=:id LIMIT 1');$plan->execute([':id'=>$planId]);
        if((int)$plan->fetchColumn()!==(int)$access['product_id'])throw new ApiException('invalid_plan',400,'Select an Access Plan for the same Enhancement.');
        return $this->grantAccess($actorUserId,(int)$access['user_id'],$planId,'immediate',$reason,$ipAddress,'access.extended',$permission);
    }

    public function convertAccessToLifetime(int $actorUserId,int $subscriptionId,string $reason,string $ipAddress): array
    {
        $query=$this->database->prepare('SELECT s.user_id,pl.id AS plan_id FROM subscriptions s INNER JOIN plans pl ON pl.product_id=s.product_id AND pl.is_lifetime=1 AND pl.is_active=1 WHERE s.id=:id ORDER BY pl.id LIMIT 1');$query->execute([':id'=>$subscriptionId]);$row=$query->fetch();
        if(!is_array($row))throw new ApiException('invalid_plan',404,'No active Lifetime plan exists for this Enhancement.');
        return $this->grantAccess($actorUserId,(int)$row['user_id'],(int)$row['plan_id'],'immediate',$reason,$ipAddress,'access.converted_to_lifetime');
    }

    public function revokeAccess(int $actorUserId,int $subscriptionId,string $reason,string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId,'licenses.manage');$reason=trim($reason);if(strlen($reason)<3||strlen($reason)>500)throw new ApiException('validation_error',400,'Enter a reason for revoking Access.');
        $find=$this->database->prepare('SELECT s.user_id,p.slug,p.name FROM subscriptions s INNER JOIN products p ON p.id=s.product_id WHERE s.id=:id LIMIT 1');$find->execute([':id'=>$subscriptionId]);$access=$find->fetch();if(!is_array($access))throw new ApiException('subscription_not_found',404,'Customer Access not found.');
        $now=gmdate('Y-m-d H:i:s');$this->database->prepare("UPDATE subscriptions SET status='suspended',suspended_at=:suspended_at,updated_at=:updated_at WHERE id=:id")->execute([':suspended_at'=>$now,':updated_at'=>$now,':id'=>$subscriptionId]);
        $this->audit($actorUserId,'access.revoked','subscription',(string)$subscriptionId,['user_id'=>(int)$access['user_id'],'product'=>(string)$access['slug'],'reason'=>$reason],$ipAddress);
    }

    public function generateAccessKeys(int $actorUserId,string $productSlug,string $planSlug,int $quantity,?string $expiresAt,string $reason,string $ipAddress): array
    {
        $this->authorization->requirePermission($actorUserId,'licenses.manage');$quantity=max(1,min(100,$quantity));$reason=trim($reason);if(strlen($reason)<3||strlen($reason)>500)throw new ApiException('validation_error',400,'Enter a reason for generating Access Keys.');
        if($expiresAt!==null&&trim($expiresAt)!==''){try{$expires=(new \DateTimeImmutable($expiresAt,new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');}catch(\Throwable){throw new ApiException('validation_error',400,'Enter a valid key expiration date.');}if(strtotime($expires)<=time())throw new ApiException('validation_error',400,'Key expiration must be in the future.');}else$expires=null;
        try{$keys=(new ActivationKeyGenerator($this->database))->generate($productSlug,$planSlug,$quantity);}catch(\RuntimeException $exception){throw new ApiException('invalid_plan',400,$exception->getMessage());}
        if($expires!==null){$expiry=$this->database->prepare('UPDATE activation_keys SET expires_at=:expires WHERE key_hash=:hash AND status=\'unused\'');foreach($keys as $key)$expiry->execute([':expires'=>$expires,':hash'=>hash('sha256',$key)]);}
        $this->audit($actorUserId,'access_key.generated','activation_key',null,['product'=>$productSlug,'plan'=>$planSlug,'quantity'=>$quantity,'expires_at'=>$expires,'reason'=>$reason],$ipAddress);return$keys;
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

    public function updateEnhancementStatus(int $actorUserId, string $gameSlug, string $status, bool $purchasesAllowed, string $reason, string $ipAddress, bool $freezeAccess = false): void
    {
        $this->authorization->requirePermission($actorUserId, 'commerce.manage');
        if (!in_array($status, ['operational','updating','maintenance','unavailable','discontinued'], true)) throw new ApiException('validation_error', 400, 'Invalid Enhancement status.');
        $find = $this->database->prepare('SELECT g.id,g.commercial_status,p.id AS product_id FROM games g INNER JOIN product_games pg ON pg.game_id=g.id INNER JOIN products p ON p.id=pg.product_id WHERE g.slug=:slug LIMIT 1');
        $find->execute([':slug'=>$gameSlug]); $game=$find->fetch();
        if (!is_array($game)) throw new ApiException('product_not_found', 404, 'Enhancement not found.');
        $now=gmdate('Y-m-d H:i:s'); $old=(string)$game['commercial_status'];
        $compensationSeconds=0; $compensatedSubscriptions=0; $compensationApplied=false;
        $this->database->beginTransaction();
        try {
            $this->database->prepare('UPDATE games SET commercial_status=:status,purchases_allowed=:allowed,status_updated_at=:status_updated_at,updated_at=:updated_at WHERE id=:id')->execute([':status'=>$status,':allowed'=>$purchasesAllowed?1:0,':status_updated_at'=>$now,':updated_at'=>$now,':id'=>(int)$game['id']]);
            if ($old === 'operational' && $status !== 'operational') {
                $this->database->prepare('INSERT INTO product_downtimes (product_id,started_at,freeze_access,reason,created_by_user_id,created_at) VALUES (:product_id,:started_at,:freeze,:reason,:actor,:created_at)')->execute([':product_id'=>(int)$game['product_id'],':started_at'=>$now,':freeze'=>$freezeAccess?1:0,':reason'=>substr(trim($reason) ?: ucfirst($status),0,255),':actor'=>$actorUserId,':created_at'=>$now]);
            } elseif ($old !== 'operational' && $status === 'operational') {
                $driver=(string)$this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
                $lock=$driver==='sqlite'?'':' FOR UPDATE';
                $open=$this->database->prepare('SELECT id,started_at,freeze_access,extension_applied_at FROM product_downtimes WHERE product_id=:product_id AND ended_at IS NULL ORDER BY id DESC LIMIT 1'.$lock); $open->execute([':product_id'=>(int)$game['product_id']]); $downtime=$open->fetch();
                if (is_array($downtime)) {
                    $startedAt=(new \DateTimeImmutable((string)$downtime['started_at'],new \DateTimeZone('UTC')))->getTimestamp();
                    $seconds=max(0,time()-$startedAt);
                    $apply=$seconds>0&&(int)$downtime['freeze_access']===1&&$downtime['extension_applied_at']===null;
                    $this->database->prepare('UPDATE product_downtimes SET ended_at=:now,duration_seconds=:seconds,extension_applied_at=:applied WHERE id=:id')->execute([':now'=>$now,':seconds'=>$seconds,':applied'=>$apply?$now:null,':id'=>(int)$downtime['id']]);
                    if ($apply) {
                        $sql=$driver==='sqlite'
                            ? "UPDATE subscriptions SET expires_at=datetime(expires_at,'+' || :seconds || ' seconds'),updated_at=:now WHERE product_id=:product_id AND status IN ('active','cancelled') AND expires_at IS NOT NULL AND expires_at>:started_at"
                            : "UPDATE subscriptions SET expires_at=TIMESTAMPADD(SECOND,:seconds,expires_at),updated_at=:now WHERE product_id=:product_id AND status IN ('active','cancelled') AND expires_at IS NOT NULL AND expires_at>:started_at";
                        $extension=$this->database->prepare($sql);
                        $extension->execute([':seconds'=>$seconds,':now'=>$now,':product_id'=>(int)$game['product_id'],':started_at'=>(string)$downtime['started_at']]);
                        $compensationSeconds=$seconds;
                        $compensatedSubscriptions=$extension->rowCount();
                        $compensationApplied=true;
                    }
                }
            }
            $this->database->commit();
        } catch (\Throwable $exception) { if ($this->database->inTransaction()) $this->database->rollBack(); throw $exception; }
        $this->audit($actorUserId, 'enhancement.status_updated', 'game', (string)$game['id'], ['slug'=>$gameSlug,'status'=>$status,'purchases_allowed'=>$purchasesAllowed,'freeze_access'=>$freezeAccess,'compensation_applied'=>$compensationApplied,'compensation_seconds'=>$compensationSeconds,'compensated_subscriptions'=>$compensatedSubscriptions], $ipAddress);
        if ($compensationApplied) {
            $this->audit($actorUserId, 'access.downtime_compensated', 'product', (string)$game['product_id'], ['slug'=>$gameSlug,'duration_seconds'=>$compensationSeconds,'subscriptions_extended'=>$compensatedSubscriptions], $ipAddress);
        }
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
            ':active' => isset($input['is_active']) ? 1 : 0, ':primary' => isset($input['is_primary']) ? 1 : 0,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        $hasPrimaryMedia = $this->hasColumn('product_media', 'is_primary');
        if (!$hasPrimaryMedia) unset($values[':primary']);
        $this->database->beginTransaction();
        try {
            if ($hasPrimaryMedia && isset($input['is_primary'])) {
                $clear = $this->database->prepare('UPDATE product_media SET is_primary=0 WHERE product_id=:product');
                $clear->execute([':product'=>$productId]);
            }
            if ($mediaId === null) {
                $statement = $this->database->prepare('INSERT INTO product_media (product_id,media_type,url,thumbnail_url,poster_url,title,alt_text,sort_order,is_public,is_active'.($hasPrimaryMedia?',is_primary':'').',created_at,updated_at) VALUES (:product,:type,:url,:thumbnail,:poster,:title,:alt,:sort,:public,:active'.($hasPrimaryMedia?',:primary':'').',:created_at,:updated_at)');
                $values[':created_at']=$values[':updated_at'];$statement->execute($values);
                $mediaId = (int) $this->database->lastInsertId();
            } else {
                $values[':id'] = $mediaId;
                $statement = $this->database->prepare('UPDATE product_media SET product_id=:product,media_type=:type,url=:url,thumbnail_url=:thumbnail,poster_url=:poster,title=:title,alt_text=:alt,sort_order=:sort,is_public=:public,is_active=:active'.($hasPrimaryMedia?',is_primary=:primary':'').',updated_at=:updated_at WHERE id=:id');
                $statement->execute($values);
            }
            $this->database->commit();
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $exception;
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

    public function updateProductPricing(int $actorUserId,int $productId,array $rows,string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId,'commerce.manage');if($productId<1||$rows===[])throw new ApiException('validation_error',400,'No pricing changes were submitted.');
        $driver=(string)$this->database->getAttribute(PDO::ATTR_DRIVER_NAME);if($driver==='sqlite')$this->database->exec('BEGIN IMMEDIATE TRANSACTION');else$this->database->beginTransaction();$changes=[];
        try{foreach($rows as $planId=>$row){if(!is_array($row))continue;$id=(int)$planId;$price=$this->decimalToCents((string)($row['price']??''));$sale=trim((string)($row['sale_price']??''))===''?null:$this->decimalToCents((string)$row['sale_price']);if($price<0||($sale!==null&&($sale<0||$sale>=$price)))throw new ApiException('validation_error',400,'Sale prices must be lower than regular prices.');$badge=trim((string)($row['badge']??''));if(strlen($badge)>40)throw new ApiException('validation_error',400,'An Access Plan badge is too long.');$update=$this->database->prepare('UPDATE plans SET price_cents=:price,sale_price_cents=:sale,badge=:badge,is_active=:active,updated_at=:now WHERE id=:id AND product_id=:product');$update->execute([':price'=>$price,':sale'=>$sale,':badge'=>$badge===''?null:$badge,':active'=>isset($row['is_active'])?1:0,':now'=>gmdate('Y-m-d H:i:s'),':id'=>$id,':product'=>$productId]);if($update->rowCount()===0){$exists=$this->database->prepare('SELECT COUNT(*) FROM plans WHERE id=:id AND product_id=:product');$exists->execute([':id'=>$id,':product'=>$productId]);if((int)$exists->fetchColumn()===0)throw new ApiException('plan_not_found',404,'Access Plan not found.');}$changes[]=['plan_id'=>$id,'price_cents'=>$price,'sale_price_cents'=>$sale,'badge'=>$badge,'active'=>isset($row['is_active'])];}if($driver==='sqlite')$this->database->exec('COMMIT');else$this->database->commit();}catch(\Throwable$exception){if($driver==='sqlite'){if($this->database->inTransaction())$this->database->exec('ROLLBACK');}elseif($this->database->inTransaction())$this->database->rollBack();throw$exception;}
        $this->audit($actorUserId,'pricing.updated','product',(string)$productId,['plans'=>$changes],$ipAddress);
    }

    public function saveDocument(int $actorUserId,int $productId,?int $documentId,array $input,string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId,'content.manage');$title=trim((string)($input['title']??''));$slug=strtolower(trim((string)($input['slug']??'')));$section=strtolower(trim((string)($input['section']??'')));$body=trim((string)($input['body']??''));$level=strtolower(trim((string)($input['access_level']??'public')));
        if($productId<1||$title===''||strlen($title)>180||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)||!in_array($section,['installation','getting-started','configuration','features','troubleshooting','faq','changelog'],true)||!in_array($level,['public','previous_customer','active_access'],true)||$body===''||strlen($body)>200000)throw new ApiException('validation_error',400,'Complete the documentation title, slug, section, access level, and content.');
        $hasSummary=$this->hasColumn('product_documents','summary');
        $values=[':product'=>$productId,':slug'=>$slug,':title'=>$title,':section'=>$section,':body'=>$body,':level'=>$level,':sort'=>(int)($input['sort_order']??0),':published'=>isset($input['is_published'])?1:0,':now'=>gmdate('Y-m-d H:i:s')];if($hasSummary)$values[':summary']=$this->nullableText($input['summary']??null,500);
        if($documentId===null){$statement=$this->database->prepare('INSERT INTO product_documents (product_id,slug,title'.($hasSummary?',summary':'').',section,body,access_level,sort_order,is_published,updated_at) VALUES (:product,:slug,:title'.($hasSummary?',:summary':'').',:section,:body,:level,:sort,:published,:now)');$statement->execute($values);$documentId=(int)$this->database->lastInsertId();}else{$values[':id']=$documentId;$this->database->prepare('UPDATE product_documents SET product_id=:product,slug=:slug,title=:title'.($hasSummary?',summary=:summary':'').',section=:section,body=:body,access_level=:level,sort_order=:sort,is_published=:published,updated_at=:now WHERE id=:id')->execute($values);}
        $this->audit($actorUserId,'documentation.saved','product_document',(string)$documentId,['product_id'=>$productId,'title'=>$title,'published'=>isset($input['is_published'])],$ipAddress);
    }

    public function deleteDocument(int $actorUserId,int $documentId,string $ipAddress): void{$this->deleteContentRow($actorUserId,'product_documents',$documentId,'documentation.deleted',$ipAddress);}

    public function saveChangelog(int $actorUserId,int $productId,?int $entryId,array $input,string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId,'content.manage');$version=trim((string)($input['version']??''));$title=trim((string)($input['title']??''));$summary=trim((string)($input['summary']??''));$body=trim((string)($input['body']??''));$released=trim((string)($input['published_at']??''));
        if($productId<1||$version===''||strlen($version)>64||strlen($title)>180||$summary===''||strlen($summary)>20000)throw new ApiException('validation_error',400,'Complete the version and release summary.');
        try{$publishedAt=$released===''?gmdate('Y-m-d H:i:s'):(new \DateTimeImmutable($released,new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');}catch(\Throwable){throw new ApiException('validation_error',400,'Enter a valid release date.');}
        $hasTitle=$this->hasColumn('product_changelog','title');$hasBody=$this->hasColumn('product_changelog','body');
        $values=[':product'=>$productId,':version'=>$version,':summary'=>$summary,':published'=>$publishedAt,':public'=>isset($input['is_public'])?1:0];if($hasTitle)$values[':title']=$title===''?null:$title;if($hasBody)$values[':body']=$body===''?null:$body;
        if($entryId===null){$statement=$this->database->prepare('INSERT INTO product_changelog (product_id,version'.($hasTitle?',title':'').',summary'.($hasBody?',body':'').',published_at,is_public) VALUES (:product,:version'.($hasTitle?',:title':'').',:summary'.($hasBody?',:body':'').',:published,:public)');$statement->execute($values);$entryId=(int)$this->database->lastInsertId();}else{$values[':id']=$entryId;$this->database->prepare('UPDATE product_changelog SET product_id=:product,version=:version'.($hasTitle?',title=:title':'').',summary=:summary'.($hasBody?',body=:body':'').',published_at=:published,is_public=:public WHERE id=:id')->execute($values);}
        $this->audit($actorUserId,'changelog.saved','product_changelog',(string)$entryId,['product_id'=>$productId,'version'=>$version,'published'=>isset($input['is_public'])],$ipAddress);
    }

    public function deleteChangelog(int $actorUserId,int $entryId,string $ipAddress): void{$this->deleteContentRow($actorUserId,'product_changelog',$entryId,'changelog.deleted',$ipAddress);}

    public function updateServiceStatus(int $actorUserId,string $serviceKey,string $status,string $incident,string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId,'commerce.manage');if(!preg_match('/^[a-z0-9-]{2,50}$/',$serviceKey)||!in_array($status,['operational','updating','maintenance','unavailable','discontinued'],true)||strlen($incident)>500)throw new ApiException('validation_error',400,'Select a valid service and status.');
        $statement=$this->database->prepare('UPDATE platform_service_status SET status=:status,incident=:incident,updated_at=:now WHERE service_key=:key');$statement->execute([':status'=>$status,':incident'=>trim($incident)===''?null:trim($incident),':now'=>gmdate('Y-m-d H:i:s'),':key'=>$serviceKey]);if($statement->rowCount()===0){$exists=$this->database->prepare('SELECT COUNT(*) FROM platform_service_status WHERE service_key=:key');$exists->execute([':key'=>$serviceKey]);if((int)$exists->fetchColumn()===0)throw new ApiException('service_not_found',404,'Service not found.');}
        $this->audit($actorUserId,'service.status_updated','platform_service',$serviceKey,['status'=>$status,'incident'=>trim($incident)],$ipAddress);
    }

    public function updateSetting(int $actorUserId,string $key,string $value,string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId,'commerce.manage');$allowed=['default_currency','support_email','community_url','device_reset_cooldown_days','public_registration_enabled','maintenance_mode','email_sender_name','legal_document_version'];if(!in_array($key,$allowed,true)||strlen($value)>2000)throw new ApiException('validation_error',400,'This setting cannot be edited here.');
        if($key==='default_currency'&&!preg_match('/^[A-Z]{3}$/',$value))throw new ApiException('validation_error',400,'Currency must be a three-letter code.');if($key==='support_email'&&filter_var($value,FILTER_VALIDATE_EMAIL)===false)throw new ApiException('validation_error',400,'Enter a valid support email.');if(in_array($key,['public_registration_enabled','maintenance_mode'],true)&&!in_array($value,['0','1'],true))throw new ApiException('validation_error',400,'Select a valid setting value.');if($key==='device_reset_cooldown_days'&&((int)$value<1||(int)$value>90))throw new ApiException('validation_error',400,'The device reset cooldown must be between 1 and 90 days.');
        $statement=$this->database->prepare('UPDATE application_settings SET setting_value=:value,updated_by_user_id=:actor,updated_at=:now WHERE setting_key=:key');$statement->execute([':value'=>$value,':actor'=>$actorUserId,':now'=>gmdate('Y-m-d H:i:s'),':key'=>$key]);$this->audit($actorUserId,'setting.updated','application_setting',$key,['value'=>$value],$ipAddress);
    }

    private function deleteContentRow(int $actorUserId, string $table, int $id, string $action, string $ipAddress): void
    {
        $this->authorization->requirePermission($actorUserId, 'content.manage');
        if (!in_array($table, ['product_media', 'product_feature_categories', 'product_features', 'product_faqs', 'product_documents', 'product_changelog'], true) || $id < 1) throw new ApiException('validation_error', 400, 'Invalid content item.');
        $statement = $this->database->prepare('DELETE FROM ' . $table . ' WHERE id=:id');
        $statement->execute([':id' => $id]);
        if ($statement->rowCount() !== 1) throw new ApiException('content_not_found', 404, 'Content item not found.');
        $this->audit($actorUserId, $action, $table, (string) $id, [], $ipAddress);
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key=$table.'.'.$column;if(array_key_exists($key,$this->schemaColumns))return$this->schemaColumns[$key];
        if(!preg_match('/^[a-z_]+$/',$table)||!preg_match('/^[a-z_]+$/',$column))return$this->schemaColumns[$key]=false;
        if((string)$this->database->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
            $rows=$this->database->query('PRAGMA table_info('.$table.')')->fetchAll();foreach($rows as $row)if((string)($row['name']??'')===$column)return$this->schemaColumns[$key]=true;return$this->schemaColumns[$key]=false;
        }
        $statement=$this->database->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:table AND column_name=:column');$statement->execute([':table'=>$table,':column'=>$column]);return$this->schemaColumns[$key]=(int)$statement->fetchColumn()===1;
    }

    private function nullableText(mixed $value, int $maximum): ?string
    {
        $value = trim(is_string($value) ? $value : '');
        if ($value === '') return null;
        if (strlen($value) > $maximum) throw new ApiException('validation_error', 400, 'One of the content fields is too long.');
        return $value;
    }

    private function decimalToCents(string $value): int
    {
        $value=str_replace(',','.',trim($value));if(!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/',$value))throw new ApiException('validation_error',400,'Enter prices in currency format, for example 24.99.');[$whole,$decimal]=array_pad(explode('.',$value,2),2,'');return(int)$whole*100+(int)str_pad($decimal,2,'0');
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
