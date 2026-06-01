<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasForge;

final class ForgeSymbolCollisionClassifierService
{
    private const SCHEMA_VERSION = 'atlas.collision.report.v1';

    private const SELF_AGENT = 'self';

    private const MATCH_EXACT = 'exact';

    private const MATCH_NAMESPACE_PREFIX = 'namespace_prefix';

    /**
     * @param  list<string>  $branchSymbols  fully-qualified symbol strings for the candidate branch
     * @param  array<string, list<string>>  $otherBranchesSymbols  map of otherAgentId => list of FQ symbols
     * @return array{
     *     schema_version: 'atlas.collision.report.v1',
     *     collisions: list<array{kind: 'symbol', ref: string, agents: list<string>, match: 'exact'|'namespace_prefix'}>,
     *     auto_resolvable: bool,
     *     blocking: bool
     * }
     */
    public function classify(array $branchSymbols, array $otherBranchesSymbols): array
    {
        $branch = $this->normalizeSymbols($branchSymbols);

        /** @var array<string, array{ref: string, match: string, agents: array<string, true>}> $byKey */
        $byKey = [];

        foreach ($otherBranchesSymbols as $agentId => $agentSymbols) {
            if (! is_string($agentId) || $agentId === self::SELF_AGENT) {
                continue;
            }

            foreach ($this->normalizeSymbols(is_array($agentSymbols) ? $agentSymbols : []) as $otherSymbol) {
                foreach ($branch as $branchSymbol) {
                    $match = $this->matchKind($branchSymbol, $otherSymbol);

                    if ($match === null) {
                        continue;
                    }

                    $key = $match.'|'.$branchSymbol;

                    if (! isset($byKey[$key])) {
                        $byKey[$key] = [
                            'ref' => $branchSymbol,
                            'match' => $match,
                            'agents' => [],
                        ];
                    }

                    $byKey[$key]['agents'][$agentId] = true;
                }
            }
        }

        $collisions = [];

        foreach ($byKey as $entry) {
            $agents = array_keys($entry['agents']);
            $agents[] = self::SELF_AGENT;
            $agents = array_values(array_unique($agents));
            sort($agents, SORT_STRING);

            $collisions[] = [
                'kind' => 'symbol',
                'ref' => $entry['ref'],
                'agents' => $agents,
                'match' => $entry['match'],
            ];
        }

        usort($collisions, static function (array $left, array $right): int {
            $byRef = strcmp($left['ref'], $right['ref']);

            if ($byRef !== 0) {
                return $byRef;
            }

            return strcmp(implode("\x00", $left['agents']), implode("\x00", $right['agents']));
        });

        $hasCollision = $collisions !== [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'collisions' => $collisions,
            'auto_resolvable' => ! $hasCollision,
            'blocking' => $hasCollision,
        ];
    }

    /**
     * Returns the collision match kind between two FQ symbols, or null when they do not collide.
     */
    private function matchKind(string $branchSymbol, string $otherSymbol): ?string
    {
        if ($branchSymbol === $otherSymbol) {
            return self::MATCH_EXACT;
        }

        if ($this->isStrictBoundaryPrefix($branchSymbol, $otherSymbol)
            || $this->isStrictBoundaryPrefix($otherSymbol, $branchSymbol)) {
            return self::MATCH_NAMESPACE_PREFIX;
        }

        return null;
    }

    /**
     * True when $prefix is a strict prefix of $full and the boundary that follows
     * $prefix inside $full is a namespace ("\") or member ("::") separator, sharing
     * the same leading FQ class path. Guards against substring false-positives such
     * as "App\A" being treated as a prefix of "App\Ab".
     */
    private function isStrictBoundaryPrefix(string $prefix, string $full): bool
    {
        $prefixLength = strlen($prefix);

        if ($prefixLength === 0 || $prefixLength >= strlen($full)) {
            return false;
        }

        if (substr($full, 0, $prefixLength) !== $prefix) {
            return false;
        }

        $boundary = $full[$prefixLength];

        if ($boundary === '\\') {
            return true;
        }

        return $boundary === ':' && substr($full, $prefixLength, 2) === '::';
    }

    /**
     * @param  list<string>  $symbols
     * @return list<string>
     */
    private function normalizeSymbols(array $symbols): array
    {
        $normalized = [];

        foreach ($symbols as $symbol) {
            if (! is_string($symbol)) {
                continue;
            }

            $trimmed = trim($symbol);

            if ($trimmed === '') {
                continue;
            }

            $normalized[$trimmed] = true;
        }

        return array_values(array_keys($normalized));
    }
}
