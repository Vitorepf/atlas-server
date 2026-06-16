<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDecompositionBoundaryOracle;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraDecompositionPlanner;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopPlanReadinessGate;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE Leap 2 — end-to-end readiness gate behaviour with the human-frozen boundary-oracle wired in.
 *
 *  - Flag ON + a frozen oracle requiring a seam the plan OMITS  => assess() is NOT ready, with the
 *    missing-boundary gap; that gap round-trips into the planner's REPLAN loop and a corrected plan passes.
 *  - Flag OFF (or no oracle for the goal) => assess() is byte-identical to the no-oracle path.
 */
final class AtlasLoopDecompositionOracleGateTest extends TestCase
{
    private string $dir = '';

    private array $allowed = ['app/Services/Hub.php', 'app/Support/HubHelper.php'];

    private string $goal = 'Extract a HubHelper from app/Services/Hub.php and redirect the caller.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-oracle-gate-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o755, true);
        config()->set('atlas.loop.decomposition_oracle_dir', $this->dir);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            (new Process(['rm', '-rf', $this->dir]))->run();
        }
        parent::tearDown();
    }

    private function goodNode(string $id, string $file): array
    {
        return [
            'id' => $id,
            'seq' => 0,
            'request' => 'Reduce the worst-method cyclomatic complexity of '.$file.' preserving behaviour.',
            'target_area' => $file,
            'complexity_proof' => true,
        ];
    }

    private function freezeOracle(): void
    {
        $reader = new AtlasLoopDecompositionBoundaryOracle($this->dir);
        file_put_contents($reader->fixturePath($this->goal), json_encode([
            'required_boundaries' => ['app/Services/Hub.php', 'app/Support/HubHelper.php'],
            'required_create_files' => ['app/Support/HubHelper.php'],
        ]));
    }

    public function test_flag_on_with_oracle_refuses_a_plan_missing_a_required_seam(): void
    {
        config()->set('atlas.loop.decomposition_oracle_enabled', true);
        $this->freezeOracle();

        // A structurally-valid, fully-specified, ACYCLIC plan that nonetheless DROPS the required helper seam.
        $plan = ['plan_id' => 'obra-wrong', 'nodes' => [
            $this->goodNode('n1', 'app/Services/Hub.php'),
            $this->goodNode('n2', 'app/Services/Hub.php'), // merges the work onto Hub, never creates the helper
        ]];

        $r = (new AtlasLoopPlanReadinessGate)->assess($plan, $this->allowed, $this->goal);

        $this->assertFalse($r['ready'], 'a wrong-but-acyclic split that drops a required seam must REPLAN');
        $this->assertTrue($r['structural_valid'], 'the plan is structurally sound — only the boundary-oracle refuses it');
        $this->assertContains('decomposition_missing_required_boundary:app/Support/HubHelper.php', $r['gaps']);
        $this->assertSame(AtlasLoopPlanReadinessGate::REPLAN, $r['decision']);
    }

    public function test_flag_on_with_oracle_passes_a_plan_that_supersets_the_required_seams(): void
    {
        config()->set('atlas.loop.decomposition_oracle_enabled', true);
        $this->freezeOracle();

        $plan = ['plan_id' => 'obra-right', 'nodes' => [
            $this->goodNode('create', 'app/Support/HubHelper.php'),
            $this->goodNode('redirect', 'app/Services/Hub.php'),
        ]];

        $r = (new AtlasLoopPlanReadinessGate)->assess($plan, $this->allowed, $this->goal);

        $this->assertTrue($r['ready'], json_encode($r['gaps']));
        $this->assertSame(AtlasLoopPlanReadinessGate::IMPLEMENT, $r['decision']);
    }

    public function test_the_missing_boundary_gap_round_trips_through_the_planner_replan_loop(): void
    {
        config()->set('atlas.loop.decomposition_oracle_enabled', true);
        $this->freezeOracle();

        // The generator emits a WRONG plan first (missing the helper), then a CORRECT plan once it sees the
        // missing-boundary gap fed back as priorGaps — exactly the iterate-to-ready REPLAN contract.
        $calls = 0;
        $generate = function (string $goal, array $context, array $priorGaps) use (&$calls): array {
            $calls++;
            if ($calls === 1) {
                return ['plan_id' => 'p1', 'nodes' => [
                    $this->goodNode('n1', 'app/Services/Hub.php'),
                    $this->goodNode('n2', 'app/Services/Hub.php'),
                ]];
            }
            // On the REPLAN the generator must have received the missing-seam gap.
            $this->assertContains('decomposition_missing_required_boundary:app/Support/HubHelper.php', $priorGaps);

            return ['plan_id' => 'p2', 'nodes' => [
                $this->goodNode('create', 'app/Support/HubHelper.php'),
                $this->goodNode('redirect', 'app/Services/Hub.php'),
            ]];
        };

        $out = (new AtlasLoopObraDecompositionPlanner)->plan($this->goal, [], $this->allowed, $generate, 3);

        $this->assertTrue($out['ready'], json_encode($out['gaps']));
        $this->assertSame(2, $out['attempts'], 'the corrected plan certifies on the second attempt');
        $this->assertSame(2, $calls);
    }

    public function test_flag_off_is_byte_identical_to_the_no_oracle_path(): void
    {
        // Same plan that the boundary-oracle WOULD refuse — but with the flag OFF the oracle is never consulted.
        $this->freezeOracle(); // fixture present but flag OFF => must be ignored
        config()->set('atlas.loop.decomposition_oracle_enabled', false);

        $plan = ['plan_id' => 'obra-wrong', 'nodes' => [
            $this->goodNode('n1', 'app/Services/Hub.php'),
            $this->goodNode('n2', 'app/Services/Hub.php'),
        ]];

        $withGoalFlagOff = (new AtlasLoopPlanReadinessGate)->assess($plan, $this->allowed, $this->goal);
        $noGoalArg = (new AtlasLoopPlanReadinessGate)->assess($plan, $this->allowed);

        // Flag OFF => byte-identical to passing no goal at all (the pre-Leap-2 signature/behaviour).
        $this->assertSame($noGoalArg, $withGoalFlagOff);
        $this->assertTrue($withGoalFlagOff['ready'], 'flag OFF => structural-only gate admits the plan exactly as today');
        $this->assertSame([], array_values(array_filter(
            $withGoalFlagOff['gaps'],
            static fn (string $g): bool => str_starts_with($g, 'decomposition_missing_required_boundary'),
        )), 'no boundary gaps appear when the flag is OFF');
    }

    public function test_flag_on_but_no_oracle_for_goal_degrades_to_structural_only(): void
    {
        config()->set('atlas.loop.decomposition_oracle_enabled', true);
        // No fixture frozen for this goal => degrade to structural-only (no false-reject).

        $plan = ['plan_id' => 'obra', 'nodes' => [
            $this->goodNode('n1', 'app/Services/Hub.php'),
            $this->goodNode('n2', 'app/Support/HubHelper.php'),
        ]];

        $r = (new AtlasLoopPlanReadinessGate)->assess($plan, $this->allowed, $this->goal);
        $noGoal = (new AtlasLoopPlanReadinessGate)->assess($plan, $this->allowed);

        $this->assertSame($noGoal, $r, 'flag ON but no frozen oracle for the goal => byte-identical to no-oracle');
        $this->assertTrue($r['ready']);
    }
}
