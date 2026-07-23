<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control\Adapters;

use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Aaeos\Spine\AaeosEngineeringSpine;

/**
 * Zero-operator muscle path: brain → seed → task. Does not reimplement SelfConstruction.
 */
final class AutonomosModeAdapter implements AaeosExecutorModeAdapter
{
    public function __construct(
        private readonly AaeosEngineeringSpine $spine = new AaeosEngineeringSpine,
    ) {}

    public function mode(): string
    {
        return AaeosExecutorMode::AUTONOMOS;
    }

    public function accept(array $cyclePlan): array
    {
        $spine = $this->spine->contractForMode(AaeosExecutorMode::AUTONOMOS);

        return [
            'status' => 'ready',
            'mode' => AaeosExecutorMode::AUTONOMOS,
            'operate_path' => [
                'atlas:brain:next',
                'atlas:brain:seed',
                'atlas:task next',
            ],
            'spine' => [
                'delivery' => 'N9',
                'evidence' => 'N11',
                'muscle' => 'N10',
            ],
            'spine_contract' => $spine,
            'reason' => 'zero_operator_brain_task',
            'commit_policy' => 'scoped_main_git_add_files',
            'seed_gate_required' => true,
            'scoped_commit_required' => true,
            'human_in_engineering_loop' => false,
            'elite_same_bar' => true,
            'difficulty_level' => (int) (($cyclePlan['difficulty']['level'] ?? 1)),
            'live_dispatch' => (bool) ($cyclePlan['live_dispatch'] ?? false),
            'live_dispatch_note' => 'Live brain/task invocation stays on atlas:brain / atlas:task surfaces; cycle records governance intent.',
        ];
    }
}
