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
