<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\QueryLanguage;

use InvalidArgumentException;

final class AtlasCortexQueryLanguageParser
{
    /**
     * @return array<string,mixed>
     */
    public function parse(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new InvalidArgumentException('Malformed token at position 0: empty query.');
        }

        $pattern = '/^SELECT\s+(?<select>.+?)\s+FROM\s+(?<from>\S+)(?:\s+WHERE\s+(?<where>.+?))?(?:\s+GROUP BY\s+(?<group>.+?))?(?:\s+ORDER BY\s+(?<order>.+?))?(?:\s+LIMIT\s+(?<limit>\d+))?$/i';
        if (! preg_match($pattern, $query, $matches)) {
            $token = strtok($query, ' ') ?: $query;
            throw new InvalidArgumentException("Malformed token at position 0: {$token}");
        }

        $spec = AtlasCortexQueryLanguageGrammar::spec();
        $ast = [
            'SELECT' => $this->parseList($matches['select'], (array) $spec['fields'], 'field'),
            'FROM' => $this->parseFrom($matches['from'], (array) $spec['from_scopes']),
        ];

        if (($matches['where'] ?? '') !== '') {
            $ast['WHERE'] = $this->parseWhere((string) $matches['where'], $spec);
        }

        if (($matches['group'] ?? '') !== '') {
            $ast['GROUP BY'] = $this->parseList($matches['group'], (array) $spec['dimensions'], 'dimension');
        }

        if (($matches['order'] ?? '') !== '') {
            $ast['ORDER BY'] = $this->parseList($matches['order'], (array) $spec['dimensions'], 'dimension');
        }

        if (($matches['limit'] ?? '') !== '') {
            $ast['LIMIT'] = (int) $matches['limit'];
        }

        return $ast;
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function parseList(string $raw, array $allowed, string $kind): array
    {
        $items = array_map('trim', explode(',', $raw));
        $items = array_values(array_filter($items, static fn (string $item): bool => $item !== ''));

        foreach ($items as $item) {
            if (! in_array($item, $allowed, true)) {
                throw new InvalidArgumentException("Unknown {$kind} token: {$item}");
            }
        }

        return $items;
    }

    /**
     * @param  list<string>  $allowedScopes
     */
    private function parseFrom(string $scope, array $allowedScopes): string
    {
        if (! in_array($scope, $allowedScopes, true)) {
            throw new InvalidArgumentException("Unknown scope token: {$scope}");
        }

        return $scope;
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    private function parseWhere(string $raw, array $spec): array
    {
        if (preg_match('/\b(LIKE|REGEX|MATCHES)\b/i', $raw, $bad) === 1) {
            throw new InvalidArgumentException('Forbidden operator token: '.$bad[1]);
        }

        if (preg_match('/\b([A-Za-z_][A-Za-z0-9_]*)\s*(=|!=|<=|>=|<|>|IN|NOT IN|EXISTS|NOT EXISTS)\s*(.*)$/i', trim($raw), $parts) !== 1) {
            throw new InvalidArgumentException('Malformed token in WHERE clause: '.trim($raw));
        }

        $field = $parts[1];
        $operator = strtoupper(trim($parts[2]));
        $value = trim($parts[3]);

        if (! in_array($field, (array) $spec['fields'], true)) {
            throw new InvalidArgumentException("Unknown field token: {$field}");
        }

        if (! in_array($operator, (array) $spec['operators'], true)) {
            throw new InvalidArgumentException("Forbidden operator token: {$operator}");
        }

        if (in_array($operator, ['EXISTS', 'NOT EXISTS'], true)) {
            return [
                'field' => $field,
                'operator' => $operator,
            ];
        }

        if ($value === '') {
            throw new InvalidArgumentException("Malformed token in WHERE clause: {$raw}");
        }

        return [
            'field' => $field,
            'operator' => $operator,
            'value' => $this->parseValue($value),
        ];
    }

    /**
     * @return string|int|list<string>
     */
    private function parseValue(string $value): string|int|array
    {
        $trimmed = trim($value);
        if (preg_match('/^\((.+)\)$/', $trimmed, $match) === 1) {
            return array_values(array_map(
                static fn (string $item): string => trim($item, " \t\n\r\0\x0B'\""),
                array_map('trim', explode(',', $match[1]))
            ));
        }

        if (ctype_digit($trimmed)) {
            return (int) $trimmed;
        }

        return trim($trimmed, "'\"");
    }
}
