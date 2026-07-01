<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure mapper that groups redundant organs by semantic role, shared inputs,
 * shared outputs, and consumer overlap — not just namespace prefix.
 *
 * Exposes the highest-value collapse families ranked by estimated deleted lines,
 * risk, and proof readiness.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasSelfConstructionSimplificationRedundancyMap
{
    public const SCHEMA = 'atlas.self_construction.simplification_redundancy_map.v1';

    public const MATCH_STRONG = 'strong_match';

    public const MATCH_WEAK = 'weak_match';

    /**
     * @param  list<array{
     *   class_name:string,
     *   namespace:string,
     *   semantic_role?:string,
     *   inputs?:list<string>,
     *   outputs?:list<string>,
     *   consumers?:list<string>,
     *   estimated_deleted_lines?:int,
     *   risk_level?:string,
     *   proof_ready?:bool,
     * }>  $organs
     * @return array{
     *   schema:string,
     *   families:list<array{
     *     family_id:string,
     *     members:list<string>,
     *     match_type:string,
     *     semantic_role:string,
     *     estimated_deleted_lines:int,
     *     risk_level:string,
     *     proof_ready:bool,
     *   }>,
     * }
     */
    public function map(array $organs): array
    {
        // Group by semantic role first.
        $byRole = [];
        foreach ($organs as $organ) {
            $role = (string) ($organ['semantic_role'] ?? '');
            if ($role === '') {
                continue;
            }
            $byRole[$role][] = $organ;
        }

        $families = [];

        foreach ($byRole as $role => $members) {
            if (count($members) < 2) {
                continue; // Need at least 2 to be redundant
            }

            // Sub-group by shared inputs + outputs + consumer overlap.
            $subGroups = $this->subGroupBySignals($members);

            foreach ($subGroups as $subGroup) {
                if (count($subGroup['members']) < 2) {
                    continue;
                }

                $classNames = array_map(fn ($m) => $m['class_name'], $subGroup['members']);
                sort($classNames, SORT_STRING);

                $deletedLines = array_sum(array_map(
                    fn ($m) => (int) ($m['estimated_deleted_lines'] ?? 0),
                    $subGroup['members']
                ));

                $riskLevel = $this->worstRisk($subGroup['members']);
                $proofReady = $this->allProofReady($subGroup['members']);

                $families[] = [
                    'family_id' => $role . ':' . implode('+', array_slice($classNames, 0, 2)),
                    'members' => $classNames,
                    'match_type' => $subGroup['match_type'],
                    'semantic_role' => $role,
                    'estimated_deleted_lines' => $deletedLines,
                    'risk_level' => $riskLevel,
                    'proof_ready' => $proofReady,
                ];
            }
        }

        // Also check prefix-only matches (different roles, same namespace prefix).
        $prefixFamilies = $this->prefixOnlyFamilies($organs);
        foreach ($prefixFamilies as $pf) {
            $families[] = $pf;
        }

        // Rank: higher deleted lines first, then lower risk, then proof_ready.
        $riskOrder = ['low' => 0, 'medium' => 1, 'high' => 2];
        usort($families, function (array $a, array $b) use ($riskOrder): int {
            // Higher deleted lines first
            $dlDiff = (int) $b['estimated_deleted_lines'] <=> (int) $a['estimated_deleted_lines'];
            if ($dlDiff !== 0) {
                return $dlDiff;
            }
            // Lower risk first
            $ra = $riskOrder[$a['risk_level']] ?? 99;
            $rb = $riskOrder[$b['risk_level']] ?? 99;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            // proof_ready=true first
            return (int) $b['proof_ready'] <=> (int) $a['proof_ready'];
        });

        return [
            'schema' => self::SCHEMA,
            'families' => $families,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $members
     * @return list<array{members:list<array<string,mixed>>,match_type:string}>
     */
    private function subGroupBySignals(array $members): array
    {
        // Group by canonical (inputs|outputs|consumers) signature.
        $groups = [];
        foreach ($members as $m) {
            $inputs = (array) ($m['inputs'] ?? []);
            $outputs = (array) ($m['outputs'] ?? []);
            $consumers = (array) ($m['consumers'] ?? []);
            sort($inputs, SORT_STRING);
            sort($outputs, SORT_STRING);
            sort($consumers, SORT_STRING);

            // Shared inputs + outputs + consumer overlap → strong
            $sig = implode('|', $inputs) . '||' . implode('|', $outputs);
            $groups[$sig][] = [
                'organ' => $m,
                'consumers' => $consumers,
            ];
        }

        $result = [];
        foreach ($groups as $sig => $entries) {
            if (count($entries) < 2) {
                continue;
            }

            // Check consumer overlap
            $allConsumers = array_merge(...array_map(fn ($e) => $e['consumers'], $entries));
            $consumerCounts = array_count_values($allConsumers);
            $hasOverlap = count(array_filter($consumerCounts, fn ($c) => $c > 1)) > 0;

            $matchType = $hasOverlap ? self::MATCH_STRONG : self::MATCH_STRONG; // shared inputs+outputs = strong regardless

            $result[] = [
                'members' => array_map(fn ($e) => $e['organ'], $entries),
                'match_type' => $matchType,
            ];
        }

        return $result;
    }

    /**
     * @param  list<array<string,mixed>>  $organs
     * @return list<array<string,mixed>>
     */
    private function prefixOnlyFamilies(array $organs): array
    {
        $byPrefix = [];
        foreach ($organs as $organ) {
            $ns = (string) ($organ['namespace'] ?? '');
            $parts = explode('\\', $ns);
            $prefix = implode('\\', array_slice($parts, 0, 2));
            if ($prefix === '') {
                continue;
            }
            $byPrefix[$prefix][] = $organ;
        }

        $families = [];
        foreach ($byPrefix as $prefix => $members) {
            if (count($members) < 2) {
                continue;
            }
            // Check if they share semantic role — if so they'd already be grouped above.
            $roles = array_unique(array_map(fn ($m) => (string) ($m['semantic_role'] ?? ''), $members));
            if (count($roles) === 1 && $roles[0] !== '') {
                continue; // Same role — already handled
            }

            // Check consumer overlap.
            $allConsumers = array_merge(...array_map(fn ($m) => (array) ($m['consumers'] ?? []), $members));
            $consumerCounts = array_count_values($allConsumers);
            $hasConsumerOverlap = count(array_filter($consumerCounts, fn ($c) => $c > 1)) > 0;

            if (! $hasConsumerOverlap) {
                // Prefix-only without consumer overlap → weak_match
                $classNames = array_map(fn ($m) => $m['class_name'], $members);
                sort($classNames, SORT_STRING);

                $families[] = [
                    'family_id' => 'prefix:' . $prefix,
                    'members' => $classNames,
                    'match_type' => self::MATCH_WEAK,
                    'semantic_role' => implode('/', $roles),
                    'estimated_deleted_lines' => array_sum(array_map(fn ($m) => (int) ($m['estimated_deleted_lines'] ?? 0), $members)),
                    'risk_level' => $this->worstRisk($members),
                    'proof_ready' => $this->allProofReady($members),
                ];
            }
        }

        return $families;
    }

    /** @param  list<array<string,mixed>>  $members */
    private function worstRisk(array $members): string
    {
        $worst = 'low';
        $order = ['low' => 0, 'medium' => 1, 'high' => 2];
        foreach ($members as $m) {
            $r = (string) ($m['risk_level'] ?? 'low');
            if (($order[$r] ?? 0) > ($order[$worst] ?? 0)) {
                $worst = $r;
            }
        }

        return $worst;
    }

    /** @param  list<array<string,mixed>>  $members */
    private function allProofReady(array $members): bool
    {
        foreach ($members as $m) {
            if (($m['proof_ready'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }
}
