<?php

namespace Tests\Feature\Ai\Rivals2;

use App\Services\Ai\Rivals2\Adapters\LocalFakeSuiteAdapter;
use App\Services\Ai\Rivals2\Core\Adjudicator;
use App\Services\Ai\Rivals2\Core\ArmRegistry;
use App\Services\Ai\Rivals2\Core\AtlasUpliftRunner;
use App\Services\Ai\Rivals2\Core\EvidencePackBuilder;
use App\Services\Ai\Rivals2\Core\RunPlan;
use Tests\TestCase;

class AtlasUpliftRunnerTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals2_uplift_test_'.uniqid();
        config()->set('atlas_rivals2.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            exec('rm -rf '.escapeshellarg($this->storage));
        }
        parent::tearDown();
    }

    private function runWithArms(array $armSpecs): string
    {
        $adapter = new LocalFakeSuiteAdapter;
        $registry = new ArmRegistry;
        $plan = RunPlan::make(
            $adapter->suiteId(),
            array_column($adapter->listCases(), 'case_id'),
            array_map(fn ($s) => $registry->parse($s), $armSpecs),
            3,
            ['max_usd' => 0.0, 'max_minutes' => 5],
            42,
        );
        $runId = $plan->persist();
        $adapter->execute($plan);
        (new EvidencePackBuilder)->build($runId);
        (new Adjudicator)->adjudicate($runId);

        return $runId;
    }

    public function test_same_model_two_runtimes_yields_scoped_delta(): void
    {
        $runId = $this->runWithArms(['local_fake_model@bare', 'local_fake_model@atlas_dev']);

        $uplift = (new AtlasUpliftRunner)->compare($runId, 'local_fake_model');

        $this->assertTrue($uplift['uplift_supported']);
        $this->assertTrue($uplift['claim_allowed']);
        $patch = collect($uplift['deltas'])->firstWhere('task_type', 'coding_patch');
        // fake: flaky estabiliza no runtime atlas → bare 5/9, atlas 6/9
        $this->assertEqualsWithDelta(5 / 9, $patch['base']['success_rate'], 0.001);
        $this->assertEqualsWithDelta(6 / 9, $patch['atlas']['success_rate'], 0.001);
        $this->assertEqualsWithDelta(1 / 9, $patch['delta_success_rate'], 0.001);
        // delta é escopado — nunca um veredito global
        $this->assertArrayNotHasKey('winner', $uplift);
        $this->assertNotNull($uplift['claim_scope']);
    }

    public function test_atlas_arm_absent_blocks_honestly_never_simulates(): void
    {
        $runId = $this->runWithArms(['local_fake_model@bare']);

        $uplift = (new AtlasUpliftRunner)->compare($runId, 'local_fake_model');

        $this->assertFalse($uplift['uplift_supported']);
        $this->assertFalse($uplift['claim_allowed']);
        $this->assertStringContainsString('arm_did_not_run:local_fake_model@atlas_dev', $uplift['reason']);
        $this->assertSame([], $uplift['deltas']);
    }

    public function test_same_runtime_comparison_is_rejected(): void
    {
        $runId = $this->runWithArms(['local_fake_model@bare']);

        $this->expectExceptionMessageMatches('/rivals2_uplift_invalid_runtimes/');
        (new AtlasUpliftRunner)->compare($runId, 'local_fake_model', 'bare', 'bare');
    }
}
