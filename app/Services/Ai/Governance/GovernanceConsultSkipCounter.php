<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * MULTX-05 (partial, no enforce flip) — counter for the SLICE 2 fail-open path.
 *
 * Today the Dev claude gateway (`PipelineRunExecutor.php:1532-1560`) and the
 * Forge CLI drivers (`AtlasForgeBaseCliInvocationDriver.php:410-428`) resolve
 * the shared `ProviderGovernanceConsult` seam lazily and silently proceed when
 * it is unavailable. That silent-fall-open is honest (advisory-only enforce is
 * default OFF), but it makes coverage IMMEASURABLE — we cannot tell "0 skips"
 * from "N skips we lost".
 *
 * This counter fixes only the observability side of MULTX-05:
 *
 *   - `record()` appends one JSONL row per skip with surface / executor /
 *     provider / reason + timestamp. No prompt, no context, no raw text — the
 *     row is provider-safe by CONSTRUCTION.
 *   - `report(windowHours)` returns aggregate counters keyed by dimension.
 *   - The enforce flip lives in a SEPARATE slice governed by ELEV-26 and is
 *     NOT part of this class.
 *
 * The class is fail-open: any I/O error is swallowed so the runtime path never
 * breaks because of a bookkeeping miss.
 */
final class GovernanceConsultSkipCounter
{
    public const SCHEMA_VERSION = 'atlas.governance.consult_skipped.v1';

    public const REASON_SEAM_UNBOUND = 'seam_unbound';

    public const REASON_METHOD_MISSING = 'consult_method_missing';

    public const REASON_CONTAINER_ABSENT = 'container_absent';

    public const REASON_SEAM_THREW = 'seam_threw';

    public const REASONS = [
        self::REASON_SEAM_UNBOUND,
        self::REASON_METHOD_MISSING,
        self::REASON_CONTAINER_ABSENT,
        self::REASON_SEAM_THREW,
    ];

    private const DEFAULT_RELATIVE_PATH = 'storage/atlas/atlas_decide/governance_consult_skipped.jsonl';

    public function __construct(private readonly string $path) {}

    public static function fromConfig(): self
    {
        $configured = null;
        if (function_exists('config')) {
            $configured = (string) config('atlas.governance.consult_skipped_ledger_path', '');
        }
        if (($configured ?? '') === '' && function_exists('storage_path')) {
            $configured = storage_path('atlas/atlas_decide/governance_consult_skipped.jsonl');
        }
        if (($configured ?? '') === '' && function_exists('base_path')) {
            $configured = base_path(self::DEFAULT_RELATIVE_PATH);
        }
        if (($configured ?? '') === '') {
            $configured = self::DEFAULT_RELATIVE_PATH;
        }

        return new self((string) $configured);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Provider-safe row (no prompt, no context body, no raw command text).
     *
     * @param  array{surface?:string,executor?:string,provider?:string,reason?:string,ts?:string}  $ctx
     */
    public function record(array $ctx): void
    {
        try {
            $row = [
                'schema_version' => self::SCHEMA_VERSION,
                'ts' => $this->timestamp($ctx['ts'] ?? null),
                'surface' => $this->sanitize($ctx['surface'] ?? ''),
                'executor' => $this->sanitize($ctx['executor'] ?? ''),
                'provider' => $this->sanitize($ctx['provider'] ?? ''),
                'reason' => $this->normalizeReason((string) ($ctx['reason'] ?? '')),
            ];

            $dir = dirname($this->path);
            if ($dir !== '' && ! is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }

            @file_put_contents(
                $this->path,
                json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );

            // P1b.2: dual-write into CoverageLedger so skip metrics are not a
            // second unreconciled writer (skip file remains for replay/report).
            try {
                if (function_exists('app')) {
                    app(ProviderGovernanceCoverageLedger::class)->ingestConsultSkip($row);
                }
            } catch (Throwable) {
                // Fail-open dual-write.
            }
        } catch (Throwable) {
            // Fail-open: never break runtime for a bookkeeping miss.
        }
    }

    /**
     * @return array{schema_version:string,since:?string,until:string,window_hours:int,total:int,by_surface:array<string,int>,by_executor:array<string,int>,by_reason:array<string,int>,by_provider:array<string,int>}
     */
    public function report(int $windowHours = 168, ?CarbonImmutable $now = null): array
    {
        $windowHours = max(1, $windowHours);
        $now = $now ?? CarbonImmutable::now();
        $cutoff = $now->subHours($windowHours);
        $cutoffIso = $cutoff->toIso8601String();

        $total = 0;
        $bySurface = [];
        $byExecutor = [];
        $byReason = [];
        $byProvider = [];

        foreach ($this->replay() as $row) {
            $ts = (string) ($row['ts'] ?? '');
            if ($ts !== '' && strcmp($ts, $cutoffIso) < 0) {
                continue;
            }
            $total++;
            $this->bumpBucket($bySurface, (string) ($row['surface'] ?? ''));
            $this->bumpBucket($byExecutor, (string) ($row['executor'] ?? ''));
            $this->bumpBucket($byReason, (string) ($row['reason'] ?? ''));
            $this->bumpBucket($byProvider, (string) ($row['provider'] ?? ''));
        }

        ksort($bySurface);
        ksort($byExecutor);
        ksort($byReason);
        ksort($byProvider);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'since' => $cutoff->toIso8601String(),
            'until' => $now->toIso8601String(),
            'window_hours' => $windowHours,
            'total' => $total,
            'by_surface' => $bySurface,
            'by_executor' => $byExecutor,
            'by_reason' => $byReason,
            'by_provider' => $byProvider,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function replay(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            return [];
        }

        $rows = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @param  array<string,int>  $bucket
     */
    private function bumpBucket(array &$bucket, string $key): void
    {
        $key = trim($key);
        if ($key === '') {
            $key = 'unknown';
        }
        $bucket[$key] = ($bucket[$key] ?? 0) + 1;
    }

    private function timestamp(mixed $override): string
    {
        if (is_string($override) && $override !== '') {
            return $override;
        }

        return CarbonImmutable::now()->toIso8601String();
    }

    private function sanitize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'unknown';
        }
        // Cap length so surfaces/executors/providers stay identifier-shaped.
        return substr($value, 0, 96);
    }

    private function normalizeReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            return self::REASON_SEAM_UNBOUND;
        }
        if (in_array($reason, self::REASONS, true)) {
            return $reason;
        }

        return self::REASON_SEAM_UNBOUND;
    }
}
