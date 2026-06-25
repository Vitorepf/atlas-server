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
    ];

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
