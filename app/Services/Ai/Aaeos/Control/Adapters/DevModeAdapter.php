<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control\Adapters;

use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Aaeos\Spine\AaeosEngineeringSpine;

final class DevModeAdapter implements AaeosExecutorModeAdapter
{
    public function __construct(
        private readonly AaeosEngineeringSpine $spine = new AaeosEngineeringSpine,
    ) {}

    public function mode(): string
    {
        return AaeosExecutorMode::DEV;
    }

    public function accept(array $cyclePlan): array
    {
        $spine = $this->spine->contractForMode(AaeosExecutorMode::DEV);

        return [
            'status' => 'ready',
            'mode' => AaeosExecutorMode::DEV,
            'operate_path' => ['atlas dev', 'atlas ask', 'atlas:cli:dev'],
            'spine' => [
                'delivery' => 'N9',
                'evidence' => 'N11',
            ],
            'spine_contract' => $spine,
            'reason' => 'interactive_session_executor',
            'human_in_engineering_loop' => true,
            'elite_same_bar' => true,
            'difficulty_level' => (int) (($cyclePlan['difficulty']['level'] ?? 1)),
        ];
    }
}
