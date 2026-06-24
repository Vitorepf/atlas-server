<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\InFlight;

final class AtlasAaelInFlightDriftAuditor
{
    public const SCHEMA = 'atlas.aael.inflight_drift_auditor.v1';

    /**
     * @param  list<array<string,mixed>>  $validatorRecords
     * @return array{
     *   schema_version:string,
     *   steps_observed:int,
     *   invariants_broken_set:list<string>,
     *   first_break_step_index:?int,
     *   ever_broken:bool,
     *   permanently_broken:bool,
     *   transient_flap:bool,
     *   permanently_broken_set:list<string>,
     *   recovered_invariants:list<string>
     * }
     */
    public function audit(array $validatorRecords): array
    {
        $stepsObserved = 0;
        $firstBreakStepIndex = null;
        $everBroken = [];
        $currentBroken = [];
        $recovered = [];

        foreach ($validatorRecords as $record) {
            $facts = array_values(array_filter((array) ($record['facts'] ?? []), 'is_array'));
            foreach ($facts as $fact) {
                $invariantId = (string) ($fact['invariant_id'] ?? '');
                $stepIndex = (int) ($fact['step_index'] ?? 0);
                $holds = (bool) ($fact['holds'] ?? false);

                if ($invariantId === '') {
                    continue;
                }

                $stepsObserved = max($stepsObserved, $stepIndex);

                if ($holds) {
                    if (isset($everBroken[$invariantId])) {
                        $recovered[$invariantId] = true;
                    }
                    unset($currentBroken[$invariantId]);
                    continue;
                }

                $everBroken[$invariantId] = true;
                $currentBroken[$invariantId] = true;
                $firstBreakStepIndex ??= $stepIndex;
            }
        }

        $brokenSet = array_keys($everBroken);
        sort($brokenSet, SORT_STRING);
        $permanentlyBrokenSet = array_keys($currentBroken);
        sort($permanentlyBrokenSet, SORT_STRING);
        $recoveredInvariants = array_values(array_diff(array_keys($recovered), $permanentlyBrokenSet));
        sort($recoveredInvariants, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA,
            'steps_observed' => $stepsObserved,
            'invariants_broken_set' => $brokenSet,
            'first_break_step_index' => $firstBreakStepIndex,
            'ever_broken' => $brokenSet !== [],
            'permanently_broken' => $permanentlyBrokenSet !== [],
            'transient_flap' => $recoveredInvariants !== [],
            'permanently_broken_set' => $permanentlyBrokenSet,
            'recovered_invariants' => $recoveredInvariants,
        ];
    }
}
