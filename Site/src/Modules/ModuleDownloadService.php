<?php

declare(strict_types=1);

namespace Pericles\Modules;

use Throwable;

final class ModuleDownloadService
{
    public function __construct(
        private ModuleTicketService $tickets,
        private ModuleStorage $storage,
        private ModulePackageBuilder $packageBuilder
    ) {
    }

    public function download(
        int $userId,
        int $deviceId,
        string $rawTicket,
        ?string $requestIp
    ): ModulePackageResult {
        $module = $this->tickets->consumeForDownload($userId, $deviceId, $rawTicket, $requestIp);
        try {
            $payload = $this->storage->read(
                (string) $module['source_path'],
                (string) $module['sha256'],
                (int) $module['file_size']
            );
            $result = $this->packageBuilder->build($module, $payload);
            $this->tickets->completeDownload((int) $module['download_id'], true);
            return $result;
        } catch (Throwable $exception) {
            $this->tickets->completeDownload((int) $module['download_id'], false);
            throw $exception;
        }
    }
}
