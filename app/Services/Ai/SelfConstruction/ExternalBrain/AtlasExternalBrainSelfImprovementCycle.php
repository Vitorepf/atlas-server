<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Read-only orchestrator for one brain cycle.
 *
 * Pipeline (pure, no I/O, no dispatch):
 *   1. Normalize   — deduplicate signals by label, drop blank objectives, drop exact targets
 *                    already present in previous outcomes (never re-propose a completed target)
 *   2. Score       — rank opportunities by compounding leverage, boosted for categories with
 *                    ≥2 previous successes (winning family gets priority, never a duplicate)
 *   3. Balance     — ensure healthy category mix across the wave
 *   4. Compose     — preflight implementability, group thin tasks, collision guard, batch cap
 *   5. Audit       — check the emitted batch against Goodhart quota-gaming patterns
 *   6. Learn       — summarise previous-outcome feedback for the next cycle
 *   7. Benchmark   — translate benchmark dimension failures into repair/scaffold-improvement
 *                    proposals for the next cycle (never silently ignored)
 *
 * Returns a next-wave plan. Never enqueues, commits, spawns workers,
 * calls providers, or claims final completion.
 */
final class AtlasExternalBrainSelfImprovementCycle
{
    public const SCHEMA = 'atlas.external_brain.self_improvement_cycle.v1';

    public function __construct(
        private readonly AtlasExternalBrainLeverageScorer $scorer,
        private readonly AtlasExternalBrainPortfolioBalancer $balancer,
        private readonly AtlasExternalBrainHighValueBatchComposer $composer,
        private readonly AtlasExternalBrainAntiGoodhartAuditor $auditor,
    ) {}

    /**
     * Run one full brain cycle.
     *
     * @param  list<array<string,mixed>>  $signals          Raw opportunities from the brain.
     * @param  list<array<string,mixed>>  $previousOutcomes Past task results: [{outcome, category, task_packet_id}].
     * @param  array<string,mixed>        $options          Forwarded to the composer (max_batch etc.).
     * @param  array<string,mixed>        $benchmarkResults Latest benchmark harness output: {dimension_failures?: array<string,string>}.
     * @return array{
     *     schema:             string,
     *     accepted:           list<array<string,mixed>>,
     *     rejected:           list<array<string,mixed>>,
     *     audit:              array<string,mixed>,
     *     learner_feedback:   array<string,mixed>,
     *     portfolio_balance:  array<string,mixed>,
     *     stats:              array<string,int|string>,
     * }
     */
    public function run(array $signals, array $previousOutcomes = [], array $options = [], array $benchmarkResults = []): array
    {
        // 6. Learn from previous outcomes (computed early — feeds normalize dedup + score boost)
        $learner = $this->learnFromOutcomes($previousOutcomes);

        // 1. Normalize — drop dupes/blanks AND any signal that exactly targets a completed task.
        $normalized = $this->normalize($signals, $learner['completed_targets']);

        // 2. Score & rank — merge score envelope back into the original signal so
        //    downstream steps (composer, balancer) still see objective/allowed_files/etc.
        //    Categories with ≥2 previous successes get a priority boost (AC3): winning
        //    families rank higher without ever duplicating an exact completed target.
        $scored = array_map(
            function (array $signal) use ($learner): array {
                $baseScore = $this->scorer->score($signal)['final_score'];
                $category  = (string) ($signal['category'] ?? '');
                $boosted   = in_array($category, $learner['boost_categories'], true)
                    ? min(1.0, $baseScore + 0.1)
                    : $baseScore;

                return array_merge($signal, ['final_score' => $boosted]);
            },
            $normalized,
        );
        usort($scored, static fn (array $a, array $b): int => $b['final_score'] <=> $a['final_score']);

        // 3. Balance portfolio
        $balanceResult = $this->balancer->balance($scored);
        $balanced      = $balanceResult['candidates'];

        // 4. Compose (preflight + thin-group + collision-guard + batch cap)
        $composeResult = $this->composer->compose($balanced, $options);

        // 5. Anti-Goodhart audit
        $auditResult = $this->auditor->audit($composeResult['emitted']);

        $nextWaveDecisions = $this->buildNextWaveDecisions($composeResult['emitted'], $auditResult, $learner);
        $nextDecision      = $this->deriveNextDecision($learner, $nextWaveDecisions);

        // 7. Benchmark response — translate failed dimensions into repair/scaffold proposals.
        $benchmarkResponse = $this->buildBenchmarkResponse($benchmarkResults);

        return [
            'schema'              => self::SCHEMA,
            'accepted'            => $composeResult['emitted'],
            'rejected'            => $composeResult['rejected'],
            'audit'               => $auditResult,
            'learner_feedback'    => $learner,
            'next_wave_decisions' => $nextWaveDecisions,
            'next_decision'       => $nextDecision,
            'benchmark_response'  => $benchmarkResponse,
            'portfolio_balance'   => [
                'status'   => $balanceResult['status'],
                'deficits' => $balanceResult['deficits'],
                'surpluses' => $balanceResult['surpluses'],
            ],
            'stats'               => [
                'signals_in'      => count($signals),
                'normalized'      => count($normalized),
                'scored'          => count($scored),
                'accepted_count'  => count($composeResult['emitted']),
                'rejected_count'  => count($composeResult['rejected']),
            ],
        ];
    }

