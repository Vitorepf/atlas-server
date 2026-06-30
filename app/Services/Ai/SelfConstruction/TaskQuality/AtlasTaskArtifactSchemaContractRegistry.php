<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Explicit schema contracts for Atlas Self-Construction artifacts, outside the pétreo Brain namespace.
 *
 * Covers four artifact kinds:
 *   - task_packet           : served task envelopes (authored by Brain, claimed by muscle)
 *   - frontier_candidate    : scope-expansion candidates (before enqueuing as a task)
 *   - blocked_respec_draft  : respec proposals for blocked/stuck packets
 *   - cortex_fact           : brain comprehension snapshots (mirrors AtlasCortexUniversalFactsSchema required fields)
 *
 * Each contract exposes:
 *   - required_fields       : fields that MUST be present for the artifact to be valid
 *   - forbidden_proxy_fields: field names that signal proxy-only / scoring work (pétreo: never a score)
 *   - validation_hints      : actionable strings for authors and batch auditors
 *
 * Pure: no I/O, no DB, no providers, no queue writes.
 */
final class AtlasTaskArtifactSchemaContractRegistry
{
    public const SCHEMA = 'atlas.task_quality.artifact_schema_contract_registry.v1';

    public const KIND_TASK_PACKET          = 'task_packet';
    public const KIND_FRONTIER_CANDIDATE   = 'frontier_candidate';
    public const KIND_BLOCKED_RESPEC_DRAFT = 'blocked_respec_draft';
    public const KIND_CORTEX_FACT          = 'cortex_fact';

    /** Pétreo universal: any artifact carrying these keys carries a hidden scoring rig. */
    private const SCORE_PROXY_FIELDS = ['score', 'rank', 'grade'];

    /**
     * Returns all registered artifact kinds.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        return [
            self::KIND_TASK_PACKET,
            self::KIND_FRONTIER_CANDIDATE,
            self::KIND_BLOCKED_RESPEC_DRAFT,
            self::KIND_CORTEX_FACT,
        ];
    }

    /**
     * Returns the contract for a given artifact kind, or null when the kind is unknown.
     *
     * @return array{required_fields:list<string>,forbidden_proxy_fields:list<string>,validation_hints:list<string>}|null
     */
    public function contract(string $kind): ?array
    {
        return match ($kind) {
            self::KIND_TASK_PACKET => [
                'required_fields' => [
                    'task_packet_id',
                    'objective',
                    'allowed_files',
                    'acceptance_criteria',
                    'required_evidence',
                ],
                'forbidden_proxy_fields' => [
                    ...self::SCORE_PROXY_FIELDS,
                    'confidence',
                    'quality_score',
                ],
                'validation_hints' => [
                    'objective must describe real compounding work, not a proxy re-export, rename, or alias',
                    'allowed_files must name concrete files — no bare directories',
                    'acceptance_criteria must include a runnable proof command (artisan test or phpunit)',
                    'required_evidence must list at least one gate (tests_or_gates_result)',
                ],
            ],
            self::KIND_FRONTIER_CANDIDATE => [
                'required_fields' => [
                    'scope_id',
                    'evidence_refs',
                    'atlas_native_owner',
                    'requires_operator',
                    'requires_human',
                    'requires_external_provider',
                    'proven_leverage_tier',
                    'autonomy_readiness_tier',
                    'risk',
                ],
                'forbidden_proxy_fields' => [
                    ...self::SCORE_PROXY_FIELDS,
                    'confidence',
                    'priority_score',
                ],
                'validation_hints' => [
                    'evidence_refs must point to real artifacts (doc, code, ledger) — no self-declared confidence',
                    'atlas_native_owner=true is required for autonomous admission without operator approval',
                    'proven_leverage_tier must be grounded in evidence, not estimated',
                ],
            ],
            self::KIND_BLOCKED_RESPEC_DRAFT => [
                'required_fields' => [
                    'original_packet_id',
                    'block_reason',
                    'proposed_objective',
                    'proposed_allowed_files',
                ],
                'forbidden_proxy_fields' => [
                    ...self::SCORE_PROXY_FIELDS,
                    'confidence',
                ],
                'validation_hints' => [
                    'proposed_objective must resolve the block_reason — not merely reorder or rename',
                    'proposed_allowed_files must differ from the blocked packet and avoid forbidden targets',
                    'a respec draft that reproduces the original scope is not a valid respec',
                ],
            ],
            self::KIND_CORTEX_FACT => [
                'required_fields' => [
                    'snapshot_id',
                    'inventory',
                    'orphans',
                    'clone_clusters',
                    'forbidden',
                    'doc_stated_gaps',
                ],
                'forbidden_proxy_fields' => [
                    ...self::SCORE_PROXY_FIELDS,
                    'quality_score',
                    'confidence',
                ],
                'validation_hints' => [
                    'facts only — no numeric verdicts; every unit key matching /^(score|rank|grade)$/i is rejected',
                    'evidence_refs must not contain provider keys, bearer tokens, or home paths',
                    'captured_at must be within the 48-hour freshness window',
                ],
            ],
            default => null,
        };
    }

    /**
     * Validate an artifact against its contract.
     * Returns missing required fields and any forbidden proxy fields found.
     *
     * @param  array<string,mixed>  $artifact
     * @return array{valid:bool,missing_required:list<string>,found_proxy_fields:list<string>}
     */
    public function validate(string $kind, array $artifact): array
    {
        $contract = $this->contract($kind);
        if ($contract === null) {
            return ['valid' => false, 'missing_required' => [], 'found_proxy_fields' => []];
        }

        $missingRequired = array_values(array_filter(
            $contract['required_fields'],
            static fn (string $f): bool => ! array_key_exists($f, $artifact),
        ));

        $foundProxy = array_values(array_filter(
            $contract['forbidden_proxy_fields'],
            static fn (string $f): bool => array_key_exists($f, $artifact),
        ));

        return [
            'valid' => $missingRequired === [] && $foundProxy === [],
            'missing_required' => $missingRequired,
            'found_proxy_fields' => $foundProxy,
        ];
    }
}
