<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Pericles\Auth\AuthService;
use Pericles\Auth\SessionService;
use Pericles\Activation\ActivationRateLimiter;
use Pericles\Activation\ActivationService;
use Pericles\Catalog\CatalogService;
use Pericles\Database;
use Pericles\Device\DeviceService;
use Pericles\Http\ApiException;
use Pericles\Http\BinaryResponse;
use Pericles\Http\JsonRequest;
use Pericles\Http\JsonResponse;
use Pericles\Security\LoginRateLimiter;
use Pericles\Subscriptions\SubscriptionService;
use Pericles\Web\AccountPage;
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
        throw new ApiException('invalid_csrf', 400, 'La session a expiré. Rechargez la page.');
    }
}

function webPath(string $path): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/.');
    return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
}

$config = require __DIR__ . '/../config/app.php';
$route = currentRoute();

try {
    if ($route === '/') {
        JsonResponse::send(200, [
            'service' => 'pericles-auth-api',
            'status' => 'ok',
            'version' => '0.1.0',
        ]);
    }

    if (($config['app_env'] ?? 'production') === 'production' && !requestIsHttps()) {
        throw new ApiException('https_required', 426, 'HTTPS is required.');
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

    if (in_array($route, ['/login', '/account', '/account/activate', '/logout'], true)) {
        startWebSession(requestIsHttps());
        $csrf = (string) $_SESSION['csrf'];

        if ($route === '/login') {
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
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['email'] = (string) $user['email'];
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                header('Location: ' . webPath('/account'));
                exit;
            } catch (ApiException $exception) {
                if (isset($email, $ip) && $exception->errorCode === 'invalid_credentials') {
                    $rateLimiter->recordFailure($ip, $email);
                }
                AccountPage::login($csrf, 'Connexion impossible. Vérifiez vos identifiants.');
            }
        }

        if (!isset($_SESSION['user_id'])) {
            header('Location: ' . webPath('/login'));
            exit;
        }
        $webUserId = (int) $_SESSION['user_id'];
        $webEmail = (string) ($_SESSION['email'] ?? '');

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
                AccountPage::account(
                    $webEmail,
                    $subscriptions->listForUser($webUserId, null),
                    $csrf,
                    'Clé activée pour ' . (string) $result['product']['name'] . '.'
                );
            } catch (ApiException $exception) {
                AccountPage::account(
                    $webEmail,
                    $subscriptions->listForUser($webUserId, null),
                    $csrf,
                    null,
                    $exception->getMessage()
                );
            }
        }

        requireMethod('GET');
        AccountPage::account($webEmail, $subscriptions->listForUser($webUserId, null), $csrf);
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
    error_log('Pericles API server error: ' . $exception::class);
    JsonResponse::send(500, [
        'error' => 'server_error',
        'message' => 'An internal server error occurred.',
    ]);
}
