<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Feedback\AtlasLoopGiveBackHonestyAuditor;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopGiveBackHonestyAuditor::audit()} at the operator surface: audits the recent
 * give-back window and emits, per packet, whether the give-back was a genuine blocker (honest), an evasion
 * (suspect — files later changed within the window), or unverifiable. Deterministic facts only, read-only.
 */
final class AtlasLoopGiveBackHonestyCommand extends Command
{
    protected $signature = 'atlas:loop:give-back-honesty {--limit=200} {--window-hours=168} {--json}';

    protected $description = 'Read-only give-back honesty audit: genuine blocker vs. evasion, per packet.';

    public function handle(): int
    {
        $audits = $this->getLaravel()->make(AtlasLoopGiveBackHonestyAuditor::class)
            ->audit(max(1, (int) $this->option('limit')), max(1, (int) $this->option('window-hours')));

        $this->line((string) json_encode([
            'schema_version' => 'atlas.loop.give_back_honesty.v1',
            'audited_count' => count($audits),
            'audits' => $audits,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
