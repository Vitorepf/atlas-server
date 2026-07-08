<?php

declare(strict_types=1);

namespace App\Services\Engineering;

/**
 * Fail-closed guard during elite compaction obra — blocks Autônomos from originating new ACOS blocks.
 */
final class EliteCompactionFreezeGuard
{
    public const SCHEMA_VERSION = 'atlas.elite_compaction.freeze_guard.v1';

    public function blocksAutonomosOrigination(): bool
    {
        return (bool) config('atlas_elite_compaction.freeze.active', false)
            && (bool) config('atlas_elite_compaction.freeze.blocks_autonomos_block_origination', false);
    }

    /**
     * @return array<string, mixed>|null null = allowed
     */
    public function refusalPayload(string $originator = 'atlas:brain:next'): ?array
    {
        if (! $this->blocksAutonomosOrigination()) {
            return null;
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'status' => 'frozen',
            'reason' => (string) config('atlas_elite_compaction.freeze.reason', 'elite_compaction_freeze'),
            'originator' => $originator,
            'started_at' => config('atlas_elite_compaction.freeze.started_at'),
            'doc' => 'docs/engineering-knowledge-base/atlas-autonomos-live-system.md',
        ];
    }
}
