<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipAutonomyEnvelope;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipAutonomyEnvelopeService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipIntegrationLaneService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeRuntimeResultEventService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class StewardshipAutonomyEnvelopeServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap806_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): StewardshipAutonomyEnvelopeService
    {
        $service = app(StewardshipAutonomyEnvelopeService::class);
        $service->setStorageRootForTesting($this->tmp);

        return $service;
    }

    private function armInput(array $overrides = []): array
    {
        return $overrides + [
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'operator_actor' => 'vitor',
            'admit_cross_system' => true,
            'risk_ceiling' => 'medium',
        ];
    }

    public function test_arm_requires_operator_actor(): void
    {
        $payload = $this->service()->arm($this->armInput(['operator_actor' => '']));

        $this->assertSame(StewardshipAutonomyEnvelopeService::STATUS_BLOCKED, $payload['status']);
        $this->assertContains('operator_actor_required', $payload['blockers']);
    }

    public function test_arm_blocks_cross_system_targeting_main(): void
    {
        $payload = $this->service()->arm($this->armInput(['merge_target' => 'main', 'admit_cross_system' => true]));

        $this->assertSame(StewardshipAutonomyEnvelopeService::STATUS_BLOCKED, $payload['status']);
        $this->assertNotSame([], $payload['blockers']);
    }

    public function test_arm_persists_and_current_loads_it(): void
    {
        $service = $this->service();
        $payload = $service->arm($this->armInput());

        $this->assertSame(StewardshipAutonomyEnvelopeService::STATUS_ARMED, $payload['status']);
        $this->assertStringStartsWith('sha256:', $payload['policy_hash']);
        $this->assertSame('vitor', $payload['operator_actor']);
        // Merge target is the integration lane, never main, in this mode.
        $this->assertSame('integration_lane', $payload['policy']['merge_target']);
        $this->assertFalse($payload['claim_policy']['cross_system_merges_to_main']);

        $current = $service->current('agentic_engineering_os', 'dev_forge');
        $this->assertInstanceOf(StewardshipAutonomyEnvelope::class, $current);
        $this->assertTrue($current->routesToIntegrationLane());
        $this->assertTrue($current->admitsCrossSystem('atlas_dev', 'medium'));

        $show = $service->show('agentic_engineering_os', 'dev_forge');
        $this->assertTrue($show['armed']);
    }

    public function test_disarm_clears_current(): void
    {
        $service = $this->service();
        $service->arm($this->armInput());
        $this->assertNotNull($service->current('agentic_engineering_os', 'dev_forge'));

        $disarm = $service->disarm(['area_id' => 'agentic_engineering_os', 'focus' => 'dev_forge', 'operator_actor' => 'vitor']);
        $this->assertSame(StewardshipAutonomyEnvelopeService::STATUS_DISARMED, $disarm['status']);
        $this->assertNull($service->current('agentic_engineering_os', 'dev_forge'));
        $this->assertFalse($service->show('agentic_engineering_os', 'dev_forge')['armed']);
    }

    public function test_no_armed_envelope_returns_null_current(): void
    {
        $this->assertNull($this->service()->current('agentic_engineering_os', 'dev_forge'));
    }

    public function test_arming_is_visible_in_product_mode(): void
    {
        $service = $this->service();
        $productMode = app(ProductModeRuntimeResultEventService::class);
        $productMode->setStorageRootForTesting($this->tmp.'/pm');
        $service->setProductModeForTesting($productMode);

        $payload = $service->arm($this->armInput());
        $this->assertNotSame('', (string) $payload['product_mode_event_id']);

        $events = $productMode->list('agentic_engineering_os');
        $this->assertNotEmpty($events['events'] ?? $events['recent'] ?? $events);
    }

    public function test_lane_ref_and_detection_for_lane_based_sandboxes(): void
    {
        $lane = app(StewardshipIntegrationLaneService::class);
        $this->assertSame(
            'atlas/integration/agentic_engineering_os/main',
            $lane->laneRefFor('agentic_engineering_os', 'main'),
        );

        // Real temp git repo: the lane is detected only once it exists, so the
        // loop bases sandbox branches on main for cycle #1 and on the lane after.
        $repo = $this->tmp.'/repo';
        File::ensureDirectoryExists($repo);
        $this->git($repo, ['init', '-q', '-b', 'main']);
        $this->git($repo, ['config', 'user.email', 'a@b.c']);
        $this->git($repo, ['config', 'user.name', 't']);
        File::put($repo.'/README.md', "x\n");
        $this->git($repo, ['add', '.']);
        $this->git($repo, ['commit', '-q', '-m', 'init']);

        $this->assertFalse($lane->laneExists($repo, 'agentic_engineering_os', 'main'));

        $this->git($repo, ['branch', 'atlas/integration/agentic_engineering_os/main', 'main']);
        $this->assertTrue($lane->laneExists($repo, 'agentic_engineering_os', 'main'));
    }

    /**
     * @param  list<string>  $args
     */
    private function git(string $cwd, array $args): void
    {
        $p = new Process(array_merge(['git'], $args), $cwd);
        $p->run();
    }
}
