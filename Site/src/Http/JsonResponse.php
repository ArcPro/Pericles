<?php

declare(strict_types=1);

namespace Pericles\Http;

final class JsonResponse
{
    public static function send(int $statusCode, ?array $payload = null): void
    {
        http_response_code($statusCode);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        if ($statusCode === 204 || $payload === null) {
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(ApiException $exception): void
    {
        self::send($exception->statusCode, [
            'error' => $exception->errorCode,
            'message' => $exception->getMessage(),
        ]);
    }
}
