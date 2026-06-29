<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Introspection;

use InvalidArgumentException;

/**
 * Read-only constructor-dependency graph reporter over `app/Services/Ai/AutonomousEvolution/**`.
 *
 * Emits a FACT array: nodes (sorted FQCN list), edges (sorted from→to pairs), graph_depth (longest
 * acyclic path length), graph_width (max fan-out), cycles (list of SCC groups of size ≥ 2). NO
 * quality grade / score / rating — only structural integers.
 *
 * Pure: regex parses constructor parameter types from source. No autoloader side-effect, no DB,
 * no network, no provider.
 */
final class AtlasLoopSelfDependencyGraphReporter
{
    public const SCHEMA = 'atlas.loop.self_dependency_graph_facts.v1';

    public function __construct(private readonly ?string $rootPathOverride = null) {}

    /**
     * @return array<string,mixed>
     */
    public function scan(?string $absoluteRoot = null): array
    {
        $root = $absoluteRoot ?? $this->defaultRoot();
        $this->assertWithinAutonomousEvolution($root);
        if (! is_dir($root)) {
            throw new InvalidArgumentException('AtlasLoopSelfDependencyGraphReporter: root not a directory: '.$root);
        }

        $files = $this->collectPhpFiles($root);
        $nodes = [];
        $edges = []; // canonicalised as "from|to"
        $useMap = []; // per-file: short name → FQCN
        $perFileClass = []; // file → FQCN

        // First pass: collect classes & their FQCN.
        foreach ($files as $file) {
            $src = (string) @file_get_contents($file);
            if ($src === '') {
                continue;
            }
            $namespace = $this->extractNamespace($src);
            $classes = $this->extractClasses($src);
            foreach ($classes as $class) {
                $fqcn = ($namespace !== '' ? $namespace.'\\' : '').$class;
                $nodes[$fqcn] = true;
                $perFileClass[$file] = $fqcn;
            }
        }

        // Second pass: edges from constructor param types into other AutonomousEvolution classes.
        foreach ($files as $file) {
            $src = (string) @file_get_contents($file);
            if ($src === '') {
                continue;
            }
            $namespace = $this->extractNamespace($src);
            $useMap[$file] = $this->extractUseMap($src);
            $fromFqcn = $perFileClass[$file] ?? null;
            if ($fromFqcn === null) {
                continue;
            }
            $ctorParams = $this->extractConstructorParamTypes($src);
            foreach ($ctorParams as $rawType) {
                $resolved = $this->resolveType($rawType, $namespace, $useMap[$file]);
                if ($resolved === null) {
                    continue;
                }
                if (! isset($nodes[$resolved])) {
                    continue;
                }
                if ($resolved === $fromFqcn) {
                    continue;
                }
                $edges[$fromFqcn.'|'.$resolved] = true;
            }
        }

        $sortedNodes = array_keys($nodes);
        sort($sortedNodes, SORT_STRING);

        $edgeRows = [];
        foreach (array_keys($edges) as $key) {
            [$from, $to] = explode('|', $key, 2);
            $edgeRows[] = ['from' => $from, 'to' => $to];
        }
        usort($edgeRows, static fn (array $a, array $b): int => strcmp($a['from'].'|'.$a['to'], $b['from'].'|'.$b['to']));

        $adj = $this->buildAdjacency($edgeRows);
        $cycles = $this->findStronglyConnectedComponentsOfSizeAtLeastTwo($sortedNodes, $adj);
        $depth = $this->longestAcyclicPathLength($sortedNodes, $adj, $cycles);
        $width = $this->maxFanOut($adj);

        return [
            'schema_version' => self::SCHEMA,
            'root' => $root,
            'nodes' => $sortedNodes,
            'edges' => $edgeRows,
            'graph_depth' => $depth,
            'graph_width' => $width,
            'cycles' => $cycles,
        ];
    }

    private function defaultRoot(): string
    {
        if ($this->rootPathOverride !== null) {
            return $this->rootPathOverride;
        }
        if (function_exists('base_path')) {
            return base_path('app/Services/Ai/AutonomousEvolution');
        }

        return __DIR__.'/../';
    }

    private function assertWithinAutonomousEvolution(string $root): void
    {
        $normalized = rtrim(str_replace('\\', '/', $root), '/');
        if (! str_contains($normalized, '/app/Services/Ai/AutonomousEvolution')) {
            throw new InvalidArgumentException('AtlasLoopSelfDependencyGraphReporter: root must be inside app/Services/Ai/AutonomousEvolution');
        }
    }

