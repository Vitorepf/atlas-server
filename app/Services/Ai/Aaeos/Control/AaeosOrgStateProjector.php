<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

/**
 * Read-only projection of AAEOS control plane capabilities.
 * Projector: zero mutation.
 */
final class AaeosOrgStateProjector
{
    public const SCHEMA = 'atlas.aaeos.org_state.v1';

    /**
     * @return array<string,mixed>
     */
    public function project(): array
    {
        return [
            'schema' => self::SCHEMA,
            'status' => 'active',
            'control_plane' => 'thin',
            'quarantine_policy' => 'frozen_do_not_reanimate',
            'executor_modes' => AaeosExecutorMode::ALL,
            'same_bar' => true,
            'difficulty_ladder' => [
                AaeosDifficultyLevel::label(AaeosDifficultyLevel::L0),
                AaeosDifficultyLevel::label(AaeosDifficultyLevel::L1),
                AaeosDifficultyLevel::label(AaeosDifficultyLevel::L2),
                AaeosDifficultyLevel::label(AaeosDifficultyLevel::L3),
                AaeosDifficultyLevel::label(AaeosDifficultyLevel::L4),
                AaeosDifficultyLevel::label(AaeosDifficultyLevel::L5),
            ],
            'human_out_of_loop_default' => true,
            'autonomos_commands' => [
                'atlas:brain:next',
                'atlas:brain:seed',
                'atlas:task',
            ],
            'spine' => [
                'delivery' => 'N9',
                'evidence' => 'N11',
                'contract' => 'atlas.aaeos.engineering_spine.v1',
            ],
            'runtime_write_performed' => false,
        ];
    }
}
