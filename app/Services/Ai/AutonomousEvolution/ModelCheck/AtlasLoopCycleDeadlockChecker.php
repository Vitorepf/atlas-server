<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\ModelCheck;

/**
 * Pure deterministic model checker over an extracted FSM.
 *
 * Detects:
 *   - deadlocks: reachable NON-terminal states with no outgoing transitions.
 *   - livelocks: reachable SCCs of size ≥ 2 that have no edge exiting to a non-component state.
 *
 * Output: `{is_fact: true, deadlocks: [...], livelocks: [...], visited_states: [...], witnesses: ...}`.
 * No scalar score / quality / rating. NEVER writes outside the verdict path passed in.
 */
final class AtlasLoopCycleDeadlockChecker
{
    public const SCHEMA = 'atlas.loop.cycle_deadlock_check.v1';

    /**
     * @param  array<string,mixed>  $fsm  output of AtlasLoopCycleStateMachineExtractor::extract()
     * @return array<string,mixed>
     */
    public function check(array $fsm): array
    {
        $entry = (string) ($fsm['entry_state'] ?? '');
        $terminals = array_values(array_map('strval', (array) ($fsm['terminal_states'] ?? [])));
        $states = array_values(array_map('strval', (array) ($fsm['states'] ?? [])));
        $transitions = array_values((array) ($fsm['transitions'] ?? []));

        $adj = [];
        foreach ($transitions as $t) {
            $adj[(string) $t['from']][] = (string) $t['to'];
        }

        // 1. Reachability from entry.
        $reachable = [];
        $stack = [$entry];
        while ($stack !== []) {
            $s = array_pop($stack);
            if ($s === '' || isset($reachable[$s])) {
                continue;
            }
            $reachable[$s] = true;
            foreach ($adj[$s] ?? [] as $to) {
                if (! isset($reachable[$to])) {
                    $stack[] = $to;
                }
            }
        }

        // 2. Deadlocks: reachable, non-terminal, no outgoing edges.
        $deadlocks = [];
        foreach (array_keys($reachable) as $s) {
            if (in_array($s, $terminals, true)) {
                continue;
            }
            if (! isset($adj[$s]) || $adj[$s] === []) {
                $deadlocks[] = ['state' => $s, 'witness_trace' => $this->traceFromEntry($adj, $entry, $s)];
            }
        }

        // 3. Livelocks: Tarjan SCC on reachable subgraph, components of size ≥ 2 with no exit.
        $livelocks = $this->detectLivelocks($adj, array_keys($reachable), $terminals, $entry);

        $verdict = [
            'schema' => self::SCHEMA,
            'is_fact' => true,
            'entry_state' => $entry,
            'terminal_states' => $terminals,
            'visited_states' => $this->sortedStrings(array_keys($reachable)),
            'deadlocks' => $deadlocks,
            'livelocks' => $livelocks,
        ];
        $verdict['verdict_hash'] = 'verdict_'.substr(hash('sha256', (string) json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 24);

        return $verdict;
    }

    /**
     * @param  array<string,mixed>  $fsm
     */
    public function checkAndWrite(array $fsm, string $verdictPath): array
    {
        $verdict = $this->check($fsm);
        $dir = dirname($verdictPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        @file_put_contents($verdictPath, (string) json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $verdict;
    }

    /**
     * @param  array<string, list<string>>  $adj
     * @return list<string>
     */
    private function traceFromEntry(array $adj, string $entry, string $target): array
    {
        $queue = [[$entry, [$entry]]];
        $visited = [$entry => true];
        while ($queue !== []) {
            [$s, $path] = array_shift($queue);
            if ($s === $target) {
                return $path;
            }
            foreach ($adj[$s] ?? [] as $next) {
                if (! isset($visited[$next])) {
                    $visited[$next] = true;
                    $queue[] = [$next, array_merge($path, [$next])];
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string, list<string>>  $adj
     * @param  list<string>  $reachable
     * @param  list<string>  $terminals
     * @return list<array{component:list<string>, witness_cycle:list<string>}>
     */
    private function detectLivelocks(array $adj, array $reachable, array $terminals, string $entry): array
    {
        $reachableSet = array_flip($reachable);
        $localAdj = [];
        foreach ($adj as $from => $tos) {
            if (! isset($reachableSet[$from])) {
                continue;
            }
            $localAdj[$from] = array_values(array_filter($tos, static fn (string $to): bool => isset($reachableSet[$to])));
        }

        $index = [];
        $lowlink = [];
        $onStack = [];
        $stack = [];
        $counter = 0;
        $sccs = [];

        $strongConnect = function (string $v) use (&$strongConnect, &$index, &$lowlink, &$onStack, &$stack, &$counter, &$sccs, $localAdj): void {
            $index[$v] = $counter;
            $lowlink[$v] = $counter;
            $counter++;
            $stack[] = $v;
            $onStack[$v] = true;

            foreach ($localAdj[$v] ?? [] as $w) {
                if (! isset($index[$w])) {
                    $strongConnect($w);
                    $lowlink[$v] = min($lowlink[$v], $lowlink[$w]);
                } elseif (! empty($onStack[$w])) {
                    $lowlink[$v] = min($lowlink[$v], $index[$w]);
                }
            }

            if ($lowlink[$v] === $index[$v]) {
                $component = [];
                while (true) {
                    $w = array_pop($stack);
                    if ($w === null) {
                        break;
                    }
                    unset($onStack[$w]);
                    $component[] = $w;
                    if ($w === $v) {
                        break;
                    }
                }
                if (count($component) >= 2) {
                    sort($component, SORT_STRING);
                    $sccs[] = $component;
                }
            }
        };

        foreach ($reachable as $node) {
            if (! isset($index[$node])) {
                $strongConnect($node);
            }
        }

        $livelocks = [];
        foreach ($sccs as $component) {
            $componentSet = array_flip($component);
            $hasExit = false;
            foreach ($component as $node) {
                foreach ($localAdj[$node] ?? [] as $to) {
                    if (! isset($componentSet[$to])) {
                        $hasExit = true;
                        break 2;
                    }
                }
            }
            if (! $hasExit) {
                $livelocks[] = [
                    'component' => $component,
                    'witness_cycle' => $component,
                ];
            }
        }

        return $livelocks;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sortedStrings(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }
}
