<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

/**
 * Regression gate that treats malformed sweep results as first-class Maestro
 * signals for originator rounds. When the sweep reports would_block_count > 0,
 * new expansion tasks are blocked and repair tasks must run first.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasMaestroMalformedSweepRegressionGate
{
    public const SCHEMA = 'atlas.maestro.malformed_sweep_regression_gate.v1';

    public const VERDICT_ALLOW = 'allow';
    public const VERDICT_REPAIR_FIRST = 'repair_first';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function evaluate(array $input): array
    {
        $sweep = is_array($input['malformed_sweep'] ?? null) ? $input['malformed_sweep'] : [];
        $wouldBlockCount = (int) ($sweep['would_block_count'] ?? 0);
        $blockedCount = (int) ($sweep['blocked_count'] ?? 0);
        $inspected = (int) ($sweep['inspected_claimable'] ?? 0);
        $originatorId = (string) ($input['originator_id'] ?? '');
        $roundId = (string) ($input['round_id'] ?? '');

        $blockedReasons = [];
        $taskSpecs = [];

        if ($wouldBlockCount > 0) {
            $blockedReasons[] = 'malformed_sweep_would_block:'.$wouldBlockCount;

            foreach ((array) ($sweep['would_block'] ?? []) as $item) {
                $taskPacketId = (string) (is_array($item) ? ($item['task_packet_id'] ?? '') : $item);
                if ($taskPacketId === '') {
                    continue;
                }

                $taskSpecs[] = [
                    'task_packet_id' => $taskPacketId,
                    'blocking_deficiencies' => array_values((array) (is_array($item) ? ($item['blocking_deficiencies'] ?? []) : [])),
                    'proposed_action' => 'repair_packet_quality',
                ];
            }
        }

        $verdict = $blockedReasons === [] ? self::VERDICT_ALLOW : self::VERDICT_REPAIR_FIRST;

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'verdict' => $verdict,
            'would_block_count' => $wouldBlockCount,
            'blocked_count' => $blockedCount,
            'inspected_claimable' => $inspected,
            'blocked_reasons' => $blockedReasons,
            'task_specs' => $taskSpecs,
            'allows_expansion' => $verdict === self::VERDICT_ALLOW,
        ];
    }
}
