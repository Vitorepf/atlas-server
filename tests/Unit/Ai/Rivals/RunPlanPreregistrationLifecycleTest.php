<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Adapters\LocalFakeSuiteAdapter;
use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\Preregistration;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class RunPlanPreregistrationLifecycleTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_preregistration_lifecycle_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_persist_materializes_preregistration_before_exposing_run(): void
    {
        $adapter = new LocalFakeSuiteAdapter;
        $plan = RunPlan::make(
            $adapter->suiteId(),
            ['fake_patch_ok'],
            [(new ArmRegistry)->parse('local_fake_model@bare')],
            1,
            ['max_usd' => 0.0, 'max_minutes' => 1],
            42,
        );

        $runId = $plan->persist();
        $persisted = RunPlan::load($runId);
        $preregistration = Preregistration::load($runId);

        $this->assertNotEmpty($persisted->data['preregistration_hash']);
        $this->assertSame($preregistration->hash(), $persisted->data['preregistration_hash']);
        $this->assertFileExists(RunPaths::preregistrationPath($runId));
    }
}
