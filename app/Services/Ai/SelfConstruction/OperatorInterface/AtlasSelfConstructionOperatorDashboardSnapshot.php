<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\OperatorInterface;

/**
 * Pure Atlas-native operator dashboard snapshot. VISIBILITY ONLY — never mutates runtime. Reports
 * autonomy mode, queue health, blockers, current organ states, and an emergency_state surface.
 *
 * The operator interface is explicitly labeled as a VISIBILITY surface (not a control surface);
 * normal-progress decisions stay Atlas-native (cycle, council, control plane). The operator only sees
 * status + an emergency switch hint.
 *
 * Output: {schema_version, surface_role, autonomy_mode, queue_health, blockers, organs, emergency_state,
 *          atlas_native_progress}
 *
 * surface_role is ALWAYS `visibility_and_emergency_only`.
 */
final class AtlasSelfConstructionOperatorDashboardSnapshot
{
    public const SCHEMA = 'atlas.operator_interface.dashboard_snapshot.v1';

    public const SURFACE_ROLE = 'visibility_and_emergency_only';

    public const HEALTH_HEALTHY = 'healthy';

    public const HEALTH_DEGRADED = 'degraded';

    public const HEALTH_BLOCKED = 'blocked';

    public const EMERGENCY_OFF = 'off';

    public const EMERGENCY_VISIBLE = 'visible';

    public const EMERGENCY_TRIPPED = 'tripped';

    /**
     * @param  array<string,mixed>  $input  {autonomy_mode, queue:{pending:int,leases:int,backlog:int},
     *                                        blockers:list<string>, organs:array<string,string>,
     *                                        emergency_state:string}
     * @return array<string,mixed>
     */
    public function snapshot(array $input): array
    {
        $autonomyMode = (string) ($input['autonomy_mode'] ?? 'off');
        $queue = (array) ($input['queue'] ?? []);
        $blockers = array_values((array) ($input['blockers'] ?? []));
        $organs = (array) ($input['organs'] ?? []);
        $emergency = (string) ($input['emergency_state'] ?? self::EMERGENCY_OFF);

        $health = $blockers !== [] ? self::HEALTH_BLOCKED : ((int) ($queue['leases'] ?? 0) > 0 ? self::HEALTH_HEALTHY : self::HEALTH_DEGRADED);

        return [
            'schema_version' => self::SCHEMA,
            'surface_role' => self::SURFACE_ROLE,
            'autonomy_mode' => $autonomyMode,
            'queue_health' => [
                'state' => $health,
                'pending' => (int) ($queue['pending'] ?? 0),
                'leases' => (int) ($queue['leases'] ?? 0),
                'backlog' => (int) ($queue['backlog'] ?? 0),
            ],
            'blockers' => $blockers,
            'organs' => $organs,
            'emergency_state' => $emergency,
            'atlas_native_progress' => $autonomyMode === 'execute' && $emergency === self::EMERGENCY_OFF && $blockers === [],
        ];
    }
}
