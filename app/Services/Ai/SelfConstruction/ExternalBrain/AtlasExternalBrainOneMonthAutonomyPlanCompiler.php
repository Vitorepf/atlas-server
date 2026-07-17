<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Pure compiler — turns capability scores, queue yield, give_back rates, simplification debt,
 * research gaps, and worker capacity into a deterministic 30-day (4-wave) autonomy plan.
 *
 * Categories, in fixed priority order: build (low-scoring capabilities), simplify (high
 * give_back-rate families + simplification debt), research (research gaps). Items are
 * interleaved round-robin across categories then bin-packed into waves bounded by the
 * worker-capacity-derived per-wave item budget.
 *
 * INPUT FACTS (each optional; missing ⇒ that category contributes no items):
 *   capability_scores:   list<{capability_id, score:0-100}>            — score < BUILD_THRESHOLD is a build target
 *   give_back_rates:     list<{family, rate:0-1}>                      — rate >= SIMPLIFY_RATE_THRESHOLD is a simplify target
 *   simplification_debt: list<{area, debt_score}>                      — debt_score >= SIMPLIFY_DEBT_THRESHOLD is a simplify target
 *   research_gaps:       list<{topic, priority:int}>                   — sorted by priority desc
 *   queue_yield:         {give_back_rate?:0-1}                          — dampens effective worker throughput
 *   worker_capacity:     {tasks_per_day?:int}                           — base daily throughput
 *
 * OUTPUT (FACTS only — no scalar quality score):
 *   {schema_version, status, blockers, waves, category_allocation, steady_state_priorities,
 *    critical_path_categories}
 * Each wave additionally carries: milestone, proof_gates, risk_burndown, stop_go.
 *
 * steady_state_priorities is a fixed ordering: internal Atlas execution, internal Atlas
 * learning, then native proof loops — external tools are never the steady-state target.
 *
 * stop_go per wave is derived only from facts already present in the plan (queue health via
 * give_back_rate, capability lift via presence of build items, evidence quality via whether the
 * wave has any items at all) — never a human judgment call baked into the compiler.
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainOneMonthAutonomyPlanCompiler
{
    public const SCHEMA = 'atlas.self_construction.external_brain.one_month_autonomy_plan.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_INSUFFICIENT_INPUT = 'insufficient_input';

    public const CATEGORY_BUILD = 'build';

    public const CATEGORY_SIMPLIFY = 'simplify';

    public const CATEGORY_RESEARCH = 'research';

    public const WAVE_COUNT = 4;

    public const DAYS_PER_WAVE = 7;

    private const BUILD_SCORE_THRESHOLD = 70.0;

    private const SIMPLIFY_RATE_THRESHOLD = 0.2;

    private const SIMPLIFY_DEBT_THRESHOLD = 50.0;

    private const DEFAULT_TASKS_PER_DAY = 5;

    private const STOP_GO_HOLD_GIVE_BACK_THRESHOLD = 0.5;

    /** @var list<string> */
    private const STEADY_STATE_PRIORITIES = [
        'internal_atlas_execution',
        'internal_atlas_learning',
        'native_proof_loops',
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function compile(array $facts): array
    {
        $buildItems = $this->buildItems((array) ($facts['capability_scores'] ?? []));
        $simplifyItems = $this->simplifyItems(
            (array) ($facts['give_back_rates'] ?? []),
            (array) ($facts['simplification_debt'] ?? []),
        );
        $researchItems = $this->researchItems((array) ($facts['research_gaps'] ?? []));

        if ($buildItems === [] && $simplifyItems === [] && $researchItems === []) {
            $capacityAssumptions = $this->capacityAssumptions($facts);
            return [
                'schema_version' => self::SCHEMA,
                'status' => self::STATUS_INSUFFICIENT_INPUT,
                'blockers' => ['no_autonomy_plan_input_signals'],
                'waves' => [],
                'category_allocation' => [
                    self::CATEGORY_BUILD => 0,
                    self::CATEGORY_SIMPLIFY => 0,
                    self::CATEGORY_RESEARCH => 0,
                ],
                'steady_state_priorities' => self::STEADY_STATE_PRIORITIES,
                'critical_path_categories' => [self::CATEGORY_BUILD, self::CATEGORY_SIMPLIFY, self::CATEGORY_RESEARCH],
                'capacity_assumptions' => $capacityAssumptions,
            ];
        }

        $ordered = $this->interleave($buildItems, $simplifyItems, $researchItems);
        $perWaveCapacity = $this->perWaveCapacity($facts);
        $giveBackRate = max(0.0, min(1.0, (float) ($facts['queue_yield']['give_back_rate'] ?? 0.0)));
        $waves = $this->bucketIntoWaves($ordered, $perWaveCapacity, $giveBackRate);

        $allocation = [
            self::CATEGORY_BUILD => count($buildItems),
            self::CATEGORY_SIMPLIFY => count($simplifyItems),
            self::CATEGORY_RESEARCH => count($researchItems),
        ];

        return [
            'schema_version' => self::SCHEMA,
            'status' => self::STATUS_READY,
            'blockers' => [],
            'waves' => $waves,
            'category_allocation' => $allocation,
            'steady_state_priorities' => self::STEADY_STATE_PRIORITIES,
            'critical_path_categories' => [self::CATEGORY_BUILD, self::CATEGORY_SIMPLIFY, self::CATEGORY_RESEARCH],
            'capacity_assumptions' => $this->capacityAssumptions($facts),
        ];
    }

    /**
     * @param  list<mixed>  $capabilityScores
     * @return list<array{category:string,target:string,reason:string}>
     */
    private function buildItems(array $capabilityScores): array
    {
        $targets = [];
        foreach ($capabilityScores as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['capability_id'] ?? ''));
            $score = AiValueNormalizer::finiteFloatOrNull($row['score'] ?? null);
            if ($id === '' || $score === null || $score >= self::BUILD_SCORE_THRESHOLD) {
                continue;
            }
            $targets[] = ['id' => $id, 'score' => $score];
        }

        usort($targets, static fn (array $a, array $b): int => $a['score'] <=> $b['score'] ?: strcmp($a['id'], $b['id']));

        return array_map(
            static fn (array $t): array => [
                'category' => self::CATEGORY_BUILD,
                'target' => $t['id'],
                'reason' => sprintf('capability_score=%.1f below build threshold=%.1f', $t['score'], self::BUILD_SCORE_THRESHOLD),
            ],
            $targets,
        );
    }

    /**
     * @param  list<mixed>  $giveBackRates
     * @param  list<mixed>  $simplificationDebt
     * @return list<array{category:string,target:string,reason:string}>
     */
    private function simplifyItems(array $giveBackRates, array $simplificationDebt): array
    {
        $targets = [];
        foreach ($giveBackRates as $row) {
            if (! is_array($row)) {
                continue;
            }
            $family = trim((string) ($row['family'] ?? ''));
            $rate = AiValueNormalizer::finiteFloatOrNull($row['rate'] ?? null);
            if ($family === '' || $rate === null || $rate < self::SIMPLIFY_RATE_THRESHOLD) {
                continue;
            }
            $targets[] = ['key' => 'give_back:'.$family, 'metric' => $rate, 'target' => $family, 'reason' => sprintf('give_back_rate=%.2f at/above threshold=%.2f', $rate, self::SIMPLIFY_RATE_THRESHOLD)];
        }
        foreach ($simplificationDebt as $row) {
            if (! is_array($row)) {
                continue;
            }
            $area = trim((string) ($row['area'] ?? ''));
            $debt = AiValueNormalizer::finiteFloatOrNull($row['debt_score'] ?? null);
            if ($area === '' || $debt === null || $debt < self::SIMPLIFY_DEBT_THRESHOLD) {
                continue;
            }
            $targets[] = ['key' => 'debt:'.$area, 'metric' => $debt, 'target' => $area, 'reason' => sprintf('simplification_debt=%.1f at/above threshold=%.1f', $debt, self::SIMPLIFY_DEBT_THRESHOLD)];
        }

        usort($targets, static fn (array $a, array $b): int => $b['metric'] <=> $a['metric'] ?: strcmp($a['key'], $b['key']));

        return array_map(
            static fn (array $t): array => [
                'category' => self::CATEGORY_SIMPLIFY,
                'target' => $t['target'],
                'reason' => $t['reason'],
            ],
            $targets,
        );
    }

    /**
     * @param  list<mixed>  $researchGaps
     * @return list<array{category:string,target:string,reason:string}>
     */
    private function researchItems(array $researchGaps): array
    {
        $targets = [];
        foreach ($researchGaps as $row) {
            if (! is_array($row)) {
                continue;
            }
            $topic = trim((string) ($row['topic'] ?? ''));
            if ($topic === '') {
                continue;
            }
            $priority = (int) ($row['priority'] ?? 0);
            $targets[] = ['topic' => $topic, 'priority' => $priority];
        }

        usort($targets, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority'] ?: strcmp($a['topic'], $b['topic']));

        return array_map(
            static fn (array $t): array => [
                'category' => self::CATEGORY_RESEARCH,
                'target' => $t['topic'],
                'reason' => sprintf('research_gap_priority=%d', $t['priority']),
            ],
            $targets,
        );
    }

    /**
     * Round-robin interleave build/simplify/research so no single category monopolizes the
     * early waves, while each category internally keeps its priority ordering.
     *
     * @param  list<array{category:string,target:string,reason:string}>  $build
     * @param  list<array{category:string,target:string,reason:string}>  $simplify
     * @param  list<array{category:string,target:string,reason:string}>  $research
     * @return list<array{category:string,target:string,reason:string}>
     */
    private function interleave(array $build, array $simplify, array $research): array
    {
        $ordered = [];
        $max = max(count($build), count($simplify), count($research));
        for ($i = 0; $i < $max; $i++) {
            if (isset($build[$i])) {
                $ordered[] = $build[$i];
            }
            if (isset($simplify[$i])) {
                $ordered[] = $simplify[$i];
            }
            if (isset($research[$i])) {
                $ordered[] = $research[$i];
            }
        }

        return $ordered;
    }

    /**
     * @param  array<string,mixed>  $facts
     */
    private function perWaveCapacity(array $facts): int
    {
        $perDay = max(1, (int) ($facts['worker_capacity']['tasks_per_day'] ?? self::DEFAULT_TASKS_PER_DAY));
        $giveBackRate = (float) ($facts['queue_yield']['give_back_rate'] ?? 0.0);
        $giveBackRate = max(0.0, min(0.9, $giveBackRate));
        $effectivePerDay = max(1, (int) round($perDay * (1 - $giveBackRate)));

        return $effectivePerDay * self::DAYS_PER_WAVE;
    }

    /**
     * @param  list<array{category:string,target:string,reason:string}>  $items
     * @return list<array<string,mixed>>
     */
    private function bucketIntoWaves(array $items, int $perWaveCapacity, float $giveBackRate): array
    {
        $waves = [];
        $cursor = 0;
        $totalItems = count($items);
        for ($wave = 1; $wave <= self::WAVE_COUNT; $wave++) {
            $waveItems = array_slice($items, $cursor, $perWaveCapacity);
            $cursor += $perWaveCapacity;
            $remainingAfterWave = max(0, $totalItems - $cursor);

            $waves[] = [
                'wave' => $wave,
                'day_start' => (($wave - 1) * self::DAYS_PER_WAVE) + 1,
                'day_end' => $wave * self::DAYS_PER_WAVE,
                'items' => $waveItems,
                'milestone' => $this->milestone($wave, $waveItems),
                'proof_gates' => $this->proofGates($waveItems),
                'risk_burndown' => $this->riskBurndown($totalItems, $remainingAfterWave),
                'stop_go' => $this->stopGoCheck($waveItems, $giveBackRate),
            ];
            if ($cursor >= $totalItems) {
                break;
            }
        }

        return $waves;
    }

    /**
     * @param  list<array{category:string,target:string,reason:string}>  $waveItems
     */
    private function milestone(int $wave, array $waveItems): string
    {
        if ($waveItems === []) {
            return sprintf('Wave %d: no items scheduled', $wave);
        }
        $counts = ['build' => 0, 'simplify' => 0, 'research' => 0];
        foreach ($waveItems as $item) {
            $category = (string) ($item['category'] ?? '');
            if (isset($counts[$category])) {
                $counts[$category]++;
            }
        }

        return sprintf(
            'Wave %d: complete %d build, %d simplify, %d research item(s) toward Atlas-native steady-state autonomy',
            $wave,
            $counts[self::CATEGORY_BUILD],
            $counts[self::CATEGORY_SIMPLIFY],
            $counts[self::CATEGORY_RESEARCH],
        );
    }

    /**
     * @param  list<array{category:string,target:string,reason:string}>  $waveItems
     * @return list<string>
     */
    private function proofGates(array $waveItems): array
    {
        $categories = array_unique(array_column($waveItems, 'category'));
        $gates = ['tests_or_gates_result'];
        if (in_array(self::CATEGORY_SIMPLIFY, $categories, true)) {
            $gates[] = 'no_behavior_change_proof';
        }
        if (in_array(self::CATEGORY_BUILD, $categories, true)) {
            $gates[] = 'capability_lift_evidence';
        }
        if (in_array(self::CATEGORY_RESEARCH, $categories, true)) {
            $gates[] = 'research_findings_documented';
        }

        return $gates;
    }

    /**
     * Residual backlog after this wave, expressed as a risk facing steady-state autonomy —
     * never a scalar quality figure, only a fact plus a bucketed risk_level for readability.
     */
    private function riskBurndown(int $totalItems, int $remainingAfterWave): array
    {
        $remainingShare = $totalItems > 0 ? $remainingAfterWave / $totalItems : 0.0;
        $riskLevel = match (true) {
            $remainingAfterWave === 0 => 'none',
            $remainingShare > 0.5 => 'high',
            $remainingShare > 0.15 => 'medium',
            default => 'low',
        };

        return [
            'remaining_items' => $remainingAfterWave,
            'risk_level' => $riskLevel,
        ];
    }

    /**
     * @param  list<array{category:string,target:string,reason:string}>  $waveItems
     * @return array{decision:string, reasons:list<string>}
     */
    private function stopGoCheck(array $waveItems, float $giveBackRate): array
    {
        $reasons = [];

        if ($waveItems === []) {
            $reasons[] = 'no_items_scheduled_for_this_wave';
        }
        if ($giveBackRate >= self::STOP_GO_HOLD_GIVE_BACK_THRESHOLD) {
            $reasons[] = sprintf('queue_give_back_rate=%.2f at/above hold threshold=%.2f', $giveBackRate, self::STOP_GO_HOLD_GIVE_BACK_THRESHOLD);
        }
        $hasCapabilityLift = in_array(self::CATEGORY_BUILD, array_column($waveItems, 'category'), true);
        if (! $hasCapabilityLift && $waveItems !== []) {
            $reasons[] = 'no_capability_lift_item_in_wave';
        }

        $decision = $reasons === [] ? 'go' : 'hold';

        return [
            'decision' => $decision,
            'reasons' => $reasons,
        ];
    }

    /**
     * Extract capacity assumptions from facts for transparency.
     *
     * @param  array<string,mixed>  $facts
     * @return array{tasks_per_day:int, give_back_rate:float, effective_tasks_per_day:int, days_per_wave:int, wave_count:int}
     */
    private function capacityAssumptions(array $facts): array
    {
        $tasksPerDay = max(1, (int) ($facts['worker_capacity']['tasks_per_day'] ?? self::DEFAULT_TASKS_PER_DAY));
        $giveBackRate = max(0.0, min(1.0, (float) ($facts['queue_yield']['give_back_rate'] ?? 0.0)));
        $effectiveTasksPerDay = max(1, (int) round($tasksPerDay * (1 - $giveBackRate)));

        return [
            'tasks_per_day' => $tasksPerDay,
            'give_back_rate' => $giveBackRate,
            'effective_tasks_per_day' => $effectiveTasksPerDay,
            'days_per_wave' => self::DAYS_PER_WAVE,
            'wave_count' => self::WAVE_COUNT,
        ];
    }
}
