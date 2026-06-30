<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskGraph;

/**
 * Deterministic final-architecture organ map for Atlas Self-Construction.
 *
 * Encodes the canonical ORGANS, their REQUIRED CAPABILITIES, NON-AUTHORITIES and
 * EVIDENCE EXPECTATIONS that the task graph must cover before final autonomy can
 * be claimed. Pure, facts-only, no DB / disk / provider calls. No scalar scoring.
 */
final class AtlasSelfConstructionFinalOrganMap
{
    public const SCHEMA = 'atlas.self_construction.final_organ_map.v1';

    public const ORGAN_CORTEX = 'cortex';

    public const ORGAN_GOAL_AND_VALUE = 'goal_and_value';

    public const ORGAN_STRATEGY_COUNCIL = 'strategy_council';

    public const ORGAN_ARCHITECTURE_COUNCIL = 'architecture_council';

    public const ORGAN_TASK_FABRIC = 'task_fabric';

    public const ORGAN_MAESTRO = 'maestro';

    public const ORGAN_WORKER_SWARM = 'worker_swarm';

    public const ORGAN_VERIFICATION_COURT = 'verification_court';

    public const ORGAN_MERGE_GOVERNOR = 'merge_governor';

    public const ORGAN_RECEIPTS = 'receipts';

    public const ORGAN_LEARNING_TRANSFER = 'learning_transfer';

    public const ORGAN_AUTOPOIESIS_LOOP = 'autopoiesis_loop';

    public const ORGAN_DOCS_KNOWLEDGE_SYNC = 'docs_knowledge_sync';

    public const ORGAN_OPERATOR_VISIBILITY = 'operator_visibility';

    public const ORGAN_MULTI_PROJECT_STEWARDSHIP = 'multi_project_stewardship';

    public const ORGAN_CODE_INTELLIGENCE = 'code_intelligence';

    public const ORGAN_FINAL_COMPLETION = 'final_completion';

