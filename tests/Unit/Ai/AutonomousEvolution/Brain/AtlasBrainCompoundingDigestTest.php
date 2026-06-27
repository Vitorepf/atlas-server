<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCompoundingDigest;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use Tests\TestCase;

/**
 * FROZEN proof of the compounding digest — tail-window summary of done-set rows. Proves the empty-ledger
 * gracefulness, the status distribution + success streak (consecutive `served` from the tail), the top-actions
 * bounded ordering, and the pétreo contract.
 */
final class AtlasBrainCompoundingDigestTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-brain-compounding-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            foreach (glob($this->root.'/*.jsonl') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    private function ledger(string $scope = 'loop'): AtlasBrainDoneSetLedger
    {
        return new AtlasBrainDoneSetLedger($scope, $this->root);
    }

    private function record(AtlasBrainDoneSetLedger $ledger, string $status, string $action, string $target): void
    {
        $ledger->record([
            'snapshot_id' => 'snap',
            'status' => $status,
            'produced' => $status === 'served',
            'action' => $action,
            'target_path' => $target,
            'task_packet_id' => 'pkt-'.bin2hex(random_bytes(3)),
            'refusal' => $status !== 'served',
        ]);
    }

    public function test_empty_ledger_returns_window_zero(): void
    {
        $r = (new AtlasBrainCompoundingDigest)->digest($this->ledger());

        self::assertSame(0, $r['window']);
        self::assertSame([], $r['by_status']);
        self::assertSame(0, $r['success_streak']);
    }

    public function test_status_distribution_and_success_streak(): void
    {
        $l = $this->ledger();
        $this->record($l, 'refused', 'origin', 'A.php');
        $this->record($l, 'served', 'origin', 'B.php');
        $this->record($l, 'served', 'origin', 'C.php');
        $this->record($l, 'served', 'origin', 'D.php');

        $r = (new AtlasBrainCompoundingDigest)->digest($l);

        self::assertSame(4, $r['window']);
        self::assertSame(['refused' => 1, 'served' => 3], $r['by_status'], 'distribution sorted alphabetically');
        self::assertSame(3, $r['success_streak'], 'tail-streak of consecutive served');
    }

    public function test_streak_breaks_on_non_served(): void
    {
        $l = $this->ledger();
        $this->record($l, 'served', 'origin', 'A.php');
        $this->record($l, 'served', 'origin', 'B.php');
        $this->record($l, 'refused', 'origin', 'C.php'); // breaks the tail streak

        $r = (new AtlasBrainCompoundingDigest)->digest($l);

        self::assertSame(0, $r['success_streak'], 'last row is refused ⇒ streak is 0');
    }

    public function test_top_actions_are_bounded_and_ordered(): void
    {
        $l = $this->ledger();
        foreach ([['origin', 4], ['seed', 2], ['repair', 1]] as [$action, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $this->record($l, 'served', $action, "{$action}-{$i}.php");
            }
        }

        $r = (new AtlasBrainCompoundingDigest)->digest($l);

        self::assertSame('origin', $r['top_actions'][0]['action']);
        self::assertSame(4, $r['top_actions'][0]['count']);
        self::assertSame('seed', $r['top_actions'][1]['action']);
        self::assertLessThanOrEqual(5, count($r['top_actions']));
    }

    public function test_digest_window_zero_returns_empty(): void
    {
        $l = $this->ledger();
        $this->record($l, 'served', 'origin', 'A.php');

        $r = (new AtlasBrainCompoundingDigest)->digest($l, 0);

        self::assertSame(0, $r['window']);
    }

    public function test_no_scalar_contract(): void
    {
        $l = $this->ledger();
        $this->record($l, 'served', 'origin', 'A.php');

        $r = (new AtlasBrainCompoundingDigest)->digest($l);

        foreach (['score', 'rank', 'leverage', 'quality', 'weight'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $r);
        }
    }

    public function test_digest_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCompoundingDigest.php',
            true
        );

        self::assertSame('forbidden', $verdict);
    }
}