    /**
     * Pure public entry point for testing the decision+learning circuit without sub-services.
     * Takes pre-scored proposals and previous outcomes; returns a closed circuit output.
     *
     * @param  list<array<string,mixed>>  $proposals       Pre-scored proposals [{task_packet_id, category, final_score, ...}]
     * @param  list<array<string,mixed>>  $previousOutcomes
     * @param  array<string,mixed>        $benchmarkResults Latest benchmark harness output: {dimension_failures?: array<string,string>}.
     * @return array{schema:string, learner_feedback:array<string,mixed>, next_wave_decisions:array<string,mixed>, next_decision:string, benchmark_response:list<array<string,string>>}
     */
    public function processCycleOutcomes(array $proposals, array $previousOutcomes, array $benchmarkResults = []): array
    {
        $learner     = $this->learnFromOutcomes($previousOutcomes);
        $auditResult = ['verdict' => AtlasExternalBrainAntiGoodhartAuditor::VERDICT_PASS, 'findings' => []];
        $decisions   = $this->buildNextWaveDecisions($proposals, $auditResult, $learner);
        $nextDecision = $this->deriveNextDecision($learner, $decisions);
        $benchmarkResponse = $this->buildBenchmarkResponse($benchmarkResults);

        return [
            'schema'              => self::SCHEMA,
            'learner_feedback'    => $learner,
            'next_wave_decisions' => $decisions,
            'next_decision'       => $nextDecision,
            'benchmark_response'  => $benchmarkResponse,
        ];
    }

    /**
     * Translate benchmark harness dimension_failures into concrete repair or
     * scaffold-improvement proposals for the next cycle (AC2). Never silently drops a
     * failed dimension — every failure becomes exactly one proposal.
     *
     * Dimensions that indicate the ORIGINATED BATCH was flawed (template farming, quota
     * padding, queue pressure, weak proof, missing certification, premature exhaustion
     * claims) become repair proposals — fix the batch that was produced. Dimensions that
     * indicate the SCAFFOLD ITSELF is too weak to reach ambition (no evolutionary leap, no
     * architectural coverage, no cross-project reach, no second-pass breakthrough) become
     * scaffold-improvement proposals — the runbook/scaffold needs to be strengthened.
     *
     * @param  array<string,mixed>  $benchmarkResults  {dimension_failures?: array<string,string>}
     * @return list<array{dimension:string, proposal_type:string, objective:string}>
     */
    private function buildBenchmarkResponse(array $benchmarkResults): array
    {
        $failures = is_array($benchmarkResults['dimension_failures'] ?? null) ? $benchmarkResults['dimension_failures'] : [];

        $scaffoldDimensions = [
            'evolutionary_leap_present',
            'architectural_coverage',
            'cross_project_reach',
            'second_pass_present',
        ];

        $proposals = [];
        foreach ($failures as $dimension => $reason) {
            $dimension = (string) $dimension;
            $type      = in_array($dimension, $scaffoldDimensions, true) ? 'scaffold_improvement' : 'repair';

            $proposals[] = [
                'dimension'     => $dimension,
                'proposal_type' => $type,
                'objective'     => "Address benchmark failure {$dimension} ({$reason}) via {$type}.",
            ];
        }

        return $proposals;
    }

    /**
     * Combine scoring, audit, and learner feedback into concrete per-candidate decisions.
     * Each decision entry includes risk_level derived from the candidate's final_score and evidence.
     *
     * @return array{accept:list<array<string,string>>,repair_required:list<array<string,string>>,held:list<array<string,string>>}
     */
    private function buildNextWaveDecisions(array $accepted, array $auditResult, array $learner): array
    {
        $avoidCategories = $learner['avoid_categories'] ?? [];
        $auditVerdict    = (string) ($auditResult['verdict'] ?? AtlasExternalBrainAntiGoodhartAuditor::VERDICT_PASS);
        $auditReason     = implode('; ', array_column($auditResult['findings'] ?? [], 'finding'));

        $accept         = [];
        $repairRequired = [];
        $held           = [];

        foreach ($accepted as $candidate) {
            $id         = (string) ($candidate['task_packet_id'] ?? $candidate['label'] ?? '');
            $category   = (string) ($candidate['category'] ?? 'unknown');
            $finalScore = (float) ($candidate['final_score'] ?? 0.0);
            $riskLevel  = $finalScore >= 0.70 ? 'low' : ($finalScore >= 0.40 ? 'medium' : 'high');

            if (in_array($category, $avoidCategories, true)) {
                $held[] = [
                    'task_packet_id' => $id,
                    'decision'       => 'hold',
                    'reason'         => 'learner_feedback_avoid_category:'.$category,
                    'risk_level'     => $riskLevel,
                ];
            } elseif ($auditVerdict !== AtlasExternalBrainAntiGoodhartAuditor::VERDICT_PASS) {
                $repairRequired[] = [
                    'task_packet_id' => $id,
                    'decision'       => 'repair_required',
                    'reason'         => $auditReason !== '' ? $auditReason : $auditVerdict,
                    'risk_level'     => $riskLevel,
                ];
            } else {
                $accept[] = [
                    'task_packet_id' => $id,
                    'decision'       => 'accept',
                    'reason'         => 'passed_all_gates',
                    'risk_level'     => $riskLevel,
                ];
            }
        }

        return [
            'accept'          => $accept,
            'repair_required' => $repairRequired,
            'held'            => $held,
        ];
    }

