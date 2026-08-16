<?php

declare(strict_types=1);

namespace Pericles\Modules;

use Pericles\Http\ApiException;
use RuntimeException;

final class ModuleStorage
{
    private string $root;

    public function __construct(string $root, private int $maximumBytes)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->maximumBytes = max(1, $maximumBytes);
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create private module storage.');
        }
        $resolvedRoot = realpath($this->root);
        $publicRoot = realpath(__DIR__ . '/../../public');
        if ($resolvedRoot === false || ($publicRoot !== false && $this->isWithin($resolvedRoot, $publicRoot))) {
            throw new RuntimeException('Module storage must be outside the public web root.');
        }
    }

    public function publish(string $gameSlug, string $version, string $sourceFile): array
    {
        $this->validateSegment($gameSlug, 'game slug');
        $this->validateVersion($version);
        $source = realpath($sourceFile);
        if ($source === false || !is_file($source) || !is_readable($source)) {
            throw new RuntimeException('Module source file is not readable.');
        }
        $publicRoot = realpath(__DIR__ . '/../../public');
        if ($publicRoot !== false && $this->isWithin($source, $publicRoot)) {
            throw new RuntimeException('Refusing to publish a module source from the public web root.');
        }
        $size = filesize($source);
        if ($size === false || $size < 1 || $size > $this->maximumBytes) {
            throw new RuntimeException('Module source size is outside the configured limit.');
        }

        $relativePath = $gameSlug . '/' . $version . '/Module.dll';
        $destination = $this->root . '/' . $relativePath;
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the module version directory.');
        }
        if (is_file($destination)) {
            throw new RuntimeException('This module version already exists in private storage.');
        }

        $temporary = $destination . '.tmp-' . bin2hex(random_bytes(8));
        $input = fopen($source, 'rb');
        $output = fopen($temporary, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new RuntimeException('Unable to open module files for publication.');
        }
        $hash = hash_init('sha256');
        $written = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read module source.');
                }
                if ($chunk === '') continue;
                $written += strlen($chunk);
                if ($written > $this->maximumBytes || fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Unable to write module to private storage.');
                }
                hash_update($hash, $chunk);
            }
            fflush($output);
        } catch (\Throwable $exception) {
            fclose($input);
            fclose($output);
            @unlink($temporary);
            throw $exception;
        }
        fclose($input);
        fclose($output);
        if (!rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to finalize module publication.');
        }
        @chmod($destination, 0600);

        return [
            'source_path' => $relativePath,
            'sha256' => hash_final($hash),
            'file_size' => $written,
        ];
    }

    public function read(string $relativePath, string $expectedHash, int $expectedSize): string
    {
        if ($expectedSize < 1 || $expectedSize > $this->maximumBytes) {
            throw new ApiException('module_unavailable', 503, 'The module is unavailable.');
        }
        $path = $this->resolve($relativePath);
        $actualSize = filesize($path);
        if ($actualSize === false || $actualSize !== $expectedSize) {
            throw new ApiException('module_unavailable', 503, 'The module is unavailable.');
        }
        $payload = file_get_contents($path);
        if ($payload === false || strlen($payload) !== $expectedSize
            || !hash_equals(strtolower($expectedHash), hash('sha256', $payload))) {
            throw new ApiException('module_unavailable', 503, 'The module is unavailable.');
        }
        return $payload;
    }

    private function resolve(string $relativePath): string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_contains($relativePath, '..')) {
            throw new ApiException('module_unavailable', 503, 'The module is unavailable.');
        }
        $candidate = realpath($this->root . '/' . ltrim(str_replace('\\', '/', $relativePath), '/'));
        $root = realpath($this->root);
        if ($candidate === false || $root === false || !is_file($candidate) || !$this->isWithin($candidate, $root)) {
            throw new ApiException('module_unavailable', 503, 'The module is unavailable.');
        }
        return $candidate;
    }

    private function isWithin(string $path, string $directory): bool
    {
        $path = str_replace('\\', '/', $path);
        $directory = rtrim(str_replace('\\', '/', $directory), '/') . '/';
        return str_starts_with(strtolower($path), strtolower($directory));
    }

    private function validateSegment(string $value, string $label): void
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
            throw new RuntimeException('Invalid ' . $label . '.');
        }
    }

    private function validateVersion(string $version): void
    {
        if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z.+-]{0,63}$/', $version)) {
            throw new RuntimeException('Invalid module version.');
        }
    }
}
