<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pericles\Device\Base64Url;

$input = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($input)) {
    fwrite(STDOUT, "INVALID\n");
    exit(2);
}

$data = base64_decode((string) ($input['data'] ?? ''), true);
$signature = Base64Url::decode((string) ($input['signature'] ?? ''));
$publicKey = (string) ($input['public_key'] ?? '');
$valid = is_string($data)
    && is_string($signature)
    && openssl_verify($data, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;

fwrite(STDOUT, $valid ? "VALID\n" : "INVALID\n");
exit($valid ? 0 : 1);
