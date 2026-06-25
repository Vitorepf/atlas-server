<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Atlas-native completion evidence verifier.
 *
 * Pure, deterministic, facts-only. Consumes {@see AtlasSelfConstructionFinalEvidenceSourceRegistry}
 * to enumerate the BLOCKING sources final completion must inspect.
 *
 * For each registry source, inspects `facts['sources'][source_id]` (status: pass|fail|missing|stale|
 * contradictory). Reports per-source-id blockers categorised by kind. Refuses with `hold` for
 * REFRESHABLE missing/stale sources (re-derivable locally) and with `blocked` for unsafe source
 * failures or autonomy contract violations.
 *
 * Status values:
 *   - atlas_native_ready   — every blocking source has passing facts AND ownership contract holds
 *   - atlas_native_hold    — only refreshable issues remain (missing or stale on refreshable sources)
 *   - atlas_native_blocked — unsafe source failures (fail/contradictory or missing/stale on
 *                             non-refreshable sources) or autonomy contract violations
 *
 * NO scalar scoring is emitted.
 */
final class AtlasSelfConstructionAtlasNativeEvidenceVerifier
{
    public const SCHEMA = 'atlas.self_construction.atlas_native_evidence_verifier.v1';

    public const STATUS_READY = 'atlas_native_ready';
    public const STATUS_HOLD = 'atlas_native_hold';
    public const STATUS_BLOCKED = 'atlas_native_blocked';

    public const SOURCE_STATUS_PASS = 'pass';
    public const SOURCE_STATUS_FAIL = 'fail';
    public const SOURCE_STATUS_MISSING = 'missing';
    public const SOURCE_STATUS_STALE = 'stale';
    public const SOURCE_STATUS_CONTRADICTORY = 'contradictory';

    /** @var list<string> */
    private const AUTONOMY_DEPENDENCY_FLAGS = [
        'depends_on_operator',
        'depends_on_claude_code',
        'depends_on_codex',
        'depends_on_external_provider_network',
    ];

    private readonly AtlasSelfConstructionFinalEvidenceSourceRegistry $registry;

    public function __construct(?AtlasSelfConstructionFinalEvidenceSourceRegistry $registry = null)
    {
        $this->registry = $registry ?? new AtlasSelfConstructionFinalEvidenceSourceRegistry();
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function verify(array $facts): array
    {
        $contractBlockers = $this->autonomyContractBlockers($facts);
        $sourceFacts = is_array($facts['sources'] ?? null) ? $facts['sources'] : $this->legacyFactsToSources($facts);

        $sourceBlockers = [];
        $sourcesObserved = [];
        $unsafeBlocker = false;
        $refreshableHold = false;

        $registry = $this->registry->describe();
        foreach ($registry['required_sources'] as $source) {
            if (! (bool) $source['blocking']) {
                continue;
            }
            $sourceId = (string) $source['id'];
            $refreshable = (bool) $source['refreshable'];
            $sourceRow = is_array($sourceFacts[$sourceId] ?? null) ? $sourceFacts[$sourceId] : null;
            $status = $sourceRow !== null ? (string) ($sourceRow['status'] ?? '') : self::SOURCE_STATUS_MISSING;
            $sourcesObserved[$sourceId] = $status;

            if ($status === self::SOURCE_STATUS_PASS) {
                continue;
            }

            $kind = match ($status) {
                self::SOURCE_STATUS_MISSING => 'source_missing',
                self::SOURCE_STATUS_STALE => 'source_stale',
                self::SOURCE_STATUS_FAIL => 'source_failed',
                self::SOURCE_STATUS_CONTRADICTORY => 'source_contradictory',
                default => 'source_unknown_status',
            };
            $sourceBlockers[] = [
                'source_id' => $sourceId,
                'kind' => $kind,
                'status' => $status,
                'refreshable' => $refreshable,
                'note' => isset($sourceRow['note']) ? (string) $sourceRow['note'] : '',
            ];

            $safeMiss = $refreshable && in_array($status, [self::SOURCE_STATUS_MISSING, self::SOURCE_STATUS_STALE], true);
            if ($safeMiss) {
                $refreshableHold = true;
            } else {
                $unsafeBlocker = true;
            }
        }

        $passed = $contractBlockers === [] && $sourceBlockers === [];
        $status = self::STATUS_READY;
        if (! $passed) {
            if ($unsafeBlocker || $contractBlockers !== []) {
                $status = self::STATUS_BLOCKED;
            } elseif ($refreshableHold) {
                $status = self::STATUS_HOLD;
            } else {
                $status = self::STATUS_BLOCKED;
            }
        }

        // Stable order for byte-deterministic output.
        usort($sourceBlockers, static fn (array $a, array $b): int => strcmp((string) $a['source_id'], (string) $b['source_id']));
        ksort($sourcesObserved);
        sort($contractBlockers, SORT_STRING);

        $flatBlockers = $contractBlockers;
        foreach ($sourceBlockers as $b) {
            $flatBlockers[] = $b['kind'].':'.$b['source_id'];
        }

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'passed' => $passed,
            'blockers' => $flatBlockers,
            'autonomy_contract_blockers' => $contractBlockers,
            'source_blockers' => $sourceBlockers,
            'sources_observed' => $sourcesObserved,
            'registry_schema_version' => (string) $registry['schema_version'],
        ];
    }

