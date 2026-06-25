<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Retention;

/**
 * Advisory garbage-collection service for AtlasLoop snapshots.
 *
 * Consumes the persistent snapshot index (newest-first) via an injected callable and the
 * {@see AtlasLoopSnapshotRetentionPolicy} to emit a SnapshotRetentionAdvisory. NEVER deletes,
 * moves, renames or mutates any snapshot file or DB row — the only thing this service produces
 * is a typed advisory. Operator opt-in deletion lives in a separate future packet.
 *
 * The advisory shape:
 *   {
 *     schema_version, kept_ids, prune_candidate_ids, policy_fingerprint,
 *     scanned_at, totals: {kept, prune_candidate}
 *   }
 */
final class AtlasLoopSnapshotRetentionGc
{
    public const SCHEMA = 'atlas.loop.snapshot_retention_gc_advisory.v1';

    /**
     * @param  callable(): list<string>  $snapshotIndexSource  returns snapshot ids newest-first
     * @param  callable(): string  $clock  returns the scanned_at timestamp (ISO-8601 UTC string)
     */
    public function __construct(
        private readonly AtlasLoopSnapshotRetentionPolicy $policy,
        private readonly mixed $snapshotIndexSource,
        private readonly mixed $clock,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function scan(): array
    {
        $source = $this->snapshotIndexSource;
        $snapshotIds = is_callable($source) ? array_values(array_map('strval', (array) $source())) : [];
        $verdict = $this->policy->classify($snapshotIds);

        $keptIds = [];
        $pruneIds = [];
        foreach ((array) $verdict['entries'] as $entry) {
            $id = (string) $entry['snapshot_id'];
            if ((string) $entry['status'] === AtlasLoopSnapshotRetentionPolicy::STATUS_KEEP) {
                $keptIds[] = $id;
            } else {
                $pruneIds[] = $id;
            }
        }

        $scannedAt = is_callable($this->clock) ? (string) ($this->clock)() : '';
        $policyFingerprint = $this->fingerprint($verdict);

        return [
            'schema_version' => self::SCHEMA,
            'kept_ids' => $keptIds,
            'prune_candidate_ids' => $pruneIds,
            'policy_fingerprint' => $policyFingerprint,
            'scanned_at' => $scannedAt,
            'totals' => [
                'kept' => (int) $verdict['keep_count'],
                'prune_candidate' => (int) $verdict['prune_candidate_count'],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $verdict
     */
    private function fingerprint(array $verdict): string
    {
        $canonical = json_encode([
            'schema_version' => $verdict['schema_version'] ?? '',
            'keep_last_n' => $verdict['keep_last_n'] ?? 0,
            'keep_every_mth' => $verdict['keep_every_mth'] ?? 1,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'policy_'.substr(hash('sha256', (string) $canonical), 0, 24);
    }
}
