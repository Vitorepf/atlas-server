<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Pure FACTS-only gate that blocks task generation for a project lane when docs sync / code index /
 * context-pack freshness evidence is stale or missing.
 *
 * NO numeric score, NO provider call, NO filesystem mutation, NO human/operator dependency.
 *
 * Input shape:
 *   manifest: {
 *     project_id: string,
 *     freshness_window_seconds: { docs_sync: int, code_index: int, context_pack: int },
 *     // OR a single uniform `int` (applied to all three).
 *   }
 *   observations: {
 *     now_unix: int,                              // the gate clock; injected (no time())
 *     docs_sync_last_unix?: int,
 *     code_index_last_unix?: int,
 *     context_pack_hash?: string,                 // non-empty hash ⇒ pack is present
 *     context_pack_last_unix?: int,
 *   }
 */
final class AtlasProjectLaneContextFreshnessGate
{
    public const SCHEMA = 'atlas.multiproject.context_freshness.v1';

    public const REQUIRED_EVIDENCE = ['docs_sync', 'code_index', 'context_pack', 'queue_namespace', 'receipt_ledger'];

    public const FRESHNESS_FRESH = 'fresh';

    public const FRESHNESS_STALE = 'stale';

    public const FRESHNESS_MISSING = 'missing';

    public const READINESS_READY = 'ready';

    public const READINESS_DEGRADED = 'degraded';

    public const READINESS_BLOCKED = 'blocked';

    /** blocker prefixes that mean the lane is fundamentally misconfigured, not merely stale. */
    private const STRUCTURAL_BLOCKER_PREFIXES = [
        'project_id_missing',
        'invalid_freshness_window',
        'context_pack_project_mismatch',
        'context_pack_missing_hash',
        'receipt_ledger_hash_missing',
    ];

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $observations
     * @return array<string,mixed>
     */
    public function evaluate(array $manifest, array $observations): array
    {
        $now = (int) ($observations['now_unix'] ?? 0);
        $windows = $this->normalizeWindows($manifest['freshness_window_seconds'] ?? null);
        $blockers = [];

        // The gate clock must be a positive time reference — missing or non-positive
        // now_unix makes every staleness check compute a negative age that can never
        // exceed the window, reporting stale sources as fresh.
        if ($now <= 0) {
            $blockers[] = 'now_unix_missing';
        }

        // project_id must be present and non-empty.
        $projectId = (string) ($manifest['project_id'] ?? '');
        if ($projectId === '') {
            $blockers[] = 'project_id_missing';
        }

        // Explicit invalid freshness window values (provided but ≤0).
        $rawWindows = $manifest['freshness_window_seconds'] ?? null;
        if (is_array($rawWindows)) {
            foreach (['docs_sync', 'code_index', 'context_pack', 'queue_namespace', 'receipt_ledger'] as $key) {
                $val = $rawWindows[$key] ?? null;
                if ($val !== null && (! is_int($val) || $val <= 0)) {
                    $blockers[] = 'invalid_freshness_window:'.$key;
                }
            }
        } elseif (is_int($rawWindows) && $rawWindows <= 0) {
            $blockers[] = 'invalid_freshness_window:all';
        }

        // context_pack_project_id in observations must match the manifest project_id.
        $packProjectId = (string) ($observations['context_pack_project_id'] ?? '');
        if ($packProjectId !== '' && $projectId !== '' && $packProjectId !== $projectId) {
            $blockers[] = 'context_pack_project_mismatch';
        }

        // docs_sync
        $docsLast = $observations['docs_sync_last_unix'] ?? null;
        if (! is_int($docsLast)) {
            $blockers[] = 'docs_sync_missing';
        } elseif ($now - $docsLast > $windows['docs_sync']) {
            $blockers[] = 'docs_sync_stale';
        }

        // code_index
        $codeLast = $observations['code_index_last_unix'] ?? null;
        if (! is_int($codeLast)) {
            $blockers[] = 'code_index_missing';
        } elseif ($now - $codeLast > $windows['code_index']) {
            $blockers[] = 'code_index_stale';
        }

        // context_pack — hash MUST be present AND within window.
        $packHash = (string) ($observations['context_pack_hash'] ?? '');
        $packLast = $observations['context_pack_last_unix'] ?? null;
        if ($packHash === '') {
            $blockers[] = 'context_pack_missing_hash';
        }
        if (! is_int($packLast)) {
            $blockers[] = 'context_pack_missing_timestamp';
        } elseif ($now - $packLast > $windows['context_pack']) {
            $blockers[] = 'context_pack_stale';
        }

        // queue_namespace — last sync timestamp must be present and within window.
        $queueLast = $observations['queue_namespace_last_unix'] ?? null;
        if (! is_int($queueLast)) {
            $blockers[] = 'queue_namespace_missing';
        } elseif ($now - $queueLast > $windows['queue_namespace']) {
            $blockers[] = 'queue_namespace_stale';
        }

        // receipt_ledger — hash must be present AND timestamp within window.
        $receiptHash = (string) ($observations['receipt_ledger_hash'] ?? '');
        $receiptLast = $observations['receipt_ledger_last_unix'] ?? null;
        if ($receiptHash === '') {
            $blockers[] = 'receipt_ledger_hash_missing';
        }
        if (! is_int($receiptLast)) {
            $blockers[] = 'receipt_ledger_missing';
        } elseif ($now - $receiptLast > $windows['receipt_ledger']) {
            $blockers[] = 'receipt_ledger_stale';
        }

        return [
            'schema_version' => self::SCHEMA,
            'project_id' => (string) ($manifest['project_id'] ?? ''),
            'conformant' => $blockers === [],
            'blockers' => $blockers,
            'window_seconds' => $windows,
            'observed_at' => $now,
        ];
    }

