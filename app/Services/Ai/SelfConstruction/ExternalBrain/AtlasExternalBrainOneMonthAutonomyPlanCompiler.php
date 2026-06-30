<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

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
 *   {schema_version, status, blockers, waves, category_allocation}
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
            ];
        }

        $ordered = $this->interleave($buildItems, $simplifyItems, $researchItems);
        $perWaveCapacity = $this->perWaveCapacity($facts);
        $waves = $this->bucketIntoWaves($ordered, $perWaveCapacity);

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
            $score = is_numeric($row['score'] ?? null) ? (float) $row['score'] : null;
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
            $rate = is_numeric($row['rate'] ?? null) ? (float) $row['rate'] : null;
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
            $debt = is_numeric($row['debt_score'] ?? null) ? (float) $row['debt_score'] : null;
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
     * @return list<array{wave:int,day_start:int,day_end:int,items:list<array{category:string,target:string,reason:string}>}>
     */
    private function bucketIntoWaves(array $items, int $perWaveCapacity): array
    {
        $waves = [];
        $cursor = 0;
        for ($wave = 1; $wave <= self::WAVE_COUNT; $wave++) {
            $waveItems = array_slice($items, $cursor, $perWaveCapacity);
            $cursor += $perWaveCapacity;
            $waves[] = [
                'wave' => $wave,
                'day_start' => (($wave - 1) * self::DAYS_PER_WAVE) + 1,
                'day_end' => $wave * self::DAYS_PER_WAVE,
                'items' => $waveItems,
            ];
            if ($cursor >= count($items)) {
                break;
            }
        }

        return $waves;
    }
}
