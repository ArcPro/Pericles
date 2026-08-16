<?php

declare(strict_types=1);

namespace Pericles\Http;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public string $errorCode;

    public int $statusCode;

    public function __construct(
        string $errorCode,
        int $statusCode,
        string $message
    ) {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
    }
}
