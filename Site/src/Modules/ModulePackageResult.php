<?php

declare(strict_types=1);

namespace Pericles\Modules;

final class ModulePackageResult
{
    public function __construct(
        public string $package,
        public string $sessionKey,
        public array $manifest
    ) {
    }
}
