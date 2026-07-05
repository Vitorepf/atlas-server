<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Compiles quarantined task reasons into coherent repair waves that
 * separate malformed repair, forbidden-target retirement, duplicate
 * give_back, and protected-scope keep-blocked actions.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasTaskFabricQuarantineToRepairWaveCompiler
{
    public const SCHEMA = 'atlas.self_construction.task_fabric_quarantine_to_repair_wave.v1';

    public const WAVE_REPAIR = 'repair';
    public const WAVE_RETIRE = 'retire';
    public const WAVE_GIVE_BACK = 'give_back';
    public const WAVE_KEEP_BLOCKED = 'keep_blocked';

    /**
     * @param  array<int, array<string, mixed>>  $quarantinedTasks
     * @return array<string, mixed>
     */
    public function compile(array $quarantinedTasks): array
    {
        $waves = [
            self::WAVE_REPAIR => [],
            self::WAVE_RETIRE => [],
            self::WAVE_GIVE_BACK => [],
            self::WAVE_KEEP_BLOCKED => [],
        ];

        foreach ($quarantinedTasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $id = (string) ($task['task_id'] ?? '');
            $reason = (string) ($task['quarantine_reason'] ?? '');

            $wave = $this->classifyReason($reason);

            $waves[$wave][] = [
                'task_id' => $id,
                'quarantine_reason' => $reason,
                'wave' => $wave,
                'repair_hint' => $this->repairHint($reason),
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'waves' => $waves,
            'wave_counts' => [
                self::WAVE_REPAIR => count($waves[self::WAVE_REPAIR]),
                self::WAVE_RETIRE => count($waves[self::WAVE_RETIRE]),
                self::WAVE_GIVE_BACK => count($waves[self::WAVE_GIVE_BACK]),
                self::WAVE_KEEP_BLOCKED => count($waves[self::WAVE_KEEP_BLOCKED]),
            ],
            'total_quarantined' => count($quarantinedTasks),
        ];
    }

    private function classifyReason(string $reason): string
    {
        // Malformed repair reasons
        if (str_contains($reason, 'malformed') || str_contains($reason, 'missing_implementation')
            || str_contains($reason, 'missing_scope') || str_contains($reason, 'repair')) {
            return self::WAVE_REPAIR;
        }

        // Forbidden target retirement
        if (str_contains($reason, 'forbidden') || str_contains($reason, 'unsafe_target')) {
            return self::WAVE_RETIRE;
        }

        // Duplicate give_back
        if (str_contains($reason, 'duplicate') || str_contains($reason, 'give_back')) {
            return self::WAVE_GIVE_BACK;
        }

        // Protected scope keep-blocked
        if (str_contains($reason, 'protected') || str_contains($reason, 'hot_scope')
            || str_contains($reason, 'voice') || str_contains($reason, 'kernel')) {
            return self::WAVE_KEEP_BLOCKED;
        }

        // Default: repair
        return self::WAVE_REPAIR;
    }

    private function repairHint(string $reason): string
    {
        return match (true) {
            str_contains($reason, 'malformed') => 'respec the task packet with valid structure',
            str_contains($reason, 'forbidden') => 'retire the task and remove the forbidden target',
            str_contains($reason, 'duplicate') => 'deduplicate and give_back the redundant packet',
            str_contains($reason, 'protected') => 'keep blocked — protected scope requires operator review',
            default => 'inspect and repair the quarantine reason',
        };
    }
}
