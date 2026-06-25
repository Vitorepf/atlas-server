<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Deterministic Atlas-native completion dossier exporter.
 *
 * Pure, facts-only. Exports a compact JSON dossier from finalization facts so the OS can
 * explain its own closure using ATLAS-NATIVE evidence sections only. NEVER converts a hold or
 * blocked finalization state into ready.
 *
 * Input:
 *   {
 *     finalization: <output of AtlasSelfConstructionAtlasNativeFinalizationGate::finalize()>,
 *     evidence_facts?: <subset of facts to surface as evidence sections>,
 *     receipts?: list<{kind: string, ref: string, observed_at?: string}>
 *   }
 *
 * Output: a stable envelope keyed by Atlas-native evidence sections only.
 */
final class AtlasSelfConstructionAtlasNativeDossierExporter
{
    public const SCHEMA = 'atlas.self_construction.atlas_native_dossier.v1';

    /** @var list<string> Mandatory Atlas-native evidence sections (no human/external provider fields). */
    public const MANDATORY_FIELDS = [
        'owner',
        'autonomy_contract',
        'queue_health',
        'native_worker',
        'verification_court',
        'merge_governor',
        'rollback',
        'receipts',
        'learning',
        'docs_knowledge_sync',
        'code_index',
        'multi_project_lanes',
        'task_serving_contract_sentinel',
        'code_index_readiness_bridge',
        'multi_project_governance_dossier',
        'evidence_source_coverage',
    ];

    public function __construct(private readonly ?AtlasSelfConstructionFinalEvidenceSourceRegistry $registry = null)
    {
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function export(array $facts): array
    {
        $finalization = is_array($facts['finalization'] ?? null) ? $facts['finalization'] : [];
        $evidence = is_array($facts['evidence_facts'] ?? null) ? $facts['evidence_facts'] : [];
        $receipts = is_array($facts['receipts'] ?? null) ? array_values($facts['receipts']) : [];

        $finalState = (string) ($finalization['final_state'] ?? 'unknown');
        $blockers = array_values((array) ($finalization['blockers'] ?? []));
        $autonomyLevel = (string) ($finalization['readiness_sections']['autonomy_level'] ?? '');
        $depGate = is_array($finalization['readiness_sections']['dependency_gate'] ?? null)
            ? $finalization['readiness_sections']['dependency_gate'] : [];

        $sections = [
            'owner' => [
                'final_runtime_owner' => (string) ($evidence['final_runtime_owner'] ?? ''),
                'steady_state_runtime_owner' => (string) ($evidence['steady_state_runtime_owner'] ?? ''),
            ],
            'autonomy_contract' => [
                'autonomy_level' => $autonomyLevel,
                'dependency_gate_passed' => (bool) ($depGate['passed'] ?? false),
                'autonomy_dependencies' => (array) ($evidence['autonomy_dependencies'] ?? []),
            ],
            'queue_health' => (bool) ($evidence['serving_queue_health'] ?? false),
            'native_worker' => (bool) ($evidence['native_worker_readiness'] ?? false),
            'verification_court' => (bool) ($evidence['verification_court_readiness'] ?? false),
            'merge_governor' => (bool) ($evidence['merge_governor_readiness'] ?? false),
            'rollback' => (bool) ($evidence['rollback_readiness'] ?? false),
            'receipts' => $this->normalizeReceipts($receipts),
            'learning' => (bool) ($evidence['learning_transfer_readiness'] ?? false),
            'docs_knowledge_sync' => [
                'docs_health' => (bool) ($evidence['docs_health'] ?? false),
                'kb_sync' => (bool) ($evidence['kb_sync'] ?? false),
            ],
            'code_index' => (bool) ($evidence['code_index_readiness'] ?? false),
            'multi_project_lanes' => (bool) ($evidence['multi_project_lane_readiness'] ?? false),
            'task_serving_contract_sentinel' => $this->sourceFlag($finalization, 'task_serving_contract_sentinel'),
            'code_index_readiness_bridge' => $this->sourceFlag($finalization, 'code_index_readiness_bridge'),
            'multi_project_governance_dossier' => $this->sourceFlag($finalization, 'multi_project_governance_dossier'),
            'evidence_source_coverage' => $this->evidenceSourceCoverage($finalization),
        ];

        $dossierId = $this->dossierId($finalState, $blockers, $sections);

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'dossier_id' => $dossierId,
            'final_state' => $finalState,
            'mandatory_fields' => self::MANDATORY_FIELDS,
            'evidence_sections' => $sections,
            'blockers' => $blockers,
            'receipts' => $sections['receipts'],
            'proof_summary' => sprintf(
                'final_state=%s sections=%d blockers=%d',
                $finalState,
                count(self::MANDATORY_FIELDS),
                count($blockers),
            ),
        ];
    }