    /**
     * @return list<string>
     */
    private function collectPhpFiles(string $root): array
    {
        $files = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    private function extractNamespace(string $src): string
    {
        if (preg_match('/^\s*namespace\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*;/m', $src, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    /**
     * @return array<string,string>  short name → FQCN
     */
    private function extractUseMap(string $src): array
    {
        $map = [];
        if (preg_match_all('/^\s*use\s+([A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/m', $src, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $m) {
                $fqcn = (string) $m[1];
                $alias = isset($m[2]) && $m[2] !== '' ? (string) $m[2] : substr($fqcn, (int) (strrpos($fqcn, '\\') ?: -1) + 1);
                $map[$alias] = $fqcn;
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function extractClasses(string $src): array
    {
        $names = [];
        if (preg_match_all('/^(?:final\s+|abstract\s+)?(?:final\s+|abstract\s+)?(?:readonly\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $src, $m) > 0) {
            foreach ($m[1] as $name) {
                $names[] = (string) $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private function extractConstructorParamTypes(string $src): array
    {
        // Find the public function __construct(...) signature and pull typed params.
        // Use balanced-paren extraction so defaults like `= new Foo()` don't truncate.
        if (preg_match('/public\s+function\s+__construct\s*\(/s', $src, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }
        $openPos = (int) $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $len = strlen($src);
        $i = $openPos;
        while ($i < $len && $depth > 0) {
            if ($src[$i] === '(') {
                $depth++;
            } elseif ($src[$i] === ')') {
                $depth--;
            }
            $i++;
        }
        $params = substr($src, $openPos, $i - $openPos - 1);
        $types = [];
        // Match "Type $var" or "?Type $var" or "private readonly Type $var" etc.
        if (preg_match_all('/(?:private|public|protected)?\s*(?:readonly\s+)?(?:\?\s*)?([A-Za-z_][A-Za-z0-9_\\\\]*)\s+\$[A-Za-z_]/', $params, $tm) > 0) {
            foreach ($tm[1] as $t) {
                $type = trim((string) $t);
                if ($type === '' || in_array(strtolower($type), ['array', 'string', 'int', 'float', 'bool', 'mixed', 'iterable', 'callable', 'object', 'self', 'static', 'parent', 'void', 'null', 'true', 'false'], true)) {
                    continue;
                }
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @param  array<string,string>  $useMap
     */
    private function resolveType(string $type, string $namespace, array $useMap): ?string
    {
        if ($type === '') {
            return null;
        }
        if (str_starts_with($type, '\\')) {
            return ltrim($type, '\\');
        }
        $head = strstr($type, '\\', true);
        $head = $head === false ? $type : $head;
        if (isset($useMap[$head])) {
            return $useMap[$head].(str_contains($type, '\\') ? substr($type, strpos($type, '\\')) : '');
        }

        // Same-namespace resolution.
        return $namespace !== '' ? $namespace.'\\'.$type : $type;
    }

    /**
     * @param  list<array{from:string,to:string}>  $edges
     * @return array<string, list<string>>
     */
    private function buildAdjacency(array $edges): array
    {
        $adj = [];
        foreach ($edges as $e) {
            $adj[$e['from']][] = $e['to'];
        }
        foreach ($adj as $from => $tos) {
            $unique = array_values(array_unique($tos));
            sort($unique, SORT_STRING);
            $adj[$from] = $unique;
        }

        return $adj;
    }

    /**
     * @param  list<string>  $nodes
     * @param  array<string, list<string>>  $adj
     * @return list<list<string>>
     */
    private function findStronglyConnectedComponentsOfSizeAtLeastTwo(array $nodes, array $adj): array
    {
        // Tarjan-lite recursion for SCCs.
        $index = [];
        $lowlink = [];
        $onStack = [];
        $stack = [];
        $counter = 0;
        $sccs = [];

        $strongConnect = function (string $v) use (&$strongConnect, &$index, &$lowlink, &$onStack, &$stack, &$counter, &$sccs, $adj): void {
            $index[$v] = $counter;
            $lowlink[$v] = $counter;
            $counter++;
            $stack[] = $v;
            $onStack[$v] = true;

            foreach ($adj[$v] ?? [] as $w) {
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

        foreach ($nodes as $node) {
            if (! isset($index[$node])) {
                $strongConnect($node);
            }
        }

        usort($sccs, static fn (array $a, array $b): int => strcmp(implode(',', $a), implode(',', $b)));

        return $sccs;
    }

    /**
     * @param  list<string>  $nodes
     * @param  array<string, list<string>>  $adj
     * @param  list<list<string>>  $cycles
     */
    private function longestAcyclicPathLength(array $nodes, array $adj, array $cycles): int
    {
        // Skip nodes that are in any SCC of size ≥ 2 to keep the path acyclic.
        $inCycle = [];
        foreach ($cycles as $group) {
            foreach ($group as $n) {
                $inCycle[$n] = true;
            }
        }

        $memo = [];
        $dfs = function (string $v) use (&$dfs, &$memo, $adj, $inCycle): int {
            if (isset($memo[$v])) {
                return $memo[$v];
            }
            $best = 0;
            foreach ($adj[$v] ?? [] as $w) {
                if (isset($inCycle[$w])) {
                    continue;
                }
                $best = max($best, 1 + $dfs($w));
            }

            return $memo[$v] = $best;
        };

        $max = 0;
        foreach ($nodes as $n) {
            if (isset($inCycle[$n])) {
                continue;
            }
            $max = max($max, $dfs($n));
        }

        return $max;
    }

    /**
     * @param  array<string, list<string>>  $adj
     */
    private function maxFanOut(array $adj): int
    {
        $max = 0;
        foreach ($adj as $tos) {
            $max = max($max, count($tos));
        }

        return $max;
    }
}
