<?php

declare(strict_types=1);

namespace App\Services\Ai\HumanSurface;

final class EngineeringGoalReadinessBandDecider
{
    private const SCHEMA_VERSION = 'atlas.human_surface.engineering_goal_readiness.v1';

    /**
     * @param  list<string>  $presentSlots
     * @return array{schema_version: string, readiness: string, reason: string}
     */
    public function decide(bool $complete, array $presentSlots): array
    {
        $normalizedSlots = $this->normalizeSlots($presentSlots);

        if (! $complete) {
            return $this->band('needs_detail', 'required_slots_missing');
        }

        if (in_array('verification', $normalizedSlots, true)) {
            return $this->band('ready', 'complete_with_verification');
        }

        return $this->band('needs_detail', 'complete_without_verification');
    }

    /**
     * @param  array<int, mixed>  $presentSlots
     * @return list<string>
     */
    private function normalizeSlots(array $presentSlots): array
    {
        $normalized = [];

        foreach ($presentSlots as $slot) {
            if (! is_string($slot)) {
                continue;
            }

            $candidate = strtolower(trim($slot));

            if ($candidate === '') {
                continue;
            }

            $normalized[] = $candidate;
        }

        return $normalized;
    }

    /**
     * @return array{schema_version: string, readiness: string, reason: string}
     */
    private function band(string $readiness, string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'readiness' => $readiness,
            'reason' => $reason,
        ];
    }
}
