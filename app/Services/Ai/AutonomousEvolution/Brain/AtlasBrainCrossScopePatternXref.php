<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * CROSS-SCOPE PATTERN XREF — given reflection tails for two or more scopes, surfaces hints
 * appearing in EVERY scope with a per-scope frequency above the floor. These are transferable
 * patterns: a fix or improvement framed around them may help every scope at once.
 *
 * Pure intersection + frequency aggregation over already-built per-scope tails. No IO. Pétreo:
 * réu would lower the floor to false-positive common hints into "transferable".
 */
final class AtlasBrainCrossScopePatternXref
{
    public const SCHEMA = 'atlas.brain.cross_scope_pattern_xref.v1';

    private const MIN_PER_SCOPE_COUNT = 2;

    /**
     * @param  array<string, list<array{action_hint?:string}>>  $tailsByScope
     * @return array{schema:string, scopes:list<string>, transferable:list<array{hint:string, per_scope:array<string,int>, total:int}>}
     */
    public function xref(array $tailsByScope): array
    {
        $scopes = array_keys($tailsByScope);
        if (count($scopes) < 2) {
            return ['schema' => self::SCHEMA, 'scopes' => $scopes, 'transferable' => []];
        }

        $perScope = [];
        foreach ($tailsByScope as $scope => $tail) {
            $perScope[$scope] = $this->countByHint($tail);
        }

        $candidate = array_keys($perScope[$scopes[0]]);
        $transferable = [];
        foreach ($candidate as $hint) {
            $row = ['hint' => $hint, 'per_scope' => [], 'total' => 0];
            $isCommon = true;
            foreach ($scopes as $scope) {
                $c = $perScope[$scope][$hint] ?? 0;
                if ($c < self::MIN_PER_SCOPE_COUNT) {
                    $isCommon = false;
                    break;
                }
                $row['per_scope'][$scope] = $c;
                $row['total'] += $c;
            }
            if ($isCommon) {
                $transferable[] = $row;
            }
        }

        usort($transferable, static fn (array $a, array $b) => $b['total'] <=> $a['total']);

        return ['schema' => self::SCHEMA, 'scopes' => $scopes, 'transferable' => $transferable];
    }

    /**
     * @param  list<array{action_hint?:string}>  $tail
     * @return array<string, int>
     */
    private function countByHint(array $tail): array
    {
        $out = [];
        foreach ($tail as $r) {
            $h = (string) ($r['action_hint'] ?? '');
            if ($h === '') {
                continue;
            }
            $out[$h] = ($out[$h] ?? 0) + 1;
        }

        return $out;
    }
}
