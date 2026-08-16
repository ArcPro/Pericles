<?php

declare(strict_types=1);

namespace Pericles\Device;

final class Base64Url
{
    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function decode(string $value): ?string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            return null;
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
        return is_string($decoded) ? $decoded : null;
    }
}
