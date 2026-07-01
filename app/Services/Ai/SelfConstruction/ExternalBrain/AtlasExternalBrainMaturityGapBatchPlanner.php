<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure planner that converts gap-index findings into coherent worker-ready task waves
 * ordered by prerequisite, impact, and proof readiness.
 *
 * Low-impact gaps are held when they would crowd out higher-leverage work.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainMaturityGapBatchPlanner
{
    public const SCHEMA = 'atlas.external_brain.maturity_gap_batch_planner.v1';

    private const IMPACT_THRESHOLD = 0.3;

    /**
     * @param  list<array{
     *   gap_id?:string,
     *   severity?:string,
     *   impact_score?:float,
     *   prerequisite_gap_ids?:list<string>,
     *   proof_ready?:bool,
     *   allowed_scope_hint?:string,
     *   expected_maturity_delta?:string,
     *   group?:string,
     * }>  $gaps
     * @return array{
     *   schema:string,
     *   waves:list<array{
     *     wave:int,
     *     tasks:list<array{
     *       task_id:string,
     *       prerequisite_notes:list<string>,
     *       proof_target:string,
     *       allowed_scope_hint:string,
     *       expected_maturity_delta:string,
     *     }>,
     *   }>,
     *   held:list<string>,
     * }
     */
    public function plan(array $gaps): array
    {
        // Filter: only high enough impact gets planned
        $eligible = [];
        $held = [];

        foreach ($gaps as $gap) {
            $impact = (float) ($gap['impact_score'] ?? 0.0);
            $gapId = (string) ($gap['gap_id'] ?? 'unknown');

            if ($impact < self::IMPACT_THRESHOLD) {
                $held[] = $gapId;
            } else {
                $eligible[] = $gap;
            }
        }

        // Order: proof_ready first, then by impact desc
        usort($eligible, function (array $a, array $b): int {
            $aReady = ($a['proof_ready'] ?? false) === true;
            $bReady = ($b['proof_ready'] ?? false) === true;
            if ($aReady !== $bReady) {
                return $aReady ? -1 : 1; // ready first
            }
            return (float) ($b['impact_score'] ?? 0) <=> (float) ($a['impact_score'] ?? 0);
        });

        // Build waves by prerequisites: gaps with no unmet prerequisites go in wave 1
        $planned = [];
        $remaining = $eligible;
        $waveNum = 0;

        while (count($remaining) > 0) {
            $waveNum++;
            $currentWave = [];
            $nextRemaining = [];

            foreach ($remaining as $gap) {
                $prereqs = (array) ($gap['prerequisite_gap_ids'] ?? []);
                // Check all prerequisites are already planned (within this or previous waves)
                $unmetPrereqs = array_filter($prereqs, fn ($p) => ! in_array($p, $planned, true));

                if (count($unmetPrereqs) === 0) {
                    $gapId = (string) ($gap['gap_id'] ?? 'unknown');
                    $prereqNotes = array_map(fn ($p) => "depends_on:{$p}", $prereqs);

                    $currentWave[] = [
                        'task_id' => $gapId,
                        'prerequisite_notes' => $prereqNotes,
                        'proof_target' => (string) ($gap['group'] ?? 'unknown') . '_test',
                        'allowed_scope_hint' => (string) ($gap['allowed_scope_hint'] ?? ''),
                        'expected_maturity_delta' => (string) ($gap['expected_maturity_delta'] ?? '+0.1'),
                    ];
                    // Don't add to $planned yet — prerequisites from the same wave
                    // don't count; they must be in a PRIOR wave.
                    $deferredPlanned[] = $gapId;
                } else {
                    $nextRemaining[] = $gap;
                }
            }

            if (count($currentWave) === 0) {
                // Circular dependency — put remaining into last wave
                foreach ($nextRemaining as $gap) {
                    $gapId = (string) ($gap['gap_id'] ?? 'unknown');
                    $currentWave[] = [
                        'task_id' => $gapId,
                        'prerequisite_notes' => ['circular_dependency_break'],
                        'proof_target' => (string) ($gap['group'] ?? 'unknown') . '_test',
                        'allowed_scope_hint' => (string) ($gap['allowed_scope_hint'] ?? ''),
                        'expected_maturity_delta' => (string) ($gap['expected_maturity_delta'] ?? '+0.1'),
                    ];
                }
                $nextRemaining = [];
            }

            $waves[] = ['wave' => $waveNum, 'tasks' => $currentWave];
            // Merge deferred planned into the main list for the next wave
            foreach ($deferredPlanned ?? [] as $id) {
                $planned[] = $id;
            }
            $remaining = $nextRemaining;
        }

        return [
            'schema' => self::SCHEMA,
            'waves' => $waves ?? [],
            'held' => $held,
        ];
    }
}
