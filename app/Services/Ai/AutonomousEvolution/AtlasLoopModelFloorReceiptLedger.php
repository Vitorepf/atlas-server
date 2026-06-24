<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * MODEL-FLOOR RECEIPT LEDGER — the audit substrate that PROVES the §0 floor invariants held across each
 * cross-model triangulation event. Per delivery it records, append-only, which invariants held: the master
 * switch state, the FrozenJudge bundle hash, the AntiFarmFloor verdict, the CrossModelTriangulator verdict,
 * the ProviderRollbackPolicy decision, and the HardCase trigger (if any).
 *
 * Tamper-evident: each line carries prev_sha256 + its own sha256 over the canonical JSON payload, forming a
 * hash CHAIN — a single mutated byte breaks verification at the exact line. Append-only by construction: there
 * is NO mutation API, and append() refuses to extend a ledger whose on-disk tail has diverged from the
 * recorded head (an external overwrite/truncate). Provider-free — pure I/O + hashing.
 */
final class AtlasLoopModelFloorReceiptLedger
{
    public const SCHEMA = 'atlas.loop.model_floor_receipt.v1';

    /** The receipt payload fields, in canonical order. null is encoded as JSON null — NEVER omitted. */
    private const RECEIPT_FIELDS = [
        'master_enabled',
        'frozen_bundle_hash',
        'anti_farm_verdict',
        'triangulator_verdict',
        'rollback_decision',
        'hard_case_trigger',
        'timestamp_utc',
    ];

    private readonly string $ledgerPath;

    private readonly string $headPath;

    public function __construct(?string $ledgerPath = null)
    {
        $this->ledgerPath = $ledgerPath ?? storage_path('atlas/loop/floor-receipts/floor-receipts.jsonl');
        $this->headPath = $this->ledgerPath.'.head';
    }

    /**
     * Append ONE floor receipt, chained to the prior line. Throws if the on-disk ledger has been
     * tampered/truncated (append-only integrity).
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>  the stored line (receipt fields + prev_sha256 + sha256)
     */
    public function append(array $payload): array
    {
        $this->assertIntact();

        $lines = $this->readLines();
        $prev = $lines === [] ? '' : (string) ($lines[count($lines) - 1]['sha256'] ?? '');

        $core = [];
        foreach (self::RECEIPT_FIELDS as $field) {
            $core[$field] = array_key_exists($field, $payload) ? $payload[$field] : null;
        }
        if ($core['timestamp_utc'] === null) {
            $core['timestamp_utc'] = Carbon::now('UTC')->toIso8601String();
        }
        $core['schema'] = self::SCHEMA;

        $own = $this->hashLine($prev, $core);
        $line = $core + ['prev_sha256' => $prev, 'sha256' => $own];

        $dir = dirname($this->ledgerPath);
        if (! is_dir($dir) && ! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
            throw new RuntimeException("floor-receipt ledger dir not writable: {$dir}");
        }
        file_put_contents($this->ledgerPath, json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
        file_put_contents($this->headPath, json_encode(['count' => count($lines) + 1, 'head_sha256' => $own], JSON_UNESCAPED_SLASHES), LOCK_EX);

        return $line;
    }

    /**
     * Verify the full hash chain. Returns 'ok', or 'broken_at_line_N' (1-indexed) at the first divergence.
     */
    public function verifyChain(): string
    {
        $prev = '';
        $n = 0;
        foreach ($this->readLines() as $decoded) {
            $n++;
            if (! is_array($decoded)) {
                return "broken_at_line_{$n}";
            }
            $storedPrev = (string) ($decoded['prev_sha256'] ?? '');
            $storedSha = (string) ($decoded['sha256'] ?? '');
            $core = $decoded;
            unset($core['prev_sha256'], $core['sha256']);

            if ($storedPrev !== $prev || $storedSha !== $this->hashLine($prev, $core)) {
                return "broken_at_line_{$n}";
            }
            $prev = $storedSha;
        }

        return 'ok';
    }

    /**
     * The stored receipts in insertion order.
     *
     * @return list<array<string,mixed>>
     */
    public function entries(): array
    {
        return $this->readLines();
    }

    /** Refuse to extend a ledger whose on-disk tail diverges from the recorded head, or whose chain is broken. */
    private function assertIntact(): void
    {
        if (! is_file($this->ledgerPath) || ! is_file($this->headPath)) {
            return; // genesis (or no sidecar yet) — nothing to diverge from
        }
        if ($this->verifyChain() !== 'ok') {
            throw new RuntimeException('append_only_violation: floor-receipt chain is broken');
        }
        $head = json_decode((string) @file_get_contents($this->headPath), true);
        $lines = $this->readLines();
        $actualHead = $lines === [] ? '' : (string) ($lines[count($lines) - 1]['sha256'] ?? '');
        if (! is_array($head) || (int) ($head['count'] ?? -1) !== count($lines) || (string) ($head['head_sha256'] ?? '') !== $actualHead) {
            throw new RuntimeException('append_only_violation: floor-receipt ledger tail diverged from recorded head');
        }
    }

    /**
     * @param  array<string,mixed>  $core
     */
    private function hashLine(string $prev, array $core): string
    {
        ksort($core);

        return hash('sha256', $prev."\n".(string) json_encode($core, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readLines(): array
    {
        if (! is_file($this->ledgerPath)) {
            return [];
        }
        $out = [];
        foreach (preg_split('/\R/', (string) @file_get_contents($this->ledgerPath)) ?: [] as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            $out[] = is_array($decoded) ? $decoded : ['__corrupt__' => $raw];
        }

        return $out;
    }
}