    /**
     * Adds a memory_snapshot freshness dimension (missing by default in evaluate(), never
     * assumed fresh) and a per-dimension freshness breakdown plus one readiness_status —
     * 'blocked' when the lane is structurally misconfigured (missing project id, invalid window,
     * mismatched/missing context pack identity), 'degraded' when it is merely stale, 'ready'
     * only when every dimension is confirmed fresh.
     *
     * @param  array<string,mixed>  $manifest       same shape as evaluate(), optionally with
     *                                                freshness_window_seconds.memory_snapshot
     * @param  array<string,mixed>  $observations   same shape as evaluate(), plus optional
     *                                                memory_snapshot_last_unix?:int
     * @return array{schema:string, project_id:string, freshness:array<string,string>, readiness_status:string, blockers:list<string>}
     */
    public function evaluateReadiness(array $manifest, array $observations): array
    {
        $base = $this->evaluate($manifest, $observations);
        $now = (int) ($observations['now_unix'] ?? 0);

        $rawWindows = $manifest['freshness_window_seconds'] ?? null;
        $memoryWindow = is_array($rawWindows) && isset($rawWindows['memory_snapshot'])
            && is_int($rawWindows['memory_snapshot']) && $rawWindows['memory_snapshot'] > 0
            ? $rawWindows['memory_snapshot']
            : 3600;

        $memoryLast = $observations['memory_snapshot_last_unix'] ?? null;
        $memoryFreshness = ! is_int($memoryLast)
            ? self::FRESHNESS_MISSING
            : (($now - $memoryLast > $memoryWindow) ? self::FRESHNESS_STALE : self::FRESHNESS_FRESH);

        $blockers = $base['blockers'];
        if ($memoryFreshness !== self::FRESHNESS_FRESH) {
            $blockers[] = 'memory_snapshot_'.$memoryFreshness;
        }

        $freshness = [
            'context_pack' => $this->dimensionFreshness($base['blockers'], 'context_pack'),
            'code_index' => $this->dimensionFreshness($base['blockers'], 'code_index'),
            'docs' => $this->dimensionFreshness($base['blockers'], 'docs_sync'),
            'queue_state' => $this->dimensionFreshness($base['blockers'], 'queue_namespace'),
            'memory_snapshot' => $memoryFreshness,
        ];

        $hasStructuralBlocker = false;
        foreach ($blockers as $blocker) {
            foreach (self::STRUCTURAL_BLOCKER_PREFIXES as $prefix) {
                if (str_starts_with($blocker, $prefix)) {
                    $hasStructuralBlocker = true;
                    break 2;
                }
            }
        }

        $readinessStatus = match (true) {
            $hasStructuralBlocker => self::READINESS_BLOCKED,
            $blockers !== [] => self::READINESS_DEGRADED,
            default => self::READINESS_READY,
        };

        return [
            'schema' => self::SCHEMA,
            'project_id' => $base['project_id'],
            'freshness' => $freshness,
            'readiness_status' => $readinessStatus,
            'blockers' => $blockers,
        ];
    }

    /** @param  list<string>  $blockers */
    private function dimensionFreshness(array $blockers, string $prefix): string
    {
        foreach ($blockers as $blocker) {
            if (str_starts_with($blocker, $prefix.'_stale')) {
                return self::FRESHNESS_STALE;
            }
        }
        foreach ($blockers as $blocker) {
            if (str_starts_with($blocker, $prefix.'_missing')) {
                return self::FRESHNESS_MISSING;
            }
        }

        return self::FRESHNESS_FRESH;
    }

    /**
     * @return array{docs_sync:int, code_index:int, context_pack:int}
     */
    private function normalizeWindows(mixed $cfg): array
    {
        $default = ['docs_sync' => 86400, 'code_index' => 86400, 'context_pack' => 3600, 'queue_namespace' => 3600, 'receipt_ledger' => 3600];
        if (is_int($cfg)) {
            return ['docs_sync' => $cfg, 'code_index' => $cfg, 'context_pack' => $cfg];
        }
        if (! is_array($cfg)) {
            return $default;
        }
        foreach ($default as $key => $fallback) {
            $value = $cfg[$key] ?? null;
            $default[$key] = is_int($value) && $value > 0 ? $value : $fallback;
        }

        return $default;
    }
}
