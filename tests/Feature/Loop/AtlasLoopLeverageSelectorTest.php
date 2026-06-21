<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopLeverageSelector;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopOrphanWiringSupplyLane;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSiblingTestResolver;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * §3 · LEVERAGE SELECTION — the brain picks the highest-leverage step among the REAL grounded candidates, and
 * it can ONLY reorder them: a model pick outside the candidate set is rejected (fail-closed), a malformed/empty
 * completion keeps the deterministic order, and the wired supply lane lands the model's pick FIRST so a capped
 * refill does the biggest step.
 */
final class AtlasLoopLeverageSelectorTest extends TestCase
{
    public function test_model_pick_only_reorders_the_real_set_and_an_out_of_range_pick_is_rejected(): void
    {
        $candidates = [
            ['kind' => 'orphan_wiring', 'summary' => 'A'],
            ['kind' => 'orphan_wiring', 'summary' => 'B'],
            ['kind' => 'orphan_wiring', 'summary' => 'C'],
        ];

        // a real, in-range pick (index 2) is honored.
        $sel = new AtlasLoopLeverageSelector(fn (string $p, string $pr): string => "<<<PICK>>>\n2");
        $this->assertSame(2, $sel->pickIndex($candidates));
        $ranked = $sel->rank($candidates);
        $this->assertSame('C', $ranked[0]['summary'], 'the model pick lands first');
        $this->assertCount(3, $ranked, 'rank is a permutation — never adds or drops');

        // an out-of-range pick (the model naming a candidate that does not exist) is REJECTED.
        $bad = new AtlasLoopLeverageSelector(fn (string $p, string $pr): string => '<<<PICK>>>99');
        $this->assertNull($bad->pickIndex($candidates), 'a pick outside the real set cannot fabricate a candidate');
        $this->assertSame($candidates, $bad->rank($candidates), 'fail-closed ⇒ deterministic order preserved');
    }

    public function test_malformed_or_no_completion_keeps_the_deterministic_order(): void
    {
        $candidates = [['summary' => 'A'], ['summary' => 'B']];
        $malformed = new AtlasLoopLeverageSelector(fn (string $p, string $pr): string => 'I think the first one');
        $this->assertNull($malformed->pickIndex($candidates));
        $this->assertSame($candidates, $malformed->rank($candidates));

        // no provider configured ⇒ live path returns null ⇒ deterministic order.
        config(['atlas.loop.default_provider' => '']);
        $this->assertNull((new AtlasLoopLeverageSelector)->pickIndex($candidates));
    }

    public function test_a_single_candidate_needs_no_judgment(): void
    {
        $this->assertNull((new AtlasLoopLeverageSelector(fn (string $p, string $pr): string => '<<<PICK>>>0'))->pickIndex([['summary' => 'only']]));
    }

    public function test_supply_lane_lands_the_models_pick_first_when_armed(): void
    {
        config(['atlas.loop.leverage_selection_enabled' => true]);
        $ws = $this->workspaceWithTwoTestedOrphans();

        // A selector double that prefers the SECOND minted directive.
        $selector = new AtlasLoopLeverageSelector(fn (string $p, string $pr): string => "<<<PICK>>>\n1");
        $lane = new AtlasLoopOrphanWiringSupplyLane(new AtlasLoopSiblingTestResolver($ws), $selector);

        $specs = $lane->mint($this->model($ws), $ws);
        $this->assertGreaterThanOrEqual(2, count($specs), 'both tested orphans are admissible directives');
        $this->assertStringContainsString('Beta', (string) $specs[0]['payload']['orphan_fqcn'], 'the model-preferred directive is landed first');

        (new Process(['rm', '-rf', $ws]))->run();
    }

    private function model(string $ws): AtlasLoopScopeComprehensionModel
    {
        $inv = [
            ['rel_path' => 'app/Alpha.php', 'fqcn' => 'Alpha', 'public_methods' => ['go'], 'is_orphan' => true, 'is_forbidden' => false, 'clone_cluster_id' => null],
            ['rel_path' => 'app/Beta.php', 'fqcn' => 'Beta', 'public_methods' => ['go'], 'is_orphan' => true, 'is_forbidden' => false, 'clone_cluster_id' => null],
        ];

        return new AtlasLoopScopeComprehensionModel(
            inventory: $inv,
            edges: ['app/Alpha.php' => [], 'app/Beta.php' => []],
            orphans: ['Alpha', 'Beta'],
            cloneClusters: [], forbidden: [], docPurposes: [], docStatedGaps: [],
            snapshotId: 'snap',
        );
    }

    private function workspaceWithTwoTestedOrphans(): string
    {
        $ws = sys_get_temp_dir().'/atlas-leverage-'.bin2hex(random_bytes(4));
        @mkdir($ws.'/app', 0o755, true);
        @mkdir($ws.'/tests/Unit', 0o755, true);
        foreach (['Alpha', 'Beta'] as $cls) {
            file_put_contents($ws."/app/{$cls}.php", "<?php\nclass {$cls} { public function go(): int { return 1; } }\n");
            file_put_contents($ws."/tests/Unit/{$cls}Test.php", "<?php\nclass {$cls}Test { public function test_go() { \$this->assertSame(1, (new {$cls})->go()); } }\n");
        }

        return $ws;
    }
}
