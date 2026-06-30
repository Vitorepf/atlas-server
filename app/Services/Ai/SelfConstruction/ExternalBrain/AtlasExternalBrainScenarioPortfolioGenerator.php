<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Generates deterministic scenario portfolios for evaluating brain-originated batches.
 *
 * Six canonical scenario families:
 *   high_yield           — many high-leverage tasks, good learning signal
 *   low_yield            — bug-hunt-only, no evolutionary leap
 *   cross_project        — evolution spanning multiple Atlas projects
 *   blocked_poison       — queue poisoned by give_back loops / stalled yield
 *   stale_doc            — docs drift from implementation; certification blocked
 *   architecture_leap    — qualitative architectural advance, anti-Goodhart proof required
 *
 * Each scenario carries:
 *   scenario_id      — unique string identifier
 *   family           — one of the six family constants
 *   description      — human-readable summary
 *   failure_modes    — what can go wrong in this scenario
 *   evidence_inputs  — data shape needed to exercise the scenario
 *   success_criteria — falsifiable conditions that mark the scenario passed
 *
 * DEDUPLICATION: generate() accepts optional extra scenarios and drops any
 * that are structurally identical to an already-added entry (same family +
 * sorted failure_modes + sorted success_criteria), regardless of scenario_id.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainScenarioPortfolioGenerator
{
    public const SCHEMA = 'atlas.external_brain.scenario_portfolio_generator.v1';

    public const FAMILY_HIGH_YIELD        = 'high_yield';
    public const FAMILY_LOW_YIELD         = 'low_yield';
    public const FAMILY_CROSS_PROJECT     = 'cross_project';
    public const FAMILY_BLOCKED_POISON    = 'blocked_poison';
    public const FAMILY_STALE_DOC         = 'stale_doc';
    public const FAMILY_ARCHITECTURE_LEAP = 'architecture_leap';

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
     * }
     */
    public function generate(array $additionalScenarios = []): array
    {
        $base = [
            $this->highYieldScenario(),
            $this->lowYieldScenario(),
            $this->crossProjectScenario(),
            $this->blockedPoisonScenario(),
            $this->staleDocScenario(),
            $this->architectureLeapScenario(),
        ];

        $seen      = [];
        $accepted  = [];
        $rejected  = [];

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
            $seen[$fp] = true;
            $accepted[] = $scenario;
        }

        return [
            'schema'              => self::SCHEMA,
            'scenarios'           => $accepted,
            'rejected_duplicates' => $rejected,
            'total_emitted'       => count($accepted),
            'family_coverage'     => array_values(array_unique(array_column($accepted, 'family'))),
        ];
    }

    private function highYieldScenario(): array
    {
        return [
            'scenario_id'     => 'scenario:high_yield:v1',
            'family'          => self::FAMILY_HIGH_YIELD,
            'description'     => 'Brain batch is dense with high-leverage evolution tasks and a rich learning ledger.',
            'failure_modes'   => ['quota_padding', 'false_high_leverage_claim', 'no_measurable_capability_delta'],
            'evidence_inputs' => [
                'task_batch_size'         => 8,
                'high_leverage_ratio'     => 0.75,
                'architecture_task_count' => 2,
                'ledger_outcome_count'    => 10,
                'anti_goodhart_verdict'   => 'pass',
            ],
            'success_criteria' => [
                'at_least_70pct_tasks_high_leverage',
                'at_least_1_architecture_task_in_batch',
                'ledger_has_at_least_5_outcomes',
                'anti_goodhart_verdict_is_pass',
            ],
        ];
    }

    private function lowYieldScenario(): array
    {
        return [
            'scenario_id'     => 'scenario:low_yield:v1',
            'family'          => self::FAMILY_LOW_YIELD,
            'description'     => 'Brain batch is dominated by low-leverage bug fixes with no evolutionary leap.',
            'failure_modes'   => ['bug_hunt_only', 'no_evolutionary_leap', 'quota_padding'],
            'evidence_inputs' => [
                'task_batch_size'         => 6,
                'high_leverage_ratio'     => 0.0,
                'architecture_task_count' => 0,
                'ledger_outcome_count'    => 2,
                'anti_goodhart_verdict'   => 'repair_required',
            ],
            'success_criteria' => [
                'batch_classified_as_bug_hunt_only_or_low_yield',
                'no_evolutionary_leap_dimension_fails',
                'brain_recognizes_and_flags_the_pattern',
            ],
        ];
    }

    private function crossProjectScenario(): array
    {
        return [
            'scenario_id'     => 'scenario:cross_project:v1',
            'family'          => self::FAMILY_CROSS_PROJECT,
            'description'     => 'Batch spans at least two distinct Atlas projects with high-leverage evolution tasks.',
            'failure_modes'   => ['single_project_isolation', 'missing_wiring_across_projects', 'cross_project_claim_without_evidence'],
            'evidence_inputs' => [
                'distinct_project_count'   => 3,
                'projects'                 => ['atlas-server', 'atlas-desktop', 'atlas-app'],
                'cross_project_task_count' => 2,
                'high_leverage_ratio'      => 0.80,
            ],
            'success_criteria' => [
                'at_least_2_distinct_projects_in_batch',
                'at_least_1_high_leverage_cross_project_task',
                'cross_project_reach_dimension_passes',
            ],
        ];
    }

    private function blockedPoisonScenario(): array
    {
        return [
            'scenario_id'     => 'scenario:blocked_poison:v1',
            'family'          => self::FAMILY_BLOCKED_POISON,
            'description'     => 'Queue poisoned by give_back loops or stalled yield; brain must detect and report, not loop forever.',
            'failure_modes'   => ['queue_jam', 'give_back_loop', 'stalled_yield', 'zombie_task_accumulation'],
            'evidence_inputs' => [
                'queue_status'    => 'stalled',
                'give_back_rate'  => 0.60,
                'stalled_yield'   => true,
                'poison_patterns' => ['scope_repair_doomed', 'multi_file_forbidden'],
            ],
            'success_criteria' => [
                'poison_packets_identified_with_reason',
                'give_back_rate_below_20pct_after_drain',
                'queue_health_returns_to_healthy',
                'no_infinite_loop_on_give_back',
            ],
        ];
    }

    private function staleDocScenario(): array
    {
        return [
            'scenario_id'     => 'scenario:stale_doc:v1',
            'family'          => self::FAMILY_STALE_DOC,
            'description'     => 'Documentation has drifted from implementation; DocSyncDrafter must produce a proposal that clears certification blockers.',
            'failure_modes'   => ['doc_drift', 'certification_blocked_by_stale_doc', 'readiness_claim_overclaims_final'],
            'evidence_inputs' => [
                'doc_proposal_drafted'        => false,
                'doc_certification_blocked'   => true,
                'maturity_band'               => 'functional',
                'evidence_gaps'               => ['architecture_section_missing', 'limitation_section_outdated'],
            ],
            'success_criteria' => [
                'doc_proposal_drafted_becomes_true',
                'certification_blocked_clears_after_proposal',
                'all_four_doc_sections_present_in_proposal',
                'readiness_claim_does_not_overclaim_95pct',
            ],
        ];
    }

    private function architectureLeapScenario(): array
    {
        return [
            'scenario_id'     => 'scenario:architecture_leap:v1',
            'family'          => self::FAMILY_ARCHITECTURE_LEAP,
            'description'     => 'Batch contains a qualitative architectural advance; anti-Goodhart and capability_delta proof required.',
            'failure_modes'   => ['incremental_not_leap', 'goodhart_proxy', 'capability_delta_vague_or_missing'],
            'evidence_inputs' => [
                'anti_goodhart_verdict'    => 'pass',
                'capability_delta_present' => true,
                'architecture_task_count'  => 2,
                'acceptance_path_falsifiable' => true,
            ],
            'success_criteria' => [
                'at_least_1_high_leverage_architecture_task',
                'anti_goodhart_audit_passes',
                'capability_delta_is_measurable_before_after_claim',
                'acceptance_path_is_falsifiable_not_vague',
            ],
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
