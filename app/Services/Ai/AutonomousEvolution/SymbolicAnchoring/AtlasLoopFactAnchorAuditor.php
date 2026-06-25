<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SymbolicAnchoring;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;

/**
 * Immutable verdict returned by {@see AtlasLoopFactAnchorAuditor::audit()}.
 */
final class AnchorAuditVerdict
{
    public function __construct(
        public readonly bool $accepted,
        public readonly string $reason,
        public readonly int $resolvedCount,
        public readonly int $unresolvedCount,
    ) {}

    public static function accept(int $resolved, int $unresolved, string $reason = 'ok'): self
    {
        return new self(true, $reason, $resolved, $unresolved);
    }

    public static function reject(string $reason, int $resolved = 0, int $unresolved = 0): self
    {
        return new self(false, $reason, $resolved, $unresolved);
    }
}

/**
 * Fail-closed gate any substrate publisher calls before persisting a FACT into a critical_path
 * channel. Verifies the FACT has at least one resolved anchor via {@see AtlasLoopFactAnchorExtractor}.
 *
 * Master-switch aware: when ATLAS_LOOP_MASTER_ENABLED=false the auditor is a byte-identical
 * pass-through that returns accept for EVERY input — it never regresses live behavior. When the
 * master switch is on, the gate enforces.
 *
 * Anti-Goodhart: only resolved anchors (file exists on disk) satisfy the gate. Phantom paths,
 * however many, do NOT count.
 */
final class AtlasLoopFactAnchorAuditor
{
    public function __construct(
        private readonly AtlasLoopFactAnchorExtractor $extractor,
        /** @var list<string> */
        private readonly array $criticalChannels,
    ) {}

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function audit(string $channel, string $factText, array $metadata = []): AnchorAuditVerdict
    {
        if (! AtlasLoopMasterSwitch::enabled()) {
            return AnchorAuditVerdict::accept(0, 0, 'master_switch_off_passthrough');
        }
        $isCritical = in_array($channel, $this->criticalChannels, true);
        if (! $isCritical) {
            return AnchorAuditVerdict::accept(0, 0, 'non_critical_channel_passthrough');
        }
        $anchors = $this->extractor->extract($factText, $metadata);
        $resolved = $anchors->resolvedCount();
        $unresolved = $anchors->unresolvedCount();
        if ($resolved > 0) {
            return AnchorAuditVerdict::accept($resolved, $unresolved);
        }
        if ($unresolved > 0) {
            return AnchorAuditVerdict::reject('only_phantom_anchors', $resolved, $unresolved);
        }

        return AnchorAuditVerdict::reject('no_resolved_anchor', $resolved, $unresolved);
    }
}
