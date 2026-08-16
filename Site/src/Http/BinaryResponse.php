<?php

declare(strict_types=1);

namespace Pericles\Http;

final class BinaryResponse
{
    public static function modulePackage(string $package, string $encodedSessionKey): never
    {
        http_response_code(200);
        header('Content-Type: application/vnd.pericles.module-package');
        header('Content-Disposition: attachment; filename="module.pkg"');
        header('Content-Length: ' . strlen($package));
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        header('X-Module-Session-Key: ' . $encodedSessionKey);
        header('X-Module-Package-Version: 1');
        echo $package;
        exit;
    }
}
