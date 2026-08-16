<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Pericles\Modules\ModulePackageBuilder;

$builder = new ModulePackageBuilder(
    __DIR__ . '/Fixtures/module-signing-test-private.pem',
    'pericles-modules-development-test',
    1024 * 1024
);
$payload = file_get_contents(__DIR__ . '/Fixtures/TestModule.bin');
if (!is_string($payload)) {
    fwrite(STDERR, "Unable to read the module fixture.\n");
    exit(1);
}

$gameSlug = isset($argv[1]) && preg_match('/^[a-z0-9-]{1,64}$/', $argv[1]) ? $argv[1] : 'deadlock';
$result = $builder->build([
    'game_slug' => $gameSlug,
    'module_slug' => $gameSlug . '-main',
    'module_version' => '1.0.0',
], $payload);

echo json_encode([
    'package' => base64_encode($result->package),
    'session_key' => base64_encode($result->sessionKey),
    'payload' => base64_encode($payload),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
