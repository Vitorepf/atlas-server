<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObserverContract;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObserverRegistry;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightReceiptLedger;
use Illuminate\Console\Command;
use Throwable;

/**
 * Provider-free CLI for the Cortex Insights surface.
 *
 *   atlas:loop:cortex:insights inspect [--snapshot=] [--json]
 *   atlas:loop:cortex:insights axis    --axis=<axis_id> [--snapshot=] [--json]
 *   atlas:loop:cortex:insights history --axis=<axis_id> [--limit=20] [--json]
 *
 * Fail-closed: when `atlas.loop.cortex.insights.enabled` is FALSE the command exits 0 with the
 * single line "cortex insights disabled" (or `{disabled:true}` JSON) and writes nothing.
 */
final class AtlasLoopCortexInsightsCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:cortex:insights
        {action : inspect|axis|history}
        {--axis= : Axis id (required for axis / history)}
        {--snapshot= : Snapshot id (default: facts.snapshot_id)}
        {--limit=20 : history row cap}
        {--json : Emit machine-readable JSON}';

    /** @var string */
    protected $description = 'Cortex Insights CLI: inspect | axis | history (provider-free, fail-closed).';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $json = (bool) $this->option('json');

        if (! (bool) config('atlas.loop.cortex.insights.enabled', false)) {
            if ($json) {
                $this->line((string) json_encode(['disabled' => true], JSON_UNESCAPED_SLASHES));
            } else {
                $this->line('cortex insights disabled');
            }

            return self::SUCCESS;
        }

        try {
            return match ($action) {
                'inspect' => $this->doInspect($json),
                'axis' => $this->doAxis($json),
                'history' => $this->doHistory($json),
                default => $this->failJson('unknown_action:'.$action, $json),
            };
        } catch (Throwable $e) {
            return $this->failJson($e->getMessage(), $json);
        }
    }

    private function doInspect(bool $json): int
    {
        $registry = app(AtlasCortexInsightObserverRegistry::class);
        $facts = $this->factsBundle();
        $snapshotId = $this->snapshotId($facts);

        $observations = [];
        foreach ($registry->axes() as $axisId => $descriptor) {
            $obs = $this->runAxis($axisId, $descriptor, $facts);
            if ($obs !== null) {
                $observations[] = $obs;
                $this->appendLedger($snapshotId, $obs);
            }
        }

        $this->emit($json, ['snapshot_id' => $snapshotId, 'observations' => $observations]);

        return self::SUCCESS;
    }

    private function doAxis(bool $json): int
    {
        $axisId = trim((string) $this->option('axis'));
        if ($axisId === '') {
            return $this->failJson('axis_required', $json);
        }
        $registry = app(AtlasCortexInsightObserverRegistry::class);
        $axes = $registry->axes();
        if (! isset($axes[$axisId])) {
            return $this->failJson('unknown_axis:'.$axisId, $json);
        }
        $facts = $this->factsBundle();
        $snapshotId = $this->snapshotId($facts);
        $obs = $this->runAxis($axisId, $axes[$axisId], $facts);
        if ($obs !== null) {
            $this->appendLedger($snapshotId, $obs);
        }
        $this->emit($json, ['snapshot_id' => $snapshotId, 'observation' => $obs]);

        return self::SUCCESS;
    }

    private function doHistory(bool $json): int
    {
        $axisId = trim((string) $this->option('axis'));
        if ($axisId === '') {
            return $this->failJson('axis_required', $json);
        }
        $limit = max(1, (int) $this->option('limit'));
        $ledger = app(AtlasCortexInsightReceiptLedger::class);
        $rows = $ledger->history($axisId, $limit);
        $this->emit($json, ['axis_id' => $axisId, 'rows' => $rows]);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $descriptor
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>|null
     */
    private function runAxis(string $axisId, array $descriptor, array $facts): ?array
    {
        $fqcn = (string) ($descriptor['fqcn'] ?? '');
        if ($fqcn === '' || ! class_exists($fqcn)) {
            return null;
        }
        $observer = app($fqcn);
        if (! $observer instanceof AtlasCortexInsightObserverContract) {
            return null;
        }
        $envelope = $observer->observe($facts);
        if ($envelope === null || $envelope === []) {
            return null;
        }
        $envelope['axis_id'] = $axisId;

        return $envelope;
    }

    /**
     * @return array<string,mixed>
     */
    private function factsBundle(): array
    {
        if (app()->bound('atlas.loop.cortex.insights.facts')) {
            $bound = app('atlas.loop.cortex.insights.facts');
            if (is_array($bound)) {
                return $bound;
            }
            if (is_callable($bound)) {
                $r = $bound();

                return is_array($r) ? $r : [];
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $facts
     */
    private function snapshotId(array $facts): string
    {
        $supplied = (string) $this->option('snapshot');
        if ($supplied !== '') {
            return $supplied;
        }

        return (string) ($facts['snapshot_id'] ?? 'unknown-snapshot');
    }

    /**
     * @param  array<string,mixed>  $observation
     */
    private function appendLedger(string $snapshotId, array $observation): void
    {
        try {
            app(AtlasCortexInsightReceiptLedger::class)->append($snapshotId, $observation);
        } catch (Throwable) {
            // ledger append is fail-open — the FACT envelope still printed.
        }
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
