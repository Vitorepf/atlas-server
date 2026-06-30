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

    // AC3: severity sort order (higher = more critical).
    private const SEVERITY_PRIORITY = [
        self::SEVERITY_CRITICAL => 3,
        self::SEVERITY_HIGH     => 2,
        self::SEVERITY_MEDIUM   => 1,
    ];

    /**
     * @param  array{
     *   audit?: array{attacks_tried?:int, holes?:list<array{attack?:string, expected?:string, deficiencies?:list<string>, severity?:string}>},
     *   severity_override?: string|null,
     * }  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $audit        = is_array($input['audit'] ?? null) ? $input['audit'] : [];
        $holes        = is_array($audit['holes'] ?? null) ? $audit['holes'] : [];
        $attacksTried = (int) ($audit['attacks_tried'] ?? 0);

        if ($holes === []) {
            return [
                'schema'              => self::SCHEMA,
                'verdict'             => self::VERDICT_NO_REGRESSION,
                'regression_count'    => 0,
                'attacks_tried'       => $attacksTried,
                'blocked_origination' => false,
            ];
        }

        // AC3: sort holes by severity descending; stable (PHP 8+ usort is stable).
        $sorted = $holes;
        usort($sorted, function (array $a, array $b): int {
            $pa = self::SEVERITY_PRIORITY[$a['severity'] ?? self::SEVERITY_CRITICAL] ?? 0;
            $pb = self::SEVERITY_PRIORITY[$b['severity'] ?? self::SEVERITY_CRITICAL] ?? 0;

            return $pb <=> $pa; // descending
        });

        $topHole      = $sorted[0];
        $attack       = (string) ($topHole['attack']       ?? 'unknown_attack');
        $expected     = (string) ($topHole['expected']     ?? 'gate_must_catch_this');
        $deficiencies = (array)  ($topHole['deficiencies'] ?? []);
        $topSeverity  = (string) ($topHole['severity']     ?? self::SEVERITY_CRITICAL);
        if (! isset(self::SEVERITY_PRIORITY[$topSeverity])) {
            $topSeverity = self::SEVERITY_CRITICAL;
        }

        // severity_override may explicitly override the output label (preserved for back-compat).
        $severityOut = (string) ($input['severity_override'] ?? $topSeverity);
        if (! isset(self::SEVERITY_PRIORITY[$severityOut])) {
            $severityOut = self::SEVERITY_CRITICAL;
        }

        return [
            'schema'              => self::SCHEMA,
            'verdict'             => self::VERDICT_REPAIR_FIRST,
            'highest_severity'    => $severityOut,
            'repair_target'       => $attack,
            'expected_deficiency' => $expected,
            'deficiencies'        => $deficiencies,
            'evidence_command'    => self::EVIDENCE_COMMAND,
            'regression_count'    => count($holes),
            'attacks_tried'       => $attacksTried,
            'blocked_origination' => true,
            'sorted_holes'        => $sorted,
            'remaining_holes'     => array_slice($sorted, 1),
        ];
    }
}
