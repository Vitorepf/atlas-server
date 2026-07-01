<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Explicit schema contracts for Atlas Self-Construction artifacts, outside the pétreo Brain namespace.
 *
 * Covers nine artifact kinds:
 *   - task_packet           : served task envelopes (authored by Brain, claimed by muscle)
 *   - frontier_candidate    : scope-expansion candidates (before enqueuing as a task)
 *   - blocked_respec_draft  : respec proposals for blocked/stuck packets
 *   - cortex_fact           : brain comprehension snapshots (mirrors AtlasCortexUniversalFactsSchema required fields)
 *   - respec                : queue self-healing repair artifact — rescope a blocked packet
 *   - replacement           : queue self-healing repair artifact — swap a packet for a proven-equivalent one
 *   - cancellation           : queue self-healing repair artifact — retire a duplicate/obsolete packet
 *   - operator_only          : queue self-healing repair artifact — hand a packet to the human gate
 *   - evidence_repair        : queue self-healing repair artifact — supply the missing proof a packet needs
 *
 * Each contract exposes:
 *   - required_fields       : fields that MUST be present for the artifact to be valid
 *   - forbidden_proxy_fields: field names that signal proxy-only / scoring work (pétreo: never a score)
 *   - validation_hints      : actionable strings for authors and batch auditors
 *
 * The 5 queue self-healing repair kinds additionally forbid GENERIC_PROXY_FIELDS
 * (vague_summary, looks_good, manual_review_only) — a repair artifact that guides autonomous
 * queue self-healing must never let a vague verdict stand in for a concrete proof field.
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
    public const KIND_RESPEC               = 'respec';
    public const KIND_REPLACEMENT          = 'replacement';
    public const KIND_CANCELLATION         = 'cancellation';
    public const KIND_OPERATOR_ONLY        = 'operator_only';
    public const KIND_EVIDENCE_REPAIR      = 'evidence_repair';

    /** Pétreo universal: any artifact carrying these keys carries a hidden scoring rig. */
    private const SCORE_PROXY_FIELDS = ['score', 'rank', 'grade'];

    /** Vague-verdict fields that replace concrete proof with an unverifiable claim. */
    private const GENERIC_PROXY_FIELDS = ['vague_summary', 'looks_good', 'manual_review_only'];

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
            self::KIND_RESPEC,
            self::KIND_REPLACEMENT,
            self::KIND_CANCELLATION,
            self::KIND_OPERATOR_ONLY,
            self::KIND_EVIDENCE_REPAIR,
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
            self::KIND_RESPEC => [
                'required_fields' => [
                    'task_packet_id',
                    'root_cause',
                    'new_objective',
                    'new_allowed_files',
                    'new_acceptance_criteria',
                ],
                'forbidden_proxy_fields' => [
                    ...self::SCORE_PROXY_FIELDS,
                    ...self::GENERIC_PROXY_FIELDS,
                    'confidence',
                ],
                'validation_hints' => [
                    'root_cause must name the concrete blocker, not a vague_summary of the packet',
                    'new_objective must resolve root_cause, not merely restate the original objective',
                    'new_acceptance_criteria must include a runnable proof command',
                ],
            ],
            self::KIND_REPLACEMENT => [
                'required_fields' => [
                    'original_task_packet_id',
                    'replacement_task_packet_id',
                    'replacement_reason',
                    'behavior_equivalence_proof',
                ],
                'forbidden_proxy_fields' => [
                    ...self::SCORE_PROXY_FIELDS,
                    ...self::GENERIC_PROXY_FIELDS,
                    'confidence',
                ],
                'validation_hints' => [
                    'behavior_equivalence_proof must be a concrete before/after comparison, not looks_good',
                    'replacement_reason must name why the original packet cannot proceed as-is',
                ],
            ],
            self::KIND_CANCELLATION => [
                'required_fields' => [
                    'task_packet_id',
                    'cancellation_reason',
                    'evidence_of_duplicate_or_obsolete',
                ],
                'forbidden_proxy_fields' => [
                    ...self::SCORE_PROXY_FIELDS,
                    ...self::GENERIC_PROXY_FIELDS,
                    'confidence',
                ],
                'validation_hints' => [
                    'evidence_of_duplicate_or_obsolete must point to the specific packet/commit it duplicates',
                    'cancellation_reason must not be a manual_review_only placeholder',
                ],
            ],
            self::KIND_OPERATOR_ONLY => [
                'required_fields' => [
                    'task_packet_id',
                    'operator_gate_reason',
                    'required_operator_action',
                ],
                'forbidden_proxy_fields' => [
                    ...self::SCORE_PROXY_FIELDS,
                    ...self::GENERIC_PROXY_FIELDS,
                    'confidence',
                ],
                'validation_hints' => [
                    'operator_gate_reason must name the specific human-only gate (credentials, legal, irreversible action)',
                    'required_operator_action must be a concrete, executable step — not manual_review_only',
                ],
            ],
            self::KIND_EVIDENCE_REPAIR => [
                'required_fields' => [
                    'task_packet_id',
                    'missing_evidence_type',
                    'repair_command',
                    'proof_of_repair',
                ],
                'forbidden_proxy_fields' => [
                    ...self::SCORE_PROXY_FIELDS,
                    ...self::GENERIC_PROXY_FIELDS,
                    'confidence',
                ],
                'validation_hints' => [
                    'repair_command must be runnable (artisan test/phpunit), not a vague_summary of intent',
                    'proof_of_repair must show the gate now passes, not just that a command exists',
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
