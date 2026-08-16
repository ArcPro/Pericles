<?php

declare(strict_types=1);

namespace Pericles\Modules;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Pericles\Device\Base64Url;
use Pericles\Http\ApiException;
use Throwable;

final class ModuleTicketService
{
    private string $driver;
    private bool $transactionActive = false;

    public function __construct(
        private PDO $database,
        private ModuleAuthorizationService $authorization,
        private int $ticketTtl
    ) {
        $this->driver = (string) $database->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->ticketTtl = max(10, min(120, $ticketTtl));
    }

    public function issue(int $userId, int $deviceId, string $gameSlug, ?string $requestIp): array
    {
        $module = $this->authorization->authorize($userId, $deviceId, $gameSlug);
        $rawTicket = Base64Url::encode(random_bytes(32));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('+' . $this->ticketTtl . ' seconds');
        $statement = $this->database->prepare(
            'INSERT INTO module_tickets '
            . '(ticket_hash, user_id, device_id, game_id, module_version_id, created_at, expires_at, request_ip) '
            . 'VALUES (:ticket_hash, :user_id, :device_id, :game_id, :version_id, :created_at, :expires_at, :request_ip)'
        );
        $statement->execute([
            ':ticket_hash' => hash('sha256', $rawTicket),
            ':user_id' => $userId,
            ':device_id' => $deviceId,
            ':game_id' => $module['game_id'],
            ':version_id' => $module['module_version_id'],
            ':created_at' => $now->format('Y-m-d H:i:s'),
            ':expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            ':request_ip' => $requestIp === null ? null : substr($requestIp, 0, 45),
        ]);

        return [
            'ticket' => $rawTicket,
            'expires_in' => $this->ticketTtl,
            'game' => $module['game_slug'],
            'module' => ['version' => $module['module_version']],
        ];
    }

    public function consumeForDownload(
        int $userId,
        int $deviceId,
        string $rawTicket,
        ?string $requestIp
    ): array {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $rawTicket)) {
            throw new ApiException('ticket_invalid', 401, 'Invalid module ticket.');
        }
        $this->beginWriteTransaction();
        try {
            $suffix = $this->driver === 'mysql' ? ' FOR UPDATE' : '';
            $query = $this->database->prepare(
                'SELECT id, user_id, device_id, game_id, module_version_id, expires_at, used_at, revoked_at '
                . 'FROM module_tickets WHERE ticket_hash = :ticket_hash LIMIT 1' . $suffix
            );
            $query->execute([':ticket_hash' => hash('sha256', $rawTicket)]);
            $ticket = $query->fetch();
            if (!is_array($ticket) || (int) $ticket['user_id'] !== $userId || (int) $ticket['device_id'] !== $deviceId) {
                throw new ApiException('ticket_invalid', 401, 'Invalid module ticket.');
            }
            if ($ticket['revoked_at'] !== null) {
                throw new ApiException('ticket_invalid', 401, 'Invalid module ticket.');
            }
            if ($ticket['used_at'] !== null) {
                throw new ApiException('ticket_used', 409, 'This module ticket has already been used.');
            }
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if (new DateTimeImmutable((string) $ticket['expires_at'], new DateTimeZone('UTC')) <= $now) {
                throw new ApiException('ticket_expired', 410, 'This module ticket has expired.');
            }
            $gameQuery = $this->database->prepare('SELECT slug FROM games WHERE id = :id LIMIT 1');
            $gameQuery->execute([':id' => (int) $ticket['game_id']]);
            $gameSlug = $gameQuery->fetchColumn();
            if (!is_string($gameSlug)) {
                throw new ApiException('module_unavailable', 503, 'The module is unavailable.');
            }
            $module = $this->authorization->authorize(
                $userId,
                $deviceId,
                $gameSlug,
                (int) $ticket['module_version_id']
            );
            $nowSql = $now->format('Y-m-d H:i:s');
            $consume = $this->database->prepare(
                'UPDATE module_tickets SET used_at = :used_at '
                . 'WHERE id = :id AND used_at IS NULL AND revoked_at IS NULL AND expires_at > :now'
            );
            $consume->execute([':used_at' => $nowSql, ':id' => (int) $ticket['id'], ':now' => $nowSql]);
            if ($consume->rowCount() !== 1) {
                throw new ApiException('ticket_used', 409, 'This module ticket is no longer usable.');
            }
            $download = $this->database->prepare(
                'INSERT INTO module_downloads '
                . '(user_id, device_id, game_id, module_version_id, module_ticket_id, status, requested_at, ip_address) '
                . 'VALUES (:user_id, :device_id, :game_id, :version_id, :ticket_id, :status, :requested_at, :ip_address)'
            );
            $download->execute([
                ':user_id' => $userId,
                ':device_id' => $deviceId,
                ':game_id' => $module['game_id'],
                ':version_id' => $module['module_version_id'],
                ':ticket_id' => (int) $ticket['id'],
                ':status' => 'started',
                ':requested_at' => $nowSql,
                ':ip_address' => $requestIp === null ? null : substr($requestIp, 0, 45),
            ]);
            $module['download_id'] = (int) $this->database->lastInsertId();
            $module['ticket_id'] = (int) $ticket['id'];
            $this->commit();
            return $module;
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    public function completeDownload(int $downloadId, bool $succeeded): void
    {
        $statement = $this->database->prepare(
            'UPDATE module_downloads SET status = :status, completed_at = :completed_at '
            . 'WHERE id = :id AND status = :started'
        );
        $statement->execute([
            ':status' => $succeeded ? 'completed' : 'failed',
            ':completed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ':id' => $downloadId,
            ':started' => 'started',
        ]);
    }

    private function beginWriteTransaction(): void
    {
        if ($this->driver === 'sqlite') {
            $this->database->exec('BEGIN IMMEDIATE TRANSACTION');
        } else {
            $this->database->beginTransaction();
        }
        $this->transactionActive = true;
    }

    private function commit(): void
    {
        if ($this->driver === 'sqlite') $this->database->exec('COMMIT');
        else $this->database->commit();
        $this->transactionActive = false;
    }

    private function rollBack(): void
    {
        if (!$this->transactionActive) return;
        if ($this->driver === 'sqlite') $this->database->exec('ROLLBACK');
        elseif ($this->database->inTransaction()) $this->database->rollBack();
        $this->transactionActive = false;
    }
}
