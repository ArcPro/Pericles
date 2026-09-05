<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Pericles\Auth\AuthService;
use Pericles\Auth\PasswordResetService;
use Pericles\Auth\SessionService;
use Pericles\Activation\ActivationRateLimiter;
use Pericles\Activation\ActivationService;
use Pericles\Account\AccountService;
use Pericles\Admin\AdminService;
use Pericles\Catalog\CatalogService;
use Pericles\Commerce\CheckoutService;
use Pericles\Commerce\CommercialCatalogService;
use Pericles\Commerce\StripePaymentService;
use Pericles\Database;
use Pericles\Device\DeviceService;
use Pericles\Http\ApiException;
use Pericles\Http\BinaryResponse;
use Pericles\Http\JsonRequest;
use Pericles\Http\JsonResponse;
use Pericles\Security\LoginRateLimiter;
use Pericles\Security\AuthorizationService;
use Pericles\Subscriptions\SubscriptionService;
use Pericles\Support\SupportService;
use Pericles\Web\AccountPage;
use Pericles\Web\AdminPage;
use Pericles\Web\PublicPage;
use Pericles\Mail\MailService;
use Pericles\Modules\ModuleAuthorizationService;
use Pericles\Modules\ModuleDownloadService;
use Pericles\Modules\ModulePackageBuilder;
use Pericles\Modules\ModuleRateLimiter;
use Pericles\Modules\ModuleStorage;
use Pericles\Modules\ModuleTicketService;
use Pericles\Device\Base64Url;

function currentRoute(): string
{
    $requestPath = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', '/') ?: '/';
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/.');

    if ($base !== '' && ($requestPath === $base || str_starts_with($requestPath, $base . '/'))) {
        $requestPath = substr($requestPath, strlen($base)) ?: '/';
    }

    return $requestPath;
}

function requireMethod(string $expected): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== $expected) {
        header('Allow: ' . $expected);
        throw new ApiException('method_not_allowed', 405, 'Method not allowed.');
    }
}

function bearerToken(): string
{
    $header = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (!preg_match('/^Bearer\s+([^\s]+)$/i', $header, $matches)) {
        throw new ApiException('invalid_token', 401, 'Invalid access token.');
    }

    return $matches[1];
}

function requestIsHttps(): bool
{
    if (strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on' || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    $forwarded = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $forwarded === 'https';
}

function startWebSession(bool $secure): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('pericles_web');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function requireCsrf(): void
{
    $provided = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
    $expected = is_string($_SESSION['csrf'] ?? null) ? $_SESSION['csrf'] : '';
    if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
        throw new ApiException('invalid_csrf', 400, 'Your session has expired. Reload the page.');
    }
}

function webPath(string $path): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/.');
    return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
}

function redirectWithFlash(string $path, string $type, string $message, ?string $key = null): never
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message, 'key' => $key];
    header('Location: ' . webPath($path));
    exit;
}

function consumeFlash(): ?array
{
    $flash = is_array($_SESSION['flash'] ?? null) ? $_SESSION['flash'] : null;
    unset($_SESSION['flash']);
    return $flash;
}

function logUnexpectedFailure(Throwable $exception, string $route): string
{
    $reference = strtoupper(bin2hex(random_bytes(4)));
    $entry = sprintf(
        'Pericles server error [%s] %s %s: %s (%s:%d)',
        $reference,
        strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        $route,
        $exception::class . ': ' . $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    );
    $logDirectory = dirname(__DIR__) . '/storage/logs';
    $written = false;
    if ((is_dir($logDirectory) || @mkdir($logDirectory, 0750, true)) && is_writable($logDirectory)) {
        $written = @file_put_contents(
            $logDirectory . '/pericles.log',
            '[' . gmdate('Y-m-d H:i:s') . " UTC] " . $entry . PHP_EOL,
            FILE_APPEND | LOCK_EX
        ) !== false;
    }
    if (!$written) error_log($entry);
    return $reference;
}

function signInWebUser(array $user): never
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['email'] = (string) $user['email'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $checkoutToken = is_string($_SESSION['checkout_token'] ?? null) ? $_SESSION['checkout_token'] : '';
    $destination = preg_match('/^[A-Za-z0-9_-]{40,60}$/', $checkoutToken)
        ? '/checkout/' . rawurlencode($checkoutToken)
        : '/account';
    header('Location: ' . webPath($destination));
    exit;
}

$config = require __DIR__ . '/../config/app.php';
$route = currentRoute();

