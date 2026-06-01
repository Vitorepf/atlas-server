<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeTopology;

final class ForgeTopologyFallbackChainCoherenceValidator
{
    private const SCHEMA_VERSION = 'atlas.aaeos.forge_fallback_coherence.v1';

    private const DEFECT_EMPTY = 'empty_fallback_chain';

    private const DEFECT_NON_CONTIGUOUS = 'non_contiguous_order';

    private const DEFECT_DUPLICATE_ORDER = 'duplicate_order_value';

    private const DEFECT_DUPLICATE_ROLE = 'duplicate_role_in_chain';

    private const DEFECT_NO_CAPABLE = 'no_capable_fallback';

    /**
     * Inspect a materialized fallback_chain for ordering coherence.
     *
     * @param  list<array{order?:int,role?:string,capable?:bool}>  $fallbackChain
     * @return array{
     *     schema_version:string,
     *     coherent:bool,
     *     defects:list<array{code:string,detail:string}>,
     *     has_capable_entry:bool,
     *     ordered_roles:list<string>
     * }
     */
    public function inspect(array $fallbackChain): array
    {
        if ($fallbackChain === []) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'coherent' => false,
                'defects' => [
                    [
                        'code' => self::DEFECT_EMPTY,
                        'detail' => 'fallback_chain is empty; no fallback ordering to validate.',
                    ],
                ],
                'has_capable_entry' => false,
                'ordered_roles' => [],
            ];
        }

        $entries = $this->normalizeEntries($fallbackChain);
        $defects = [];

        $orders = array_map(static fn (array $entry): int => $entry['order'], $entries);

        foreach ($this->duplicateOrderDefects($orders) as $defect) {
            $defects[] = $defect;
        }

        $contiguityDefect = $this->contiguityDefect($orders);
        if ($contiguityDefect !== null) {
            $defects[] = $contiguityDefect;
        }

        $roles = array_map(static fn (array $entry): string => $entry['role'], $entries);

        foreach ($this->duplicateRoleDefects($roles) as $defect) {
            $defects[] = $defect;
        }

        $hasCapableEntry = false;
        foreach ($entries as $entry) {
            if ($entry['capable'] === true) {
                $hasCapableEntry = true;

                break;
            }
        }

        if (! $hasCapableEntry) {
            $defects[] = [
                'code' => self::DEFECT_NO_CAPABLE,
                'detail' => 'no fallback entry is marked capable; the chain cannot serve any request.',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'coherent' => $defects === [],
            'defects' => $defects,
            'has_capable_entry' => $hasCapableEntry,
            'ordered_roles' => $this->orderedRoles($entries),
        ];
    }

    /**
     * @param  list<array{order?:int,role?:string,capable?:bool}>  $fallbackChain
     * @return list<array{order:int,role:string,capable:bool}>
     */
    private function normalizeEntries(array $fallbackChain): array
    {
        $entries = [];
        foreach ($fallbackChain as $index => $entry) {
            $entries[] = [
                'order' => $this->intValue($entry['order'] ?? null),
                'role' => $this->stringValue($entry['role'] ?? null, $index),
                'capable' => ($entry['capable'] ?? false) === true,
            ];
        }

        return $entries;
    }

    /**
     * @param  list<int>  $orders
     * @return list<array{code:string,detail:string}>
     */
    private function duplicateOrderDefects(array $orders): array
    {
        $counts = [];
        foreach ($orders as $order) {
            $counts[$order] = ($counts[$order] ?? 0) + 1;
        }

        $duplicated = [];
        foreach ($counts as $order => $count) {
            if ($count > 1) {
                $duplicated[] = (int) $order;
            }
        }

        if ($duplicated === []) {
            return [];
        }

        sort($duplicated);

        $rendered = implode(', ', array_map(static fn (int $order): string => (string) $order, $duplicated));

        return [
            [
                'code' => self::DEFECT_DUPLICATE_ORDER,
                'detail' => 'duplicate order value(s) present: '.$rendered.'.',
            ],
        ];
    }

    /**
     * Contiguity is computed over the DISTINCT sorted order ints: each must
     * equal its 1-based position. Any gap or short coverage is non-contiguous.
     *
     * @param  list<int>  $orders
     * @return array{code:string,detail:string}|null
     */
    private function contiguityDefect(array $orders): ?array
    {
        $distinct = array_values(array_unique($orders));
        sort($distinct);

        $missing = [];
        foreach ($distinct as $position => $value) {
            $expected = $position + 1;
            if ($value !== $expected) {
                $missing[] = $expected;
            }
        }

        if ($missing === []) {
            return null;
        }

        $rendered = implode(', ', array_map(static fn (int $position): string => (string) $position, $missing));

        return [
            'code' => self::DEFECT_NON_CONTIGUOUS,
            'detail' => 'order sequence is not contiguous from 1; missing position(s): '.$rendered.'.',
        ];
    }

    /**
     * @param  list<string>  $roles
     * @return list<array{code:string,detail:string}>
     */
    private function duplicateRoleDefects(array $roles): array
    {
        $counts = [];
        foreach ($roles as $role) {
            $counts[$role] = ($counts[$role] ?? 0) + 1;
        }

        $duplicated = [];
        foreach ($counts as $role => $count) {
            if ($count > 1) {
                $duplicated[] = (string) $role;
            }
        }

        if ($duplicated === []) {
            return [];
        }

        sort($duplicated);

        return [
            [
                'code' => self::DEFECT_DUPLICATE_ROLE,
                'detail' => 'duplicate role(s) present in chain: '.implode(', ', $duplicated).'.',
            ],
        ];
    }

    /**
     * @param  list<array{order:int,role:string,capable:bool}>  $entries
     * @return list<string>
     */
    private function orderedRoles(array $entries): array
    {
        $sorted = $entries;
        usort($sorted, static function (array $left, array $right): int {
            return $left['order'] <=> $right['order'];
        });

        return array_map(static fn (array $entry): string => $entry['role'], $sorted);
    }

    private function intValue(mixed $value): int
    {
        return is_int($value) ? $value : (int) $value;
    }

    private function stringValue(mixed $value, int $index): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return 'role_'.$index;
    }
}
