<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\NonFunctional;

/**
 * Engineering Kernel probe (Obra #3): a PURE, deterministic detector of architectural regression —
 * a newly-added import edge that crosses a forbidden layer boundary.
 *
 * Owns: matching the diff's ADDED import edges (from-namespace => to-namespace) against a forbidden
 * ruleset and reporting the violating edges, so the sovereign floor can block a delivery that rots
 * the layering (e.g. the pure EngineeringKernel floor importing Eloquent).
 * Must never own: the verdict (SovereignHonestyFloor) or reading files (the adapter extracts edges).
 */
final class ArchitectureRegressionProbe
{
    /**
     * Sane defaults, dogfoodable: the sovereign kernel floor is pure by design, so an edge from it
     * into the database/HTTP/console layers is a real regression. Config may add more via
     * atlas.engineering_kernel.forbidden_layer_edges (a list of {from,to} namespace prefixes).
     *
     * @return list<array{from:string,to:string}>
     */
    public static function defaultRules(): array
    {
        return [
            // the pure kernel must stay free of framework I/O
            ['from' => 'App\\Services\\Ai\\EngineeringKernel\\', 'to' => 'Illuminate\\Database'],
            ['from' => 'App\\Services\\Ai\\EngineeringKernel\\', 'to' => 'App\\Models\\'],
            ['from' => 'App\\Services\\Ai\\EngineeringKernel\\', 'to' => 'Illuminate\\Http'],
        ];
    }

    /**
     * Regex-parse raw PHP sources into import edges {from, to}.
     *
     * @param  array<string,string>  $sources  map of path => raw PHP source
     * @return list<array{from:string,to:string}>
     */
    public static function edgesFromSources(array $sources): array
    {
        $edges = [];
        foreach ($sources as $path => $source) {
            if (! is_string($source)) {
                continue;
            }

            // Extract the declared namespace
            $namespace = '';
            if (preg_match('/^\s*namespace\s+([^;]+);/m', $source, $m)) {
                $namespace = trim($m[1]);
            }
            if ($namespace === '') {
                continue;
            }

            // 1. Simple use statements: use Foo\Bar;  or  use \Foo\Bar as Baz;
            if (preg_match_all('/^\s*use\s+(\\\\?)([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?:\s+as\s+\w+)?\s*;/m', $source, $simpleMatches, PREG_SET_ORDER)) {
                foreach ($simpleMatches as $match) {
                    $edges[] = [
                        'from' => $namespace,
                        'to' => $match[2],
                    ];
                }
            }

            // 2. Grouped use statements: use App\Foo\{Bar, Baz};
            if (preg_match_all('/^\s*use\s+(\\\\?)([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\{([^}]+)\}\s*;/m', $source, $groupedMatches, PREG_SET_ORDER)) {
                foreach ($groupedMatches as $match) {
                    $prefix = $match[1].$match[2];
                    $members = explode(',', $match[3]);
                    foreach ($members as $member) {
                        $member = trim($member);
                        if ($member === '') {
                            continue;
                        }
                        $edges[] = [
                            'from' => $namespace,
                            'to' => ltrim($prefix.$member, '\\'),
                        ];
                    }
                }
            }
        }

        return $edges;
    }

    /**
     * @param  list<array{from:string,to:string}>  $addedEdges   import edges the diff ADDS
     * @param  list<array{from:string,to:string}>|null  $forbiddenRules  null => defaults
     * @return list<string>  human-readable violating edges (empty = clean)
     */
    public static function violations(array $addedEdges, ?array $forbiddenRules = null): array
    {
        $rules = $forbiddenRules ?? self::defaultRules();
        $out = [];
        foreach ($addedEdges as $edge) {
            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            if ($from === '' || $to === '') {
                continue;
            }
            foreach ($rules as $rule) {
                $rf = (string) ($rule['from'] ?? '');
                $rt = (string) ($rule['to'] ?? '');
                if ($rf !== '' && $rt !== '' && str_starts_with($from, $rf) && str_starts_with($to, $rt)) {
                    $out[] = $from.' -> '.$to.' [forbidden: '.$rf.'* -> '.$rt.'*]';
                }
            }
        }

        return array_values(array_unique($out));
    }
}