    /**
     * @return array<string,mixed>
     */
    public function describe(): array
    {
        $organs = $this->organs();
        $allCapabilities = [];
        $allEvidence = [];
        foreach ($organs as $organ) {
            $allCapabilities = array_merge($allCapabilities, $organ['required_capabilities']);
            $allEvidence = array_merge($allEvidence, $organ['blocking_evidence_ids']);
        }

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'organs' => $organs,
            'required_capabilities' => array_values(array_unique($allCapabilities)),
            'non_authorities' => $this->aggregateNonAuthorities($organs),
            'evidence_expectations' => array_values(array_unique($allEvidence)),
            'proof_summary' => sprintf(
                'organs=%d capabilities=%d evidence_ids=%d',
                count($organs),
                count(array_unique($allCapabilities)),
                count(array_unique($allEvidence)),
            ),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function organs(): array
    {
        return [
            $this->organ(self::ORGAN_CORTEX,
                'Read-only context provider; answers "what / where / why" from canonical docs and code intelligence.',
                ['scope_comprehension', 'doc_authority_lookup'],
                ['cortex_context_pack_receipt'],
                ['must_not_mutate_code', 'must_not_call_provider']),

            $this->organ(self::ORGAN_GOAL_AND_VALUE,
                'Records operator goal and the value tree the OS optimizes against.',
                ['goal_capture', 'value_alignment_check'],
                ['goal_value_alignment_receipt'],
                ['must_not_override_operator_petreo']),

            $this->organ(self::ORGAN_STRATEGY_COUNCIL,
                'Evaluates alternatives, picks the highest-leverage move under constitution.',
                ['leverage_analysis', 'strategy_proposal'],
                ['strategy_decision_receipt'],
                ['must_not_skip_architecture_council']),

            $this->organ(self::ORGAN_ARCHITECTURE_COUNCIL,
                'Validates designs against the constitution and reality.',
                ['design_review', 'constitution_check'],
                ['architecture_decision_receipt'],
                ['must_not_promote_designs_with_unmet_evidence']),

            $this->organ(self::ORGAN_TASK_FABRIC,
                'Decomposes designs into self-sufficient packets with allowed_files.',
                ['packet_authoring', 'simplicity_contract_enforcement'],
                ['task_packet_quality_receipt'],
                ['must_not_authorize_petreo_edits_without_operator']),

            $this->organ(self::ORGAN_MAESTRO,
                'Routes packets to workers under tier and behavior policy.',
                ['worker_routing', 'tiering_policy'],
                ['maestro_routing_receipt'],
                ['must_not_serve_blocked_or_quarantined_packets']),

            $this->organ(self::ORGAN_WORKER_SWARM,
                'Native workers implement packets inside allowed_files.',
                ['scoped_implementation', 'scoped_commit'],
                ['scoped_commit_receipt', 'tests_or_gates_result'],
                ['must_not_widen_scope']),

            $this->organ(self::ORGAN_VERIFICATION_COURT,
                'Server-side verification of evidence before merge.',
                ['acceptance_verification', 'gate_re_run'],
                ['verification_court_receipt'],
                ['must_not_self_certify_without_independent_verifier']),

            $this->organ(self::ORGAN_MERGE_GOVERNOR,
                'Decides merge / reject / hold and stamps the result.',
                ['merge_decision', 'rollback_plan_validation'],
                ['merge_governor_receipt'],
                ['must_not_merge_without_verification_pass']),

            $this->organ(self::ORGAN_RECEIPTS,
                'Append-only evidence ledger for every load-bearing decision.',
                ['append_only_ledger_write'],
                ['receipts_ledger_receipt'],
                ['must_not_mutate_or_delete_receipts']),

            $this->organ(self::ORGAN_LEARNING_TRANSFER,
                'Routes give-back diagnostics and outcomes into future-affecting policy.',
                ['outcome_recording', 'policy_update_proposal'],
                ['learning_transfer_receipt'],
                ['must_not_apply_policy_without_architecture_council']),

            $this->organ(self::ORGAN_AUTOPOIESIS_LOOP,
                'Self-modification loop that improves the OS within the constitution.',
                ['self_modification_under_constitution'],
                ['autopoiesis_loop_receipt'],
                ['must_not_self_edit_petreo_core']),

            $this->organ(self::ORGAN_DOCS_KNOWLEDGE_SYNC,
                'Keeps canonical docs, code intelligence and KB in sync.',
                ['docs_health_sync', 'kb_sync', 'code_intelligence_sync'],
                ['docs_knowledge_sync_receipt'],
                ['must_not_declare_ready_with_stale_kb']),

            $this->organ(self::ORGAN_OPERATOR_VISIBILITY,
                'Surfaces facts the operator needs to inspect from a phone or desktop.',
                ['operator_dossier_export'],
                ['operator_visibility_receipt'],
                ['must_not_swallow_safety_facts']),

            $this->organ(self::ORGAN_MULTI_PROJECT_STEWARDSHIP,
                'Isolates external project lanes and prevents cross-lane leakage.',
                ['project_lane_isolation', 'cross_lane_leak_guard'],
                ['multi_project_stewardship_receipt'],
                ['must_not_share_workspace_across_lanes']),

            $this->organ(self::ORGAN_CODE_INTELLIGENCE,
                'Indexed code graph used by Cortex and by every readiness check.',
                ['code_index_freshness', 'schema_drift_audit'],
                ['code_intelligence_readiness_receipt'],
                ['must_not_serve_stale_or_drifted_index']),

            $this->organ(self::ORGAN_FINAL_COMPLETION,
                'Composes evidence, gates and ledgers into the final ready / hold / blocked verdict.',
                ['final_evidence_composition', 'final_verdict_emission'],
                ['final_completion_receipt'],
                ['must_not_emit_ready_with_any_blocking_gate_open']),
        ];
    }

    /**
     * Compact completion view over the canonical organ map.
     *
     * INPUT:
     *   implemented  — organ_ids with a shipped implementation
     *   has_tests    — organ_ids with passing test coverage
     *   wired        — organ_ids wired into the live runtime
     *
     * OUTPUT (facts-only, no decisions):
     *   total_organs, implemented_organs, missing_organs,
     *   missing_tests (implemented but no test), unwired_organs (implemented but not wired),
     *   completion_pct (implemented / total * 100, two decimal places)
     *
     * @param  array{implemented?:list<string>, has_tests?:list<string>, wired?:list<string>}  $facts
     * @return array<string,mixed>
     */
    public function coverageView(array $facts): array
    {
        $allIds = array_column($this->organs(), 'organ_id');
        $implemented = array_values(array_intersect($allIds, (array) ($facts['implemented'] ?? [])));
        $hasTests    = array_flip((array) ($facts['has_tests'] ?? []));
        $wired       = array_flip((array) ($facts['wired'] ?? []));

        $missing      = array_values(array_diff($allIds, $implemented));
        $missingTests = array_values(array_filter($implemented, static fn (string $id): bool => ! isset($hasTests[$id])));
        $unwired      = array_values(array_filter($implemented, static fn (string $id): bool => ! isset($wired[$id])));

        $total = count($allIds);
        $completionPct = $total > 0 ? round(count($implemented) / $total * 100, 2) : 0.0;

        return [
            'schema'              => self::SCHEMA,
            'total_organs'        => $total,
            'implemented_organs'  => $implemented,
            'missing_organs'      => $missing,
            'missing_tests'       => $missingTests,
            'unwired_organs'      => $unwired,
            'completion_pct'      => $completionPct,
        ];
    }

    /**
     * Returns all capability gaps ranked by urgency: missing (1) > untested (2) > unwired (3).
     *
     * @param  array{implemented?:list<string>, has_tests?:list<string>, wired?:list<string>}  $facts
     * @return list<array{organ_id:string, gap_type:string, rank:int}>
     */
    public function rankedGaps(array $facts): array
    {
        $view = $this->coverageView($facts);
        $gaps = [];

        foreach ($view['missing_organs'] as $id) {
            $gaps[] = ['organ_id' => $id, 'gap_type' => 'missing', 'rank' => 1];
        }
        foreach ($view['missing_tests'] as $id) {
            $gaps[] = ['organ_id' => $id, 'gap_type' => 'untested', 'rank' => 2];
        }
        $missingTestIds = array_flip($view['missing_tests']);
        foreach ($view['unwired_organs'] as $id) {
            if (! isset($missingTestIds[$id])) {
                $gaps[] = ['organ_id' => $id, 'gap_type' => 'unwired', 'rank' => 3];
            }
        }

        usort($gaps, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return array_values($gaps);
    }

    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $evidenceIds
     * @param  list<string>  $nonAuthorities
     * @return array<string,mixed>
     */
    private function organ(
        string $id,
        string $purpose,
        array $capabilities,
        array $evidenceIds,
        array $nonAuthorities,
    ): array {
        return [
            'organ_id' => $id,
            'purpose' => $purpose,
            'required_task_tags' => array_values(array_unique(array_merge(['self_construction'], [$id]))),
            'required_capabilities' => $capabilities,
            'blocking_evidence_ids' => $evidenceIds,
            'non_authorities' => $nonAuthorities,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $organs
     * @return list<string>
     */
    private function aggregateNonAuthorities(array $organs): array
    {
        $all = [];
        foreach ($organs as $organ) {
            $all = array_merge($all, $organ['non_authorities']);
        }

        return array_values(array_unique($all));
    }
}
