<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Introspection;


use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\Carbon;
use RuntimeException;

/**
 * Append-only ledger of every introspection snapshot taken by the Scanner / DepGraph / Coverage
 * reporters. Receipts are persisted at:
 *   {root}/<yyyy>/<mm>/<sha256-of-payload>.json
 *
 * Each receipt records:
 *   {taken_at_utc, reporter, atlas_git_sha, payload_sha256, payload_summary}
 *
 * INVARIANTS:
 *   - NEVER mutates an existing receipt (Evidence Ledger discipline). An attempt to overwrite an
 *     existing payload-keyed file throws RuntimeException with code APPEND_ONLY_VIOLATION.
 *   - Identical payloads ⇒ identical sha256 ⇒ idempotent record() (second call is a no-op,
 *     returning the existing receipt).
 *   - Canonical JSON encoding (recursive ksort) gives byte-stable hashes.
 */
final class AtlasLoopSelfIntrospectionReceiptLedger
{
    use KsortsArraysByReference;

    public const APPEND_ONLY_VIOLATION = 'APPEND_ONLY_VIOLATION';

    private static ?string $rootOverride = null;

    public static function setRootForTesting(?string $root): void
    {
        self::$rootOverride = $root;
    }

    /**
     * Record an introspection snapshot. Idempotent on payload_sha256.
     *
     * @param  array<string,mixed>  $payload          the structural facts being recorded
     * @param  array<string,mixed>  $payloadSummary   small human-friendly summary persisted alongside
     * @return array<string,mixed>
     */
    public function record(string $reporter, array $payload, array $payloadSummary, string $atlasGitSha): array
    {
        $sha = $this->payloadSha($payload);
        $path = $this->pathFor($sha);

        if (is_file($path)) {
            $existing = $this->readReceipt($path);
            $existingSha = (string) ($existing['payload_sha256'] ?? '');
            if ($existingSha !== '' && $existingSha !== $sha) {
                throw new RuntimeException(self::APPEND_ONLY_VIOLATION.': existing receipt at '.$path.' has mismatched payload_sha256');
            }

            return $existing;
        }

        $dir = \dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException('introspection_ledger_mkdir_failed:'.$dir);
        }

        $receipt = [
            'taken_at_utc' => $this->nowUtc(),
            'reporter' => $reporter,
            'atlas_git_sha' => $atlasGitSha,
            'payload_sha256' => $sha,
            'payload_summary' => $payloadSummary,
        ];

        // Append-only write: use 'xb' so an existing file causes an explicit failure.
        $fh = @fopen($path, 'xb');
        if ($fh === false) {
            // Path now exists despite our earlier check → concurrent writer. Treat as APPEND_ONLY_VIOLATION
            // only when the existing payload differs; otherwise (race on identical payload) honour
            // idempotency.
            if (is_file($path)) {
                $existing = $this->readReceipt($path);
                if ((string) ($existing['payload_sha256'] ?? '') === $sha) {
                    return $existing;
                }
            }
            throw new RuntimeException(self::APPEND_ONLY_VIOLATION.': cannot overwrite '.$path);
        }
        try {
            fwrite($fh, $this->canonicalJson($receipt));
        } finally {
            fclose($fh);
        }

        return $receipt;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function list(?Carbon $since = null): array
    {
        $rows = [];
        foreach ($this->iterateAllReceiptPaths() as $path) {
            $receipt = $this->readReceipt($path);
            if ($since !== null) {
                $takenAt = Carbon::parse((string) ($receipt['taken_at_utc'] ?? '0000-01-01T00:00:00Z'));
                if ($takenAt->lt($since)) {
                    continue;
                }
            }
            $rows[] = $receipt;
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['taken_at_utc'], (string) $b['taken_at_utc']));

        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $payloadSha256): ?array
    {
        $path = $this->pathFor($payloadSha256);

        return is_file($path) ? $this->readReceipt($path) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function latest(string $reporter): ?array
    {
        $rows = array_values(array_filter($this->list(), static fn (array $r): bool => (string) ($r['reporter'] ?? '') === $reporter));
        if ($rows === []) {
            return null;
        }

        return $rows[count($rows) - 1];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function payloadSha(array $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    private function root(): string
    {
        return self::$rootOverride ?? storage_path('atlas/loop/introspection');
    }

    private function pathFor(string $sha256): string
    {
        $now = Carbon::now('UTC');

        return $this->root().'/'.$now->format('Y').'/'.$now->format('m').'/'.$sha256.'.json';
    }

    /**
     * @return iterable<string>
     */
    private function iterateAllReceiptPaths(): iterable
    {
        $root = $this->root();
        if (! is_dir($root)) {
            return;
        }
        foreach ((array) glob($root.'/*/*/*.json') as $path) {
            if (is_string($path) && is_file($path)) {
                yield $path;
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function readReceipt(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function canonicalJson(array $data): string
    {
        $copy = $data;
        $this->ksortRecursiveByReference($copy);

        return (string) json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string,mixed>  $arr
     */

    private function nowUtc(): string
    {
        return Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z');
    }
}
