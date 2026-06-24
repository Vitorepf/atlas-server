<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAntiFarmFloor;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopOrphanWiringSupplyLane;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredAcceptanceProducer;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopWiredAcceptanceProductionCallerTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            (new Process(['rm', '-rf', $dir]))->run();
        }

        parent::tearDown();
    }

    private function workspace(string $suffix, bool $withProductionCaller): string
    {
        $root = sys_get_temp_dir().'/atlas-production-caller-'.bin2hex(random_bytes(5)).'-'.$suffix;
        $this->dirs[] = $root;

        File::ensureDirectoryExists($root.'/app');
        File::ensureDirectoryExists($root.'/tests/Unit');
        File::put($root.'/app/OrphanTarget.php', "<?php\nnamespace App;\nclass OrphanTarget { public function go(): int { return 1; } }\n");
        File::put($root.'/tests/Unit/OrphanTargetTest.php', "<?php\nnamespace Tests\\Unit;\nuse PHPUnit\\Framework\\TestCase;\nclass OrphanTargetTest extends TestCase { public function test_go(): void { \$this->assertSame(1, (new \\App\\OrphanTarget)->go()); } }\n");

        if ($withProductionCaller) {
            File::put($root.'/app/RealCaller.php', "<?php\nnamespace App;\nfinal class RealCaller { public function call(): OrphanTarget { return new OrphanTarget; } }\n");
        }

        return $root;
    }

    private function model(): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: [[
                'rel_path' => 'app/OrphanTarget.php',
                'fqcn' => 'App\\OrphanTarget',
                'public_methods' => ['go'],
                'is_orphan' => true,
                'is_forbidden' => false,
                'clone_cluster_id' => null,
            ]],
            edges: [],
            orphans: ['App\\OrphanTarget'],
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: [],
            snapshotId: 'test',
        );
    }

    public function test_test_only_callers_stamp_production_caller_false_and_fail_the_anti_farm_floor(): void
    {
        $root = $this->workspace('test-only', false);

        $acceptance = (new AtlasLoopWiredAcceptanceProducer)->productionCaller(
            'app/OrphanTarget.php',
            'tests/Unit/OrphanTargetTest.php',
            $root,
        );
        $this->assertFalse($acceptance);

        $spec = (new AtlasLoopOrphanWiringSupplyLane)->mint($this->model(), $root)[0];
        $this->assertFalse((bool) $spec['payload']['production_caller']);

        $verdict = (new AtlasLoopAntiFarmFloor)->eligibleToMerge([
            'wired_proof' => true,
            'method_kills' => true,
            'production_caller' => $acceptance,
        ]);

        $this->assertFalse($verdict['eligible']);
        $this->assertSame(['wired_into_non_production_path'], $verdict['reasons']);
    }

    public function test_an_app_caller_stamps_production_caller_true_and_clears_the_anti_farm_floor(): void
    {
        $root = $this->workspace('production-caller', true);

        $acceptance = (new AtlasLoopWiredAcceptanceProducer)->productionCaller(
            'app/OrphanTarget.php',
            'tests/Unit/OrphanTargetTest.php',
            $root,
        );
        $this->assertTrue($acceptance);

        $spec = (new AtlasLoopOrphanWiringSupplyLane)->mint($this->model(), $root)[0];
        $this->assertTrue((bool) $spec['payload']['production_caller']);

        $verdict = (new AtlasLoopAntiFarmFloor)->eligibleToMerge([
            'wired_proof' => true,
            'method_kills' => true,
            'production_caller' => $acceptance,
        ]);

        $this->assertTrue($verdict['eligible']);
        $this->assertSame([], $verdict['reasons']);
    }
}
