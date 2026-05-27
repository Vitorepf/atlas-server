<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchLifecycleRegistryService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class StewardshipBranchLifecycleRegistryServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap770_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): StewardshipBranchLifecycleRegistryService
    {
        $service = app(StewardshipBranchLifecycleRegistryService::class);
        $service->setStorageRootForTesting($this->tmp.'/registry');

        return $service;
    }

    public function test_reserves_branch_identity_and_records_idempotently(): void
    {
        $service = $this->service();
        $input = [
            'repo_root' => $this->tmp,
            'branch_name' => 'atlas/area-focus/agentic-engineering-os/docs-safe',
            'handoff_hash' => 'sha256:h1',
            'sandbox_id' => 'afsb_h1',
            'record_branch_registry' => true,
        ];

        $first = $service->reserve($input);
        $second = $service->reserve($input);

        $this->assertSame(StewardshipBranchLifecycleRegistryService::STATUS_RESERVED, $first['status']);
        $this->assertSame('recorded', $first['registry_storage_status']);
        $this->assertSame('existing', $second['registry_storage_status']);
        $this->assertTrue($first['claim_policy']['prevents_parallel_branch_collision']);
        $this->assertFileExists($service->recordPath('agentic_engineering_os'));
        $this->assertSame(1, $service->listRecords('agentic_engineering_os')['active_record_count']);
    }

    public function test_blocks_parallel_collision_on_same_branch_key(): void
    {
        $service = $this->service();
        $branch = 'atlas/area-focus/agentic-engineering-os/collision';

        $service->reserve([
            'repo_root' => $this->tmp,
            'branch_name' => $branch,
            'handoff_hash' => 'sha256:h1',
            'sandbox_id' => 'afsb_h1',
            'record_branch_registry' => true,
        ]);

        $blocked = $service->reserve([
            'repo_root' => $this->tmp,
            'branch_name' => $branch,
            'handoff_hash' => 'sha256:h2',
            'sandbox_id' => 'afsb_h2',
            'record_branch_registry' => true,
        ]);

        $this->assertSame(StewardshipBranchLifecycleRegistryService::STATUS_BLOCKED, $blocked['status']);
        $this->assertSame('branch_lifecycle_collision', $blocked['reason']);
        $this->assertSame(1, $service->listRecords('agentic_engineering_os')['record_count']);
    }

    public function test_allows_released_branch_identity_to_be_reused(): void
    {
        $service = $this->service();
        $branch = 'atlas/area-focus/agentic-engineering-os/reusable';

        $reserved = $service->reserve([
            'repo_root' => $this->tmp,
            'branch_name' => $branch,
            'handoff_hash' => 'sha256:h1',
            'sandbox_id' => 'afsb_h1',
            'record_branch_registry' => true,
        ]);
        $released = $service->transition([
            'repo_root' => $this->tmp,
            'branch_name' => $branch,
            'handoff_hash' => 'sha256:h1',
            'sandbox_id' => 'afsb_h1',
            'lifecycle_status' => 'released',
            'record_branch_registry' => true,
        ]);
        $next = $service->reserve([
            'repo_root' => $this->tmp,
            'branch_name' => $branch,
            'handoff_hash' => 'sha256:h2',
            'sandbox_id' => 'afsb_h2',
            'record_branch_registry' => true,
        ]);

        $this->assertSame(StewardshipBranchLifecycleRegistryService::STATUS_RESERVED, $reserved['status']);
        $this->assertSame(StewardshipBranchLifecycleRegistryService::STATUS_RELEASED, $released['status']);
        $this->assertSame(StewardshipBranchLifecycleRegistryService::STATUS_RESERVED, $next['status']);
        $this->assertSame(3, $service->listRecords('agentic_engineering_os')['record_count']);
        $this->assertSame(1, $service->listRecords('agentic_engineering_os')['active_record_count']);
    }
}
