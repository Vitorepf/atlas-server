<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\ApiDiff;

final class AtlasCortexApiSurfaceDiffer
{
    /**
     * @param  array<string,array<string,mixed>>  $snapshotA
     * @param  array<string,array<string,mixed>>  $snapshotB
     * @return list<array<string,mixed>>
     */
    public function diff(array $snapshotA, array $snapshotB, string $fqcn): array
    {
        $methodNames = array_values(array_unique(array_merge(array_keys($snapshotA), array_keys($snapshotB))));
        sort($methodNames, SORT_STRING);

        $rows = [];
        foreach ($methodNames as $methodName) {
            $left = $snapshotA[$methodName] ?? null;
            $right = $snapshotB[$methodName] ?? null;

            if ($left === null) {
                $rows[] = [
                    'fqcn' => $fqcn,
                    'method_name' => $methodName,
                    'classification' => 'added',
                    'changed_fields' => [],
                ];

                continue;
            }

            if ($right === null) {
                $rows[] = [
                    'fqcn' => $fqcn,
                    'method_name' => $methodName,
                    'classification' => 'removed',
                    'changed_fields' => [],
                ];

                continue;
            }

            $changes = $this->changedFields($left, $right);
            $rows[] = [
                'fqcn' => $fqcn,
                'method_name' => $methodName,
                'classification' => $changes === [] ? 'unchanged' : 'changed',
                'changed_fields' => $changes,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $left
     * @param  array<string,mixed>  $right
     * @return list<array<string,mixed>>
     */
    private function changedFields(array $left, array $right): array
    {
        if ((string) ($left['signature_hash'] ?? '') === (string) ($right['signature_hash'] ?? '')) {
            return [];
        }

        $changes = [];
        $leftParameters = array_values((array) ($left['parameters'] ?? []));
        $rightParameters = array_values((array) ($right['parameters'] ?? []));
        $max = max(count($leftParameters), count($rightParameters));

        for ($index = 0; $index < $max; $index++) {
            $leftParameter = is_array($leftParameters[$index] ?? null) ? $leftParameters[$index] : null;
            $rightParameter = is_array($rightParameters[$index] ?? null) ? $rightParameters[$index] : null;

            if ($leftParameter === null && $rightParameter !== null) {
                $changes[] = [
                    'kind' => 'param_added',
                    'parameter' => (string) ($rightParameter['name'] ?? ''),
                ];

                continue;
            }

            if ($leftParameter !== null && $rightParameter === null) {
                $changes[] = [
                    'kind' => 'param_removed',
                    'parameter' => (string) ($leftParameter['name'] ?? ''),
                ];

                continue;
            }

            if ($leftParameter === null || $rightParameter === null) {
                continue;
            }

            if ((string) ($leftParameter['type_hint'] ?? '') !== (string) ($rightParameter['type_hint'] ?? '')) {
                $changes[] = [
                    'kind' => 'param_type_changed',
                    'parameter' => (string) ($rightParameter['name'] ?? $leftParameter['name'] ?? ''),
                    'from' => (string) ($leftParameter['type_hint'] ?? ''),
                    'to' => (string) ($rightParameter['type_hint'] ?? ''),
                ];
            }

            if ((string) ($leftParameter['default_literal'] ?? '') !== (string) ($rightParameter['default_literal'] ?? '')) {
                $changes[] = [
                    'kind' => 'param_default_changed',
                    'parameter' => (string) ($rightParameter['name'] ?? $leftParameter['name'] ?? ''),
                    'from' => (string) ($leftParameter['default_literal'] ?? ''),
                    'to' => (string) ($rightParameter['default_literal'] ?? ''),
                ];
            }
        }

        if ((string) ($left['return_type_hint'] ?? '') !== (string) ($right['return_type_hint'] ?? '')) {
            $changes[] = [
                'kind' => 'return_type_changed',
                'from' => (string) ($left['return_type_hint'] ?? ''),
                'to' => (string) ($right['return_type_hint'] ?? ''),
            ];
        }

        if ((bool) ($left['static'] ?? false) !== (bool) ($right['static'] ?? false)) {
            $changes[] = [
                'kind' => 'static_changed',
                'from' => (bool) ($left['static'] ?? false),
                'to' => (bool) ($right['static'] ?? false),
            ];
        }

        return $changes;
    }
}
