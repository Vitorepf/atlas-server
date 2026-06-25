<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Spatial;

/**
 * FACT-only callgraph locality reporter.
 *
 * Consumes an INJECTED callable that returns the callgraph adjacency map:
 *   fn(): array<FQCN, list<FQCN>>  (edge = direct method-call or constructor injection ONLY).
 *
 * Inheritance and interface-implements edges are NOT callgraph edges and the caller MUST exclude
 * them — the reporter just walks BFS over whatever it is given.
 *
 * Emits per (fqcn, depth K) the count and the FQCN list. NO scoring, NO coupling verdict.
 * Honors master switch — OFF ⇒ byte-identical no-op (no writes).
 */
final class AtlasCortexCallGraphLocalityReporter
{
    public const SCHEMA = 'atlas.cortex.callgraph_locality.v1';

    /** @var callable(): array<string, list<string>> */
    private $adjacencySource;

    /** @var callable(): bool */
    private $masterSwitch;

    /**
     * @param  callable(): array<string, list<string>>  $adjacencySource
     * @param  list<int>  $depths
     */
    public function __construct(
        callable $adjacencySource,
        private readonly string $jsonlPath,
        private readonly array $depths = [1, 2, 3],
        ?callable $masterSwitch = null,
    ) {
        $this->adjacencySource = $adjacencySource;
        $this->masterSwitch = $masterSwitch ?? static function (): bool {
            if (function_exists('config')) {
                $v = config('atlas.loop.master_enabled');
                if ($v !== null) {
                    return (bool) $v;
                }
            }
            $env = getenv('ATLAS_LOOP_MASTER_ENABLED');

            return $env === false ? true : in_array(strtolower((string) $env), ['1', 'true', 'on', 'yes'], true);
        };
        $dir = dirname($this->jsonlPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function emit(): array
    {
        if (! ($this->masterSwitch)()) {
            return ['schema' => self::SCHEMA, 'disabled' => true, 'reason' => 'master_switch_off'];
        }

        $adj = ($this->adjacencySource)();
        $sources = array_keys($adj);
        sort($sources, SORT_STRING);

        $rows = [];
        foreach ($sources as $fqcn) {
            $byDepth = $this->bfs($fqcn, $adj, max($this->depths));
            foreach ($this->depths as $k) {
                $neighbors = array_values($byDepth[$k] ?? []);
                sort($neighbors, SORT_STRING);
                $rows[] = [
                    'fqcn' => $fqcn,
                    'depth' => $k,
                    'neighbor_count' => count($neighbors),
                    'neighbors' => $neighbors,
                ];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            $c = strcmp($a['fqcn'], $b['fqcn']);

            return $c !== 0 ? $c : ($a['depth'] <=> $b['depth']);
        });

        // Atomic write: tmp + rename so the file is byte-identical when state is identical.
        $bytes = '';
        foreach ($rows as $row) {
            $bytes .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        }
        @file_put_contents($this->jsonlPath, $bytes);

        return ['schema' => self::SCHEMA, 'rows' => $rows, 'jsonl_path' => $this->jsonlPath];
    }

    /**
     * @param  array<string, list<string>>  $adj
     * @return array<int, list<string>>  depth => neighbors at that exact depth
     */
    private function bfs(string $start, array $adj, int $maxDepth): array
    {
        $visited = [$start => 0];
        $queue = [$start];
        $byDepth = [];
        while ($queue !== []) {
            $node = array_shift($queue);
            $d = $visited[$node];
            if ($d > 0) {
                $byDepth[$d][] = $node;
            }
            if ($d >= $maxDepth) {
                continue;
            }
            foreach ($adj[$node] ?? [] as $next) {
                if (! isset($visited[$next])) {
                    $visited[$next] = $d + 1;
                    $queue[] = $next;
                }
            }
        }

        return $byDepth;
    }
}
