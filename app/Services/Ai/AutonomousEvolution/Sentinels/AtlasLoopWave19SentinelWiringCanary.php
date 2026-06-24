<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Sentinels;

/**
 * WAVE-19 · SENTINEL WIRING CANARY — proves the four wave-19 sentinels are RESOLVABLE from the container and
 * runnable in a single pass with stable, schema-pinned outputs. Emits one FACTS digest combining each
 * sentinel's verdict (no scalar score).
 *
 * Pétreo floor preservation: when `atlas.loop.sentinels.wave19_enabled` is OFF (default false), {@see runAll()}
 * returns an empty array AND no container singleton is touched ⇒ byte-identical no-op.
 */
final class AtlasLoopWave19SentinelWiringCanary
{
    public const SCHEMA = 'atlas.loop.sentinels.wave19_canary.v1';

    /** Stable sentinel ids — the digest is keyed by these. */
    public const SENTINEL_IDS = [
        'sibling_role_coherence',
        'served_queue_inspector_sweep',
        'queue_disk_conformance',
        'replenisher_docgap_oracle_coverage',
    ];

    public function __construct(
        private readonly AtlasLoopReplenisherSiblingRoleCoherenceSentinel $siblingRoleCoherence,
        private readonly AtlasLoopServedQueueInspectorSweepSentinel $servedQueueInspectorSweep,
        private readonly AtlasLoopServingQueueDiskConformanceSentinel $queueDiskConformance,
        private readonly AtlasLoopReplenisherDocGapOracleCoverageSentinel $replenisherDocGapOracle,
    ) {}

    /**
     * Run every wave-19 sentinel and return a single digest. OFF ⇒ empty digest (byte-identical no-op).
     *
     * @return array<string, array{verdict:bool, facts:array<string,mixed>}>
     */
    public function runAll(): array
    {
        if (! (bool) config('atlas.loop.sentinels.wave19_enabled', false)) {
            return [];
        }

        $sibling = $this->siblingRoleCoherence->inspectDocblocks('', '');
        $sweep = $this->servedQueueInspectorSweep->sweep();
        $disk = $this->queueDiskConformance->check();
        $docGap = $this->replenisherDocGapOracle->check();

        return [
            'sibling_role_coherence' => [
                'verdict' => (bool) ($sibling['self_sufficient'] ?? false),
                'facts' => (array) ($sibling['facts'] ?? []),
            ],
            'served_queue_inspector_sweep' => [
                'verdict' => (bool) ($sweep['clean'] ?? false),
                'facts' => [
                    'scanned' => (int) ($sweep['scanned'] ?? 0),
                    'offenders' => (array) ($sweep['offenders'] ?? []),
                ],
            ],
            'queue_disk_conformance' => [
                'verdict' => (bool) ($disk['conformant'] ?? false),
                'facts' => [
                    'env_disk' => (string) ($disk['env_disk'] ?? ''),
                    'resolved_disk' => (string) ($disk['resolved_disk'] ?? ''),
                ],
            ],
            'replenisher_docgap_oracle_coverage' => [
                'verdict' => (bool) ($docGap['conformant'] ?? false),
                'facts' => [
                    'both_halves' => (array) ($docGap['both_halves'] ?? []),
                    'app_only' => (array) ($docGap['app_only'] ?? []),
                ],
            ],
        ];
    }
}
