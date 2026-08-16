<?php

declare(strict_types=1);

namespace Pericles\Activation;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class ActivationKeyGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private PDO $database)
    {
    }

    public function generate(string $productSlug, string $planSlug, int $count, bool $transferable = false): array
    {
        if ($count < 1 || $count > 1000) {
            throw new RuntimeException('The key count must be between 1 and 1000.');
        }
        $plan = $this->findPlan($productSlug, $planSlug);
        $insert = $this->database->prepare(
            'INSERT INTO activation_keys '
            . '(key_hash, key_hint, plan_id, status, created_at, transferable, max_transfers, transfer_count) '
            . 'VALUES (:key_hash, :key_hint, :plan_id, :status, :created_at, :transferable, :max_transfers, 0)'
        );
        $keys = [];
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        while (count($keys) < $count) {
            $plain = $this->createPlainKey();
            try {
                $insert->execute([
                    ':key_hash' => hash('sha256', $plain),
                    ':key_hint' => '...' . substr($plain, -4),
                    ':plan_id' => (int) $plan['id'],
                    ':status' => 'unused',
                    ':created_at' => $now,
                    ':transferable' => $transferable ? 1 : 0,
                    ':max_transfers' => $transferable ? 1 : 0,
                ]);
                $keys[] = $plain;
            } catch (\PDOException $exception) {
                if (!in_array((string) $exception->getCode(), ['19', '23000'], true)) {
                    throw $exception;
                }
            }
        }
        return $keys;
    }

    private function findPlan(string $productSlug, string $planSlug): array
    {
        $statement = $this->database->prepare(
            'SELECT p.id FROM plans p INNER JOIN products pr ON pr.id = p.product_id '
            . 'WHERE pr.slug = :product_slug AND p.slug = :plan_slug AND pr.is_active = 1 AND p.is_active = 1 '
            . 'LIMIT 1'
        );
        $statement->execute([':product_slug' => $productSlug, ':plan_slug' => $planSlug]);
        $plan = $statement->fetch();
        if (!is_array($plan)) {
            throw new RuntimeException('Active product/plan not found.');
        }
        return $plan;
    }

    private function createPlainKey(): string
    {
        $bytes = random_bytes(28);
        $characters = '';
        foreach (str_split($bytes) as $byte) {
            $characters .= self::ALPHABET[ord($byte) & 31];
        }
        return 'PERI-' . implode('-', str_split($characters, 4));
    }
}
