<?php

declare(strict_types=1);

namespace Pericles\Web;

final class AccountPage
{
    public static function login(string $csrfToken, ?string $error = null): never
    {
        self::header('Connexion');
        echo '<main class="card"><h1>PERICLES</h1><p class="muted">Espace membre</p>';
        if ($error !== null) {
            echo '<p class="error">' . self::escape($error) . '</p>';
        }
        echo '<form method="post" action="' . self::escape(self::url('/login')) . '">'
            . '<input type="hidden" name="csrf" value="' . self::escape($csrfToken) . '">'
            . '<label>Email<input type="email" name="email" required autocomplete="username"></label>'
            . '<label>Mot de passe<input type="password" name="password" required autocomplete="current-password"></label>'
            . '<button type="submit">SE CONNECTER</button></form></main>';
        self::footer();
    }

    public static function account(
        string $email,
        array $subscriptions,
        string $csrfToken,
        ?string $message = null,
        ?string $error = null
    ): never {
        self::header('Mon compte');
        echo '<main class="card wide"><div class="top"><div><h1>Mon compte</h1><p class="muted">'
            . self::escape($email) . '</p></div><form method="post" action="' . self::escape(self::url('/logout')) . '">'
            . '<input type="hidden" name="csrf" value="' . self::escape($csrfToken) . '">'
            . '<button class="secondary">Déconnexion</button></form></div>';
        if ($message !== null) {
            echo '<p class="success">' . self::escape($message) . '</p>';
        }
        if ($error !== null) {
            echo '<p class="error">' . self::escape($error) . '</p>';
        }
        echo '<section><h2>Activer une clé</h2><form class="activation" method="post" action="'
            . self::escape(self::url('/account/activate')) . '">'
            . '<input type="hidden" name="csrf" value="' . self::escape($csrfToken) . '">'
            . '<input name="key" placeholder="PERI-XXXX-XXXX-XXXX" required autocomplete="off">'
            . '<button type="submit">ACTIVER</button></form></section>';
        echo '<section><h2>Mes abonnements</h2>';
        if ($subscriptions === []) {
            echo '<p class="muted">Aucun abonnement actif ou passé.</p>';
        }
        foreach ($subscriptions as $subscription) {
            $product = $subscription['product'];
            $expiry = $subscription['expires_at'] === null
                ? 'À vie'
                : (new \DateTimeImmutable((string) $subscription['expires_at']))->format('d/m/Y');
            $binding = [
                'unbound' => 'Non encore lié à un appareil',
                'current_device' => 'Appareil actuel',
                'other_device' => 'Autre appareil',
            ][$subscription['device_binding']['state']] ?? 'Inconnu';
            echo '<article><div><strong>' . self::escape((string) $product['name']) . '</strong>'
                . '<span>' . self::escape(ucfirst((string) $subscription['status'])) . '</span></div>'
                . '<p>Expiration : ' . self::escape($expiry) . '<br>Appareil : ' . self::escape($binding) . '</p></article>';
        }
        echo '</section></main>';
        self::footer();
    }

    private static function header(string $title): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::escape($title) . ' · Pericles</title><style>'
            . ':root{color-scheme:dark;font-family:Inter,Segoe UI,sans-serif;background:#080814;color:#f5f3ff}'
            . 'body{min-height:100vh;margin:0;display:grid;place-items:center;background:radial-gradient(circle at 20% 0,#241047,#080814 48%)}'
            . '.card{width:min(360px,calc(100% - 40px));padding:32px;border:1px solid #3b2a63;border-radius:16px;background:#0e0d1dcc;box-shadow:0 24px 80px #0008}.wide{width:min(680px,calc(100% - 40px))}'
            . 'h1{margin:0;color:#fff}h2{font-size:17px;margin:28px 0 12px}.muted{color:#9b96ad}.top,.top form,.activation{display:flex;gap:12px;align-items:center}.top{justify-content:space-between}'
            . 'label{display:block;color:#bdb7cf;font-size:13px;margin-top:18px}input{box-sizing:border-box;width:100%;margin-top:7px;padding:12px;border:1px solid #3c3454;border-radius:8px;background:#121020;color:#fff;outline:none}input:focus{border-color:#8b5cf6}'
            . 'button{border:0;border-radius:8px;padding:12px 18px;margin-top:20px;background:linear-gradient(135deg,#9b5cff,#6525dc);color:#fff;font-weight:700;cursor:pointer}.secondary{margin:0;background:#201a31}.activation input{margin:0}.activation button{margin:0}'
            . '.error,.success{padding:10px 12px;border-radius:8px}.error{background:#491d2a;color:#ffbdc9}.success{background:#153d31;color:#8ff0c6}'
            . 'article{padding:14px 16px;margin:10px 0;border:1px solid #2d2840;border-radius:10px;background:#131121}article div{display:flex;justify-content:space-between}article span,article p{color:#aaa4ba;font-size:13px}article p{margin:8px 0 0;line-height:1.6}'
            . '</style></head><body>';
    }

    private static function footer(): never
    {
        echo '</body></html>';
        exit;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function url(string $path): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/.');
        return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
    }
}
