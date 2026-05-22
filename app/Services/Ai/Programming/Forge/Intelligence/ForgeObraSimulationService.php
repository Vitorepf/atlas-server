<?php

namespace App\Services\Ai\Programming\Forge\Intelligence;

use App\Models\AiForgeIntake;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;

final class ForgeObraSimulationService
{
    public const SCHEMA_VERSION = 'atlas.forge.obra_simulation.v1';

    /**
     * @param  array<string,mixed>  $contextGate
     * @param  array<string,mixed>  $scopeGuard
     * @param  array<string,mixed>  $testImpact
     * @return array<string,mixed>
     */
    public function simulate(
        AiForgeWorkPacket $packet,
        ?AiForgeIntake $intake,
        array $contextGate,
        array $scopeGuard,
        array $testImpact,
    ): array {
        $riskPoints = 0;
        $riskPoints += ($contextGate['status'] ?? null) === 'blocked' ? 4 : 0;
        $riskPoints += ($scopeGuard['status'] ?? null) === 'blocked' ? 4 : 0;
        $riskPoints += count((array) ($packet->dependencies ?? []));
        $riskPoints += in_array((string) $packet->risk_band, ['high', 'critical'], true) ? 3 : 1;
        $riskPoints += count((array) ($testImpact['commands'] ?? [])) === 0 ? 2 : 0;

        $prediction = $riskPoints >= 8 ? 'high_risk' : ($riskPoints >= 4 ? 'medium_risk' : 'low_risk');
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'packet_id' => (string) $packet->packet_id,
            'prediction' => $prediction,
            'risk_points' => $riskPoints,
            'expected_conflicts' => $this->expectedConflicts($packet),
            'recommended_execution_mode' => $prediction === 'high_risk' ? 'safe_simulation' : 'real_or_safe_simulation',
            'human_checkpoint_required' => $prediction === 'high_risk',
            'simulation_steps' => [
                'validate_context_gate',
                'validate_scope_guard',
                'run_focused_tests_or_attach_no_test_reason',
                'attach_work_packet_receipt',
                'update_obra_state',
            ],
        ];
        $payload['simulation_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function expectedConflicts(AiForgeWorkPacket $packet): array
    {
        $conflicts = [];
        foreach ((array) ($packet->dependencies ?? []) as $dependency) {
            if (is_string($dependency) && $dependency !== '') {
                $conflicts[] = 'dependency:'.$dependency;
            }
        }
        if (count((array) ($packet->expected_files ?? [])) > 8) {
            $conflicts[] = 'large_file_surface';
        }

        return $conflicts;
    }
}