    /**
     * Legacy bridge — when callers pass the old shape (no `sources` key, only flat readiness
     * booleans), derive a sources map so existing call sites keep working. `pass` when the legacy
     * boolean is true; `missing` when the key is absent; `fail` when the boolean is explicitly false.
     *
     * @param  array<string,mixed>  $facts
     * @return array<string,array<string,mixed>>
     */
    private function legacyFactsToSources(array $facts): array
    {
        $map = [
            'task_serving_contract_sentinel' => 'serving_queue_health',
            'code_index_readiness_bridge' => 'code_index_readiness',
            'multi_project_governance_dossier' => 'multi_project_lane_readiness',
            'native_worker_readiness' => 'native_worker_readiness',
            'verification_court' => 'verification_court_readiness',
            'merge_governor' => 'merge_governor_readiness',
            'rollback' => 'rollback_readiness',
            'receipts' => 'serving_queue_health',
            'learning_transfer' => 'learning_transfer_readiness',
            'docs_health' => 'docs_health',
            'knowledge_sync' => 'kb_sync',
        ];
        $sources = [];
        foreach ($map as $sourceId => $factKey) {
            if (! array_key_exists($factKey, $facts)) {
                $sources[$sourceId] = ['status' => self::SOURCE_STATUS_MISSING];

                continue;
            }
            $sources[$sourceId] = ['status' => (bool) $facts[$factKey] ? self::SOURCE_STATUS_PASS : self::SOURCE_STATUS_FAIL];
        }

        return $sources;
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return list<string>
     */
    private function autonomyContractBlockers(array $facts): array
    {
        $blockers = [];

        $finalOwner = (string) ($facts['final_runtime_owner'] ?? '');
        if ($finalOwner !== 'atlas_native') {
            $blockers[] = 'final_runtime_owner_not_atlas_native:'.$finalOwner;
        }

        $steady = (string) ($facts['steady_state_runtime_owner'] ?? '');
        if (! in_array($steady, ['atlas_server', 'atlas_native'], true)) {
            $blockers[] = 'steady_state_runtime_owner_not_native_or_server:'.$steady;
        }

        $deps = is_array($facts['autonomy_dependencies'] ?? null) ? $facts['autonomy_dependencies'] : [];
        foreach (self::AUTONOMY_DEPENDENCY_FLAGS as $flag) {
            if (! array_key_exists($flag, $deps)) {
                $blockers[] = 'autonomy_dependency_missing:'.$flag;

                continue;
            }
            if ((bool) $deps[$flag] !== false) {
                $blockers[] = 'autonomy_dependency_true:'.$flag;
            }
        }

        return $blockers;
    }
}
