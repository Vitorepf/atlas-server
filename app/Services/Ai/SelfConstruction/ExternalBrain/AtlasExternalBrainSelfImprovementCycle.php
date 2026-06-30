<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Read-only orchestrator for one brain cycle.
 *
 * Pipeline (pure, no I/O, no dispatch):
 *   1. Normalize   — deduplicate signals by label, drop blank objectives
 *   2. Score       — rank opportunities by compounding leverage
 *   3. Balance     — ensure healthy category mix across the wave
 *   4. Compose     — preflight implementability, group thin tasks, collision guard, batch cap
 *   5. Audit       — check the emitted batch against Goodhart quota-gaming patterns
 *   6. Learn       — summarise previous-outcome feedback for the next cycle
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
    public function run(array $signals, array $previousOutcomes = [], array $options = []): array
    {
        // 1. Normalize
        $normalized = $this->normalize($signals);

        // 2. Score & rank — merge score envelope back into the original signal so
        //    downstream steps (composer, balancer) still see objective/allowed_files/etc.
        $scored = array_map(
            fn (array $signal): array => array_merge($signal, ['final_score' => $this->scorer->score($signal)['final_score']]),
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

        // 6. Learn from previous outcomes
        $learner = $this->learnFromOutcomes($previousOutcomes);

        $nextWaveDecisions = $this->buildNextWaveDecisions($composeResult['emitted'], $auditResult, $learner);

        return [
            'schema'              => self::SCHEMA,
            'accepted'            => $composeResult['emitted'],
            'rejected'            => $composeResult['rejected'],
            'audit'               => $auditResult,
            'learner_feedback'    => $learner,
            'next_wave_decisions' => $nextWaveDecisions,
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
     * Combine scoring, audit, and learner feedback into concrete per-candidate decisions.
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
            $id       = (string) ($candidate['task_packet_id'] ?? $candidate['label'] ?? '');
            $category = (string) ($candidate['category'] ?? 'unknown');

            if (in_array($category, $avoidCategories, true)) {
                $held[] = [
                    'task_packet_id' => $id,
                    'decision'       => 'hold',
                    'reason'         => 'learner_feedback_avoid_category:'.$category,
                ];
            } elseif ($auditVerdict !== AtlasExternalBrainAntiGoodhartAuditor::VERDICT_PASS) {
                $repairRequired[] = [
                    'task_packet_id' => $id,
                    'decision'       => 'repair_required',
                    'reason'         => $auditReason !== '' ? $auditReason : $auditVerdict,
                ];
            } else {
                $accept[] = [
                    'task_packet_id' => $id,
                    'decision'       => 'accept',
                    'reason'         => 'passed_all_gates',
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
     * Deduplicate by label; drop entries with a blank objective or missing label.
     *
     * @param  list<array<string,mixed>>  $signals
     * @return list<array<string,mixed>>
     */
    private function normalize(array $signals): array
    {
        $seen = [];
        $out  = [];

        foreach ($signals as $signal) {
            if (! is_array($signal)) {
                continue;
            }

            $label     = (string) ($signal['label'] ?? '');
            $objective = trim((string) ($signal['objective'] ?? ''));

            if ($label === '' || $objective === '' || isset($seen[$label])) {
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
     * @return array{total_outcomes:int,success_count:int,give_back_count:int,failure_count:int,avoid_categories:list<string>,signal:string}
     */
    private function learnFromOutcomes(array $previousOutcomes): array
    {
        $success          = 0;
        $giveBack         = 0;
        $failure          = 0;
        $categoryGiveBack = [];

        foreach ($previousOutcomes as $o) {
            $outcome  = (string) ($o['outcome'] ?? '');
            $category = (string) ($o['category'] ?? 'unknown');

            if ($outcome === 'success') {
                $success++;
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

        return [
            'total_outcomes'   => $total,
            'success_count'    => $success,
            'give_back_count'  => $giveBack,
            'failure_count'    => $failure,
            'avoid_categories' => $avoidCategories,
            'signal'           => $total > 0
                ? ($success / $total >= 0.6 ? 'healthy' : 'degraded')
                : 'no_data',
        ];
    }
}
