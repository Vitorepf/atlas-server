<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Orders simplification candidates into a single deterministic sequence so waves
 * never collide (touch the same files at once) or delete a prerequisite before its
 * dependents have consumed it. A topological sort over `prerequisites` guarantees
 * ordering; within a readiness tier, proof-ready and low-risk candidates go first,
 * and candidates without proof readiness or with a file-overlap collision are held
 * behind whichever candidate they depend on or collide with.
 *
 * Pure / deterministic. No I/O — callers persist/act on the returned waves.
 */
final class AtlasSelfConstructionConsolidationWaveSequencer
{
    public const SCHEMA = 'atlas.self_construction.simplification.consolidation_wave_sequencer.v1';

    /** @var array<string,int> */
    private const RISK_RANK = ['low' => 0, 'medium' => 1, 'high' => 2];

    /**
     * @param  array{
     *   candidates?: list<array{
     *     id?: string,
     *     prerequisites?: list<string>,
     *     risk?: string,
     *     proof_ready?: bool,
     *     missing_proof?: string,
     *     allowed_files?: list<string>,
     *     expected_leverage?: float|int,
     *   }>,
     * }  $facts
     * @return array{schema:string, waves:list<array<string,mixed>>, blocked:list<array<string,mixed>>}
     */
    public function sequence(array $facts): array
    {
        $candidates = [];
        foreach ((array) ($facts['candidates'] ?? []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $id = trim((string) ($raw['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $candidates[$id] = [
                'id' => $id,
                'prerequisites' => array_values((array) ($raw['prerequisites'] ?? [])),
                'risk' => (string) ($raw['risk'] ?? 'low'),
                'proof_ready' => (bool) ($raw['proof_ready'] ?? false),
                'missing_proof' => (string) ($raw['missing_proof'] ?? 'proof_not_ready'),
                'allowed_files' => array_values((array) ($raw['allowed_files'] ?? [])),
                'expected_leverage' => (float) ($raw['expected_leverage'] ?? 0),
            ];
        }

        $collisions = $this->collisionMap($candidates);

        $placed = [];
        $waves = [];
        $blocked = [];
        $remaining = $candidates;

        while ($remaining !== []) {
            $ready = array_values(array_filter(
                $remaining,
                fn (array $c): bool => $this->prerequisitesSatisfied($c, $candidates, $placed),
            ));

            if ($ready === []) {
                // Every remaining candidate depends on something never satisfiable (missing or cyclic).
                foreach ($remaining as $c) {
                    $blocked[] = [
                        'id' => $c['id'],
                        'reason' => 'unmet_or_cyclic_prerequisites',
                        'prerequisites' => $c['prerequisites'],
                    ];
                }
                break;
            }

            usort($ready, fn (array $a, array $b): int => $this->comparePriority($a, $b));
            $next = $ready[0];

            $whyNow = $this->whyNow($next);
            $waves[] = [
                'task_ids' => [$next['id']],
                'prerequisites' => $next['prerequisites'],
                'collision_risks' => $collisions[$next['id']] ?? [],
                'proof_requirements' => $next['proof_ready'] ? [] : [$next['missing_proof']],
                'why_now' => $whyNow,
            ];

            $placed[$next['id']] = true;
            unset($remaining[$next['id']]);
        }

        return [
            'schema' => self::SCHEMA,
            'waves' => $waves,
            'blocked' => $blocked,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $candidates
     * @param  array<string,bool>  $placed
     */
    private function prerequisitesSatisfied(array $c, array $candidates, array $placed): bool
    {
        foreach ($c['prerequisites'] as $prereqId) {
            // Fail-closed: a prerequisite that isn't a known, placeable candidate never counts as
            // satisfied — an unresolvable dependency must block, not silently pass.
            if (! array_key_exists($prereqId, $candidates) || ! isset($placed[$prereqId])) {
                return false;
            }
        }

        return true;
    }

    private function comparePriority(array $a, array $b): int
    {
        // Proof-ready candidates always go before not-yet-proven ones.
        $proofCmp = ($b['proof_ready'] ? 1 : 0) <=> ($a['proof_ready'] ? 1 : 0);
        if ($proofCmp !== 0) {
            return $proofCmp;
        }

        $riskCmp = ($this->riskRank($a['risk'])) <=> ($this->riskRank($b['risk']));
        if ($riskCmp !== 0) {
            return $riskCmp;
        }

        $leverageCmp = $b['expected_leverage'] <=> $a['expected_leverage'];
        if ($leverageCmp !== 0) {
            return $leverageCmp;
        }

        return $a['id'] <=> $b['id'];
    }

    private function riskRank(string $risk): int
    {
        return self::RISK_RANK[$risk] ?? PHP_INT_MAX;
    }

    private function whyNow(array $c): string
    {
        if ($c['prerequisites'] !== []) {
            return 'prerequisites_already_satisfied';
        }
        if (! $c['proof_ready']) {
            return 'no_proof_ready_alternative_remaining';
        }
        if ($this->riskRank($c['risk']) === 0) {
            return 'proof_ready_and_lowest_risk';
        }

        return 'proof_ready_and_highest_remaining_leverage';
    }

    /**
     * @param  array<string,array<string,mixed>>  $candidates
     * @return array<string,list<string>>
     */
    private function collisionMap(array $candidates): array
    {
        $map = [];
        foreach ($candidates as $id => $candidate) {
            $map[$id] = [];
        }

        $ids = array_keys($candidates);
        foreach ($ids as $i => $idA) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $idB = $ids[$j];
                $overlap = array_intersect($candidates[$idA]['allowed_files'], $candidates[$idB]['allowed_files']);
                if ($overlap === []) {
                    continue;
                }
                $map[$idA][] = $idB;
                $map[$idB][] = $idA;
            }
        }

        return $map;
    }
}
