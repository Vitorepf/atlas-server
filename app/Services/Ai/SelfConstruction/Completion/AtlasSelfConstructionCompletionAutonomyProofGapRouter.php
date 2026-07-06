<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Routes final autonomy proof gaps into concrete task specs instead
 * of declaring completion from aggregate maturity labels.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasSelfConstructionCompletionAutonomyProofGapRouter
{
    public const SCHEMA = 'atlas.self_construction.completion_autonomy_proof_gap_router.v1';

    public const GAP_QUEUE = 'queue_proof_gap';
    public const GAP_LEARNING = 'learning_proof_gap';
    public const GAP_RECOVERY = 'recovery_proof_gap';
    public const GAP_ORIGINATOR = 'originator_proof_gap';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function route(array $input): array
    {
        $tasks = [];

        $gaps = [
            self::GAP_QUEUE => (bool) ($input['queue_proof_present'] ?? true),
            self::GAP_LEARNING => (bool) ($input['learning_proof_present'] ?? true),
            self::GAP_RECOVERY => (bool) ($input['recovery_proof_present'] ?? true),
            self::GAP_ORIGINATOR => (bool) ($input['originator_proof_present'] ?? true),
        ];

        foreach ($gaps as $gap => $present) {
            if (! $present) {
                $tasks[] = [
                    'gap' => $gap,
                    'task_spec' => 'implement '.$gap,
                    'scope' => $this->scopeForGap($gap),
                    'priority' => $this->priorityForGap($gap),
                ];
            }
        }

        return [
            'schema' => self::SCHEMA,
            'gap_tasks' => $tasks,
            'gap_count' => count($tasks),
            'complete' => $tasks === [],
        ];
    }

    private function scopeForGap(string $gap): string
    {
        return match ($gap) {
            self::GAP_QUEUE => 'queue_steady_state',
            self::GAP_LEARNING => 'learning_loop',
            self::GAP_RECOVERY => 'recovery_pipeline',
            self::GAP_ORIGINATOR => 'originator_autonomy',
            default => 'unknown',
        };
    }

    private function priorityForGap(string $gap): int
    {
        return match ($gap) {
            self::GAP_QUEUE => 100,
            self::GAP_RECOVERY => 90,
            self::GAP_LEARNING => 80,
            self::GAP_ORIGINATOR => 70,
            default => 50,
        };
    }
}
