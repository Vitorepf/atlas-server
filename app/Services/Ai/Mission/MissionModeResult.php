<?php

namespace App\Services\Ai\Mission;

use App\Models\AiMission;
use App\Models\AiObjective;
use App\Models\AiWorkOrder;
use Illuminate\Support\Collection;

/**
 * Result of {@see MissionModeService::processIntent()}.
 *
 * Schema: atlas.ai.mission_mode_result.v1
 *
 * One of two terminal shapes:
 *   - skipped(signal): prompt era TRIVIAL/TASK sem persistence → nenhum AiMission criado
 *   - created(signal, mission, objectives, workOrders): Mission Mode ativou
 */
final class MissionModeResult
{
    public const SCHEMA_VERSION = 'atlas.ai.mission_mode_result.v1';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_CREATED = 'created';

    /**
     * @param  Collection<int,AiObjective>|null  $objectives
     * @param  Collection<int,AiWorkOrder>|null  $workOrders
     */
    private function __construct(
        public readonly string $outcome,
        public readonly MissionSignal $signal,
        public readonly ?AiMission $mission,
        public readonly ?Collection $objectives,
        public readonly ?Collection $workOrders,
    ) {}

    public static function skipped(MissionSignal $signal): self
    {
        return new self(
            outcome: self::OUTCOME_SKIPPED,
            signal: $signal,
            mission: null,
            objectives: null,
            workOrders: null,
        );
    }

    /**
     * @param  Collection<int,AiObjective>  $objectives
     * @param  Collection<int,AiWorkOrder>  $workOrders
     */
    public static function created(
        MissionSignal $signal,
        AiMission $mission,
        Collection $objectives,
        Collection $workOrders,
    ): self {
        return new self(
            outcome: self::OUTCOME_CREATED,
            signal: $signal,
            mission: $mission,
            objectives: $objectives,
            workOrders: $workOrders,
        );
    }

    public function activated(): bool
    {
        return $this->outcome === self::OUTCOME_CREATED;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'outcome' => $this->outcome,
            'signal' => $this->signal->toArray(),
            'mission' => $this->mission === null
                ? null
                : [
                    'id' => $this->mission->id,
                    'uuid' => $this->mission->uuid,
                    'title' => $this->mission->title,
                    'mission_type' => $this->mission->mission_type,
                    'status' => $this->mission->status,
                    'autonomy_level' => $this->mission->autonomy_level,
                    'risk_level' => $this->mission->risk_level,
                ],
            'objectives_count' => $this->objectives?->count() ?? 0,
            'work_orders_count' => $this->workOrders?->count() ?? 0,
        ];
    }
}
