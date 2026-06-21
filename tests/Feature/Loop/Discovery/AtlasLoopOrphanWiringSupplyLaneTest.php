<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopOrphanWiringSupplyLane;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * §5.6 · ORPHAN-WIRING — the supply lane mints a directive ONLY for a real, pétreo-free, TESTED orphan that
 * exposes behavior. Untested orphans (dead scaffolding), forbidden organs, and non-orphans are never minted.
 */
final class AtlasLoopOrphanWiringSupplyLaneTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-orphan-supply-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->root.'/app');
        File::ensureDirectoryExists($this->root.'/tests/Unit');

        // A TESTED orphan (worth wiring): a public method + a real sibling assertion.
        File::put($this->root.'/app/WiredMe.php', "<?php\nnamespace App;\nclass WiredMe { public function go(int \$n): int { return \$n + 1; } }\n");
        File::put($this->root.'/tests/Unit/WiredMeTest.php', "<?php\nnamespace Tests\\Unit;\nuse PHPUnit\\Framework\\TestCase;\nclass WiredMeTest extends TestCase { public function test_go(): void { \$this->assertSame(2, (new \\App\\WiredMe)->go(1)); } }\n");

        // A BARE orphan (no sibling test): dead scaffolding — must NOT be minted.
        File::put($this->root.'/app/BareOrphan.php', "<?php\nnamespace App;\nclass BareOrphan { public function noop(): void {} }\n");

        // A FORBIDDEN orphan (tested) — the judge/merge organ: must NEVER be wired.
        File::put($this->root.'/app/Petreo.php', "<?php\nnamespace App;\nclass Petreo { public function seal(): bool { return true; } }\n");
        File::put($this->root.'/tests/Unit/PetreoTest.php', "<?php\nnamespace Tests\\Unit;\nuse PHPUnit\\Framework\\TestCase;\nclass PetreoTest extends TestCase { public function test_seal(): void { \$this->assertTrue((new \\App\\Petreo)->seal()); } }\n");
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->root]))->run();
        parent::tearDown();
    }

    private function model(): AtlasLoopScopeComprehensionModel
    {
        $node = fn (string $rel, string $fqcn, array $methods, bool $orphan, bool $forbidden) => [
            'rel_path' => $rel, 'fqcn' => $fqcn, 'public_methods' => $methods,
            'is_orphan' => $orphan, 'is_forbidden' => $forbidden, 'clone_cluster_id' => null,
        ];

        return new AtlasLoopScopeComprehensionModel(
            inventory: [
                $node('app/WiredMe.php', 'App\\WiredMe', ['go'], true, false),
                $node('app/BareOrphan.php', 'App\\BareOrphan', ['noop'], true, false),
                $node('app/Petreo.php', 'App\\Petreo', ['seal'], true, true),
            ],
            edges: [],
            orphans: ['App\\WiredMe', 'App\\BareOrphan', 'App\\Petreo'],
            cloneClusters: [],
            forbidden: ['app/Petreo.php'],
            docPurposes: [],
            docStatedGaps: [],
            snapshotId: 'test',
        );
    }

    public function test_mints_only_the_tested_pétreo_free_orphan(): void
    {
        $specs = (new AtlasLoopOrphanWiringSupplyLane)->mint($this->model(), $this->root);

        $this->assertCount(1, $specs, 'only the tested, non-forbidden orphan is a wiring directive');
        $spec = $specs[0];
        $this->assertSame('App\\WiredMe', $spec['payload']['orphan_fqcn']);
        $this->assertSame('app/WiredMe.php', $spec['payload']['orphan_path']);
        $this->assertTrue($spec['payload']['wired_proof']);
        $this->assertSame('orphan_wiring', $spec['payload']['objective_kind']);
        $this->assertContains('go', $spec['payload']['public_methods']);
        $this->assertNotNull($spec['payload']['sibling_test'], 'the proven sibling rides along for the executor');
    }

    public function test_a_non_orphan_is_never_minted(): void
    {
        $model = new AtlasLoopScopeComprehensionModel(
            inventory: [[
                'rel_path' => 'app/WiredMe.php', 'fqcn' => 'App\\WiredMe', 'public_methods' => ['go'],
                'is_orphan' => false, 'is_forbidden' => false, 'clone_cluster_id' => null,
            ]],
            edges: [], orphans: [], cloneClusters: [], forbidden: [], docPurposes: [], docStatedGaps: [], snapshotId: 'test',
        );

        $this->assertSame([], (new AtlasLoopOrphanWiringSupplyLane)->mint($model, $this->root));
    }
}
