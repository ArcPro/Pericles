<?php

declare(strict_types=1);

namespace Pericles\Http;

final class JsonRequest
{
    public static function body(int $maximumBytes = 8192): array
    {
        $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($contentType !== 'application/json') {
            throw new ApiException('validation_error', 400, 'Content-Type must be application/json.');
        }

        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > $maximumBytes) {
            throw new ApiException('validation_error', 400, 'Request body is too large.');
        }

        $raw = file_get_contents('php://input', false, null, 0, $maximumBytes + 1);
        if ($raw === false || strlen($raw) > $maximumBytes) {
            throw new ApiException('validation_error', 400, 'Invalid request body.');
        }

        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException('validation_error', 400, 'Invalid JSON payload.');
        }

        if (!is_array($payload)) {
            throw new ApiException('validation_error', 400, 'JSON payload must be an object.');
        }

        return $payload;
    }
}
