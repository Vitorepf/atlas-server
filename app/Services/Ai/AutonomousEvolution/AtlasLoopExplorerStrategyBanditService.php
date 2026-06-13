<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * L6-3: UCB portfolio over explorer strategy hints.
 *
 * This is strategic routing, not a new executor. It reads resolved attempt metrics,
 * ranks the existing scenario strategies by target type, and only applies a changed
 * distribution when the chosen strategy has measured certification-per-token lift.
 */
final class AtlasLoopExplorerStrategyBanditService
{
    public const SCHEMA_VERSION = 'atlas.loop.explorer_strategy_bandit.v1';

    /**
     * @return array<string,string>
     */
    public function portfolio(): array
    {
        return [
            'baseline' => '',
            'surgical' => 'Prefer the smallest, most surgical change that satisfies the objective.',
            'clean_alternative' => 'If the obvious fix is fragile, consider a cleaner alternative — but stay strictly in scope.',
            'root_cause' => 'Re-read the failing acceptance carefully; fix the true root cause, not the symptom.',
            'simplify' => 'Favor deleting/simplifying over adding, if it still satisfies the objective.',
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function decideForTask(string $targetPath, array $options = []): array
    {
        $measurement = $this->measure(['write_receipt' => false]);
        $type = $this->targetType($targetPath);
        $recommendation = null;
        foreach ((array) ($measurement['recommendations'] ?? []) as $candidate) {
            if (is_array($candidate) && ($candidate['target_type'] ?? null) === $type) {
                $recommendation = $candidate;
                break;
            }
        }

        $scenarioCount = max(1, (int) ($options['scenario_count'] ?? config('atlas.loop.scenarios_per_task', 3)));
        $defaultKeys = array_keys($this->portfolio());
        $selectedKeys = is_array($recommendation['selected_strategy_keys'] ?? null)
            ? array_values($recommendation['selected_strategy_keys'])
            : $defaultKeys;
        $applied = (bool) config('atlas.loop.explorer_strategy_bandit.apply_enabled', true)
            && (bool) data_get($recommendation, 'completion_eligible', false);
        if (! $applied) {
            $selectedKeys = $defaultKeys;
        }

        $selectedKeys = array_slice($selectedKeys, 0, max($scenarioCount, count($defaultKeys)));
        $portfolio = $this->portfolio();

        return [
            'schema_version' => self::SCHEMA_VERSION.'.decision.v1',
            'status' => $applied ? 'applied' : (string) ($measurement['status'] ?? 'insufficient_evidence'),
            'applied' => $applied,
            'target_path' => $targetPath,
            'target_type' => $type,
            'selected_strategy_keys' => $selectedKeys,
            'selected_strategy_texts' => array_map(static fn (string $key): string => (string) ($portfolio[$key] ?? ''), $selectedKeys),
            'baseline_strategy_keys' => $defaultKeys,
            'distribution_changed' => $applied && $selectedKeys !== array_slice($defaultKeys, 0, count($selectedKeys)),
            'token_efficiency_delta_per_1k' => data_get($recommendation, 'token_efficiency_delta_per_1k'),
            'blockers' => array_values((array) ($measurement['blockers'] ?? [])),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function measure(array $options = []): array
    {
        $cfg = (array) config('atlas.loop.explorer_strategy_bandit', []);
        $enabled = (bool) ($options['enabled'] ?? $cfg['enabled'] ?? true);
        $hours = max(1, min(2160, (int) ($options['hours'] ?? $cfg['window_hours'] ?? 168)));
        $minAttempts = max(1, (int) ($options['min_attempts_per_target_type'] ?? $cfg['min_attempts_per_target_type'] ?? 4));
        $minTokenSamples = max(1, (int) ($options['min_token_samples_per_strategy'] ?? $cfg['min_token_samples_per_strategy'] ?? 1));
        $minTokenLift = max(0.0, (float) ($options['min_token_efficiency_delta_per_1k'] ?? $cfg['min_token_efficiency_delta_per_1k'] ?? 0.01));
        $explorationWeight = max(0.0, min(2.0, (float) ($options['ucb_exploration_weight'] ?? $cfg['ucb_exploration_weight'] ?? 0.35)));
        $write = (bool) ($options['write_receipt'] ?? false);
        $receiptPath = (string) ($options['receipt_path'] ?? $cfg['receipt_path'] ?? storage_path('app/atlas/evidence/explorer-strategy-bandit.json'));

        if (! $enabled) {
            return $this->payload('disabled', [], [], ['explorer_strategy_bandit_disabled'], $hours, $minAttempts, $minTokenSamples, $minTokenLift, $explorationWeight, $write, $receiptPath);
        }

        if (! DatabaseTableAvailability::all(['atlas_loop_explorations', 'atlas_loop_tasks'])) {
            return $this->payload('blocked', [], [], ['loop_exploration_tables_missing'], $hours, $minAttempts, $minTokenSamples, $minTokenLift, $explorationWeight, $write, $receiptPath);
        }

        $stats = $this->historicalStats($hours);
        $recommendations = $this->recommendations($stats, $minAttempts, $minTokenSamples, $minTokenLift, $explorationWeight);
        $eligible = array_values(array_filter($recommendations, static fn (array $row): bool => (bool) ($row['completion_eligible'] ?? false)));
        $blockers = $eligible === [] ? $this->measurementBlockers($stats, $recommendations) : [];
        $status = $eligible !== [] ? 'active_distribution_changed_with_token_lift' : 'insufficient_evidence';

        $payload = $this->payload($status, $stats, $recommendations, $blockers, $hours, $minAttempts, $minTokenSamples, $minTokenLift, $explorationWeight, $write, $receiptPath);
        if ($write) {
            File::ensureDirectoryExists(dirname($receiptPath));
            File::put($receiptPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function historicalStats(int $hours): array
    {
        try {
            $rows = DB::table('atlas_loop_explorations as e')
                ->leftJoin('atlas_loop_tasks as t', 't.id', '=', 'e.task_id')
                ->where('e.updated_at', '>=', Carbon::now()->subHours($hours))
                ->orderByDesc('e.updated_at')
                ->limit(2000)
                ->get(['e.attempt_metrics', 't.target_path']);
        } catch (Throwable) {
            return [];
        }

        $stats = [];
        foreach ($rows as $row) {
            $type = $this->targetType((string) ($row->target_path ?? ''));
            $attempts = $this->arrayPayload($row->attempt_metrics ?? null);
            foreach ($attempts as $index => $attempt) {
                if (! is_array($attempt)) {
                    continue;
                }
                $key = trim((string) ($attempt['strategy_key'] ?? ''));
                if ($key === '') {
                    $key = array_keys($this->portfolio())[$index % count($this->portfolio())];
                }
                $stats[$type][$key] ??= $this->emptyStrategyStats($key);
                $passed = (bool) ($attempt['passed'] ?? false);
                $tokens = is_numeric($attempt['tokens_used'] ?? null) ? max(0, (int) $attempt['tokens_used']) : null;

                $stats[$type][$key]['attempts']++;
                if ($passed) {
                    $stats[$type][$key]['certified']++;
                }
                if ($tokens !== null && $tokens > 0) {
                    $stats[$type][$key]['tokens_used'] += $tokens;
                    $stats[$type][$key]['token_samples']++;
                }
            }
        }

        foreach ($stats as $type => $byStrategy) {
            foreach ($byStrategy as $key => $row) {
                $attempts = max(1, (int) $row['attempts']);
                $tokens = max(0, (int) $row['tokens_used']);
                $stats[$type][$key]['certification_rate'] = round((int) $row['certified'] / $attempts, 4);
                $stats[$type][$key]['certified_per_1k_tokens'] = $tokens > 0
                    ? round(((int) $row['certified'] / $tokens) * 1000, 4)
                    : null;
                $stats[$type][$key]['strategy'] = $this->portfolio()[$key] ?? '';
            }
        }

        return $stats;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyStrategyStats(string $key): array
    {
        return [
            'strategy_key' => $key,
            'strategy' => $this->portfolio()[$key] ?? '',
            'attempts' => 0,
            'certified' => 0,
            'tokens_used' => 0,
            'token_samples' => 0,
            'certification_rate' => null,
            'certified_per_1k_tokens' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $stats
     * @return list<array<string,mixed>>
     */
    private function recommendations(array $stats, int $minAttempts, int $minTokenSamples, float $minTokenLift, float $explorationWeight): array
    {
        $defaultKeys = array_keys($this->portfolio());
        $recommendations = [];
        foreach ($stats as $type => $byStrategy) {
            if (! is_array($byStrategy)) {
                continue;
            }
            $totalAttempts = array_sum(array_map(static fn (mixed $row): int => is_array($row) ? (int) ($row['attempts'] ?? 0) : 0, $byStrategy));
            $known = array_values(array_filter($byStrategy, static fn (mixed $row): bool => is_array($row) && (int) ($row['attempts'] ?? 0) > 0));
            usort($known, function (array $a, array $b) use ($totalAttempts, $explorationWeight): int {
                return $this->ucbScore($b, $totalAttempts, $explorationWeight) <=> $this->ucbScore($a, $totalAttempts, $explorationWeight);
            });
            $selected = array_values(array_unique(array_merge(
                array_map(static fn (array $row): string => (string) $row['strategy_key'], $known),
                $defaultKeys,
            )));
            $baseline = is_array($byStrategy['baseline'] ?? null) ? $byStrategy['baseline'] : $this->emptyStrategyStats('baseline');
            $top = is_array($byStrategy[$selected[0] ?? 'baseline'] ?? null) ? $byStrategy[$selected[0]] : $baseline;
            $baselineEfficiency = is_numeric($baseline['certified_per_1k_tokens'] ?? null) ? (float) $baseline['certified_per_1k_tokens'] : null;
            $topEfficiency = is_numeric($top['certified_per_1k_tokens'] ?? null) ? (float) $top['certified_per_1k_tokens'] : null;
            $delta = $baselineEfficiency !== null && $topEfficiency !== null ? round($topEfficiency - $baselineEfficiency, 4) : null;
            $distributionChanged = $selected !== $defaultKeys;
            $completionEligible = $totalAttempts >= $minAttempts
                && $distributionChanged
                && (int) ($baseline['token_samples'] ?? 0) >= $minTokenSamples
                && (int) ($top['token_samples'] ?? 0) >= $minTokenSamples
                && is_float($delta)
                && $delta >= $minTokenLift;

            $recommendations[] = [
                'target_type' => (string) $type,
                'attempts' => $totalAttempts,
                'selected_strategy_keys' => $selected,
                'baseline_strategy_keys' => $defaultKeys,
                'top_strategy_key' => (string) ($selected[0] ?? 'baseline'),
                'distribution_changed' => $distributionChanged,
                'token_efficiency_delta_per_1k' => $delta,
                'completion_eligible' => $completionEligible,
                'ucb_scores' => array_map(fn (array $row): array => [
                    'strategy_key' => (string) $row['strategy_key'],
                    'score' => round($this->ucbScore($row, $totalAttempts, $explorationWeight), 4),
                    'certification_rate' => $row['certification_rate'],
                    'certified_per_1k_tokens' => $row['certified_per_1k_tokens'],
                    'attempts' => $row['attempts'],
                ], $known),
            ];
        }

        return $recommendations;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function ucbScore(array $row, int $totalAttempts, float $explorationWeight): float
    {
        $attempts = max(1, (int) ($row['attempts'] ?? 0));
        $rate = is_numeric($row['certification_rate'] ?? null) ? (float) $row['certification_rate'] : 0.0;

        return $rate + ($explorationWeight * sqrt(log(max(2, $totalAttempts + 1)) / $attempts));
    }

    /**
     * @param  array<string,mixed>  $stats
     * @param  list<array<string,mixed>>  $recommendations
     * @return list<string>
     */
    private function measurementBlockers(array $stats, array $recommendations): array
    {
        if ($stats === []) {
            return ['no_strategy_attempt_metrics_in_window'];
        }
        if ($recommendations === []) {
            return ['no_target_type_recommendations'];
        }
        $hasDistributionChange = false;
        $hasTokenDelta = false;
        foreach ($recommendations as $row) {
            $hasDistributionChange = $hasDistributionChange || (bool) ($row['distribution_changed'] ?? false);
            $hasTokenDelta = $hasTokenDelta || is_float($row['token_efficiency_delta_per_1k'] ?? null);
        }

        return array_values(array_filter([
            $hasDistributionChange ? null : 'strategy_distribution_not_changed',
            $hasTokenDelta ? null : 'token_efficiency_not_measured',
            'no_recommendation_met_completion_floor',
        ]));
    }

    public function targetType(string $targetPath): string
    {
        $path = ltrim(str_replace('\\', '/', trim($targetPath)), '/');

        return match (true) {
            $path === '' => 'unknown',
            str_starts_with($path, 'tests/') => 'test',
            str_starts_with($path, 'database/migrations/') => 'migration',
            str_starts_with($path, 'config/') => 'config',
            str_contains($path, '/Generated/') => 'generated_service',
            str_starts_with($path, 'app/Services/Ai/AutonomousEvolution/') => 'loop_harness',
            str_starts_with($path, 'app/Services/') => 'service',
            default => 'other',
        };
    }

    /**
     * @return list<mixed>
     */
    private function arrayPayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return array_values($payload);
        }
        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param  array<string,mixed>  $stats
     * @param  list<array<string,mixed>>  $recommendations
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function payload(
        string $status,
        array $stats,
        array $recommendations,
        array $blockers,
        int $hours,
        int $minAttempts,
        int $minTokenSamples,
        float $minTokenLift,
        float $explorationWeight,
        bool $write,
        string $receiptPath,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'window' => [
                'hours' => $hours,
                'min_attempts_per_target_type' => $minAttempts,
                'min_token_samples_per_strategy' => $minTokenSamples,
                'min_token_efficiency_delta_per_1k' => $minTokenLift,
                'ucb_exploration_weight' => $explorationWeight,
            ],
            'portfolio' => $this->portfolio(),
            'target_type_stats' => $stats,
            'recommendations' => $recommendations,
            'blockers' => $blockers,
            'artifacts' => [
                'write_enabled' => $write,
                'receipt_path' => $write ? $receiptPath : null,
            ],
            'completion_claim_allowed' => $status === 'active_distribution_changed_with_token_lift',
            'claim_policy' => [
                'strategic_routing_only' => true,
                'provider_calls_made' => false,
                'workspace_mutated' => false,
                'merge_gate_changed' => false,
                'operator_override_wins' => true,
                'requires_distribution_change' => true,
                'requires_certification_per_token_lift' => true,
            ],
        ];
    }
}
