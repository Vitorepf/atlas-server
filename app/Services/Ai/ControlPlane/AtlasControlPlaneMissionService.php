<?php

namespace App\Services\Ai\ControlPlane;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;

class AtlasControlPlaneMissionService
{
    public const SCHEMA = 'atlas.ai.control_plane.mission_state.v1';

    public const COMPONENT = 'mission';

    private const REQUIRED_TABLES = [
        'ai_missions',
        'ai_objectives',
        'ai_work_orders',
        'ai_mission_events',
        'ai_mission_evidence_refs',
        'ai_mission_certifications',
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * Aggregated mission summary for the global snapshot.
     *
     * @return array<string,mixed>
     */
    public function summary(int $recentLimit = 10): array
    {
        $availability = $this->tableAvailability();
        if ($availability['status'] === AtlasControlPlaneStatus::MISSING) {
            return $availability + ['component' => self::COMPONENT];
        }

        try {
            $missionModel = '\\App\\Models\\AiMission';
            if (! class_exists($missionModel)) {
                return [
                    'component' => self::COMPONENT,
                    'status' => AtlasControlPlaneStatus::MISSING,
                    'detail' => 'AiMission model missing',
                ];
            }

            /** @var Builder $query */
            $query = $missionModel::query();
            $byStatus = $query->clone()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all();

            $recent = $query->clone()
                ->orderByDesc('created_at')
                ->limit($recentLimit)
                ->get()
                ->map(static function ($mission): array {
                    return [
                        'id' => $mission->id,
                        'uuid' => $mission->uuid,
                        'title' => $mission->title,
                        'mission_type' => $mission->mission_type,
                        'status' => $mission->status,
                        'risk_level' => $mission->risk_level,
                        'certification_hash' => $mission->certification_hash,
                        'completed_at' => optional($mission->completed_at)->toJSON(),
                        'created_at' => optional($mission->created_at)->toJSON(),
                    ];
                })->all();

            return [
                'component' => self::COMPONENT,
                'status' => $availability['status'],
                'tables' => $availability['tables'],
                'total' => (int) $query->clone()->count(),
                'by_status' => $byStatus,
                'recent' => $recent,
            ];
        } catch (Throwable $e) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => 'mission summary failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Full snapshot for a single mission. Delegates to MissionControlPlaneService
     * when present; otherwise returns a degraded payload.
     *
     * @return array<string,mixed>|null
     */
    public function snapshot(string $uuidOrId): ?array
    {
        $missionModel = '\\App\\Models\\AiMission';
        if (! class_exists($missionModel) || ! DatabaseTableAvailability::has('ai_missions')) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::MISSING,
                'detail' => 'Mission Foundation not available',
            ];
        }

        $mission = $missionModel::query()
            ->where('uuid', $uuidOrId)
            ->orWhere('id', $uuidOrId)
            ->first();

        if (! $mission) {
            return null;
        }

        $missionControlPlaneClass = '\\App\\Services\\Ai\\Mission\\MissionControlPlaneService';
        if (! class_exists($missionControlPlaneClass)) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => 'MissionControlPlaneService not resolvable; falling back to raw mission',
                'mission' => [
                    'id' => $mission->id,
                    'uuid' => $mission->uuid,
                    'status' => $mission->status,
                    'title' => $mission->title,
                ],
            ];
        }

        try {
            $service = $this->container->make($missionControlPlaneClass);
            $payload = $service->snapshot($mission);
            $payload['component'] = self::COMPONENT;
            $payload['status'] = AtlasControlPlaneStatus::READY;

            return $payload;
        } catch (Throwable $e) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => 'mission snapshot failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function tableAvailability(): array
    {
        $tables = [];
        $present = 0;
        foreach (self::REQUIRED_TABLES as $table) {
            $exists = DatabaseTableAvailability::has($table);
            $tables[$table] = $exists;
            if ($exists) {
                $present++;
            }
        }

        $status = match (true) {
            $present === 0 => AtlasControlPlaneStatus::MISSING,
            $present === count(self::REQUIRED_TABLES) => AtlasControlPlaneStatus::READY,
            default => AtlasControlPlaneStatus::DEGRADED,
        };

        return [
            'status' => $status,
            'tables' => $tables,
            'tables_present' => $present,
            'tables_required' => count(self::REQUIRED_TABLES),
        ];
    }
}
