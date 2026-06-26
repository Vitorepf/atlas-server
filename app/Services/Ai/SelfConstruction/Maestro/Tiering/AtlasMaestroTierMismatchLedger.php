<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Tiering;


use App\Services\Ai\SelfConstruction\Support\UsesUtcClock;
use RuntimeException;

/**
 * Append-only audit ledger that records EVERY tier-mismatch refusal surfaced by the routing policy. Allow
 * verdicts (allow / allow_unknown_worker) are deliberately NOT recorded — signal-to-noise.
 *
 * PÉTREO: the class exposes record() + history() and nothing else — no update, no delete, no truncate
 * code path exists. Persistence uses fopen('a') + flock(LOCK_EX) so existing bytes are never overwritten.
 */
final class AtlasMaestroTierMismatchLedger
{
    use UsesUtcClock;

    public const SCHEMA = 'atlas.maestro.tier_mismatch.v1';

    /** @var null|callable():string */
    private $clock;

    public function __construct(private readonly string $ledgerPath, ?callable $clock = null)
    {
        $this->clock = $clock;
    }

    /**
     * @param  array{verdict?:string, packet_tier?:string, packet_fact_basis?:list<string>, worker_declared_max_tier?:?string, client_id?:string, reason?:string}  $verdict
     * @param  array<string,mixed>  $context
     */
    public function record(array $verdict, array $context = []): void
    {
        if (($verdict['verdict'] ?? '') !== AtlasMaestroTieredRoutingPolicy::VERDICT_REFUSE) {
            return; // allow verdicts are intentionally NOT recorded
        }

        $row = [
            'schema' => self::SCHEMA,
            'recorded_at' => $this->now(),
            'packet_id' => (string) ($context['packet_id'] ?? ''),
            'client_id' => (string) ($verdict['client_id'] ?? ''),
            'inferred_tier' => (string) ($verdict['packet_tier'] ?? ''),
            'fact_basis' => array_values((array) ($verdict['packet_fact_basis'] ?? [])),
            'worker_declared_tier' => $verdict['worker_declared_max_tier'] ?? null,
            'reason' => (string) ($verdict['reason'] ?? ''),
        ];
        $canonical = $row;
        ksort($canonical);
        $row['content_hash'] = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->appendOnly($row);
    }

    /**
     * @return list<array<string,mixed>>  newest-first
     */
    public function history(int $limit, ?string $clientId = null): array
    {
        if (! is_file($this->ledgerPath)) {
            return [];
        }
        $out = [];
        foreach (file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode((string) $line, true);
            if (! is_array($decoded)) {
                continue;
            }
            if ($clientId !== null && (string) ($decoded['client_id'] ?? '') !== $clientId) {
                continue;
            }
            $out[] = $decoded;
        }
        $out = array_reverse($out); // newest-first

        return array_slice($out, 0, max(0, $limit));
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function appendOnly(array $row): void
    {
        $dir = dirname($this->ledgerPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = @fopen($this->ledgerPath, 'a');
        if ($fh === false) {
            throw new RuntimeException('Cannot open Maestro tier-mismatch ledger for append');
        }
        try {
            if (! flock($fh, LOCK_EX)) {
                throw new RuntimeException('Cannot acquire LOCK_EX on Maestro tier-mismatch ledger');
            }
            fwrite($fh, (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            fflush($fh);
            @\fsync($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

}
