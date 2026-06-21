<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDedupSupplyLane;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * §5.6 · DEDUP — the SUPPLY lane proof: the brain ORIGINATES a certifiable clone-unification task from the
 * comprehension model's clone clusters — net-new work the proxy scan cannot produce (the clone is
 * low-cyclomatic, so the rédea's cyclomatic ambition floor would drop it). Admissibility: a member without an
 * asserting sibling is NOT minted (can't anchor behavior); a petreo member is excluded.
 */
final class AtlasLoopDedupSupplyLaneTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    /** The duplicated method body (substantial enough for the AST clone detector: >=4 lines, >=120 chars). */
    private function cloneMethod(): string
    {
        return "    public function calc(array \$xs): int\n    {\n        \$sum = 0;\n        foreach (\$xs as \$x) {\n            if (\$x > 0) {\n                \$sum += \$x * 2;\n            }\n        }\n\n        return \$sum;\n    }\n";
    }

    private function siblingTest(string $class): string
    {
        return "<?php\n\nclass {$class}Test extends \\PHPUnit\\Framework\\TestCase\n{\n    public function test_calc(): void\n    {\n        \$this->assertSame(8, (new \\App\\X\\{$class})->calc([1, 2, 1]));\n    }\n}\n";
    }

    /** A temp repo with a low-cyclomatic clone pair + (optionally) their asserting siblings. */
    private function repo(bool $betaSibling = true): string
    {
        $d = sys_get_temp_dir().'/atlas-dedup-supply-'.bin2hex(random_bytes(6));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app/X');
        File::ensureDirectoryExists($d.'/tests/Unit');
        File::put($d.'/app/X/Alpha.php', "<?php\n\nnamespace App\\X;\n\nfinal class Alpha\n{\n{$this->cloneMethod()}}\n");
        File::put($d.'/app/X/Beta.php', "<?php\n\nnamespace App\\X;\n\nfinal class Beta\n{\n{$this->cloneMethod()}}\n");
        File::put($d.'/tests/Unit/AlphaTest.php', $this->siblingTest('Alpha'));
        if ($betaSibling) {
            File::put($d.'/tests/Unit/BetaTest.php', $this->siblingTest('Beta'));
        }

        return $d;
    }

    private function model(string $repo): \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel
    {
        return (new AtlasLoopScopeComprehensionModelBuilder)->build($repo, 'app/X', ['docs_roots' => []]);
    }

    public function test_mints_a_certifiable_dedup_spec_for_an_admissible_clone(): void
    {
        $repo = $this->repo();
        $model = $this->model($repo);
        // sanity: the model found the clone cluster.
        $this->assertNotSame([], $model->cloneClusters, 'the builder must detect the Alpha/Beta clone');

        $specs = (new AtlasLoopDedupSupplyLane)->mint($model, $repo);

        $this->assertCount(1, $specs, 'one admissible clone cluster => one dedup task');
        $spec = $specs[0];
        $this->assertSame(AtlasLoopDedupSupplyLane::OBJECTIVE_KIND, $spec['payload']['objective_kind']);
        $this->assertTrue($spec['payload']['dedup_proof'], 'the material key the queue recognises');
        $this->assertTrue($spec['payload']['acceptance']['dedup_proof']);
        $this->assertSame('minimize', $spec['payload']['acceptance']['metric_kind']);
        $this->assertSame(
            ['app/X/Alpha.php', 'app/X/Beta.php'],
            array_column($spec['payload']['acceptance']['clone_target']['members'], 'path'),
        );
        // The frozen anchors are the per-member siblings; commands re-run them (Guard 3 behavior-preserved).
        $this->assertNotSame([], $spec['payload']['acceptance']['frozen_globs']);
        $this->assertNotSame([], $spec['payload']['acceptance']['commands']);
        $this->assertNotSame('', $spec['acceptance_hash']);
    }

    public function test_does_not_mint_when_a_member_has_no_asserting_sibling(): void
    {
        // Beta has no sibling => the per-member behavior anchor is missing => not admissible => no task.
        $repo = $this->repo(betaSibling: false);
        $specs = (new AtlasLoopDedupSupplyLane)->mint($this->model($repo), $repo);
        $this->assertSame([], $specs, 'a clone member without an asserting sibling is never minted');
    }

    public function test_armed_architect_gate_designs_the_dedup_before_minting(): void
    {
        // §1 UNIFICATION: with the architect gate ON, the dedup directive is DESIGNED before it becomes work —
        // the minted spec carries the architect-phase contract (typed obligations incl. the dedup work-type's
        // mandatory complexity_reduced proof). OFF (default) the spec has no such contract (byte-identical).
        $repo = $this->repo();
        $model = $this->model($repo);

        config()->set('atlas.loop.architect_gate_enabled', false);
        $off = (new AtlasLoopDedupSupplyLane)->mint($model, $repo);
        $this->assertArrayNotHasKey('obligations', $off[0]['payload']['acceptance'], 'OFF ⇒ no design contract (byte-identical)');

        config()->set('atlas.loop.architect_gate_enabled', true);
        $on = (new AtlasLoopDedupSupplyLane)->mint($model, $repo);
        $this->assertCount(1, $on, 'a convergent dedup is still minted, now designed');
        $obligations = $on[0]['payload']['acceptance']['obligations'] ?? [];
        $this->assertContains('complexity_reduced', array_column($obligations, 'kind'), 'ON ⇒ the spec carries the architect contract with the dedup mandatory proof');
    }
}
