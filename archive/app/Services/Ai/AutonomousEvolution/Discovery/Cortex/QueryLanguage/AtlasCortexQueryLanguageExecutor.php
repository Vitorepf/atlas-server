<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\QueryLanguage;

final class AtlasCortexQueryLanguageExecutor
{
    /**
     * @param  array<string,mixed>  $ast
     * @param  list<array<string,mixed>>  $snapshot
     * @return array<string,mixed>
     */
    public function execute(array $ast, array $snapshot, string $snapshotVersion): array
    {
        $filtered = array_values(array_filter(
            $snapshot,
            fn (array $row): bool => $this->matchesWhere($row, is_array($ast['WHERE'] ?? null) ? $ast['WHERE'] : null)
        ));

        $groupBy = array_values(array_map('strval', (array) ($ast['GROUP BY'] ?? [])));
        $rows = $groupBy === []
            ? $this->projectRows($filtered, (array) ($ast['SELECT'] ?? []))
            : $this->groupRows($filtered, (array) ($ast['SELECT'] ?? []), $groupBy);

        $rows = $this->orderRows($rows, (array) ($ast['ORDER BY'] ?? []));

        $limit = (int) ($ast['LIMIT'] ?? 0);
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        return [
            'columns' => $this->columns((array) ($ast['SELECT'] ?? [])),
            'rows' => $rows,
            'group_by' => $groupBy,
            'applied_filters' => isset($ast['WHERE']) ? [$ast['WHERE']] : [],
            'snapshot_version' => $snapshotVersion,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $where
     */
    private function matchesWhere(array $row, ?array $where): bool
    {
        if ($where === null) {
            return true;
        }

        $field = (string) ($where['field'] ?? '');
        $operator = (string) ($where['operator'] ?? '');
        $actual = $row[$field] ?? null;
        $expected = $where['value'] ?? null;

        return match ($operator) {
            '=' => $actual === $expected,
            '!=' => $actual !== $expected,
            '<' => $this->compare($actual, $expected) < 0,
            '<=' => $this->compare($actual, $expected) <= 0,
            '>' => $this->compare($actual, $expected) > 0,
            '>=' => $this->compare($actual, $expected) >= 0,
            'IN' => is_array($expected) && in_array($actual, $expected, true),
            'NOT IN' => is_array($expected) && ! in_array($actual, $expected, true),
            'EXISTS' => array_key_exists($field, $row) && $actual !== null,
            'NOT EXISTS' => ! array_key_exists($field, $row) || $actual === null,
            default => false,
        };
    }

    /**
     * @param  list<string>  $select
     * @return list<array<string,mixed>>
     */
    private function projectRows(array $rows, array $select): array
    {
        $projected = [];
        foreach ($rows as $row) {
            $projected[] = $this->projectRow($row, $select);
        }

        return $projected;
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  list<string>  $select
     * @return array<string,mixed>
     */
    private function projectRow(array $row, array $select): array
    {
        $out = [];
        foreach ($select as $column) {
            $column = (string) $column;
            $out[$column] = $row[$column] ?? null;
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $select
     * @param  list<string>  $groupBy
     * @return list<array<string,mixed>>
     */
    private function groupRows(array $rows, array $select, array $groupBy): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $keyParts = [];
            foreach ($groupBy as $field) {
                $keyParts[$field] = $row[$field] ?? null;
            }
            $key = json_encode($keyParts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $groups[$key]['rows'][] = $row;
            $groups[$key]['group'] = $keyParts;
        }

        ksort($groups, SORT_STRING);

        $out = [];
        foreach ($groups as $group) {
            $rowsInGroup = (array) ($group['rows'] ?? []);
            $groupRow = (array) ($group['group'] ?? []);
            $result = [];

            foreach ($select as $column) {
                $column = (string) $column;
                if (array_key_exists($column, $groupRow)) {
                    $result[$column] = $groupRow[$column];
                    continue;
                }

                $result[$column] = $this->aggregate($column, $rowsInGroup);
            }

            $out[] = $result;
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function aggregate(string $expression, array $rows): int|float|null
    {
        if ($expression === 'count(*)') {
            return count($rows);
        }

        if (preg_match('/^(sum|min|max)\(([^)]+)\)$/', $expression, $match) !== 1) {
            return null;
        }

        $function = $match[1];
        $field = $match[2];
        $values = array_values(array_filter(
            array_map(static fn (array $row): mixed => $row[$field] ?? null, $rows),
            static fn (mixed $value): bool => is_int($value) || is_float($value)
        ));

        if ($values === []) {
            return null;
        }

        return match ($function) {
            'sum' => array_sum($values),
            'min' => min($values),
            'max' => max($values),
            default => null,
        };
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $orderBy
     * @return list<array<string,mixed>>
     */
    private function orderRows(array $rows, array $orderBy): array
    {
        usort($rows, function (array $left, array $right) use ($orderBy): int {
            foreach ($orderBy as $field) {
                $field = (string) $field;
                $cmp = $this->compare($left[$field] ?? null, $right[$field] ?? null);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return $this->primaryKey($left) <=> $this->primaryKey($right);
        });

        return $rows;
    }

    /**
     * @param  list<string>  $select
     * @return list<string>
     */
    private function columns(array $select): array
    {
        return array_values(array_map('strval', $select));
    }

    private function compare(mixed $left, mixed $right): int
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return $left <=> $right;
        }

        return strcmp((string) $left, (string) $right);
    }

    private function primaryKey(array $row): string
    {
        return implode('|', array_map(
            static fn (string $field): string => (string) ($row[$field] ?? ''),
            ['fqcn', 'file_path', 'target_symbol']
        ));
    }
}
