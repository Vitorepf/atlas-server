<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDecompositionArchetypeOracle;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopPlanReadinessGate;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE Leap 8 — the reusable greenfield archetype library: a goal that DETERMINISTICALLY classifies into a
 * human-frozen archetype is held to its structural invariants for ANY novel objective in the family (no
 * per-goal fixture). A goal matching no archetype degrades to structural-only (byte-identical).
 */
final class AtlasLoopDecompositionArchetypeTest extends TestCase
{
    private string $dir = '';

    private array $allowed = ['app/Services/Hub.php', 'app/Support/HubHelper.php'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-archetype-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o755, true);
        config()->set('atlas.loop.decomposition_archetype_dir', $this->dir);
        // Freeze ONE archetype: an extract-class obra must have >= 2 nodes AND create a *Helper.php file.
        file_put_contents($this->dir.'/extract_class.json', json_encode([
            'id' => 'extract_class',
            'match_all' => ['extract'],
            'match_any' => ['class', 'helper', 'service'],
            'min_nodes' => 2,
            'required_create_suffixes' => ['Helper.php', 'Service.php'],
            'min_distinct_targets' => 2,
        ]));
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            (new Process(['rm', '-rf', $this->dir]))->run();
        }
        parent::tearDown();
    }

    private function node(string $id, string $file, int $seq = 0): array
    {
        return [
            'id' => $id,
            'seq' => $seq,
            'request' => 'Reduce the worst-method cyclomatic complexity of '.$file.' preserving behaviour.',
            'target_area' => $file,
            'depends_on' => [],
            'complexity_proof' => true,
        ];
    }

    public function test_oracle_classifies_only_matching_goals(): void
    {
        $o = new AtlasLoopDecompositionArchetypeOracle($this->dir);

        $this->assertSame('extract_class', $o->classify('Extract a Helper class from Hub')['id'] ?? null);
        $this->assertNull($o->classify('Add a new REST endpoint for orders'), 'no archetype matches => null');
        $this->assertNull($o->classify('extract the data'), 'match_all ok but match_any (class/helper/service) absent');
    }

    public function test_oracle_flags_a_single_node_merge_that_omits_the_create_class(): void
    {
        $o = new AtlasLoopDecompositionArchetypeOracle($this->dir);
        // One node, edits only the hub, never creates a helper — the structurally-wrong greenfield split.
        $plan = ['plan_id' => 'p', 'nodes' => [$this->node('n1', 'app/Services/Hub.php')]];

        $v = $o->violations($plan, 'Extract a Helper class from Hub');

        $this->assertContains('decomposition_archetype_violation:extract_class:min_nodes:1<2', $v);
        $this->assertContains('decomposition_archetype_violation:extract_class:missing_create_class:Helper.php|Service.php', $v);
    }

    public function test_oracle_passes_a_correct_extract_split(): void
    {
        $o = new AtlasLoopDecompositionArchetypeOracle($this->dir);
        $plan = ['plan_id' => 'p', 'nodes' => [
            $this->node('create', 'app/Support/HubHelper.php', 0),
            $this->node('redirect', 'app/Services/Hub.php', 1),
        ]];

        $this->assertSame([], $o->violations($plan, 'Extract a Helper class from Hub'));
    }

    public function test_gate_replans_a_violating_plan_when_armed(): void
    {
        config()->set('atlas.loop.decomposition_archetype_enabled', true);
        $plan = ['plan_id' => 'p', 'nodes' => [$this->node('n1', 'app/Services/Hub.php')]];

        $r = (new AtlasLoopPlanReadinessGate)->assess($plan, $this->allowed, 'Extract a Helper class from Hub');

        $this->assertFalse($r['ready']);
        $this->assertSame(AtlasLoopPlanReadinessGate::REPLAN, $r['decision']);
        $this->assertNotEmpty(array_filter($r['gaps'], static fn (string $g): bool => str_starts_with($g, 'decomposition_archetype_violation')));
    }

    public function test_gate_is_byte_identical_when_flag_off(): void
    {
        config()->set('atlas.loop.decomposition_archetype_enabled', false);
        // A plan that WOULD violate the archetype — but the flag is off, and it's a valid 2-node plan.
        $plan = ['plan_id' => 'p', 'nodes' => [
            $this->node('create', 'app/Support/HubHelper.php', 0),
            $this->node('redirect', 'app/Services/Hub.php', 1),
        ]];

        $withGoal = (new AtlasLoopPlanReadinessGate)->assess($plan, $this->allowed, 'Extract a Helper class from Hub');
        $noGoal = (new AtlasLoopPlanReadinessGate)->assess($plan, $this->allowed);

        $this->assertSame($noGoal, $withGoal);
        $this->assertTrue($withGoal['ready']);
    }

    public function test_gate_degrades_when_no_archetype_matches(): void
    {
        config()->set('atlas.loop.decomposition_archetype_enabled', true);
        // A structurally-valid 2-node plan; the goal classifies into NO archetype => structural-only => ready.
        $plan = ['plan_id' => 'p', 'nodes' => [
            $this->node('a', 'app/Services/Hub.php', 0),
            $this->node('b', 'app/Support/HubHelper.php', 1),
        ]];

        $r = (new AtlasLoopPlanReadinessGate)->assess($plan, $this->allowed, 'Add a new REST endpoint for orders');

        $this->assertSame([], array_values(array_filter($r['gaps'], static fn (string $g): bool => str_starts_with($g, 'decomposition_archetype_violation'))));
        $this->assertTrue($r['ready']);
    }
}
