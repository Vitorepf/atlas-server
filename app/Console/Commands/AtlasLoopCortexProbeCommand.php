<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\AtlasCortexActiveSnapshot;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\AtlasCortexCallGraphProjector;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\AtlasCortexCriticalPathDetector;
use Illuminate\Console\Command;

/**
 * Operator surface of the cortex-active probes. READ-ONLY (no writes to workspace / ledger / Cortex index).
 *
 *   atlas:loop:cortex:probe --target=Sym --edit=remove --depth=N --flows=* [--json]
 *
 * Gated by ATLAS_LOOP_MASTER_ENABLED (must be ON unless --json is used to force a read-only render) and by the
 * feature flag `atlas.cortex.active.enabled` (default false ⇒ prints exactly one line `cortex.active.disabled`
 * byte-identical across runs). Output is FACTs only — no recommendation, no score, no ranking.
 *
 * The command resolves an optional container binding {@see self::INDEX_SOURCE_KEY} to obtain the index (a plain
 * adjacency map) the projector + detector consume. When absent the probes still emit (with empty bodies),
 * preserving the schema.
 */
final class AtlasLoopCortexProbeCommand extends Command
{
    public const INDEX_SOURCE_KEY = 'atlas.cortex.active.index_source';

    public const DISABLED_LINE = 'cortex.active.disabled';

    protected $signature = 'atlas:loop:cortex:probe {--target=} {--edit=} {--depth=8} {--flows=*} {--json}';

    protected $description = 'Read-only cortex-active probe — renders AtlasCortexActiveSnapshot FACTs (hypothetical_changes, counterfactuals, call_graph_projections, critical_paths, unknown_regions).';

    public function handle(): int
    {
        if (! (bool) config('atlas.cortex.active.enabled', false)) {
            $this->line(self::DISABLED_LINE);

            return self::SUCCESS;
        }

        if (! $this->masterSwitchOn() && ! (bool) $this->option('json')) {
            $this->line(self::DISABLED_LINE);

            return self::SUCCESS;
        }

        $target = trim((string) $this->option('target'));
        $edit = trim((string) $this->option('edit'));
        $depth = max(0, (int) $this->option('depth'));
        $flows = array_values(array_filter(array_map('strval', (array) $this->option('flows')), static fn (string $f): bool => $f !== ''));

        $index = $this->resolveIndex();
        $projector = new AtlasCortexCallGraphProjector($depth ?: 8);

        $projections = [];
        foreach ($flows as $flow) {
            $projections[] = $projector->project($flow, $depth, true, $index);
        }

        $criticalPaths = [];
        if ($flows !== []) {
            $detector = new AtlasCortexCriticalPathDetector($projector);
            $flowSpec = [];
            foreach ($flows as $f) {
                $flowSpec[$f] = ['entry' => $f, 'depth' => $depth, 'reverse' => true];
            }
            $criticalPaths = $detector->detect($flowSpec, $index, 1)['facts'];
        }

        $activeProbes = [
            'hypothetical_changes' => $target !== '' && $edit !== ''
                ? [['target' => $target, 'edit_kind' => $edit, 'depth' => $depth]]
                : [],
            'counterfactuals' => $target !== ''
                ? [['target' => $target, 'edit_kind' => $edit !== '' ? $edit : 'unspecified', 'fact' => 'TARGET_PROBED']]
                : [],
            'call_graph_projections' => $projections,
            'critical_paths' => $criticalPaths,
        ];

        $passive = ['schema' => 'atlas.cortex.scope_comprehension.v1', 'note' => 'probe_invocation', 'target' => $target];
        $snapshot = new AtlasCortexActiveSnapshot($passive, $activeProbes);

        if ($this->option('json')) {
            $this->line($snapshot->toJson());

            return self::SUCCESS;
        }

        $payload = $snapshot->toArray();
        $this->line('schema='.$payload['schema']);
        foreach (['hypothetical_changes', 'counterfactuals', 'call_graph_projections', 'critical_paths', 'unknown_regions'] as $section) {
            $this->line($section.' count='.count((array) ($payload['active'][$section] ?? [])));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveIndex(): array
    {
        if (! app()->bound(self::INDEX_SOURCE_KEY)) {
            return ['callers' => [], 'callees' => [], 'symbols' => []];
        }
        $source = app(self::INDEX_SOURCE_KEY);
        $index = is_callable($source) ? $source() : $source;

        return is_array($index) ? $index : ['callers' => [], 'callees' => [], 'symbols' => []];
    }

    private function masterSwitchOn(): bool
    {
        return (bool) (env('ATLAS_LOOP_MASTER_ENABLED') ?? false);
    }
}
