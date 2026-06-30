<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure planner. Converts critical atlas:brain:audit gate_regression findings
 * into repair-first recommendations BEFORE any ambition, frontier, or volume
 * task is accepted.
 *
 * INVARIANTS:
 *  - Any holes in the audit result → verdict='repair_first', blocked_origination=true.
 *    The repair packet targets the highest-priority hole (first in the sorted list).
 *    No volume-padding fallback is ever emitted.
 *  - Zero holes → verdict='no_regression', blocked_origination=false so normal
 *    origination can continue.
 *
 * The evidence_command tells the external brain which CLI command proves the
 * regression is fixed, making the repair packet self-verifiable.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainGateRegressionResponsePlanner
{
    public const SCHEMA = 'atlas.external_brain.gate_regression_response_planner.v1';

    public const VERDICT_REPAIR_FIRST   = 'repair_first';
    public const VERDICT_NO_REGRESSION  = 'no_regression';

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH     = 'high';
    public const SEVERITY_MEDIUM   = 'medium';

    private const EVIDENCE_COMMAND = 'php artisan atlas:brain:audit --verify';

    /**
     * @param  array{
     *   audit?: array{attacks_tried?:int, holes?:list<array{attack?:string, expected?:string, deficiencies?:list<string>}>},
     *   severity_override?: string|null,
     * }  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $audit = is_array($input['audit'] ?? null) ? $input['audit'] : [];
        $holes = is_array($audit['holes'] ?? null) ? $audit['holes'] : [];
        $attacksTried = (int) ($audit['attacks_tried'] ?? 0);

        if ($holes === []) {
            return [
                'schema'               => self::SCHEMA,
                'verdict'              => self::VERDICT_NO_REGRESSION,
                'regression_count'     => 0,
                'attacks_tried'        => $attacksTried,
                'blocked_origination'  => false,
            ];
        }

        // Holes exist: emit repair-first for the top hole. Gate regressions are
        // always critical — they indicate the quality gate can be bypassed.
        $topHole = $holes[0];
        $attack  = (string) ($topHole['attack']   ?? 'unknown_attack');
        $expected = (string) ($topHole['expected'] ?? 'gate_must_catch_this');
        $deficiencies = (array) ($topHole['deficiencies'] ?? []);

        $severity = (string) ($input['severity_override'] ?? self::SEVERITY_CRITICAL);
        if (! in_array($severity, [self::SEVERITY_CRITICAL, self::SEVERITY_HIGH, self::SEVERITY_MEDIUM], true)) {
            $severity = self::SEVERITY_CRITICAL;
        }

        return [
            'schema'               => self::SCHEMA,
            'verdict'              => self::VERDICT_REPAIR_FIRST,
            'highest_severity'     => $severity,
            'repair_target'        => $attack,
            'expected_deficiency'  => $expected,
            'deficiencies'         => $deficiencies,
            'evidence_command'     => self::EVIDENCE_COMMAND,
            'regression_count'     => count($holes),
            'attacks_tried'        => $attacksTried,
            'blocked_origination'  => true,
            'remaining_holes'      => array_slice($holes, 1),
        ];
    }
}