try {
    if (($config['app_env'] ?? 'production') === 'production' && !requestIsHttps()) {
        throw new ApiException('https_required', 426, 'HTTPS is required.');
    }

    $protectedWebRoute = $route === '/logout' || str_starts_with($route, '/account') || str_starts_with($route, '/billing/')
        || str_starts_with($route, '/admin')
        || str_starts_with($route, '/support-attachments/')
        || in_array($route, ['/products', '/downloads', '/documentation', '/licenses', '/access', '/devices', '/activity', '/billing', '/settings', '/security'], true);
    if ($protectedWebRoute) {
        startWebSession(requestIsHttps());
        if (!isset($_SESSION['user_id'])) {
            header('Location: ' . webPath('/login'));
            exit;
        }
    }

    $database = Database::getConnection();
    $auth = new AuthService($database);
    $sessions = new SessionService($database, (int) $config['access_token_ttl']);
    $rateLimiter = new LoginRateLimiter(
        $database,
        (int) $config['login_rate_limit'],
        (int) $config['login_rate_window']
    );
    $devices = new DeviceService(
        $database,
        (int) $config['device_challenge_ttl'],
        (int) $config['max_devices_per_user']
    );
    $activation = new ActivationService($database);
    $activationRateLimiter = new ActivationRateLimiter(
        $database,
        (int) $config['activation_rate_limit'],
        (int) $config['activation_rate_window']
    );
    $catalog = new CatalogService($database);
    $subscriptions = new SubscriptionService($database);
    $moduleAuthorization = new ModuleAuthorizationService($database);
    $moduleTickets = new ModuleTicketService(
        $database,
        $moduleAuthorization,
        (int) $config['module_ticket_ttl']
    );
    $moduleRateLimiter = new ModuleRateLimiter(
        $database,
        (int) $config['module_ticket_rate_limit'],
        (int) $config['module_download_rate_limit'],
        (int) $config['module_rate_window']
    );
    $commercialCatalog = new CommercialCatalogService($database);
    $checkoutService = new CheckoutService($database);
    $stripePayments = new StripePaymentService(
        $checkoutService,
        (string) $config['stripe_publishable_key'],
        (string) $config['stripe_secret_key'],
        (string) $config['stripe_webhook_secret'],
        (string) $config['app_url']
    );
    $accounts = new AccountService($database);
    $passwordReset = new PasswordResetService(
        $database,
        (int) $config['password_reset_ttl'],
        (int) $config['password_reset_limit']
    );
    $mail = new MailService((string) $config['mail_from'], (string) $config['app_url']);
    $supportService = new SupportService($database);

    if ($route === '/api/v1/payments/stripe/webhook') {
        requireMethod('POST');
        $rawBody = file_get_contents('php://input');
        JsonResponse::send(200, $stripePayments->handleWebhook(
            is_string($rawBody) ? $rawBody : '',
            trim((string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''))
        ));
    }

    if ($route === '/api/v1/payments/webhook') {
        requireMethod('POST');
        $secret = (string) $config['payment_webhook_secret'];
        $provider = (string) $config['payment_provider'];
        if (strtolower($provider) === 'stripe') {
            throw new ApiException('webhook_endpoint_retired', 410, 'Use the Stripe-signed webhook endpoint.');
        }
        $signature = trim((string) ($_SERVER['HTTP_X_PERICLES_SIGNATURE'] ?? ''));
        $rawBody = file_get_contents('php://input');
        if ($secret === '' || $provider === '' || !is_string($rawBody)
            || $signature === '' || !hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)) {
            throw new ApiException('invalid_webhook_signature', 401, 'Invalid webhook signature.');
        }
        $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || ($payload['status'] ?? null) !== 'paid') {
            throw new ApiException('invalid_webhook', 400, 'Unsupported payment event.');
        }
        JsonResponse::send(200, $checkoutService->confirmPayment(
            $provider,
            is_string($payload['event_id'] ?? null) ? $payload['event_id'] : '',
            is_string($payload['order_number'] ?? null) ? $payload['order_number'] : '',
            is_string($payload['reference'] ?? null) ? $payload['reference'] : '',
            (int) ($payload['amount_cents'] ?? -1),
            is_string($payload['currency'] ?? null) ? $payload['currency'] : ''
        ));
    }

    $legalRoutes=['/terms'=>'terms','/terms-of-sale'=>'terms-of-sale','/privacy'=>'privacy','/refund-policy'=>'refund-policy','/cookies'=>'cookies','/legal-notice'=>'legal-notice','/disclaimer'=>'disclaimer'];
    $publicCommerceRoute = in_array($route, ['/', '/home', '/enhancements', '/status', '/changelog', '/support', '/checkout/start'], true)
        || isset($legalRoutes[$route])
        || preg_match('#^/enhancements/[a-z0-9]+(?:-[a-z0-9]+)*$#', $route)
        || preg_match('#^/checkout/[A-Za-z0-9_-]{40,60}(?:/(?:order|pay|stripe-session))?$#', $route);
    if ($publicCommerceRoute) {
        startWebSession(requestIsHttps());
        $publicUser = isset($_SESSION['user_id']) ? $accounts->profile((int) $_SESSION['user_id']) : null;
        $csrf = (string) $_SESSION['csrf'];

        if ($route === '/' || $route === '/home') {
            requireMethod('GET');
            $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
            if (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) {
                JsonResponse::send(200, ['service' => 'pericles-auth-api', 'status' => 'ok', 'version' => '0.2.0']);
            }
            PublicPage::landing($commercialCatalog->listEnhancements(), $commercialCatalog->publicChangelog(null, 4), $publicUser, $csrf);
        }
        if ($route === '/enhancements') {
            requireMethod('GET');
            PublicPage::catalog($commercialCatalog->listEnhancements(), $publicUser, $csrf);
        }
        if (preg_match('#^/enhancements/([a-z0-9]+(?:-[a-z0-9]+)*)$#', $route, $matches)) {
            requireMethod('GET');
            $flash=consumeFlash();
            PublicPage::product($commercialCatalog->enhancement($matches[1], $publicUser === null ? null : (int) $publicUser['id']), $publicUser, $csrf, $flash === null ? null : (string)$flash['message']);
        }
        if ($route === '/status') {
            requireMethod('GET');
            PublicPage::status($commercialCatalog->statusOverview(), $publicUser, $csrf);
        }
        if ($route === '/changelog') {
            requireMethod('GET');
            PublicPage::changelog($commercialCatalog->publicChangelog(), $commercialCatalog->listEnhancements(), $publicUser, $csrf);
        }
        if ($route === '/support') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                try {
                    requireCsrf();
                    $ticket=$supportService->createRequest(
                        $publicUser===null?null:(int)$publicUser['id'],
                        $publicUser===null&&(is_string($_POST['email']??null))?$_POST['email']:null,
                        is_string($_POST['subject']??null)?$_POST['subject']:'',
                        is_string($_POST['category']??null)?$_POST['category']:'',
                        is_string($_POST['message']??null)?$_POST['message']:'',
                        is_string($_POST['priority']??null)?$_POST['priority']:'normal',
                        isset($_POST['product_id'])&&(int)$_POST['product_id']>0?(int)$_POST['product_id']:null,
                        isset($_POST['order_id'])&&(int)$_POST['order_id']>0?(int)$_POST['order_id']:null,
                        is_array($_FILES['attachments']??null)?$_FILES['attachments']:[]
                    );
                    PublicPage::support($publicUser,$csrf,'Support request '.(string)$ticket['ticket_number'].' created.');
                } catch(ApiException $exception){PublicPage::support($publicUser,$csrf,null,$exception->getMessage());}
                catch(Throwable $exception){$reference=logUnexpectedFailure($exception,$route);PublicPage::support($publicUser,$csrf,null,'The support request could not be saved. Please try again or contact support with reference '.$reference.'.');}
            }
            requireMethod('GET'); PublicPage::support($publicUser,$csrf);
        }
        if(isset($legalRoutes[$route])){
            requireMethod('GET');$key=$legalRoutes[$route];$content=$commercialCatalog->content($key)??['title'=>ucwords(str_replace('-',' ',$key)),'body'=>'Content pending legal review.','version'=>'draft','updated_at'=>gmdate('Y-m-d H:i:s')];PublicPage::legal($content,$publicUser,$csrf);
        }
        if ($route === '/checkout/start') {
            requireMethod('POST'); requireCsrf();
            $productSlug=is_string($_POST['product_slug'] ?? null)?$_POST['product_slug']:'';
            try {
                $session = $checkoutService->start($productSlug,(int)($_POST['plan_id']??0),$publicUser===null?null:(int)$publicUser['id'],is_string($_SERVER['HTTP_REFERER']??null)?$_SERVER['HTTP_REFERER']:'product');
                $_SESSION['checkout_token']=(string)$session['token'];
                header('Location: '.webPath('/checkout/'.rawurlencode((string)$session['token']))); exit;
            } catch (ApiException $exception) {
                redirectWithFlash('/enhancements/'.rawurlencode($productSlug).'#pricing','error',$exception->getMessage());
            }
        }
        if (preg_match('#^/checkout/([A-Za-z0-9_-]{40,60})$#', $route, $matches)) {
            requireMethod('GET');
            $checkout = $checkoutService->checkout($matches[1], $publicUser === null ? null : (int) $publicUser['id']);
            $order = null;
            if ($publicUser !== null) {
                $query = $database->prepare('SELECT * FROM orders WHERE checkout_session_id = :checkout_id AND user_id = :user_id LIMIT 1');
                $query->execute([':checkout_id' => (int) $checkout['id'], ':user_id' => (int) $publicUser['id']]);
                $row = $query->fetch(); $order = is_array($row) ? $row : null;
            }
            $flash = consumeFlash();
            $message = $flash === null ? null : (string) $flash['message'];
            if (isset($_GET['stripe_return'])) {
                $message = is_array($order) && (string) $order['status'] === 'paid'
                    ? 'Payment confirmed. Your Access is ready.'
                    : 'Stripe received the payment attempt. Secure confirmation is still being processed.';
                if (is_array($order) && (string) $order['status'] === 'pending') {
                    $order['payment_processing'] = true;
                }
            }
            $stripe = strtolower((string) $config['payment_provider']) === 'stripe' && $stripePayments->isConfigured()
                ? [
                    'publishable_key' => $stripePayments->publishableKey(),
                    'session_url' => webPath('/checkout/' . rawurlencode($matches[1]) . '/stripe-session'),
                ]
                : null;
            PublicPage::checkout($checkout, $publicUser, $csrf, $matches[1], $order, $stripe, $message);
        }
        if (preg_match('#^/checkout/([A-Za-z0-9_-]{40,60})/order$#', $route, $matches)) {
            requireMethod('POST'); requireCsrf();
            if ($publicUser === null) { $_SESSION['checkout_token'] = $matches[1]; header('Location: ' . webPath('/login')); exit; }
            try {
                $termsOfSale=$commercialCatalog->content('terms-of-sale');
                $checkoutService->createOrder($matches[1], (int) $publicUser['id'], is_array($termsOfSale)?(string)$termsOfSale['version']:null, isset($_POST['immediate_delivery']));
                redirectWithFlash('/checkout/' . $matches[1], 'success', 'Your order was created securely.');
            } catch (ApiException $exception) {
                redirectWithFlash('/checkout/' . $matches[1], 'error', $exception->getMessage());
            }
        }
        if (preg_match('#^/checkout/([A-Za-z0-9_-]{40,60})/pay$#', $route, $matches)) {
            requireMethod('POST'); requireCsrf();
            if ($publicUser === null) { $_SESSION['checkout_token'] = $matches[1]; header('Location: ' . webPath('/login')); exit; }
            redirectWithFlash('/checkout/' . $matches[1], 'success', 'Use the secure Stripe payment form below.');
        }
        if (preg_match('#^/checkout/([A-Za-z0-9_-]{40,60})/stripe-session$#', $route, $matches)) {
            requireMethod('POST'); requireCsrf();
            if ($publicUser === null) {
                throw new ApiException('authentication_required', 401, 'Sign in before starting payment.');
            }
            if (strtolower((string) $config['payment_provider']) !== 'stripe') {
                throw new ApiException('payment_not_configured', 503, 'Stripe payment is not enabled. No charge was attempted.');
            }
            $order = $checkoutService->orderForCheckout($matches[1], (int) $publicUser['id']);
            JsonResponse::send(201, $stripePayments->createEmbeddedSession($order, $matches[1]));
        }
    }

    $memberPageRoutes = [
        '/products', '/downloads', '/documentation', '/account/changelog', '/licenses', '/access', '/devices', '/activity',
        '/billing', '/account/support', '/settings', '/security',
    ];
    if (in_array($route, ['/login', '/auth', '/register', '/signup', '/forgot-password', '/reset-password', '/logout'], true)
        || in_array($route, $memberPageRoutes, true)
        || str_starts_with($route, '/account') || str_starts_with($route, '/billing/') || str_starts_with($route, '/support-attachments/') || str_starts_with($route, '/admin')) {
        startWebSession(requestIsHttps());
        $csrf = (string) $_SESSION['csrf'];

        if ($route === '/login' || $route === '/auth') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                if (isset($_SESSION['user_id'])) {
                    header('Location: ' . webPath('/account'));
                    exit;
                }
                AccountPage::login($csrf);
            }
            requireMethod('POST');
            try {
                requireCsrf();
                $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
                $rateLimiter->ensureAllowed($ip, $email);
                $user = $auth->authenticate($email, $password);
                $rateLimiter->clear($ip, $email);
                signInWebUser($user);
            } catch (ApiException $exception) {
                if (isset($email, $ip) && $exception->errorCode === 'invalid_credentials') {
                    $rateLimiter->recordFailure($ip, $email);
                }
                AccountPage::login($csrf, 'Unable to sign in. Check your credentials.');
            }
        }

        if ($route === '/register' || $route === '/signup') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                if (isset($_SESSION['user_id'])) {
                    header('Location: ' . webPath('/account'));
                    exit;
                }
                AccountPage::register($csrf);
            }
            requireMethod('POST');
            try {
                requireCsrf();
                $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                $passwordConfirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';
                $displayName = is_string($_POST['display_name'] ?? null) ? $_POST['display_name'] : '';
                $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
                $rateLimiter->ensureAllowed($ip, $email);
                if ($password !== $passwordConfirm) {
                    throw new ApiException('validation_error', 400, 'The passwords do not match.');
                }
                $user = $auth->register($email, $password, $displayName, isset($_POST['accept_terms']));
                $rateLimiter->clear($ip, $email);
                signInWebUser($user);
            } catch (ApiException $exception) {
                if (isset($email, $ip) && $exception->errorCode === 'email_taken') {
                    $rateLimiter->recordFailure($ip, $email);
                }
                $message = $exception->errorCode === 'rate_limited'
                    ? 'Too many attempts. Try again later.'
                    : $exception->getMessage();
                AccountPage::register($csrf, $message);
            }
        }

        if ($route === '/forgot-password') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') AccountPage::recovery($csrf);
            requireMethod('POST'); requireCsrf();
            try {
                $reset = $passwordReset->request(
                    is_string($_POST['email'] ?? null) ? $_POST['email'] : '',
                    (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
                );
                if ($reset !== null) $mail->sendPasswordReset((string) $reset['email'], (string) $reset['display_name'], (string) $reset['token']);
                AccountPage::recovery($csrf, 'If an active account matches that address, a recovery link has been sent.');
            } catch (ApiException $exception) {
                $message = $exception->errorCode === 'rate_limited' ? 'Too many requests. Try again later.' : $exception->getMessage();
                AccountPage::recovery($csrf, null, $message);
            }
        }

        if ($route === '/reset-password') {
            $token = is_string(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? ($_GET['token'] ?? null) : ($_POST['token'] ?? null))
                ? (string) (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? $_GET['token'] : $_POST['token']) : '';
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') AccountPage::resetPassword($csrf, $token);
            requireMethod('POST'); requireCsrf();
            try {
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                if ($password !== (is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '')) throw new ApiException('validation_error', 400, 'The passwords do not match.');
                $passwordReset->reset($token, $password);
                redirectWithFlash('/login', 'success', 'Your password has been updated. You can now sign in.');
            } catch (ApiException $exception) {
                AccountPage::resetPassword($csrf, $token, $exception->getMessage());
            }
        }

        if (!isset($_SESSION['user_id'])) {
            header('Location: ' . webPath('/login'));
            exit;
        }
        $webUserId = (int) $_SESSION['user_id'];
        $webEmail = (string) ($_SESSION['email'] ?? '');
        $authorization = new AuthorizationService($database);

        if ($route === '/logout') {
            requireMethod('POST');
            requireCsrf();
            $_SESSION = [];
            session_destroy();
            header('Location: ' . webPath('/login'));
            exit;
        }

        if ($route === '/account/activate') {
            requireMethod('POST');
            try {
                requireCsrf();
                $activationRateLimiter->consume($webUserId, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                $result = $activation->redeem(
                    $webUserId,
                    is_string($_POST['key'] ?? null) ? $_POST['key'] : ''
                );
                redirectWithFlash('/licenses', 'success', 'Key activated for ' . (string) $result['product']['name'] . '.');
            } catch (ApiException $exception) {
                redirectWithFlash('/licenses#activate-key', 'error', $exception->getMessage());
            }
        }

        if (preg_match('#^/account/products/([a-z0-9]+(?:-[a-z0-9]+)*)/unbind$#', $route, $matches)) {
            requireMethod('POST');
            try {
                requireCsrf();
                $accounts->unbindProduct($webUserId, $matches[1]);
                redirectWithFlash('/products', 'success', 'The product has been unlinked from its device.');
            } catch (ApiException $exception) {
                redirectWithFlash('/products', 'error', $exception->getMessage());
            }
        }

        if (preg_match('#^/account/devices/([a-fA-F0-9]{32})/revoke$#', $route, $matches)) {
            requireMethod('POST');
            try {
                requireCsrf();
                $devices->revoke($webUserId, $matches[1]);
                redirectWithFlash('/devices', 'success', 'The device has been revoked.');
            } catch (ApiException $exception) {
                redirectWithFlash('/devices', 'error', $exception->getMessage());
            }
        }

        if ($route === '/account/profile') {
            requireMethod('POST');
            try {
                requireCsrf();
                $profile = $accounts->updateProfile(
                    $webUserId,
                    is_string($_POST['display_name'] ?? null) ? $_POST['display_name'] : ''
                );
                $_SESSION['display_name'] = (string) $profile['display_name'];
                redirectWithFlash('/settings', 'success', 'Your profile has been updated.');
            } catch (ApiException $exception) {
                redirectWithFlash('/settings', 'error', $exception->getMessage());
            }
        }

        if ($route === '/account/security/password') {
            requireMethod('POST');
            try {
                requireCsrf();
                $accounts->changePassword(
                    $webUserId,
                    is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '',
                    is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : ''
                );
                redirectWithFlash('/security', 'success', 'Password changed. Launcher sessions have been revoked.');
            } catch (ApiException $exception) {
                redirectWithFlash('/security', 'error', $exception->getMessage());
            }
        }

        if ($route === '/account/support' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                requireCsrf();
                $ticket = $supportService->createRequest(
                    $webUserId,
                    null,
                    is_string($_POST['subject'] ?? null) ? $_POST['subject'] : '',
                    is_string($_POST['category'] ?? null) ? $_POST['category'] : '',
                    is_string($_POST['message'] ?? null) ? $_POST['message'] : '',
                    is_string($_POST['priority'] ?? null) ? $_POST['priority'] : 'normal',
                    isset($_POST['product_id']) && (int) $_POST['product_id'] > 0 ? (int) $_POST['product_id'] : null,
                    isset($_POST['order_id']) && (int) $_POST['order_id'] > 0 ? (int) $_POST['order_id'] : null,
                    is_array($_FILES['attachments'] ?? null) ? $_FILES['attachments'] : []
                );
                redirectWithFlash('/account/support', 'success', 'Support ticket ' . (string) $ticket['ticket_number'] . ' created.');
            } catch (ApiException $exception) {
                redirectWithFlash('/account/support', 'error', $exception->getMessage());
            } catch (Throwable $exception) {
                $reference=logUnexpectedFailure($exception,$route);
                redirectWithFlash('/account/support','error','The support request could not be saved. Reference '.$reference.'.');
            }
        }

        if (preg_match('#^/account/support/(SUP-[A-Z0-9-]{8,24})/reply$#', $route, $matches)) {
            requireMethod('POST'); requireCsrf();
            try {
                $supportService->replyForUser($matches[1], $webUserId, is_string($_POST['message'] ?? null) ? $_POST['message'] : '', is_array($_FILES['attachments'] ?? null) ? $_FILES['attachments'] : []);
                redirectWithFlash('/account/support/' . rawurlencode($matches[1]), 'success', 'Your reply was added.');
            } catch (ApiException $exception) { redirectWithFlash('/account/support/' . rawurlencode($matches[1]), 'error', $exception->getMessage()); }
            catch (Throwable $exception) { $reference=logUnexpectedFailure($exception,$route);redirectWithFlash('/account/support/' . rawurlencode($matches[1]),'error','The reply could not be saved. Reference '.$reference.'.'); }
        }

        if (preg_match('#^/account/support/(SUP-[A-Z0-9-]{8,24})/solve$#', $route, $matches)) {
            requireMethod('POST'); requireCsrf();
            try { $supportService->markSolved($matches[1], $webUserId); redirectWithFlash('/account/support/' . rawurlencode($matches[1]), 'success', 'This ticket was marked resolved.'); }
            catch (ApiException $exception) { redirectWithFlash('/account/support/' . rawurlencode($matches[1]), 'error', $exception->getMessage()); }
        }

        if (preg_match('#^/support-attachments/(\d+)$#', $route, $matches)) {
            requireMethod('GET');
            $isStaff=$authorization->can($webUserId,'support.manage');
            $attachment=$supportService->attachmentForUser((int)$matches[1],$webUserId,$isStaff);
            header('Content-Type: '.(string)$attachment['mime_type']);
            header('Content-Length: '.(string)filesize((string)$attachment['path']));
            header('Content-Disposition: attachment; filename="'.str_replace(['"',"\r","\n"],'',(string)$attachment['original_name']).'"');
            header('X-Content-Type-Options: nosniff');
            readfile((string)$attachment['path']); exit;
        }

        if (str_starts_with($route, '/admin')) {
            try {
                $authorization->requirePermission($webUserId, 'admin.access');
                $admin = new AdminService($database);
                $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
                $adminPages = [
                    '/admin' => 'overview', '/admin/users' => 'users',
                    '/admin/customer-access' => 'customer_access', '/admin/licenses' => 'customer_access',
                    '/admin/access-keys' => 'access_keys',
                    '/admin/enhancements' => 'enhancements', '/admin/products' => 'enhancements',
                    '/admin/orders' => 'orders', '/admin/payments' => 'orders',
                    '/admin/coupons' => 'coupons', '/admin/devices' => 'devices',
                    '/admin/documentation' => 'documentation', '/admin/changelog' => 'changelog',
                    '/admin/support' => 'support', '/admin/status' => 'status', '/admin/builds' => 'builds',
                    '/admin/audit' => 'audit', '/admin/settings' => 'settings',
                ];
                if (preg_match('#^/admin/enhancements/(\d+)$#',$route,$matches)) {
                    requireMethod('GET');
                    if (!$authorization->can($webUserId,'content.manage') && !$authorization->can($webUserId,'commerce.manage')) throw new ApiException('forbidden',403,'You do not have the required permission.');
                    $tab=is_string($_GET['tab']??null)?$_GET['tab']:'general';
                    AdminPage::render($webEmail,$admin->dashboard($webUserId),$csrf,consumeFlash(),'product_editor',['product_id'=>(int)$matches[1],'tab'=>$tab]);
                }
                if (preg_match('#^/admin/users/(\d+)$#',$route,$matches)) {
                    requireMethod('GET');$authorization->requirePermission($webUserId,'users.view');
                    AdminPage::render($webEmail,$admin->dashboard($webUserId),$csrf,consumeFlash(),'user_detail',['user_detail'=>$admin->userDetail($webUserId,(int)$matches[1])]);
                }
                if (preg_match('#^/admin/orders/(PER-[A-Z0-9-]{8,32})$#',$route,$matches)) {
                    requireMethod('GET');$authorization->requirePermission($webUserId,'commerce.view');
                    AdminPage::render($webEmail,$admin->dashboard($webUserId),$csrf,consumeFlash(),'order_detail',['order_detail'=>$admin->orderDetail($webUserId,$matches[1])]);
                }
                if (preg_match('#^/admin/support/(SUP-[A-Z0-9-]{8,24})$#',$route,$matches)) {
                    requireMethod('GET');
                    $authorization->requirePermission($webUserId,'support.manage');
                    AdminPage::render($webEmail,$admin->dashboard($webUserId),$csrf,consumeFlash(),'support_ticket',['ticket'=>$supportService->ticketForAdmin($matches[1]),'actor_id'=>$webUserId]);
                }
                if (isset($adminPages[$route])) {
                    requireMethod('GET');
                    $section=$adminPages[$route];
                    $requiredPermission=match($section){
                        'users'=>'users.view',
                        'orders','coupons'=>'commerce.view',
                        'access_keys'=>'licenses.manage',
                        'devices'=>'devices.manage',
                        'support'=>'support.manage',
                        'audit'=>'audit.view',
                        'builds'=>'audit.view',
                        'settings'=>'commerce.manage',
                        default=>null,
                    };
                    if(is_string($requiredPermission))$authorization->requirePermission($webUserId,$requiredPermission);
                    if(in_array($section,['enhancements','documentation','changelog','status'],true)&&!$authorization->can($webUserId,'content.manage')&&!$authorization->can($webUserId,'commerce.manage'))throw new ApiException('forbidden',403,'You do not have the required permission.');
                    AdminPage::render($webEmail, $admin->dashboard($webUserId), $csrf, consumeFlash(), $adminPages[$route]);
                }
                if ($route === '/admin/customer-access/grant') {
                    requireMethod('POST');requireCsrf();
                    $grant=$admin->grantAccess($webUserId,(int)($_POST['user_id']??0),(int)($_POST['plan_id']??0),is_string($_POST['start_behavior']??null)?$_POST['start_behavior']:'first_activation',is_string($_POST['reason']??null)?$_POST['reason']:'',$ipAddress);
                    redirectWithFlash('/admin/customer-access','success',(string)$grant['product_name'].' Access granted to '.(string)$grant['email'].'.');
                }
                if (preg_match('#^/admin/customer-access/(\d+)/extend$#',$route,$matches)) {
                    requireMethod('POST');requireCsrf();$grant=$admin->extendAccess($webUserId,(int)$matches[1],(int)($_POST['plan_id']??0),is_string($_POST['reason']??null)?$_POST['reason']:'',$ipAddress);
                    redirectWithFlash('/admin/customer-access','success',(string)$grant['product_name'].' Access extended.');
                }
                if (preg_match('#^/admin/customer-access/(\d+)/lifetime$#',$route,$matches)) {
                    requireMethod('POST');requireCsrf();$grant=$admin->convertAccessToLifetime($webUserId,(int)$matches[1],is_string($_POST['reason']??null)?$_POST['reason']:'',$ipAddress);
                    redirectWithFlash('/admin/customer-access','success',(string)$grant['product_name'].' converted to Lifetime Access.');
                }
                if (preg_match('#^/admin/customer-access/(\d+)/revoke$#',$route,$matches)) {
                    requireMethod('POST');requireCsrf();$admin->revokeAccess($webUserId,(int)$matches[1],is_string($_POST['reason']??null)?$_POST['reason']:'',$ipAddress);
                    redirectWithFlash('/admin/customer-access','success','Customer Access revoked.');
                }
                if ($route === '/admin/access-keys/generate') {
                    requireMethod('POST');requireCsrf();$planReference=is_string($_POST['plan_ref']??null)?$_POST['plan_ref']:'';[$productSlug,$planSlug]=array_pad(explode(':',$planReference,2),2,'');
                    $keys=$admin->generateAccessKeys($webUserId,$productSlug,$planSlug,(int)($_POST['quantity']??1),is_string($_POST['expires_at']??null)?$_POST['expires_at']:null,is_string($_POST['reason']??null)?$_POST['reason']:'',$ipAddress);
                    redirectWithFlash('/admin/access-keys','success',count($keys).' Access Key'.(count($keys)===1?'':'s').' generated. Copy now; full keys are not shown again.',implode("\n",$keys));
                }
                if (preg_match('#^/admin/products/(\d+)/pricing$#',$route,$matches)) {
                    requireMethod('POST');requireCsrf();$admin->updateProductPricing($webUserId,(int)$matches[1],is_array($_POST['plans']??null)?$_POST['plans']:[],$ipAddress);
                    redirectWithFlash('/admin/enhancements/'.(int)$matches[1].'?tab=pricing','success','Access Plan pricing has been saved.');
                }
                if (preg_match('#^/admin/products/(\d+)/documents(?:/(\d+))?$#',$route,$matches)) {
                    requireMethod('POST');requireCsrf();$admin->saveDocument($webUserId,(int)$matches[1],isset($matches[2])?(int)$matches[2]:null,$_POST,$ipAddress);
                    redirectWithFlash('/admin/enhancements/'.(int)$matches[1].'?tab=documentation','success','Documentation has been saved.');
                }
                if (preg_match('#^/admin/documents/(\d+)/delete$#',$route,$matches)) {
                    requireMethod('POST');requireCsrf();$admin->deleteDocument($webUserId,(int)$matches[1],$ipAddress);redirectWithFlash('/admin/enhancements','success','Documentation page deleted.');
                }
                if (preg_match('#^/admin/products/(\d+)/changelog(?:/(\d+))?$#',$route,$matches)) {
                    requireMethod('POST');requireCsrf();$admin->saveChangelog($webUserId,(int)$matches[1],isset($matches[2])?(int)$matches[2]:null,$_POST,$ipAddress);
                    redirectWithFlash('/admin/enhancements/'.(int)$matches[1].'?tab=changelog','success','Changelog entry has been saved.');
                }
                if (preg_match('#^/admin/changelog/(\d+)/delete$#',$route,$matches)) {
                    requireMethod('POST');requireCsrf();$admin->deleteChangelog($webUserId,(int)$matches[1],$ipAddress);redirectWithFlash('/admin/enhancements','success','Changelog entry deleted.');
                }
                if (preg_match('#^/admin/services/([a-z0-9-]{2,50})$#',$route,$matches)) {
                    requireMethod('POST');requireCsrf();$admin->updateServiceStatus($webUserId,$matches[1],is_string($_POST['status']??null)?$_POST['status']:'',is_string($_POST['incident']??null)?$_POST['incident']:'',$ipAddress);redirectWithFlash('/admin/status','success','Service status updated.');
                }
                if (preg_match('#^/admin/settings/([a-z0-9_]{2,100})$#',$route,$matches)) {
                    requireMethod('POST');requireCsrf();$admin->updateSetting($webUserId,$matches[1],is_string($_POST['value']??null)?$_POST['value']:'',$ipAddress);redirectWithFlash('/admin/settings','success','Application setting updated.');
                }
                if (preg_match('#^/admin/support/(SUP-[A-Z0-9-]{8,24})/update$#',$route,$matches)) {
                    requireMethod('POST');requireCsrf();$authorization->requirePermission($webUserId,'support.manage');
                    $assignee=isset($_POST['assigned_to_user_id'])&&(int)$_POST['assigned_to_user_id']>0?(int)$_POST['assigned_to_user_id']:null;if(isset($_POST['assign_to_me']))$assignee=$webUserId;
                    $supportService->updateByStaff($matches[1],$webUserId,is_string($_POST['priority']??null)?$_POST['priority']:'normal',is_string($_POST['status']??null)?$_POST['status']:'open',$assignee,is_string($_POST['message']??null)?$_POST['message']:null,isset($_POST['internal_note']),$ipAddress,is_array($_FILES['attachments']??null)?$_FILES['attachments']:[]);
                    redirectWithFlash('/admin/support/'.rawurlencode($matches[1]),'success','Support ticket updated.');
                }
                if ($route === '/admin/licenses/assign') {
                    requireMethod('POST'); requireCsrf();
                    $planReference = is_string($_POST['plan_ref'] ?? null) ? $_POST['plan_ref'] : '';
                    [$productSlug, $planSlug] = array_pad(explode(':', $planReference, 2), 2, '');
                    $grant = $admin->assignProduct(
                        $webUserId,
                        (int) ($_POST['user_id'] ?? 0),
                        $productSlug,
                        $planSlug,
                        $ipAddress
                    );
                    redirectWithFlash('/admin/customer-access', 'success', (string) $grant['result']['product']['name'] . ' Access assigned to ' . (string) $grant['user']['email'] . '.', (string) $grant['plain_key']);
                }
                if (preg_match('#^/admin/subscriptions/(\d+)/unbind$#', $route, $matches)) {
                    requireMethod('POST'); requireCsrf();
                    $admin->unbindSubscription($webUserId, (int) $matches[1], $ipAddress);
                    redirectWithFlash('/admin/customer-access', 'success', 'The Authorized Device has been reset.');
                }
                if (preg_match('#^/admin/subscriptions/(\d+)/delete$#', $route, $matches)) {
                    requireMethod('POST'); requireCsrf();
                    $admin->deleteSubscription($webUserId, (int) $matches[1], $ipAddress);
                    redirectWithFlash('/admin/customer-access', 'success', 'Customer Access has been permanently removed.');
                }
                if (preg_match('#^/admin/users/(\d+)/status$#', $route, $matches)) {
                    requireMethod('POST'); requireCsrf();
                    $admin->updateUserStatus($webUserId, (int) $matches[1], is_string($_POST['status'] ?? null) ? $_POST['status'] : '', $ipAddress);
                    redirectWithFlash('/admin/users', 'success', 'The account status has been updated.');
                }
                if (preg_match('#^/admin/users/(\d+)/role$#', $route, $matches)) {
                    requireMethod('POST'); requireCsrf();
                    $admin->updateUserRole($webUserId, (int) $matches[1], is_string($_POST['role'] ?? null) ? $_POST['role'] : '', $ipAddress);
                    redirectWithFlash('/admin/users', 'success', 'The account role has been updated.');
                }
                if (preg_match('#^/admin/plans/(\d+)$#', $route, $matches)) {
                    requireMethod('POST'); requireCsrf();
                    $sale = is_string($_POST['sale_price_cents'] ?? null) && trim($_POST['sale_price_cents']) !== '' ? (int) $_POST['sale_price_cents'] : null;
                    $admin->updatePlan($webUserId, (int)$matches[1], (int)($_POST['price_cents'] ?? -1), $sale, is_string($_POST['badge'] ?? null) ? $_POST['badge'] : null, isset($_POST['is_active']), $ipAddress);
                    redirectWithFlash('/admin/enhancements', 'success', 'Access Plan pricing has been updated.');
                }
                if (preg_match('#^/admin/enhancements/([a-z0-9]+(?:-[a-z0-9]+)*)/status$#', $route, $matches)) {
                    requireMethod('POST'); requireCsrf();
                    $admin->updateEnhancementStatus($webUserId, $matches[1], is_string($_POST['status'] ?? null) ? $_POST['status'] : '', isset($_POST['purchases_allowed']), is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '', $ipAddress, isset($_POST['freeze_access']));
                    redirectWithFlash('/admin/enhancements', 'success', 'Enhancement status has been updated.');
                }
                if (preg_match('#^/admin/products/(\d+)/content$#', $route, $matches)) {
                    requireMethod('POST'); requireCsrf();
                    $admin->updateProductContent($webUserId, (int) $matches[1], $_POST, $ipAddress);
                    redirectWithFlash('/admin/enhancements/' . (int)$matches[1] . '?tab=general', 'success', 'Enhancement details have been updated.');
                }
                if (preg_match('#^/admin/products/(\d+)/media(?:/(\d+))?$#', $route, $matches)) {
                    requireMethod('POST'); requireCsrf();
                    $admin->saveProductMedia($webUserId, (int) $matches[1], isset($matches[2]) ? (int) $matches[2] : null, $_POST, $ipAddress);
                    redirectWithFlash('/admin/enhancements/' . (int)$matches[1] . '?tab=media', 'success', 'Product media has been saved.');
                }
                if (preg_match('#^/admin/media/(\d+)/delete$#', $route, $matches)) {
                    requireMethod('POST'); requireCsrf(); $admin->deleteProductMedia($webUserId, (int) $matches[1], $ipAddress);
                    redirectWithFlash('/admin/enhancements', 'success', 'Product media has been deleted.');
                }
                if (preg_match('#^/admin/products/(\d+)/feature-categories(?:/(\d+))?$#', $route, $matches)) {
                    requireMethod('POST'); requireCsrf();
                    $admin->saveFeatureCategory($webUserId, (int) $matches[1], isset($matches[2]) ? (int) $matches[2] : null, $_POST, $ipAddress);
                    redirectWithFlash('/admin/enhancements/' . (int)$matches[1] . '?tab=features', 'success', 'Feature category has been saved.');
                }
                if (preg_match('#^/admin/feature-categories/(\d+)/delete$#', $route, $matches)) {
                    requireMethod('POST'); requireCsrf(); $admin->deleteFeatureCategory($webUserId, (int) $matches[1], $ipAddress);
                    redirectWithFlash('/admin/enhancements', 'success', 'Feature category has been deleted.');
                }
                if (preg_match('#^/admin/feature-categories/(\d+)/features(?:/(\d+))?$#', $route, $matches)) {
                    requireMethod('POST'); requireCsrf();
                    $admin->saveFeature($webUserId, (int) $matches[1], isset($matches[2]) ? (int) $matches[2] : null, $_POST, $ipAddress);
                    redirectWithFlash('/admin/enhancements', 'success', 'Feature has been saved.');
                }
                if (preg_match('#^/admin/features/(\d+)/delete$#', $route, $matches)) {
                    requireMethod('POST'); requireCsrf(); $admin->deleteFeature($webUserId, (int) $matches[1], $ipAddress);
                    redirectWithFlash('/admin/enhancements', 'success', 'Feature has been deleted.');
                }
                if (preg_match('#^/admin/products/(\d+)/faqs(?:/(\d+))?$#', $route, $matches)) {
                    requireMethod('POST'); requireCsrf();
                    $admin->saveFaq($webUserId, (int) $matches[1], isset($matches[2]) ? (int) $matches[2] : null, $_POST, $ipAddress);
                    redirectWithFlash('/admin/enhancements/' . (int)$matches[1] . '?tab=faq', 'success', 'FAQ entry has been saved.');
                }
                if (preg_match('#^/admin/faqs/(\d+)/delete$#', $route, $matches)) {
                    requireMethod('POST'); requireCsrf(); $admin->deleteFaq($webUserId, (int) $matches[1], $ipAddress);
                    redirectWithFlash('/admin/enhancements', 'success', 'FAQ entry has been deleted.');
                }
                throw new ApiException('not_found', 404, 'Administration page not found.');
            } catch (ApiException $exception) {
                if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                    redirectWithFlash('/account', 'error', $exception->getMessage());
                }
                redirectWithFlash('/admin', 'error', $exception->getMessage());
            } catch (Throwable $exception) {
                $reference=logUnexpectedFailure($exception,$route);
                if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                    redirectWithFlash('/account','error','The administration page could not be loaded. Reference '.$reference.'.');
                }
                redirectWithFlash('/admin','error','The modification could not be saved. Reference '.$reference.'.');
            }
        }

        if (preg_match('#^/account/support/(SUP-[A-Z0-9-]{8,24})$#', $route, $matches)) {
            requireMethod('GET');
            $flash=consumeFlash();
            AccountPage::ticket(
                $accounts->profile($webUserId),
                $authorization->roleForUser($webUserId),
                $authorization->can($webUserId,'admin.access'),
                $csrf,
                $supportService->ticketForUser($matches[1],$webUserId),
                ($flash['type']??null)==='success'?(string)$flash['message']:null,
                ($flash['type']??null)==='error'?(string)$flash['message']:null
            );
        }

        if (preg_match('#^/billing/orders/(PER-[A-Z0-9-]{8,32})$#',$route,$matches)) {
            requireMethod('GET');
            AccountPage::order($accounts->profile($webUserId),$authorization->roleForUser($webUserId),$authorization->can($webUserId,'admin.access'),$csrf,$checkoutService->orderForUser($matches[1],$webUserId));
        }

        requireMethod('GET');
        $flash = consumeFlash();
        $memberPage = [
            '/account' => 'overview', '/products' => 'products', '/downloads' => 'downloads',
            '/documentation' => 'documentation', '/account/changelog' => 'changelog',
            '/licenses' => 'licenses', '/access' => 'licenses', '/devices' => 'devices', '/activity' => 'activity',
            '/billing' => 'billing', '/account/support' => 'support', '/settings' => 'settings',
            '/security' => 'security',
        ][$route] ?? 'overview';
        $memberWorkspace = $accounts->workspace($webUserId);
        $memberWorkspace['documents'] = $commercialCatalog->documentationForUser($webUserId);
        $memberWorkspace['changelog'] = $commercialCatalog->publicChangelog(null, 100);
        $memberWorkspace['orders'] = $checkoutService->ordersForUser($webUserId);
        $memberWorkspace['tickets'] = $supportService->listForUser($webUserId);
        $memberWorkspace['catalog'] = $commercialCatalog->listEnhancements();
        $memberWorkspace['launcher_download_url'] = (string) $config['launcher_download_url'];
        $memberWorkspace['support_email'] = (string) $config['support_email'];
        AccountPage::account(
            $webEmail,
            $subscriptions->listForUser($webUserId, null),
            $devices->listDevices($webUserId, null),
            $accounts->profile($webUserId),
            $authorization->roleForUser($webUserId),
            $authorization->can($webUserId, 'admin.access'),
            $csrf,
            ($flash['type'] ?? null) === 'success' ? (string) $flash['message'] : null,
            ($flash['type'] ?? null) === 'error' ? (string) $flash['message'] : null,
            $memberWorkspace,
            $memberPage
        );
    }

    if ($route === '/api/v1/auth/login') {
        requireMethod('POST');
        $payload = JsonRequest::body();
        $email = is_string($payload['email'] ?? null) ? trim($payload['email']) : '';
        $password = is_string($payload['password'] ?? null) ? $payload['password'] : '';

        if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || $password === '' || strlen($password) > 1024) {
            throw new ApiException('validation_error', 400, 'A valid email and password are required.');
        }

        $ipAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
        $rateLimiter->ensureAllowed($ipAddress, $email);

        try {
            $user = $auth->authenticate($email, $password);
        } catch (ApiException $exception) {
            if (in_array($exception->errorCode, ['invalid_credentials', 'account_disabled'], true)) {
                $rateLimiter->recordFailure($ipAddress, $email);
            }
            throw $exception;
        }

        $rateLimiter->clear($ipAddress, $email);
        $token = $sessions->create($user, $ipAddress, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        JsonResponse::send(200, $token + ['user' => $user]);
    }

    if ($route === '/api/v1/me') {
        requireMethod('GET');
        JsonResponse::send(200, $sessions->currentUser(bearerToken()));
    }

    if ($route === '/api/v1/auth/logout') {
        requireMethod('POST');
        $sessions->revoke(bearerToken());
        JsonResponse::send(204);
    }

    if ($route === '/api/v1/devices') {
        requireMethod('GET');
        $user = $sessions->currentUser(bearerToken());
        $currentDeviceId = trim((string) ($_SERVER['HTTP_X_DEVICE_ID'] ?? '')) ?: null;
        JsonResponse::send(200, [
            'devices' => $devices->listDevices((int) $user['id'], $currentDeviceId),
        ]);
    }

    if ($route === '/api/v1/devices/register') {
        requireMethod('POST');
        $user = $sessions->currentUser(bearerToken());
        $payload = JsonRequest::body();
        $device = $devices->register(
            (int) $user['id'],
            is_string($payload['device_id'] ?? null) ? $payload['device_id'] : '',
            is_string($payload['public_key'] ?? null) ? $payload['public_key'] : '',
            is_string($payload['key_algorithm'] ?? null) ? $payload['key_algorithm'] : '',
            is_string($payload['display_name'] ?? null) ? $payload['display_name'] : ''
        );
        JsonResponse::send(200, ['device' => $device]);
    }

    if ($route === '/api/v1/devices/challenge') {
        requireMethod('POST');
        $user = $sessions->currentUser(bearerToken());
        $payload = JsonRequest::body();
        JsonResponse::send(201, $devices->createChallenge(
            (int) $user['id'],
            is_string($payload['device_id'] ?? null) ? $payload['device_id'] : ''
        ));
    }

    if ($route === '/api/v1/devices/verify') {
        requireMethod('POST');
        $session = $sessions->currentSession(bearerToken());
        $payload = JsonRequest::body();
        JsonResponse::send(200, $devices->verify(
            (int) $session['user_id'],
            is_string($payload['device_id'] ?? null) ? $payload['device_id'] : '',
            is_string($payload['challenge_id'] ?? null) ? $payload['challenge_id'] : '',
            is_string($payload['signature'] ?? null) ? $payload['signature'] : '',
            (int) $session['session_id']
        ));
    }

    if ($route === '/api/v1/devices/migrate-hardware-id') {
        requireMethod('POST');
        $session = $sessions->requireVerifiedDevice(bearerToken());
        $payload = JsonRequest::body();
        $device = $devices->migrateHardwareId(
            (int) $session['user_id'],
            (int) $session['device_row_id'],
            is_string($payload['device_id'] ?? null) ? $payload['device_id'] : ''
        );
        JsonResponse::send(200, ['device' => $device]);
    }

    if ($route === '/api/v1/activation/redeem') {
        requireMethod('POST');
        $session = $sessions->currentSession(bearerToken());
        $activationRateLimiter->consume(
            (int) $session['user_id'],
            (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
        );
        $payload = JsonRequest::body();
        JsonResponse::send(200, $activation->redeem(
            (int) $session['user_id'],
            is_string($payload['key'] ?? null) ? $payload['key'] : ''
        ));
    }

    if ($route === '/api/v1/games') {
        requireMethod('GET');
        $session = $sessions->requireVerifiedDevice(bearerToken());
        JsonResponse::send(200, [
            'games' => $catalog->listGames(
                (int) $session['user_id'],
                (int) $session['device_row_id']
            ),
        ]);
    }

    if (preg_match('#^/api/v1/games/([a-z0-9]+(?:-[a-z0-9]+)*)/bind-device$#', $route, $matches)) {
        requireMethod('POST');
        $session = $sessions->requireVerifiedDevice(bearerToken());
        JsonResponse::send(200, $catalog->bindGame(
            (int) $session['user_id'],
            (int) $session['device_row_id'],
            $matches[1]
        ));
    }

    if ($route === '/api/v1/subscriptions') {
        requireMethod('GET');
        $session = $sessions->currentSession(bearerToken());
        JsonResponse::send(200, [
            'subscriptions' => $subscriptions->listForUser(
                (int) $session['user_id'],
                $session['device_row_id'] === null ? null : (int) $session['device_row_id']
            ),
        ]);
    }

    if ($route === '/api/v1/modules/ticket') {
        requireMethod('POST');
        $session = $sessions->requireVerifiedDevice(bearerToken());
        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $moduleRateLimiter->consume(
            (int) $session['user_id'],
            (int) $session['device_row_id'],
            'ticket',
            $ipAddress
        );
        $payload = JsonRequest::body();
        JsonResponse::send(201, $moduleTickets->issue(
            (int) $session['user_id'],
            (int) $session['device_row_id'],
            is_string($payload['game'] ?? null) ? $payload['game'] : '',
            $ipAddress
        ));
    }

    if ($route === '/api/v1/modules/download') {
        requireMethod('GET');
        $session = $sessions->requireVerifiedDevice(bearerToken());
        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $moduleRateLimiter->consume(
            (int) $session['user_id'],
            (int) $session['device_row_id'],
            'download',
            $ipAddress
        );
        $rawTicket = trim((string) ($_SERVER['HTTP_X_MODULE_TICKET'] ?? ''));
        $storage = new ModuleStorage(__DIR__ . '/../storage/modules', (int) $config['max_module_size_bytes']);
        $packageBuilder = new ModulePackageBuilder(
            (string) $config['module_signing_private_key_path'],
            (string) $config['module_signing_key_id'],
            (int) $config['max_module_size_bytes']
        );
        $download = new ModuleDownloadService($moduleTickets, $storage, $packageBuilder);
        $result = $download->download(
            (int) $session['user_id'],
            (int) $session['device_row_id'],
            $rawTicket,
            $ipAddress
        );
        BinaryResponse::modulePackage($result->package, Base64Url::encode($result->sessionKey));
    }

    if (preg_match('#^/api/v1/devices/([a-fA-F0-9]{32})$#', $route, $matches)) {
        requireMethod('DELETE');
        $user = $sessions->currentUser(bearerToken());
        $devices->revoke((int) $user['id'], $matches[1]);
        JsonResponse::send(204);
    }

    throw new ApiException('not_found', 404, 'Endpoint not found.');
} catch (ApiException $exception) {
    JsonResponse::error($exception);
} catch (Throwable $exception) {
    $reference=logUnexpectedFailure($exception,$route);
    JsonResponse::send(500, [
        'error' => 'server_error',
        'message' => 'An internal server error occurred. Reference: '.$reference,
    ]);
}
