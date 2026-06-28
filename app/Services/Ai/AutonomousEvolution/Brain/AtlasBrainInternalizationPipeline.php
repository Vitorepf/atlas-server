<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * INTERNALIZATION PIPELINE — turns EXTERNAL cycle capsules ({@see AtlasBrainCycleCapsule}) into INTERNAL
 * capability candidates: skill / policy / wiring / metric / reflection. This is how a 15-day external soak
 * compounds into the future internal 24/7 brain instead of evaporating.
 *
 * HARD RULE (author≠judge / no fabricated capability): it ONLY proposes — every candidate is
 * promoted=false, requires_gate=true. It NEVER auto-promotes a capability; a separate gate/operator does.
 * Pure + deterministic: candidates are a function of the capsules' REAL facts (certified outcome, files,
 * failures, metrics, learning) — never invented. A capsule with no usable signal yields no candidate.
 */
final class AtlasBrainInternalizationPipeline
{
    public const KINDS = ['skill', 'policy', 'wiring', 'metric', 'reflection'];

    /**
     * Derive internal capability candidates from external cycle capsules. Each candidate is a PROPOSAL.
     *
     * @param  list<array<string,mixed>>  $capsules
     * @return list<array{kind:string, source_cycle:string, summary:string, evidence_ref:array<string,mixed>, promoted:bool, requires_gate:bool}>
     */
    public static function candidatesFrom(array $capsules): array
    {
        $candidates = [];
        foreach ($capsules as $capsule) {
            $cycle = trim((string) ($capsule['task_packet_id'] ?? ''));
            if ($cycle === '') {
                continue; // unattributable capsule → no internalization.
            }
            $certified = (bool) ($capsule['certified'] ?? ($capsule['validation']['certified'] ?? false));
            $files = (array) ($capsule['files_touched'] ?? []);
            $failures = (array) ($capsule['failures'] ?? []);
            $metrics = (array) ($capsule['metrics'] ?? []);
            $learning = trim((string) ($capsule['learning'] ?? ''));
            $objective = trim((string) ($capsule['objective'] ?? ''));

            // A CERTIFIED cycle that changed real files = a reusable wiring/skill the internal brain can adopt.
            if ($certified && $files !== []) {
                $candidates[] = self::candidate('wiring', $cycle,
                    'Internalize the certified change pattern: '.($objective !== '' ? $objective : $cycle),
                    ['files_touched' => array_values($files), 'validation' => $capsule['validation'] ?? null]);
            }
            // Failures → a policy/reflection candidate so the internal brain avoids the same trap.
            if ($failures !== []) {
                $candidates[] = self::candidate('policy', $cycle,
                    'Avoidance policy from observed failure(s) in cycle '.$cycle,
                    ['failures' => array_values($failures)]);
            }
            // A real learning note → a reflection candidate (the no-scalar post-mortem).
            if ($learning !== '') {
                $candidates[] = self::candidate('reflection', $cycle, $learning, ['source' => 'cycle_capsule']);
            }
            // Measured metrics → a metric candidate (a signal worth tracking internally).
            if ($metrics !== []) {
                $candidates[] = self::candidate('metric', $cycle,
                    'Track the metric(s) measured in cycle '.$cycle, ['metrics' => $metrics]);
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string,mixed>  $evidenceRef
     * @return array{kind:string, source_cycle:string, summary:string, evidence_ref:array<string,mixed>, promoted:bool, requires_gate:bool}
     */
    private static function candidate(string $kind, string $cycle, string $summary, array $evidenceRef): array
    {
        return [
            'kind' => $kind,
            'source_cycle' => $cycle,
            'summary' => $summary,
            'evidence_ref' => $evidenceRef,
            'promoted' => false,      // NEVER auto-promote.
            'requires_gate' => true,  // a separate gate/operator decides adoption.
        ];
    }
}
