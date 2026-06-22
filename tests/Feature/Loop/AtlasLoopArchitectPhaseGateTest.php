<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopArchitectPhaseGate;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Tests\TestCase;

/**
 * §1 · ARCHITECT PHASE made UNIVERSAL — a reusable gate any supply lane calls to DESIGN a directive before it
 * becomes work: it admits a directive that converges (attaching the typed obligations + the enforceable
 * consumer_contracts that protect every real caller) and SUPPRESSES one it cannot — a pétreo cert organ or a
 * blast-radius too large to land as one safe step. So origination stops minting un-designed work.
 */
final class AtlasLoopArchitectPhaseGateTest extends TestCase
{
    public function test_admits_a_convergent_directive_with_its_design_contract_and_caller_protection(): void
    {
        $target = 'app/Services/Ai/AutonomousEvolution/Hub.php';
        $caller = 'app/Services/Ai/AutonomousEvolution/CallerA.php';
        $verdict = (new AtlasLoopArchitectPhaseGate)->admit($this->model($target, [$caller]), $target, 'dedup', sys_get_temp_dir());

        $this->assertTrue($verdict['admitted'], (string) ($verdict['reason'] ?? ''));
        $kinds = array_column($verdict['obligations'], 'kind');
        $this->assertContains('complexity_reduced', $kinds, 'a dedup directive carries its mandatory work-type proof');
        $this->assertContains('consumer_intact', $kinds, 'and protects the real caller');
        // the consumer obligation became an enforceable contract naming the real caller.
        $contractConsumers = array_column($verdict['consumer_contracts'], 'consumer_file');
        $this->assertContains($caller, $contractConsumers, 'the design contract is enforceable against the real caller');
    }

    public function test_suppresses_a_petreo_cert_organ_directive(): void
    {
        $target = 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php';
        $model = $this->model($target, ['app/Services/Ai/AutonomousEvolution/CallerA.php'], forbidden: [$target]);
        $verdict = (new AtlasLoopArchitectPhaseGate)->admit($model, $target, 'dedup', sys_get_temp_dir());

        $this->assertFalse($verdict['admitted']);
        $this->assertSame('forbidden_target_petreo', $verdict['reason']);
        $this->assertSame([], $verdict['obligations']);
    }

    public function test_suppresses_a_blast_radius_too_large_to_design_as_one_step(): void
    {
        $target = 'app/Services/Ai/AutonomousEvolution/MegaHub.php';
        $callers = [];
        for ($i = 0; $i < 12; $i++) {
            $callers[] = 'app/Services/Ai/AutonomousEvolution/Caller'.$i.'.php';
        }
        $verdict = (new AtlasLoopArchitectPhaseGate)->admit($this->model($target, $callers), $target, 'dedup', sys_get_temp_dir());

        $this->assertFalse($verdict['admitted'], 'a 12-caller blast radius cannot be designed as one safe step');
    }

    public function test_suppresses_a_behavior_preserving_refactor_of_untested_code(): void
    {
        $target = 'app/X/Untested.php';
        $model = $this->model($target, []);
        $gate = new AtlasLoopArchitectPhaseGate;

        // a behavior-PRESERVING refactor of a target with NO sibling test cannot be designed.
        $untested = $this->workspace($target, withSibling: false);
        $v = $gate->admit($model, $target, 'refactor_reduce_complexity', $untested);
        $this->assertFalse($v['admitted']);
        $this->assertSame('no_behavior_anchor', $v['reason']);

        // the SAME refactor with a behavior anchor present ⇒ designable.
        $tested = $this->workspace($target, withSibling: true);
        $v2 = $gate->admit($model, $target, 'refactor_reduce_complexity', $tested);
        $this->assertTrue($v2['admitted'], (string) ($v2['reason'] ?? ''));

        foreach ([$untested, $tested] as $ws) {
            (new \Symfony\Component\Process\Process(['rm', '-rf', $ws]))->run();
        }
    }

    private function workspace(string $target, bool $withSibling): string
    {
        $ws = sys_get_temp_dir().'/atlas-anchor-'.bin2hex(random_bytes(5));
        @mkdir($ws.'/app/X', 0o755, true);
        file_put_contents($ws.'/'.$target, "<?php\nnamespace App\\X;\nclass Untested { public function go(): int { return 1; } }\n");
        if ($withSibling) {
            @mkdir($ws.'/tests/Unit', 0o755, true);
            file_put_contents($ws.'/tests/Unit/UntestedTest.php', "<?php\nclass UntestedTest extends \\PHPUnit\\Framework\\TestCase {\n    public function test_go(): void { \$this->assertSame(1, (new \\App\\X\\Untested)->go()); }\n}\n");
        }

        return $ws;
    }

    /**
     * @param  list<string>  $callers
     * @param  list<string>  $forbidden
     */
    private function model(string $target, array $callers, array $forbidden = []): AtlasLoopScopeComprehensionModel
    {
        $paths = array_values(array_unique(array_merge([$target], $callers)));
        $inventory = array_map(static fn (string $p): array => [
            'rel_path' => $p, 'fqcn' => 'App\\'.basename($p, '.php'),
            'public_methods' => ['run'], 'is_orphan' => false, 'is_forbidden' => in_array($p, $forbidden, true), 'clone_cluster_id' => null,
        ], $paths);

        return new AtlasLoopScopeComprehensionModel(
            inventory: $inventory,
            edges: [$target => $callers],
            orphans: [], cloneClusters: [], forbidden: $forbidden, docPurposes: [], docStatedGaps: [],
            snapshotId: hash('sha256', $target),
        );
    }
}
