<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\Depth\AtlasCortexCounterfactualHypothesisLedger;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\Depth\AtlasCortexCounterfactualImpactAggregator;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\Depth\AtlasCortexCounterfactualMultiStepWalker;
use Illuminate\Console\Command;
use Throwable;

/**
 * Single CLI surface for the counterfactual depth probe trio.
 *
 *   atlas:loop:cortex:counterfactual multi       --site=<site> --depth=N [--json]
 *   atlas:loop:cortex:counterfactual impact      [--json]
 *   atlas:loop:cortex:counterfactual hypothesis  --site=<site> --walk=<id> [--json]
 *   atlas:loop:cortex:counterfactual history     [--site= | --walk=] [--json]
 *
 * NEVER prints score/rank/best/recommendation. With ATLAS_LOOP_MASTER_ENABLED=false the command
 * exits 0 with a single line and performs no walker invocations or ledger writes.
 */
final class AtlasLoopCortexCounterfactualCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:cortex:counterfactual
        {action : multi|impact|hypothesis|history}
        {--site=}
        {--walk=}
        {--depth=3}
        {--json}';

    /** @var string */
    protected $description = 'Counterfactual depth CLI: multi | impact | hypothesis | history (FACT-only).';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $json = (bool) $this->option('json');

        if (! $this->masterEnabled()) {
            if ($json) {
                $this->line((string) json_encode(['disabled' => true, 'reason' => 'loop master disabled'], JSON_UNESCAPED_SLASHES));
            } else {
                $this->line('loop master disabled');
            }

            return self::SUCCESS;
        }

        try {
            return match ($action) {
                'multi' => $this->doMulti($json),
                'impact' => $this->doImpact($json),
                'hypothesis' => $this->doHypothesis($json),
                'history' => $this->doHistory($json),
                default => $this->failJson('unknown_action:'.$action.' (valid: multi|impact|hypothesis|history)', $json),
            };
        } catch (Throwable $e) {
            return $this->failJson($e->getMessage(), $json);
        }
    }

    private function doMulti(bool $json): int
    {
        $site = (string) $this->option('site');
        if ($site === '') {
            return $this->failJson('site_required', $json);
        }
        $depth = max(1, (int) $this->option('depth'));

        $walker = new AtlasCortexCounterfactualMultiStepWalker(enabled: true, depth: $depth);
        $snapshot = $this->snapshot();
        $hypotheses = [['site' => $site, 'mutation_kind' => 'enumerate']];
        $result = $walker->walk($snapshot, $hypotheses);
        $this->emit($json, $result);

        return self::SUCCESS;
    }

    private function doImpact(bool $json): int
    {
        $agg = new AtlasCortexCounterfactualImpactAggregator(enabled: true);
        $walks = $this->recentWalks();
        $result = $agg->aggregate($walks);
        $this->emit($json, $result);

        return self::SUCCESS;
    }

    private function doHypothesis(bool $json): int
    {
        $site = (string) $this->option('site');
        $walk = (string) $this->option('walk');
        if ($site === '' || $walk === '') {
            return $this->failJson('site_and_walk_required', $json);
        }
        $ledger = app(AtlasCortexCounterfactualHypothesisLedger::class);
        $row = $ledger->record([
            'walk_id' => $walk,
            'snapshot_sha' => 'unknown',
            'steps_json' => [['site' => $site]],
            'terminated_reason' => 'cli_recorded',
            'observed_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'site_id' => $site,
        ]);
        $this->emit($json, ['recorded' => $row]);

        return self::SUCCESS;
    }

    private function doHistory(bool $json): int
    {
        $ledger = app(AtlasCortexCounterfactualHypothesisLedger::class);
        $site = (string) $this->option('site');
        $walk = (string) $this->option('walk');

        $rows = match (true) {
            $site !== '' => $ledger->historyForSite($site),
            $walk !== '' => $ledger->historyForWalk($walk),
            default => $ledger->all(),
        };
        $this->emit($json, ['rows' => $rows]);

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        if (app()->bound('atlas.cortex.counterfactual.snapshot')) {
            $bound = app('atlas.cortex.counterfactual.snapshot');
            if (is_array($bound)) {
                return $bound;
            }
        }

        return ['reachability' => []];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function recentWalks(): array
    {
        if (app()->bound('atlas.cortex.counterfactual.recent_walks')) {
            $bound = app('atlas.cortex.counterfactual.recent_walks');
            if (is_array($bound)) {
                return array_values($bound);
            }
        }

        return [];
    }

    private function masterEnabled(): bool
    {
        $env = getenv('ATLAS_LOOP_MASTER_ENABLED');
        if ($env === false) {
            return true;
        }

        return ! in_array(strtolower((string) $env), ['0', 'false', 'off', ''], true);
    }

    private function emit(bool $json, array $payload): void
    {
        if ($json) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return;
        }
        $this->line(print_r($payload, true));
    }

    private function failJson(string $reason, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode(['error' => $reason], JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($reason);
        }

        return self::FAILURE;
    }
}
