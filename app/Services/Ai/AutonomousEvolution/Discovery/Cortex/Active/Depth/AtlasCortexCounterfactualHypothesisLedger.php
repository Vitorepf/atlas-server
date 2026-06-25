<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\Depth;

use RuntimeException;

/**
 * Append-only, content-addressed ledger of every counterfactual hypothesis explored. Rows are a
 * single canonical JSON snapshot (NOT a wide read-model) — the part2 B1 pattern. NEVER stores a
 * score/grade/quality/rating.
 *
 * INVARIANTS:
 *   - hypothesis_id = sha256 of canonical(snapshot_sha + walk_id + steps_json) — same content ⇒
 *     same id ⇒ idempotent record() returns the existing row, NOT a duplicate.
 *   - Attempting to MUTATE an existing row throws.
 *   - Flag `atlas.loop.cortex.active.depth.hypothesis_ledger_enabled` default FALSE makes record()
 *     a byte-identical no-op (returns null).
 */
final class AtlasCortexCounterfactualHypothesisLedger
{
    public const FORBIDDEN_KEYS = ['score', 'grade', 'rating', 'quality'];

    private static ?string $rootOverride = null;

    /** Test seam: override the in-process flag without touching config. */
    public static ?bool $flagOverride = null;

    public static function setRootForTesting(?string $root): void
    {
        self::$rootOverride = $root;
    }

    /**
     * @param  array<string,mixed>  $payload  {walk_id, snapshot_sha, steps_json, terminated_reason, observed_at_utc, [site_id?]}
     * @return array<string,mixed>|null  null when the feature flag is off
     */
    public function record(array $payload): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $this->assertNoForbiddenKeys($payload);

        $row = [
            'walk_id' => (string) ($payload['walk_id'] ?? ''),
            'snapshot_sha' => (string) ($payload['snapshot_sha'] ?? ''),
            'steps_json' => is_array($payload['steps_json'] ?? null) ? $payload['steps_json'] : [],
            'terminated_reason' => (string) ($payload['terminated_reason'] ?? ''),
            'observed_at_utc' => (string) ($payload['observed_at_utc'] ?? ''),
            'site_id' => (string) ($payload['site_id'] ?? ''),
        ];
        $row['hypothesis_id'] = $this->hypothesisId($row);

        $path = $this->rowPath($row['hypothesis_id']);
        if (is_file($path)) {
            $existing = json_decode((string) file_get_contents($path), true);
            if (is_array($existing) && (string) ($existing['hypothesis_id'] ?? '') === $row['hypothesis_id']) {
                return $existing;
            }
            throw new RuntimeException('hypothesis_ledger_mutation_violation:'.$path);
        }
        $this->writeAtomic($path, $row);

        return $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function historyForWalk(string $walkId): array
    {
        return array_values(array_filter($this->all(), static fn (array $r): bool => (string) ($r['walk_id'] ?? '') === $walkId));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function historyForSite(string $siteId): array
    {
        return array_values(array_filter($this->all(), static fn (array $r): bool => (string) ($r['site_id'] ?? '') === $siteId));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $rows = [];
        foreach ((array) glob($this->root().'/*.json') as $path) {
            if (! is_string($path) || ! is_file($path)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($a['observed_at_utc'] ?? ''), (string) ($b['observed_at_utc'] ?? '')));

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    public function hypothesisId(array $row): string
    {
        $canonical = [
            'walk_id' => $row['walk_id'] ?? '',
            'snapshot_sha' => $row['snapshot_sha'] ?? '',
            'steps_json' => $row['steps_json'] ?? [],
        ];
        $this->ksortRecursive($canonical);

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function enabled(): bool
    {
        if (self::$flagOverride !== null) {
            return self::$flagOverride;
        }
        if (function_exists('config')) {
            return (bool) config('atlas.loop.cortex.active.depth.hypothesis_ledger_enabled', false);
        }

        return false;
    }

    private function root(): string
    {
        return self::$rootOverride ?? storage_path('app/atlas/loop/cortex/active/depth/hypotheses');
    }

    private function rowPath(string $hypothesisId): string
    {
        return $this->root().'/'.$hypothesisId.'.json';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertNoForbiddenKeys(array $payload): void
    {
        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            $this->assertNoKey($payload, $forbidden);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertNoKey(array $payload, string $forbidden): void
    {
        foreach ($payload as $key => $value) {
            if (stripos((string) $key, $forbidden) !== false) {
                throw new RuntimeException('forbidden_key_in_hypothesis_payload:'.$key);
            }
            if (is_array($value)) {
                $this->assertNoKey($value, $forbidden);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function writeAtomic(string $path, array $row): void
    {
        $dir = \dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException('hypothesis_ledger_mkdir_failed:'.$dir);
        }
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));
        @file_put_contents($tmp, (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        @rename($tmp, $path);
    }

    /**
     * @param  array<string,mixed>  $arr
     */
    private function ksortRecursive(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$v) {
            if (is_array($v)) {
                $this->ksortRecursive($v);
            }
        }
    }
}
