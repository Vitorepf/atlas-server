<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control\Adapters;

use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Aaeos\Spine\AaeosEngineeringSpine;

final class ForgeModeAdapter implements AaeosExecutorModeAdapter
{
    public function __construct(
        private readonly AaeosEngineeringSpine $spine = new AaeosEngineeringSpine,
    ) {}

    public function mode(): string
    {
        return AaeosExecutorMode::FORGE;
    }

    public function accept(array $cyclePlan): array
    {
        $spine = $this->spine->contractForMode(AaeosExecutorMode::FORGE);

        return [
            'status' => 'ready',
            'mode' => AaeosExecutorMode::FORGE,
            'operate_path' => ['atlas forge', 'atlas:obra', 'atlas:cli:dev --forge'],
            'spine' => [
                'delivery' => 'N9',
                'evidence' => 'N11',
            ],
            'spine_contract' => $spine,
            'reason' => 'long_obra_executor',
            'human_in_engineering_loop' => false,
            'human_in_planning' => true,
            'elite_same_bar' => true,
            'difficulty_level' => (int) (($cyclePlan['difficulty']['level'] ?? 1)),
        ];
    }
}