    /**
     * Derive a single next_decision string from learner signal and decision buckets (AC3).
     */
    private function deriveNextDecision(array $learner, array $decisions): string
    {
        if (($learner['signal'] ?? '') === 'degraded') {
            return 'pause_and_repair';
        }
        if ($decisions['repair_required'] !== []) {
            return 'repair_before_advancing';
        }
        if ($decisions['held'] !== []) {
            return 'resolve_held_before_wave';
        }
        return 'advance_wave';
    }

    /**
     * Deduplicate by label; drop entries with a blank objective or missing label; drop any
     * signal whose label exactly matches a completed target (AC3: never re-propose an exact
     * target already delivered).
     *
     * @param  list<array<string,mixed>>  $signals
     * @param  list<string>  $completedTargets
     * @return list<array<string,mixed>>
     */
    private function normalize(array $signals, array $completedTargets = []): array
    {
        $seen = [];
        $out  = [];

        foreach ($signals as $signal) {
            if (! is_array($signal)) {
                continue;
            }

            $label     = (string) ($signal['label'] ?? '');
            $objective = trim((string) ($signal['objective'] ?? ''));

            if ($label === '' || $objective === '' || isset($seen[$label]) || in_array($label, $completedTargets, true)) {
                continue;
            }

            $seen[$label] = true;
            $out[]        = $signal;
        }

        return $out;
    }

    /**
     * Summarise previous-cycle task outcomes into learner feedback.
     *
     * @param  list<array<string,mixed>>  $previousOutcomes
     * @return array{total_outcomes:int,success_count:int,give_back_count:int,failure_count:int,avoid_categories:list<string>,boost_categories:list<string>,completed_targets:list<string>,signal:string}
     */
    private function learnFromOutcomes(array $previousOutcomes): array
    {
        $success          = 0;
        $giveBack         = 0;
        $failure          = 0;
        $categoryGiveBack = [];
        $categorySuccess  = [];
        $completedTargets = [];

        foreach ($previousOutcomes as $o) {
            $outcome  = (string) ($o['outcome'] ?? '');
            $category = (string) ($o['category'] ?? 'unknown');
            $target   = (string) ($o['task_packet_id'] ?? $o['label'] ?? '');
            if ($target !== '') {
                $completedTargets[] = $target;
            }

            if ($outcome === 'success') {
                $success++;
                $categorySuccess[$category] = ($categorySuccess[$category] ?? 0) + 1;
            } elseif ($outcome === 'give_back') {
                $giveBack++;
                $categoryGiveBack[$category] = ($categoryGiveBack[$category] ?? 0) + 1;
            } else {
                $failure++;
            }
        }

        $total           = count($previousOutcomes);
        $avoidCategories = array_values(
            array_keys(array_filter($categoryGiveBack, static fn (int $c): bool => $c >= 2)),
        );
        $boostCategories = array_values(
            array_keys(array_filter($categorySuccess, static fn (int $c): bool => $c >= 2)),
        );

        $signal = $total > 0
            ? ($success / $total >= 0.6 ? 'healthy' : 'degraded')
            : 'no_data';

        // AC2: next_action derived from outcome ratios.
        $giveBackRatio = $total > 0 ? $giveBack / $total : 0.0;
        $failureRatio  = $total > 0 ? $failure  / $total : 0.0;
        $nextAction = match(true) {
            $giveBackRatio > 0.4  => 'reduce_give_back_rate',
            $failureRatio  > 0.3  => 'investigate_failure_pattern',
            $signal === 'healthy' => 'continue_or_escalate_ambition',
            default               => 'rebalance_portfolio',
        };

        return [
            'total_outcomes'   => $total,
            'success_count'    => $success,
            'give_back_count'  => $giveBack,
            'failure_count'    => $failure,
            'avoid_categories' => $avoidCategories,
            'boost_categories' => $boostCategories,
            'completed_targets' => array_values(array_unique($completedTargets)),
            'signal'           => $signal,
            'next_action'      => $nextAction,
        ];
    }
}
