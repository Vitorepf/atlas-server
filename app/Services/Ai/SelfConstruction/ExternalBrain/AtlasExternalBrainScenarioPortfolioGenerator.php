<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Generates deterministic scenario portfolios for evaluating brain-originated batches.
 *
 * Twelve canonical scenario families (7 original + 5 post-muscle outcome classes):
 *   high_yield              — many high-leverage tasks, good learning signal
 *   low_yield               — bug-hunt-only, no evolutionary leap
 *   adversarial             — noisy fabricated input, Goodhart disguise
 *   cross_project           — evolution spanning multiple Atlas projects
 *   blocked_poison          — queue poisoned by give_back loops / stalled yield
 *   stale_doc               — docs drift from implementation; certification blocked
 *   architecture_leap       — qualitative architectural advance, anti-Goodhart proof required
 *   success_low_impact      — tasks succeed but capability delta is negligible (proxy-success trap)
 *   give_back_diagnostic    — give_back fires but brain delivers actionable root-cause diagnosis
 *   quarantine_respec       — task quarantined; brain produces a valid respec that unblocks it
 *   proxy_green_commit      — proxy metrics pass (green tests / lint) but real impact is absent
 *   model_tier_failure      — brain output quality degrades because model tier is insufficient
 *
 * Each scenario carries:
 *   scenario_id      — unique string identifier
 *   family           — one of the twelve family constants
 *   description      — human-readable summary
 *   failure_modes    — what can go wrong in this scenario
 *   evidence_inputs  — data shape needed to exercise the scenario
 *   success_criteria — falsifiable conditions that mark the scenario passed
 *   evidence_floor   — minimum evidence shape to exercise this scenario
 *   expected_failure_mode — the most likely failure mode
 *   capability_delta — measurable before→after change
 *
 * DEDUPLICATION: generate() accepts optional extra scenarios and drops any
 * that are structurally identical to an already-added entry (same family +
 * sorted failure_modes + sorted success_criteria), regardless of scenario_id.
 *
 * NARROWNESS DETECTION: assessPortfolio() checks a caller-supplied scenario set
 * against the canonical family list and reports which families are absent.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainScenarioPortfolioGenerator
{
    public const SCHEMA = 'atlas.external_brain.scenario_portfolio_generator.v1';

    // ── original 7 families ───────────────────────────────────────────────────
    public const FAMILY_HIGH_YIELD        = 'high_yield';
    public const FAMILY_LOW_YIELD         = 'low_yield';
    public const FAMILY_ADVERSARIAL       = 'adversarial';
    public const FAMILY_CROSS_PROJECT     = 'cross_project';
    public const FAMILY_BLOCKED_POISON    = 'blocked_poison';
    public const FAMILY_STALE_DOC         = 'stale_doc';
    public const FAMILY_ARCHITECTURE_LEAP = 'architecture_leap';

    // ── 5 post-muscle outcome classes ────────────────────────────────────────
    public const FAMILY_SUCCESS_LOW_IMPACT   = 'success_low_impact';
    public const FAMILY_GIVE_BACK_DIAGNOSTIC = 'give_back_diagnostic';
    public const FAMILY_QUARANTINE_RESPEC    = 'quarantine_respec';
    public const FAMILY_PROXY_GREEN_COMMIT   = 'proxy_green_commit';
    public const FAMILY_MODEL_TIER_FAILURE   = 'model_tier_failure';

    /** All canonical families — used for narrowness detection. */
    public const CANONICAL_FAMILIES = [
        self::FAMILY_HIGH_YIELD,
        self::FAMILY_LOW_YIELD,
        self::FAMILY_ADVERSARIAL,
        self::FAMILY_CROSS_PROJECT,
        self::FAMILY_BLOCKED_POISON,
        self::FAMILY_STALE_DOC,
        self::FAMILY_ARCHITECTURE_LEAP,
        self::FAMILY_SUCCESS_LOW_IMPACT,
        self::FAMILY_GIVE_BACK_DIAGNOSTIC,
        self::FAMILY_QUARANTINE_RESPEC,
        self::FAMILY_PROXY_GREEN_COMMIT,
        self::FAMILY_MODEL_TIER_FAILURE,
    ];

    /**
     * Generate the canonical portfolio plus any caller-supplied additions.
     * Duplicate scenarios (same structure, different id) are silently dropped
     * and reported in `rejected_duplicates`.
     *
     * @param  list<array<string,mixed>>  $additionalScenarios
     * @return array{
     *     schema: string,
     *     scenarios: list<array<string,mixed>>,
     *     rejected_duplicates: list<array{scenario_id:string, fingerprint:string, reason:string}>,
     *     total_emitted: int,
     *     family_coverage: list<string>,
     *     missing_scenario_families: list<string>,
     * }
     */
    public function generate(array $additionalScenarios = []): array
    {
        $base = [
            $this->highYieldScenario(),
            $this->lowYieldScenario(),
            $this->adversarialScenario(),
            $this->crossProjectScenario(),
            $this->blockedPoisonScenario(),
            $this->staleDocScenario(),
            $this->architectureLeapScenario(),
            $this->successLowImpactScenario(),
            $this->giveBackDiagnosticScenario(),
            $this->quarantineRespecScenario(),
            $this->proxyGreenCommitScenario(),
            $this->modelTierFailureScenario(),
        ];

        $seen     = [];
        $accepted = [];
        $rejected = [];

        foreach (array_merge($base, $additionalScenarios) as $scenario) {
            $fp = $this->fingerprint($scenario);
            if (isset($seen[$fp])) {
                $rejected[] = [
                    'scenario_id' => (string) ($scenario['scenario_id'] ?? 'unknown'),
                    'fingerprint' => $fp,
                    'reason'      => 'duplicate_structure:family+failure_modes+success_criteria identical to existing scenario',
                ];
                continue;
            }
            $seen[$fp]  = true;
            $accepted[] = $scenario;
        }

        $coverage = array_values(array_unique(array_column($accepted, 'family')));
        $missing  = array_values(array_diff(self::CANONICAL_FAMILIES, $coverage));

        return [
            'schema'                    => self::SCHEMA,
            'scenarios'                 => $accepted,
            'rejected_duplicates'       => $rejected,
            'total_emitted'             => count($accepted),
            'family_coverage'           => $coverage,
            'missing_scenario_families' => $missing,
        ];
    }

    /**
     * Check how narrow a caller-supplied scenario set is against the canonical families.
     *
     * @param  list<array<string,mixed>>  $callerScenarios
     * @return array{
     *     required_families: list<string>,
     *     covered_families: list<string>,
     *     missing_scenario_families: list<string>,
     *     too_narrow: bool,
     * }
     */
    public function assessPortfolio(array $callerScenarios): array
    {
        $covered = array_values(array_unique(array_column($callerScenarios, 'family')));
        $missing = array_values(array_diff(self::CANONICAL_FAMILIES, $covered));

        return [
            'required_families'         => self::CANONICAL_FAMILIES,
            'covered_families'          => $covered,
            'missing_scenario_families' => $missing,
            'too_narrow'                => $missing !== [],
        ];
    }

    private function highYieldScenario(): array
    {
        return [
            'scenario_id'           => 'scenario:high_yield:v1',
            'family'                => self::FAMILY_HIGH_YIELD,
            'description'           => 'Brain batch is dense with high-leverage evolution tasks and a rich learning ledger.',
            'failure_modes'         => ['quota_padding', 'false_high_leverage_claim', 'no_measurable_capability_delta'],
            'evidence_inputs'       => [
                'task_batch_size'         => 8,
                'high_leverage_ratio'     => 0.75,
                'architecture_task_count' => 2,
                'ledger_outcome_count'    => 10,
                'anti_goodhart_verdict'   => 'pass',
            ],
            'success_criteria'      => [
                'at_least_70pct_tasks_high_leverage',
                'at_least_1_architecture_task_in_batch',
                'ledger_has_at_least_5_outcomes',
                'anti_goodhart_verdict_is_pass',
            ],
            'evidence_floor'        => 'high_leverage_ratio:gte_0.70 AND ledger_outcome_count:gte_5',
            'expected_failure_mode' => 'quota_padding',
            'capability_delta'      => 'high_leverage_ratio sustains above 0.70 with anti_goodhart_verdict:pass',
        ];
    }

    private function lowYieldScenario(): array
    {
        return [
            'scenario_id'           => 'scenario:low_yield:v1',
            'family'                => self::FAMILY_LOW_YIELD,
            'description'           => 'Brain batch is dominated by low-leverage bug fixes with no evolutionary leap.',
            'failure_modes'         => ['bug_hunt_only', 'no_evolutionary_leap', 'quota_padding'],
            'evidence_inputs'       => [
                'task_batch_size'         => 6,
                'high_leverage_ratio'     => 0.0,
                'architecture_task_count' => 0,
                'ledger_outcome_count'    => 2,
                'anti_goodhart_verdict'   => 'repair_required',
            ],
            'success_criteria'      => [
                'batch_classified_as_bug_hunt_only_or_low_yield',
                'no_evolutionary_leap_dimension_fails',
                'brain_recognizes_and_flags_the_pattern',
            ],
            'evidence_floor'        => 'high_leverage_ratio:lt_0.20 AND architecture_task_count:0',
            'expected_failure_mode' => 'bug_hunt_only',
            'capability_delta'      => 'low_yield_pattern_detection_rate increases from 0 to gt_0.80',
        ];
    }

    private function adversarialScenario(): array
    {
        return [
            'scenario_id'           => 'scenario:adversarial:v1',
            'family'                => self::FAMILY_ADVERSARIAL,
            'description'           => 'Brain receives noisy adversarial input; must reject fabricated claims and flag anti-Goodhart violations.',
            'failure_modes'         => ['hallucinated_evidence', 'false_positive_capability_claim', 'goodhart_proxy_disguised_as_leap'],
            'evidence_inputs'       => [
                'noise_ratio'              => 0.40,
                'fabricated_evidence_refs' => true,
                'anti_goodhart_verdict'    => 'fail',
            ],
            'success_criteria'      => [
                'adversarial_inputs_flagged_with_reason',
                'anti_goodhart_violation_detected_and_blocked',
                'no_fabricated_evidence_accepted',
            ],
            'evidence_floor'        => 'anti_goodhart_verdict:fail AND noise_ratio:gte_0.30',
            'expected_failure_mode' => 'goodhart_proxy_disguised_as_leap',
            'capability_delta'      => 'adversarial_input_detection_rate increases from 0 to gt_0.90',
        ];
    }

    private function crossProjectScenario(): array
    {
        return [
            'scenario_id'           => 'scenario:cross_project:v1',
            'family'                => self::FAMILY_CROSS_PROJECT,
            'description'           => 'Batch spans at least two distinct Atlas projects with high-leverage evolution tasks.',
            'failure_modes'         => ['single_project_isolation', 'missing_wiring_across_projects', 'cross_project_claim_without_evidence'],
            'evidence_inputs'       => [
                'distinct_project_count'   => 3,
                'projects'                 => ['atlas-server', 'atlas-desktop', 'atlas-app'],
                'cross_project_task_count' => 2,
                'high_leverage_ratio'      => 0.80,
            ],
            'success_criteria'      => [
                'at_least_2_distinct_projects_in_batch',
                'at_least_1_high_leverage_cross_project_task',
                'cross_project_reach_dimension_passes',
            ],
            'evidence_floor'        => 'distinct_project_count:gte_2 AND cross_project_task_count:gte_1',
            'expected_failure_mode' => 'single_project_isolation',
            'capability_delta'      => 'cross_project_reach_dimension passes with at_least_2_projects',
        ];
    }

    private function blockedPoisonScenario(): array
    {
        return [
            'scenario_id'           => 'scenario:blocked_poison:v1',
            'family'                => self::FAMILY_BLOCKED_POISON,
            'description'           => 'Queue poisoned by give_back loops or stalled yield; brain must detect and report, not loop forever.',
            'failure_modes'         => ['queue_jam', 'give_back_loop', 'stalled_yield', 'zombie_task_accumulation'],
            'evidence_inputs'       => [
                'queue_status'    => 'stalled',
                'give_back_rate'  => 0.60,
                'stalled_yield'   => true,
                'poison_patterns' => ['scope_repair_doomed', 'multi_file_forbidden'],
            ],
            'success_criteria'      => [
                'poison_packets_identified_with_reason',
                'give_back_rate_below_20pct_after_drain',
                'queue_health_returns_to_healthy',
                'no_infinite_loop_on_give_back',
            ],
            'evidence_floor'        => 'give_back_rate:gte_0.50 AND queue_status:stalled',
            'expected_failure_mode' => 'give_back_loop',
            'capability_delta'      => 'give_back_rate drops below 0.20 after brain drain intervention',
        ];
    }

    private function staleDocScenario(): array
    {
        return [
            'scenario_id'           => 'scenario:stale_doc:v1',
            'family'                => self::FAMILY_STALE_DOC,
            'description'           => 'Documentation has drifted from implementation; DocSyncDrafter must produce a proposal that clears certification blockers.',
            'failure_modes'         => ['doc_drift', 'certification_blocked_by_stale_doc', 'readiness_claim_overclaims_final'],
            'evidence_inputs'       => [
                'doc_proposal_drafted'      => false,
                'doc_certification_blocked' => true,
                'maturity_band'             => 'functional',
                'evidence_gaps'             => ['architecture_section_missing', 'limitation_section_outdated'],
            ],
            'success_criteria'      => [
                'doc_proposal_drafted_becomes_true',
                'certification_blocked_clears_after_proposal',
                'all_four_doc_sections_present_in_proposal',
                'readiness_claim_does_not_overclaim_95pct',
            ],
            'evidence_floor'        => 'doc_certification_blocked:true AND evidence_gaps:non_empty',
            'expected_failure_mode' => 'doc_drift',
            'capability_delta'      => 'doc_proposal_drafted becomes true and certification_blocked clears',
        ];
    }

    private function architectureLeapScenario(): array
    {
        return [
            'scenario_id'           => 'scenario:architecture_leap:v1',
            'family'                => self::FAMILY_ARCHITECTURE_LEAP,
            'description'           => 'Batch contains a qualitative architectural advance; anti-Goodhart and capability_delta proof required.',
            'failure_modes'         => ['incremental_not_leap', 'goodhart_proxy', 'capability_delta_vague_or_missing'],
            'evidence_inputs'       => [
                'anti_goodhart_verdict'       => 'pass',
                'capability_delta_present'    => true,
                'architecture_task_count'     => 2,
                'acceptance_path_falsifiable' => true,
            ],
            'success_criteria'      => [
                'at_least_1_high_leverage_architecture_task',
                'anti_goodhart_audit_passes',
                'capability_delta_is_measurable_before_after_claim',
                'acceptance_path_is_falsifiable_not_vague',
            ],
            'evidence_floor'        => 'anti_goodhart_verdict:pass AND capability_delta_present:true',
            'expected_failure_mode' => 'incremental_not_leap',
            'capability_delta'      => 'qualitative architecture advance proven with measurable before_after delta',
        ];
    }

    private function successLowImpactScenario(): array
    {
        return [
            'scenario_id'           => 'scenario:success_low_impact:v1',
            'family'                => self::FAMILY_SUCCESS_LOW_IMPACT,
            'description'           => 'Tasks complete successfully (green tests, committed) but capability delta is negligible — the proxy-success trap.',
            'failure_modes'         => ['proxy_success_accepted_as_real', 'impact_metric_missing', 'low_leverage_disguised_as_success'],
            'evidence_inputs'       => [
                'task_outcome'           => 'success',
                'tests_green'            => true,
                'committed'              => true,
                'capability_delta_score' => 0.02,
                'leverage_score'         => 0.10,
            ],
            'success_criteria'      => [
                'brain_detects_low_capability_delta_despite_green_outcome',
                'impact_score_below_threshold_flagged',
                'batch_not_promoted_to_high_yield_without_real_delta',
            ],
            'evidence_floor'        => 'task_outcome:success AND capability_delta_score:lt_0.10 AND leverage_score:lt_0.20',
            'expected_failure_mode' => 'proxy_success_accepted_as_real',
            'capability_delta'      => 'brain distinguishes green-test success from real capability advance',
        ];
    }

    private function giveBackDiagnosticScenario(): array
    {
        return [
            'scenario_id'           => 'scenario:give_back_diagnostic:v1',
            'family'                => self::FAMILY_GIVE_BACK_DIAGNOSTIC,
            'description'           => 'Worker gives back a task; brain provides an actionable root-cause diagnosis that unblocks the queue.',
            'failure_modes'         => ['give_back_without_diagnosis', 'generic_error_only', 'root_cause_missing_from_report'],
            'evidence_inputs'       => [
                'task_outcome'       => 'give_back',
                'give_back_reason'   => 'scope_repair_doomed',
                'diagnostic_emitted' => true,
                'repair_action'      => 'narrow_allowed_files',
            ],
            'success_criteria'      => [
                'give_back_has_structured_root_cause',
                'diagnostic_contains_repair_action',
                'queue_unblocked_after_applying_repair_action',
            ],
            'evidence_floor'        => 'task_outcome:give_back AND diagnostic_emitted:true',
            'expected_failure_mode' => 'give_back_without_diagnosis',
            'capability_delta'      => 'give_back-with-diagnosis unblock rate increases vs give_back-without',
        ];
    }

    private function quarantineRespecScenario(): array
    {
        return [
            'scenario_id'           => 'scenario:quarantine_respec:v1',
            'family'                => self::FAMILY_QUARANTINE_RESPEC,
            'description'           => 'Task quarantined due to repeated failure; brain produces a valid respec that unblocks it.',
            'failure_modes'         => ['respec_too_similar_to_original', 'quarantine_without_respec', 'respec_unverified'],
            'evidence_inputs'       => [
                'quarantine_count'      => 3,
                'respec_proposed'       => true,
                'respec_diff_from_orig' => true,
                'respec_verified'       => true,
            ],
            'success_criteria'      => [
                'respec_is_structurally_different_from_original',
                'respec_passes_admission_gate',
                'task_unquarantined_after_respec',
            ],
            'evidence_floor'        => 'quarantine_count:gte_2 AND respec_proposed:true',
            'expected_failure_mode' => 'respec_too_similar_to_original',
            'capability_delta'      => 'quarantine-to-respec unblock rate reaches gt_0.70',
        ];
    }

    private function proxyGreenCommitScenario(): array
    {
        return [
            'scenario_id'           => 'scenario:proxy_green_commit:v1',
            'family'                => self::FAMILY_PROXY_GREEN_COMMIT,
            'description'           => 'Proxy metrics (tests green, lint clean) pass but real impact is absent — the Goodhart commit trap.',
            'failure_modes'         => ['green_tests_mask_zero_impact', 'lint_compliance_mistaken_for_quality', 'commit_rate_proxy_gaming'],
            'evidence_inputs'       => [
                'tests_green'       => true,
                'lint_clean'        => true,
                'commit_merged'     => true,
                'impact_score'      => 0.0,
                'anti_goodhart_verdict' => 'fail',
            ],
            'success_criteria'      => [
                'anti_goodhart_audit_detects_proxy_gaming',
                'commit_not_counted_as_high_leverage',
                'impact_score_gate_rejects_zero_impact_commits',
            ],
            'evidence_floor'        => 'tests_green:true AND impact_score:lte_0.05 AND anti_goodhart_verdict:fail',
            'expected_failure_mode' => 'green_tests_mask_zero_impact',
            'capability_delta'      => 'proxy_green_commit detection rate increases from 0 to gt_0.85',
        ];
    }

    private function modelTierFailureScenario(): array
    {
        return [
            'scenario_id'           => 'scenario:model_tier_failure:v1',
            'family'                => self::FAMILY_MODEL_TIER_FAILURE,
            'description'           => 'Brain output quality degrades because the model tier is insufficient for the task complexity.',
            'failure_modes'         => ['insufficient_model_tier', 'task_complexity_exceeds_model_capability', 'silent_quality_degradation'],
            'evidence_inputs'       => [
                'model_tier'         => 'small',
                'task_complexity'    => 'high',
                'output_quality'     => 0.35,
                'escalation_triggered' => false,
            ],
            'success_criteria'      => [
                'quality_degradation_detected_before_commit',
                'escalation_triggered_for_insufficient_tier',
                'task_not_committed_with_low_quality_output',
            ],
            'evidence_floor'        => 'model_tier:small AND task_complexity:high AND output_quality:lt_0.50',
            'expected_failure_mode' => 'insufficient_model_tier',
            'capability_delta'      => 'model-tier-failure detection triggers escalation in gt_0.90 of cases',
        ];
    }

    private function fingerprint(array $scenario): string
    {
        $family          = (string) ($scenario['family'] ?? '');
        $failureModes    = (array) ($scenario['failure_modes'] ?? []);
        $successCriteria = (array) ($scenario['success_criteria'] ?? []);
        sort($failureModes);
        sort($successCriteria);

        return md5($family.'|'.implode(',', $failureModes).'|'.implode(',', $successCriteria));
    }
}
