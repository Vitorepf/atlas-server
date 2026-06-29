<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\AtlasLoopFactAnchorAuditor;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\AtlasLoopFactAnchorExtractor;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopFactAnchorAuditor::audit()} at the operator surface: audits a fact text on a
 * given channel and emits the anchor-audit verdict (accepted, reason, resolved/unresolved anchor counts) as
 * JSON. Critical channels (config atlas.loop.fact_anchor.critical_channels) are anchor-checked; others pass
 * through. Read-only.
 */
final class AtlasLoopFactAnchorAuditCommand extends Command
{
    protected $signature = 'atlas:loop:fact-anchor-audit {--channel=} {--fact=} {--json}';

    protected $description = 'Read-only fact-anchor audit: does a critical-channel fact resolve to real symbol anchors?';

    public function handle(): int
    {
        $channel = trim((string) $this->option('channel'));
        $fact = (string) $this->option('fact');
        if ($channel === '' || $fact === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'fact-anchor-audit requires --channel and --fact',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $criticalChannels = array_values(array_filter(
            array_map('strval', (array) config('atlas.loop.fact_anchor.critical_channels', [])),
            static fn (string $c): bool => $c !== '',
        ));

        $verdict = (new AtlasLoopFactAnchorAuditor(new AtlasLoopFactAnchorExtractor(base_path()), $criticalChannels))
            ->audit($channel, $fact);

        $this->line((string) json_encode([
            'schema_version' => 'atlas.loop.fact_anchor_audit.v1',
            'channel' => $channel,
            'accepted' => $verdict->accepted,
            'reason' => $verdict->reason,
            'resolved_count' => $verdict->resolvedCount,
            'unresolved_count' => $verdict->unresolvedCount,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
