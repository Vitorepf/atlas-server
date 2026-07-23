<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Frontend\RivalReplay;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Frontend\AtlasFrontendCompetitiveRubricService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidencePackVerifierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRivalReplayHarnessService;
use Illuminate\Support\Facades\File;

/**
 * Rival-replay competitive diagnostics section — extracted verbatim from
 * AtlasFrontendRivalReplayHarnessService by the GOD-DEBULK split. Owns the
 * scoreboard, fairness gates, per-case competitive diagnostics, dimension
 * gaps, evidence-pack readiness, the proof contract and remaining-gap
 * routing. DECISIVE_LEAD_MINIMUM_POINTS lives on the façade (FQCN); case
 * and system catalogs route through RivalReplaySupport.
 */
class RivalReplayDiagnosticsSection
{
    public function __construct(
        private readonly RivalReplaySupport $support,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function evidencePackReadiness(string $directory): array
    {
        $packs = [];
        foreach ($this->support->cases() as $case) {
            foreach ($this->support->systems() as $system) {
                $path = $directory.'/'.$case['id'].'/'.$system['id'].'/evidence/evidence-pack.json';
                if (! File::isFile($path)) {
                    $packs[] = [
                        'case_id' => $case['id'],
                        'system' => $system['id'],
                        'status' => 'missing',
                        'blockers' => ['evidence_pack_missing'],
                    ];

                    continue;
                }

                $verification = app(AtlasFrontendEvidencePackVerifierService::class)->verify($path);
                $packs[] = [
                    'case_id' => $case['id'],
                    'system' => $system['id'],
                    'status' => ($verification['status'] ?? null) === 'passed' ? 'passed' : 'blocked',
                    'verification_hash' => $verification['verification_hash'] ?? null,
                    'blockers' => array_values(array_unique((array) ($verification['blockers'] ?? []))),
                    'warnings' => array_values(array_unique((array) ($verification['warnings'] ?? []))),
                ];
            }
        }

        $present = collect($packs)->whereNotIn('status', ['missing'])->count();
        $passed = collect($packs)->where('status', 'passed')->count();
        $blocked = collect($packs)->where('status', 'blocked')->count();
        $missing = collect($packs)->where('status', 'missing')->count();

        return [
            'schema_version' => 'atlas.frontend.rival_replay_evidence_pack_readiness.v1',
            'status' => $passed === count($packs) ? 'ready' : 'pending',
            'summary' => [
                'total' => count($packs),
                'present' => $present,
                'passed' => $passed,
                'blocked' => $blocked,
                'missing' => $missing,
            ],
            'required_artifact_kinds' => app(AtlasFrontendEvidencePackVerifierService::class)->requiredArtifactKinds(),
            'packs' => $packs,
        ];
    }

    /**
     * @param  array<string,mixed>  $evidencePackReadiness
     * @param  array<string,mixed>  $fairness
     * @param  array<string,mixed>  $competitiveDiagnostics
     * @return array<string,mixed>
     */
    public function competitiveProofContract(
        bool $allRunsCompleted,
        bool $externalReplayCompleted,
        bool $atlasWinsAllCompleteCases,
        array $evidencePackReadiness,
        array $fairness,
        array $competitiveDiagnostics,
    ): array {
        $evidenceReady = ($evidencePackReadiness['status'] ?? null) === 'ready';
        $fairnessPassed = ($fairness['status'] ?? null) === 'passed';
        $diagnosticsStatus = (string) ($competitiveDiagnostics['status'] ?? 'pending_replay');
        $worldBestReady = $allRunsCompleted
            && $externalReplayCompleted
            && $evidenceReady
            && $fairnessPassed
            && $atlasWinsAllCompleteCases
            && $diagnosticsStatus === 'atlas_leads_all_complete_cases';

        $nextActions = [];
        if (! $evidenceReady) {
            $nextActions[] = 'fill_and_hash_missing_rival_replay_evidence_packs';
        }
        if (! $externalReplayCompleted) {
            $nextActions[] = 'run_external_rivals_against_unchanged_task_specs';
            $nextActions[] = 'embed_verified_external_execution_receipts';
        }
        if (! $fairnessPassed) {
            $nextActions[] = 'restore_same_task_spec_hash_across_all_systems_per_case';
        }
        if (! $allRunsCompleted) {
            $nextActions[] = 'complete_all_replay_manifests_with_verified_hash_refs';
        }
        if ($allRunsCompleted && ! $atlasWinsAllCompleteCases) {
            $nextActions = array_merge(
                $nextActions,
                collect((array) ($competitiveDiagnostics['cases'] ?? []))
                    ->filter(fn (mixed $case): bool => is_array($case) && ($case['status'] ?? null) !== 'atlas_leads')
                    ->map(fn (array $case): string => (string) ($case['next_action'] ?? 'improve_atlas_frontend_case_until_decisive_lead'))
                    ->filter()
                    ->values()
                    ->all(),
            );
        }
        if ($worldBestReady) {
            $nextActions[] = 'preserve_verified_replay_evidence_and_publish_world_best_proof_packet';
        }

        $repairCommands = collect((array) ($competitiveDiagnostics['cases'] ?? []))
            ->flatMap(fn (mixed $case): array => is_array($case) ? (array) ($case['recommended_repair_plan_commands'] ?? []) : [])
            ->filter(fn (mixed $command): bool => is_string($command) && $command !== '')
            ->unique()
            ->values()
            ->all();

        $contract = [
            'schema_version' => 'atlas.frontend.rival_replay_competitive_proof_contract.v1',
            'status' => match (true) {
                $worldBestReady => 'world_best_proof_ready',
                ! $evidenceReady => 'evidence_packs_required',
                ! $externalReplayCompleted => 'external_replay_receipts_required',
                ! $fairnessPassed => 'fairness_repair_required',
                ! $allRunsCompleted => 'run_manifests_required',
                default => 'atlas_improvement_required',
            },
            'gates' => [
                'evidence_packs_verified' => $evidenceReady,
                'external_rival_execution_receipts_verified' => $externalReplayCompleted,
                'same_task_spec_hash_fairness_passed' => $fairnessPassed,
                'all_run_manifests_complete' => $allRunsCompleted,
                'atlas_decisively_leads_every_case' => $atlasWinsAllCompleteCases,
                'no_dimension_gaps_against_best_rival' => ((int) ($competitiveDiagnostics['dimension_gap_case_count'] ?? 0)) === 0,
            ],
            'minimum_decisive_lead_points' => AtlasFrontendRivalReplayHarnessService::DECISIVE_LEAD_MINIMUM_POINTS,
            'diagnostics_status' => $diagnosticsStatus,
            'next_minimum_actions' => array_values(array_unique($nextActions)),
            'recommended_repair_plan_commands' => $repairCommands,
            'claim_policy' => [
                'contract_is_a_proof_index_not_the_artifacts' => true,
                'may_claim_external_replay_completed' => $externalReplayCompleted,
                'may_claim_world_best_frontend_system' => $worldBestReady,
                'world_best_requires_verified_evidence_pack_for_every_run' => true,
                'world_best_requires_verified_external_receipt_for_every_external_rival_run' => true,
                'world_best_requires_decisive_lead_and_no_dimension_gaps' => true,
                'documentation_only_claim_forbidden' => true,
            ],
        ];
        $contract['proof_contract_hash'] = MissionCanonicalHash::sha256($contract);

        return $contract;
    }

    /**
     * @param  array<int,array<string,mixed>>  $runs
     * @return array<string,mixed>
     */
    public function competitiveDiagnostics(array $runs): array
    {
        $cases = [];
        foreach ($this->support->cases() as $case) {
            $caseRuns = collect($runs)->where('case_id', $case['id']);
            $completeRuns = $caseRuns->where('status', 'complete');
            if ($completeRuns->count() < count($this->support->systems())) {
                $cases[] = [
                    'case_id' => $case['id'],
                    'status' => 'pending',
                    'complete_run_count' => $completeRuns->count(),
                    'required_run_count' => count($this->support->systems()),
                    'next_action' => 'complete_external_rival_replay_manifests',
                ];

                continue;
            }

            $atlas = $completeRuns->firstWhere('system', 'atlas_frontend');
            $rivals = $completeRuns->reject(fn (array $run): bool => $run['system'] === 'atlas_frontend');
            $bestRival = $rivals->sortByDesc(fn (array $run): int => (int) ($run['score_total'] ?? -1))->first();
            $atlasScore = (int) data_get($atlas, 'score_total', 0);
            $bestRivalScore = (int) data_get($bestRival, 'score_total', 0);
            $delta = $atlasScore - $bestRivalScore;
            $hasDecisiveMargin = $delta >= AtlasFrontendRivalReplayHarnessService::DECISIVE_LEAD_MINIMUM_POINTS;
            $dimensionGaps = $this->dimensionGaps(
                is_array($atlas['score_breakdown'] ?? null) ? $atlas['score_breakdown'] : [],
                is_array($bestRival['score_breakdown'] ?? null) ? $bestRival['score_breakdown'] : [],
                $case['id'],
                (string) ($bestRival['system'] ?? ''),
            );

            $cases[] = [
                'case_id' => $case['id'],
                'status' => match (true) {
                    $hasDecisiveMargin && $dimensionGaps === [] => 'atlas_leads',
                    $delta > 0 && $dimensionGaps === [] => 'atlas_leads_without_decisive_margin',
                    $delta > 0 => 'atlas_leads_with_dimension_gaps',
                    $delta === 0 => 'atlas_tied_best',
                    default => 'atlas_loses',
                },
                'atlas_score' => $atlasScore,
                'best_rival_system' => $bestRival['system'] ?? null,
                'best_rival_score' => $bestRivalScore,
                'atlas_delta_vs_best_rival' => $delta,
                'minimum_points_to_match_best_rival' => max(0, $bestRivalScore - $atlasScore),
                'minimum_points_to_lead_best_rival' => max(0, $bestRivalScore - $atlasScore + 1),
                'minimum_decisive_lead_points' => AtlasFrontendRivalReplayHarnessService::DECISIVE_LEAD_MINIMUM_POINTS,
                'minimum_points_to_decisive_lead' => max(0, AtlasFrontendRivalReplayHarnessService::DECISIVE_LEAD_MINIMUM_POINTS - $delta),
                'dimension_gap_count' => count($dimensionGaps),
                'dimension_gaps' => $dimensionGaps,
                'recommended_repair_plan_commands' => array_values(array_filter(array_map(
                    fn (array $gap): ?string => is_string($gap['repair_plan_command'] ?? null) ? (string) $gap['repair_plan_command'] : null,
                    $dimensionGaps,
                ))),
                'next_action' => match (true) {
                    $delta <= 0 => 'improve_atlas_frontend_case_until_decisive_lead',
                    $dimensionGaps !== [] => 'improve_atlas_frontend_case_until_dimension_lead',
                    ! $hasDecisiveMargin => 'improve_atlas_frontend_case_until_minimum_decisive_margin',
                    default => 'preserve_case_evidence',
                },
            ];
        }

        $losing = collect($cases)->where('status', 'atlas_loses')->count();
        $tied = collect($cases)->where('status', 'atlas_tied_best')->count();
        $weakLead = collect($cases)->where('status', 'atlas_leads_without_decisive_margin')->count();
        $dimensionGapCases = collect($cases)->where('status', 'atlas_leads_with_dimension_gaps')->count();
        $pending = collect($cases)->where('status', 'pending')->count();

        return [
            'schema_version' => 'atlas.frontend.rival_replay_competitive_diagnostics.v1',
            'status' => match (true) {
                $losing > 0 => 'atlas_needs_improvement',
                $tied > 0 => 'atlas_needs_decisive_lead',
                $dimensionGapCases > 0 => 'atlas_needs_dimension_lead',
                $weakLead > 0 => 'atlas_needs_decisive_margin',
                $pending > 0 => 'pending_replay',
                default => 'atlas_leads_all_complete_cases',
            },
            'case_count' => count($cases),
            'losing_case_count' => $losing,
            'tied_case_count' => $tied,
            'weak_lead_case_count' => $weakLead,
            'dimension_gap_case_count' => $dimensionGapCases,
            'pending_case_count' => $pending,
            'cases' => $cases,
            'claim_policy' => [
                'diagnostics_are_not_market_claim_evidence' => true,
                'world_best_requires_no_losing_cases' => true,
                'world_best_requires_no_tied_cases' => true,
                'world_best_requires_decisive_lead_each_case' => true,
                'world_best_requires_minimum_decisive_lead_points' => AtlasFrontendRivalReplayHarnessService::DECISIVE_LEAD_MINIMUM_POINTS,
                'world_best_requires_no_dimension_gaps_against_best_rival' => true,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $runs
     * @return array<string,mixed>
     */
    public function scoreboard(array $runs): array
    {
        $scoreboard = [];
        foreach ($runs as $run) {
            $system = (string) $run['system'];
            $scoreboard[$system] ??= ['score' => 0, 'max' => 0, 'complete_runs' => 0];
            if (($run['status'] ?? null) !== 'complete') {
                continue;
            }

            $scoreboard[$system]['score'] += (int) ($run['score_total'] ?? 0);
            $scoreboard[$system]['max'] += (int) ($run['score_max'] ?? 0);
            $scoreboard[$system]['complete_runs']++;
        }

        foreach ($scoreboard as $system => $data) {
            $scoreboard[$system]['percent'] = round(((int) $data['score'] / max(1, (int) $data['max'])) * 100, 2);
        }

        return $scoreboard;
    }

    /**
     * @param  array<int,array<string,mixed>>  $runs
     * @return array<int,array<string,mixed>>
     */
    public function applyFairnessGates(array $runs): array
    {
        foreach ($this->support->cases() as $case) {
            $evaluatedIndexes = [];
            foreach ($runs as $index => $run) {
                if (
                    ($run['case_id'] ?? null) === $case['id']
                    && isset($run['task_spec_hash'])
                    && in_array(($run['status'] ?? null), ['complete', 'invalid'], true)
                ) {
                    $evaluatedIndexes[] = $index;
                }
            }

            if (count($evaluatedIndexes) < count($this->support->systems())) {
                continue;
            }

            $taskSpecHashes = array_values(array_unique(array_map(
                fn (int $index): string => (string) ($runs[$index]['task_spec_hash'] ?? ''),
                $evaluatedIndexes,
            )));

            if (count($taskSpecHashes) > 1) {
                foreach ($evaluatedIndexes as $index) {
                    $runs[$index] = $this->invalidateRun($runs[$index], 'task_spec_hash_mismatch_across_systems');
                }
            }
        }

        return array_values($runs);
    }

    /**
     * @param  array<int,array<string,mixed>>  $runs
     * @return array<string,mixed>
     */
    public function fairnessSummary(array $runs): array
    {
        $cases = [];
        foreach ($this->support->cases() as $case) {
            $caseRuns = collect($runs)->where('case_id', $case['id']);
            $complete = $caseRuns->where('status', 'complete')->count();
            $invalidIssues = $caseRuns
                ->flatMap(fn (array $run): array => (array) ($run['issues'] ?? []))
                ->filter(fn (mixed $issue): bool => is_string($issue) && str_contains($issue, 'mismatch_across_systems'))
                ->values()
                ->all();

            $cases[] = [
                'case_id' => $case['id'],
                'status' => $invalidIssues !== [] ? 'failed' : ($complete === count($this->support->systems()) ? 'passed' : 'pending'),
                'same_task_spec_hash_across_systems' => ! in_array('task_spec_hash_mismatch_across_systems', $invalidIssues, true),
                'issues' => array_values(array_unique($invalidIssues)),
            ];
        }

        return [
            'schema_version' => 'atlas.frontend.rival_replay_fairness.v1',
            'status' => collect($cases)->every(fn (array $case): bool => $case['status'] === 'passed')
                ? 'passed'
                : (collect($cases)->contains(fn (array $case): bool => $case['status'] === 'failed') ? 'failed' : 'pending'),
            'policy' => [
                'same_task_spec_hash_required_across_systems' => true,
                'same_rubric_required_across_systems' => true,
                'provider_brand_is_not_a_score_dimension' => true,
                'score_attestation_required_for_each_complete_run' => true,
            ],
            'cases' => $cases,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $runs
     */
    public function atlasWinsAllCompleteCases(array $runs): bool
    {
        foreach ($this->support->cases() as $case) {
            $caseRuns = collect($runs)->where('case_id', $case['id'])->where('status', 'complete');
            if ($caseRuns->count() < count($this->support->systems())) {
                return false;
            }

            $atlasRun = $caseRuns->firstWhere('system', 'atlas_frontend');
            $atlas = (int) data_get($atlasRun, 'score_total', -1);
            $bestRivalRun = $caseRuns
                ->reject(fn (array $run): bool => $run['system'] === 'atlas_frontend')
                ->sortByDesc(fn (array $run): int => (int) ($run['score_total'] ?? -1))
                ->first();
            $bestRival = (int) data_get($bestRivalRun, 'score_total', -1);

            if (($atlas - $bestRival) < AtlasFrontendRivalReplayHarnessService::DECISIVE_LEAD_MINIMUM_POINTS) {
                return false;
            }

            $dimensionGaps = $this->dimensionGaps(
                is_array($atlasRun['score_breakdown'] ?? null) ? $atlasRun['score_breakdown'] : [],
                is_array($bestRivalRun['score_breakdown'] ?? null) ? $bestRivalRun['score_breakdown'] : [],
                $case['id'],
                (string) ($bestRivalRun['system'] ?? ''),
            );

            if ($dimensionGaps !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $evidencePackReadiness
     * @return array<int,string>
     */
    public function remainingGaps(bool $allRunsCompleted, bool $atlasWinsAllCompleteCases, array $evidencePackReadiness, array $competitiveDiagnostics): array
    {
        if (! $allRunsCompleted) {
            return [
                'external_rival_replay_artifacts_required_for_world_best_claim',
                ...($evidencePackReadiness['status'] === 'ready' ? [] : ['rival_replay_evidence_packs_incomplete']),
            ];
        }

        if ($atlasWinsAllCompleteCases) {
            return [];
        }

        return array_values(array_filter([
            ((int) ($competitiveDiagnostics['losing_case_count'] ?? 0)) > 0 ? 'atlas_does_not_win_every_complete_case' : null,
            ((int) ($competitiveDiagnostics['tied_case_count'] ?? 0)) > 0 ? 'atlas_does_not_lead_every_complete_case' : null,
            ((int) ($competitiveDiagnostics['weak_lead_case_count'] ?? 0)) > 0 ? 'atlas_lead_margin_below_decisive_threshold' : null,
            ((int) ($competitiveDiagnostics['dimension_gap_case_count'] ?? 0)) > 0 ? 'atlas_has_dimension_gaps_against_best_rival' : null,
        ]));
    }

    /**
     * @param  array<string,mixed>  $atlasBreakdown
     * @param  array<string,mixed>  $bestRivalBreakdown
     * @return array<int,array<string,mixed>>
     */
    private function dimensionGaps(array $atlasBreakdown, array $bestRivalBreakdown, string $caseId, string $bestRivalSystem): array
    {
        $rubricDimensions = app(AtlasFrontendCompetitiveRubricService::class)->rubric()['dimensions'];
        $gaps = [];

        foreach ($rubricDimensions as $dimension) {
            $id = (string) $dimension['id'];
            $atlas = (int) ($atlasBreakdown[$id] ?? 0);
            $rival = (int) ($bestRivalBreakdown[$id] ?? 0);
            $delta = $atlas - $rival;
            if ($delta >= 0) {
                continue;
            }

            $gaps[] = [
                'dimension' => $id,
                'case_id' => $caseId,
                'atlas_score' => $atlas,
                'best_rival_system' => $bestRivalSystem,
                'best_rival_score' => $rival,
                'delta_vs_best_rival' => $delta,
                'points_to_match' => abs($delta),
                'points_to_lead' => abs($delta) + 1,
                'target_score_to_match' => $rival,
                'target_score_to_lead' => $rival + 1,
                'weight' => (int) ($dimension['weight'] ?? 0),
                'lead_possible_within_rubric' => $rival < (int) ($dimension['weight'] ?? 0),
                'next_action' => 'improve_'.$id,
                'repair_plan_command' => sprintf(
                    'php artisan atlas:frontend:repair-plan --dimension-gap=%s:%d:%d:%d:%s:%s:%d:%d --json',
                    $id,
                    abs($delta),
                    $delta,
                    $rival,
                    $caseId,
                    $bestRivalSystem,
                    $atlas,
                    (int) ($dimension['weight'] ?? 0),
                ),
            ];
        }

        return collect($gaps)
            ->sortByDesc(fn (array $gap): int => (int) $gap['points_to_match'])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function invalidateRun(array $run, string $issue): array
    {
        $run['status'] = 'invalid';
        $run['issues'] = array_values(array_unique(array_merge((array) ($run['issues'] ?? []), [$issue])));

        return $run;
    }
}
