<?php

namespace App\Console\Commands;

use App\Services\Ai\Autonomy\AtlasAutonomyDemoteWatchdog;
use App\Services\Ai\Autonomy\AtlasAutonomyLadderRuntimeService;
use App\Services\Ai\Autonomy\AtlasAutonomyMetricsAggregator;
use Illuminate\Console\Command;

/**
 * Runtime surface for the Atlas Autonomy Ladder Promotion Runbook. Without args
 * it prints the canonical 8-level ladder with measurable exit criteria. With
 * --level + --signals it evaluates a real promotion request (and, with
 * --cycles, an automatic demote) from measured metrics.
 *
 * @see docs/engineering-knowledge-base/atlas-autonomy-ladder-promotion-runbook.md
 */
class AtlasAutonomyLadderCommand extends Command
{
    protected $signature = 'atlas:autonomy:ladder
        {--level= : Current level (L0..L7) to evaluate a promotion from}
        {--signals= : JSON map of raw autonomy signals to aggregate into metrics}
        {--signatures= : JSON map e.g. {"operator":true,"architect":true}}
        {--cycles= : JSON list of recent cycle metric maps to evaluate auto-demote}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect and evaluate the Atlas Autonomy Ladder (L0..L7) promotion/demote runtime.';

    public function handle(
        AtlasAutonomyLadderRuntimeService $ladder,
        AtlasAutonomyMetricsAggregator $aggregator,
        AtlasAutonomyDemoteWatchdog $watchdog,
    ): int {
        $level = $this->stringOption('level');

        if ($level === null) {
            $payload = ['schema_version' => AtlasAutonomyLadderRuntimeService::SCHEMA, 'ladder' => $ladder->ladder()];
            if ((bool) $this->option('json')) {
                $this->line($this->encode($payload));

                return self::SUCCESS;
            }
            $this->table(
                ['rank', 'level', 'name', 'promote signature', 'next'],
                collect($ladder->ladder())->map(fn (array $r): array => [
                    $r['rank'], $r['level'], $r['name'], $r['promotion_signature'] ?? '-', $r['next_level'] ?? '(top)',
                ])->all(),
            );

            return self::SUCCESS;
        }

        $metrics = $aggregator->aggregate($this->jsonOption('signals'));
        $signatures = $this->jsonOption('signatures');
        $promotion = $ladder->evaluatePromotion($level, $metrics, $signatures);

        $cycles = $this->jsonOption('cycles');
        $demote = $cycles !== [] ? $watchdog->watch($level, array_values($cycles)) : null;

        $payload = [
            'schema_version' => AtlasAutonomyLadderRuntimeService::SCHEMA,
            'metrics' => $metrics,
            'promotion' => $promotion,
            'demote' => $demote,
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return ($promotion['eligible'] ?? false) ? self::SUCCESS : self::SUCCESS;
        }

        $this->components->twoColumnDetail('current level', (string) $promotion['current_level']);
        $this->components->twoColumnDetail('next level', (string) ($promotion['next_level'] ?? '(top)'));
        $this->components->twoColumnDetail('decision', (string) $promotion['decision']);
        $this->components->twoColumnDetail('eligible', ($promotion['eligible'] ?? false) ? 'yes' : 'no');
        if (($promotion['unmet_criteria'] ?? []) !== []) {
            $this->table(
                ['metric', 'need', 'threshold', 'observed'],
                collect($promotion['unmet_criteria'])->map(fn (array $c): array => [
                    $c['metric'], $c['comparator'], $c['threshold'], $c['missing'] ? '(missing)' : $c['observed'],
                ])->all(),
            );
        }
        if ($demote !== null && data_get($demote, 'decision.demote')) {
            $this->warn('AUTO-DEMOTE: '.data_get($demote, 'decision.from_level').' -> '.data_get($demote, 'decision.to_level'));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOption(string $key): array
    {
        $raw = $this->option($key);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
