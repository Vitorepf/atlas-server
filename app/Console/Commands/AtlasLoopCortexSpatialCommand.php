<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Spatial\AtlasCortexCallGraphLocalityReporter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Spatial\AtlasCortexFsLocalityReporter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Spatial\AtlasCortexLocalityIntersectionEmitter;
use Illuminate\Console\Command;

/**
 * Read-only operator surface for the three Cortex spatial reporters + history tail.
 *   atlas:loop:cortex:spatial fs        [--depth=N]* [--json]
 *   atlas:loop:cortex:spatial callgraph [--depth=N]* [--json]
 *   atlas:loop:cortex:spatial intersect [--json]
 *   atlas:loop:cortex:spatial history   [--fqcn=FQCN] [--limit=N] [--json]
 *
 * FACT-only. No scores. Never mutates code. With ATLAS_LOOP_MASTER_ENABLED=false the command
 * is a byte-identical no-op (exits 0 with explicit OFF message, writes ZERO bytes).
 */
final class AtlasLoopCortexSpatialCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:loop:cortex:spatial {mode : fs|callgraph|intersect|history}
        {--depth=* : depth (fs|callgraph), repeatable}
        {--fqcn= : FQCN filter (history)}
        {--limit=10 : history tail size}
        {--json}';

    protected $description = 'Cortex spatial locality CLI (fs | callgraph | intersect | history).';

    public function handle(): int
    {
        if (! $this->masterEnabled()) {
            $this->getOutput()->writeln('loop master switch is OFF — spatial cortex disabled');

            return self::EXIT_OK;
        }
        $mode = (string) $this->argument('mode');

        return match ($mode) {
            'fs' => $this->fs(),
            'callgraph' => $this->callgraph(),
            'intersect' => $this->intersect(),
            'history' => $this->history(),
            default => $this->refuse('unknown_mode:'.$mode),
        };
    }

    private function fs(): int
    {
        $reporter = $this->fsReporter($this->depths([1, 2]));
        $records = $reporter->report()->all();
        $this->emit(array_values($records));

        return self::EXIT_OK;
    }

    private function callgraph(): int
    {
        $reporter = $this->callGraphReporter($this->depths([1, 2]));
        $result = $reporter->emit();
        $this->emit($result);

        return self::EXIT_OK;
    }

    private function intersect(): int
    {
        $emitter = $this->intersectionEmitter();
        try {
            $result = $emitter->emit();
        } catch (\Throwable $e) {
            $result = ['schema' => 'atlas.cortex.locality_intersection.v1', 'unavailable_reason' => $e->getMessage()];
        }
        $this->emit($result);

        return self::EXIT_OK;
    }

    private function history(): int
    {
        $fqcn = (string) ($this->option('fqcn') ?? '');
        $limit = max(1, (int) ($this->option('limit') ?? 10));
        $roots = [
            'fs' => $this->storagePath('atlas/cortex/spatial/fs'),
            'callgraph' => $this->storagePath('atlas/cortex/spatial/callgraph'),
            'intersection' => $this->storagePath('atlas/cortex/spatial/intersection'),
        ];
        $rows = [];
        foreach ($roots as $kind => $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (glob($dir.'/*.jsonl') ?: [] as $file) {
                foreach ((array) file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $decoded = json_decode((string) $line, true);
                    if (! is_array($decoded)) {
                        continue;
                    }
                    if ($fqcn !== '' && ! $this->mentionsFqcn($decoded, $fqcn)) {
                        continue;
                    }
                    $decoded['__source_kind'] = $kind;
                    $rows[] = $decoded;
                }
            }
        }
        $rows = array_slice($rows, -$limit);
        $this->emit($rows);

        return self::EXIT_OK;
    }

    /**
     * @param  array<int,int>  $fallback
     * @return list<int>
     */
    private function depths(array $fallback): array
    {
        $depths = (array) ($this->option('depth') ?? []);
        $depths = array_values(array_filter(array_map('intval', $depths), static fn (int $d): bool => $d >= 1));
        if ($depths === []) {
            return array_values($fallback);
        }
        sort($depths, SORT_NUMERIC);

        return array_values(array_unique($depths));
    }

    private function fsReporter(array $depths): AtlasCortexFsLocalityReporter
    {
        if (app()->bound(AtlasCortexFsLocalityReporter::class)) {
            return app(AtlasCortexFsLocalityReporter::class);
        }
        $scope = function_exists('base_path') ? base_path(AtlasCortexFsLocalityReporter::ALLOWED_SCOPE_RELATIVE) : '';
        $storage = $this->storagePath('atlas/cortex/spatial/fs');

        return new AtlasCortexFsLocalityReporter($scope, $storage, $depths);
    }

    private function callGraphReporter(array $depths): AtlasCortexCallGraphLocalityReporter
    {
        if (app()->bound(AtlasCortexCallGraphLocalityReporter::class)) {
            return app(AtlasCortexCallGraphLocalityReporter::class);
        }
        $path = $this->storagePath('atlas/cortex/spatial/callgraph').'/locality.jsonl';

        return new AtlasCortexCallGraphLocalityReporter(
            static fn (): array => [],
            $path,
            $depths,
        );
    }

    private function intersectionEmitter(): AtlasCortexLocalityIntersectionEmitter
    {
        if (app()->bound(AtlasCortexLocalityIntersectionEmitter::class)) {
            return app(AtlasCortexLocalityIntersectionEmitter::class);
        }

        return new AtlasCortexLocalityIntersectionEmitter(
            $this->storagePath('atlas/cortex/spatial/fs').'/locality.jsonl',
            $this->storagePath('atlas/cortex/spatial/callgraph').'/locality.jsonl',
            $this->storagePath('atlas/cortex/spatial/intersection').'/locality.jsonl',
        );
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function mentionsFqcn(array $row, string $fqcn): bool
    {
        return str_contains((string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $fqcn);
    }

    private function masterEnabled(): bool
    {
        if (! function_exists('config')) {
            return false;
        }

        return (bool) config('atlas.loop.master_enabled', false);
    }

    private function storagePath(string $rel): string
    {
        return function_exists('storage_path') ? storage_path($rel) : sys_get_temp_dir().'/'.$rel;
    }

    /**
     * @param  array<int|string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        $this->getOutput()->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function refuse(string $reason): int
    {
        $this->getOutput()->writeln($reason);

        return self::EXIT_USAGE;
    }
}
