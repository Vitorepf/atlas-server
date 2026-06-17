<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopChangeClassDrainGate;
use App\Services\Ai\Governance\AtlasChangeClassTrustLadder;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ACDE DG2 — the per-change-class earned-autonomy drain gate. Proves default-OFF is byte-identical, that a
 * freshly-armed gate parks a class with no earned autonomy, and that the SAME class auto-merges once its clean
 * streak reaches the operator's autonomous threshold. Temp trust-ladder log, no DB => hang-free.
 */
final class AtlasLoopChangeClassDrainGateTest extends TestCase
{
    private string $log = '';

    private const TARGET = 'app/Services/Foo/Bar.php'; // a 'code' change class

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = sys_get_temp_dir().'/atlas-dg2-'.bin2hex(random_bytes(5)).'.jsonl';
        config([
            'atlas.ai.trust_ladder.enabled' => true,
            'atlas.ai.trust_ladder.thresholds.autonomous' => 2,
            'atlas.ai.trust_ladder.eligible_classes' => [],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->log !== '' && is_file($this->log)) {
            File::delete($this->log);
        }
        parent::tearDown();
    }

    private function ladder(): AtlasChangeClassTrustLadder
    {
        $l = new AtlasChangeClassTrustLadder;
        $l->setLogPathForTesting($this->log);

        return $l;
    }

    public function test_off_is_byte_identical_never_abstains(): void
    {
        config(['atlas.loop.change_class_drain_gate_enabled' => false]);

        $this->assertFalse((new AtlasLoopChangeClassDrainGate($this->ladder()))->shouldAbstain(self::TARGET));
    }

    public function test_armed_parks_a_class_with_no_earned_autonomy(): void
    {
        config(['atlas.loop.change_class_drain_gate_enabled' => true]);

        // Fresh 'code' class: clean streak 0 < autonomous threshold 2 => not autonomous => abstain.
        $this->assertTrue((new AtlasLoopChangeClassDrainGate($this->ladder()))->shouldAbstain(self::TARGET));
    }

    public function test_armed_allows_a_class_that_earned_its_streak(): void
    {
        config(['atlas.loop.change_class_drain_gate_enabled' => true]);
        $ladder = $this->ladder();

        // Two clean landed merges of the 'code' class => streak 2 >= threshold 2 => autonomous => no abstain.
        $ladder->recordMergeOutcome([self::TARGET], 'commit-aaaa', ['ran' => true, 'passed' => true]);
        $ladder->recordMergeOutcome([self::TARGET], 'commit-bbbb', ['ran' => true, 'passed' => true]);

        $this->assertFalse((new AtlasLoopChangeClassDrainGate($ladder))->shouldAbstain(self::TARGET));
    }

    public function test_armed_blank_target_never_abstains(): void
    {
        config(['atlas.loop.change_class_drain_gate_enabled' => true]);

        $this->assertFalse((new AtlasLoopChangeClassDrainGate($this->ladder()))->shouldAbstain('   '));
    }
}
