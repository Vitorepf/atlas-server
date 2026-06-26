<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use Tests\TestCase;

/**
 * record/isDone/recentCycles roundtrip on a tmp root (injectable, so no real storage is touched). Proves the
 * dedup is sticky on target_path and recentCycles returns the tail in chronological order.
 */
final class AtlasBrainDoneSetLedgerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-brain-doneset-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root.'/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    private function ledger(string $scope = 'loop-scope'): AtlasBrainDoneSetLedger
    {
        return new AtlasBrainDoneSetLedger($scope, $this->root);
    }

    public function test_record_then_isDone_roundtrips(): void
    {
        $ledger = $this->ledger();
        self::assertFalse($ledger->isDone('app/Services/Ai/Foo/Bar.php'));

        $ledger->record([
            'snapshot_id' => 'snap-1',
            'status' => 'served',
            'produced' => true,
            'action' => 'proceed',
            'target_path' => 'app/Services/Ai/Foo/Bar.php',
            'task_packet_id' => 'pkt-1',
            'refusal' => false,
        ]);

        self::assertTrue($ledger->isDone('app/Services/Ai/Foo/Bar.php'));
        self::assertTrue($ledger->isDone('  app/Services/Ai/Foo/Bar.php  '), 'isDone trims the query');
        self::assertFalse($ledger->isDone('app/Services/Ai/Foo/Other.php'));
    }

    public function test_isDone_is_false_for_empty_target(): void
    {
        self::assertFalse($this->ledger()->isDone(''));
        self::assertFalse($this->ledger()->isDone('   '));
    }

    public function test_recentCycles_returns_chronological_tail(): void
    {
        $ledger = $this->ledger();
        foreach (['a', 'b', 'c', 'd'] as $t) {
            $ledger->record(['target_path' => "app/{$t}.php", 'action' => 'proceed', 'produced' => true]);
        }

        $recent = $ledger->recentCycles(2);

        self::assertCount(2, $recent);
        self::assertSame('app/c.php', $recent[0]['target_path']);
        self::assertSame('app/d.php', $recent[1]['target_path']);
    }

    public function test_recentCycles_zero_or_negative_returns_empty(): void
    {
        $ledger = $this->ledger();
        $ledger->record(['target_path' => 'app/a.php']);

        self::assertSame([], $ledger->recentCycles(0));
        self::assertSame([], $ledger->recentCycles(-3));
    }

    public function test_record_fills_schema_and_recorded_at(): void
    {
        $ledger = $this->ledger();
        $ledger->record(['target_path' => 'app/a.php', 'action' => 'abstain', 'refusal' => true]);

        $row = $ledger->recentCycles(1)[0];
        self::assertSame('atlas.brain.cycle.v1', $row['schema']);
        self::assertNotEmpty($row['recorded_at']);
        self::assertTrue($row['refusal']);
        self::assertSame('abstain', $row['action']);
    }

    public function test_scopes_are_isolated_by_slug(): void
    {
        $a = $this->ledger('scope-alpha');
        $b = $this->ledger('scope-beta');
        $a->record(['target_path' => 'app/only-in-alpha.php']);

        self::assertTrue($a->isDone('app/only-in-alpha.php'));
        self::assertFalse($b->isDone('app/only-in-alpha.php'), 'a different scope slug must not see alpha rows');
    }
}
