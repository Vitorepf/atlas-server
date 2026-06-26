<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use Illuminate\Support\Arr;

/**
 * Option/payload normalization for the Agent Control Plane terminal-loop
 * health digest.
 *
 * Extracted from AgentControlPlaneTerminalLoopHealthDigestService to reduce
 * the god-class. All methods are stateless.
 */
final class TerminalLoopHealthDigestPayloadNormalizer
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function stringOption(array $options, string $key, string $default): string
    {
        $value = trim((string) ($options[$key] ?? ''));

        return $value === '' ? $default : $value;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    public function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hashPayload(array $payload): string
    {
        $stable = Arr::except($payload, [
            'digest_id',
            'generated_at',
            'terminal_loop_health_digest_hash',
            'terminal_loop_fleet_launch_plan_hash',
            'terminal_loop_fleet_replenishment_plan_hash',
            'terminal_loop_fleet_resume_rollup_hash',
            'terminal_loop_fleet_evidence_rollup_hash',
            'terminal_loop_fleet_operator_handoff_hash',
            'terminal_loop_fleet_lane_isolation_hash',
            'terminal_loop_cycle_supervisor_hash',
            'terminal_loop_fleet_launch_runbook_hash',
            'terminal_loop_end_to_end_contract_hash',
        ]);

        return hash('sha256', (string) json_encode($stable, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}