    /**
     * @param  list<mixed>  $receipts
     * @return list<array<string,string>>
     */
    private function normalizeReceipts(array $receipts): array
    {
        $normalized = [];
        foreach ($receipts as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $normalized[] = [
                'kind' => (string) ($entry['kind'] ?? 'unknown'),
                'ref' => (string) ($entry['ref'] ?? ''),
                'observed_at' => (string) ($entry['observed_at'] ?? ''),
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $sections
     */
    private function sourceFlag(array $finalization, string $sourceId): string
    {
        $coverage = is_array($finalization['source_coverage'] ?? null) ? $finalization['source_coverage'] : [];
        if (in_array($sourceId, (array) ($coverage['blocked_sources'] ?? []), true)) {
            return 'blocked';
        }
        if (in_array($sourceId, (array) ($coverage['hold_sources'] ?? []), true)) {
            return 'hold';
        }
        if (in_array($sourceId, (array) ($coverage['missing_sources'] ?? []), true)) {
            return 'missing';
        }

        return 'observed';
    }

    /**
     * @param  array<string,mixed>  $finalization
     * @return array<string,mixed>
     */
    private function evidenceSourceCoverage(array $finalization): array
    {
        $coverage = is_array($finalization['source_coverage'] ?? null) ? $finalization['source_coverage'] : [];

        $registry = $this->registry ?? new AtlasSelfConstructionFinalEvidenceSourceRegistry;
        $registryDescription = $registry->describe();
        $mandatorySourceIds = [];
        foreach ((array) ($registryDescription['required_sources'] ?? []) as $source) {
            if (! is_array($source)) {
                continue;
            }
            if ((bool) ($source['blocking'] ?? false)) {
                $mandatorySourceIds[] = (string) ($source['id'] ?? '');
            }
        }
        sort($mandatorySourceIds, SORT_STRING);

        $required = (int) ($coverage['required_count'] ?? 0);
        $observedCount = (int) ($coverage['observed_count'] ?? 0);
        $missing = array_values((array) ($coverage['missing_sources'] ?? []));
        $hold = array_values((array) ($coverage['hold_sources'] ?? []));
        $blocked = array_values((array) ($coverage['blocked_sources'] ?? []));

        $observedSourceIds = array_values(array_diff($mandatorySourceIds, $missing));

        return [
            'mandatory_source_ids' => $mandatorySourceIds,
            'observed_source_ids' => $observedSourceIds,
            'missing_source_ids' => $missing,
            'hold_source_ids' => $hold,
            'blocked_source_ids' => $blocked,
            'proof_summary' => sprintf(
                'required=%d observed=%d missing=%d hold=%d blocked=%d',
                $required,
                $observedCount,
                count($missing),
                count($hold),
                count($blocked),
            ),
        ];
    }

    private function dossierId(string $finalState, array $blockers, array $sections): string
    {
        $canonical = json_encode([
            'final_state' => $finalState,
            'blockers' => $blockers,
            'sections' => $sections,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'atlas-dossier_'.substr(hash('sha256', (string) $canonical), 0, 24);
    }
}
