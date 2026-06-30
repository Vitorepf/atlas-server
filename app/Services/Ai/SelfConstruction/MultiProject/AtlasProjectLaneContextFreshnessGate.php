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
