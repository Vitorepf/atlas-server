<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Console\Command;

/**
 * Composing dashboard: renders ONE TTY frame divided into four labelled panels in fixed order
 * {status, trinity, quaternity, receipts}. Each panel is produced by the SAME public render(array $snapshot)
 * method the underlying command uses (same code path, no re-running of those commands' handle()s).
 *
 * INVARIANTS:
 *   - READ-ONLY: never writes; never invokes any of the 4 dashboard handle() methods.
 *   - DETERMINISTIC: a frozen composite snapshot ⇒ byte-identical frame.
 *   - NO COMPOSITE SCORE: the composer does not aggregate a single number across panels (R1/R2 anti-Goodhart).
 *   - BYTE-IDENTICAL master-OFF: when ATLAS_LOOP_MASTER_ENABLED=false, only the master-off banner is emitted
 *     and ZERO render() calls happen (verified by spies on each dashboard).
 */
final class AtlasLoopSubstrateOverviewCommand extends Command
{
    public const SNAPSHOT_SOURCE_KEY = 'atlas.loop.overview.snapshot_source';

    public const MASTER_OFF_BANNER = '[atlas:loop:overview] master_switch_off';

    public const PANEL_ORDER = ['status', 'trinity', 'quaternity', 'receipts'];

    /** @var string */
    protected $signature = 'atlas:loop:overview';

    /** @var string */
    protected $description = 'One-screen composite dashboard: status / trinity / quaternity / receipts.';

    public function handle(): int
    {
        if (! AtlasLoopMasterSwitch::enabled()) {
            $this->line(self::MASTER_OFF_BANNER);

            return self::SUCCESS;
        }

        $composite = $this->loadComposite();
        $this->line($this->composeFrame($composite));

        return self::SUCCESS;
    }

    /**
     * Pure composer: given a composite snapshot {status, trinity, quaternity, receipts}, renders the
     * 4-panel frame. Called by handle() AND by the feature test that asserts overview == concat-with-headers
     * of the 4 individual render() outputs given the SAME snapshot.
     *
     * @param  array{status?:array<string,mixed>, trinity?:array<string,mixed>, quaternity?:array<string,mixed>, receipts?:array<string,mixed>}  $composite
     */
    public function composeFrame(array $composite): string
    {
        $status = $this->resolveStatusDashboard()->render((array) ($composite['status'] ?? []));
        $trinity = $this->resolveTrinityDashboard()->render((array) ($composite['trinity'] ?? []));
        $quaternity = $this->resolveQuaternityDashboard()->render((array) ($composite['quaternity'] ?? []));
        $receipts = $this->resolveReceiptsDashboard()->render((array) ($composite['receipts'] ?? []));

        return implode("\n", [
            '=== STATUS ===', $status,
            '=== TRINITY ===', $trinity,
            '=== QUATERNITY ===', $quaternity,
            '=== RECEIPTS ===', $receipts,
        ]);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function loadComposite(): array
    {
        if (! $this->getLaravel()->bound(self::SNAPSHOT_SOURCE_KEY)) {
            return ['status' => [], 'trinity' => [], 'quaternity' => [], 'receipts' => []];
        }
        $source = $this->getLaravel()->make(self::SNAPSHOT_SOURCE_KEY);
        if (! is_callable($source)) {
            return ['status' => [], 'trinity' => [], 'quaternity' => [], 'receipts' => []];
        }
        $snapshot = $source();

        return is_array($snapshot) ? $snapshot : [];
    }

    private function resolveStatusDashboard(): AtlasLoopStatusDashboardCommand
    {
        return $this->getLaravel()->make(AtlasLoopStatusDashboardCommand::class);
    }

    private function resolveTrinityDashboard(): AtlasLoopTrinityDashboardCommand
    {
        return $this->getLaravel()->make(AtlasLoopTrinityDashboardCommand::class);
    }

    private function resolveQuaternityDashboard(): AtlasLoopQuaternityDashboardCommand
    {
        return $this->getLaravel()->make(AtlasLoopQuaternityDashboardCommand::class);
    }

    private function resolveReceiptsDashboard(): AtlasLoopReceiptsDashboardCommand
    {
        return $this->getLaravel()->make(AtlasLoopReceiptsDashboardCommand::class);
    }
}
