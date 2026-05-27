<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipRepoMergeLeaseService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class StewardshipRepoMergeLeaseServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap775_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_acquires_reentrant_same_owner_and_blocks_other_owner(): void
    {
        $service = $this->service();
        $repo = $this->repo();

        $first = $service->acquire(['repo_root' => $repo, 'base_ref' => 'main', 'owner' => 'runner-a']);
        $second = $service->acquire(['repo_root' => $repo, 'base_ref' => 'main', 'owner' => 'runner-a']);
        $blocked = $service->acquire(['repo_root' => $repo, 'base_ref' => 'main', 'owner' => 'runner-b']);

        $this->assertSame(StewardshipRepoMergeLeaseService::STATUS_ACQUIRED, $first['status']);
        $this->assertSame(StewardshipRepoMergeLeaseService::STATUS_ACQUIRED, $second['status']);
        $this->assertTrue($second['lease']['reentrant_for_same_owner']);
        $this->assertSame(StewardshipRepoMergeLeaseService::STATUS_BLOCKED, $blocked['status']);
        $this->assertSame('active_merge_lease_exists', $blocked['reason']);
    }

    public function test_release_requires_active_owner_and_allows_next_owner(): void
    {
        $service = $this->service();
        $repo = $this->repo();

        $service->acquire(['repo_root' => $repo, 'base_ref' => 'main', 'owner' => 'runner-a']);
        $wrongOwner = $service->release(['repo_root' => $repo, 'base_ref' => 'main', 'owner' => 'runner-b']);
        $released = $service->release(['repo_root' => $repo, 'base_ref' => 'main', 'owner' => 'runner-a']);
        $next = $service->acquire(['repo_root' => $repo, 'base_ref' => 'main', 'owner' => 'runner-b']);

        $this->assertSame(StewardshipRepoMergeLeaseService::STATUS_BLOCKED, $wrongOwner['status']);
        $this->assertSame('lease_owner_mismatch', $wrongOwner['reason']);
        $this->assertSame(StewardshipRepoMergeLeaseService::STATUS_RELEASED, $released['status']);
        $this->assertSame(StewardshipRepoMergeLeaseService::STATUS_ACQUIRED, $next['status']);
    }

    public function test_list_records_reports_active_lease_count(): void
    {
        $service = $this->service();

        $service->acquire(['repo_root' => $this->repo(), 'base_ref' => 'main', 'owner' => 'runner-a']);
        $records = $service->listRecords('agentic_engineering_os');

        $this->assertSame('ready', $records['status']);
        $this->assertSame(1, $records['active_lease_count']);
        $this->assertSame(1, $records['record_count']);
        $this->assertFileExists($service->recordPath('agentic_engineering_os'));
    }

    public function test_blocks_missing_owner(): void
    {
        $blocked = $this->service()->acquire(['repo_root' => $this->repo(), 'base_ref' => 'main']);

        $this->assertSame(StewardshipRepoMergeLeaseService::STATUS_BLOCKED, $blocked['status']);
        $this->assertSame('lease_owner_required', $blocked['reason']);
    }

    private function service(): StewardshipRepoMergeLeaseService
    {
        $service = app(StewardshipRepoMergeLeaseService::class);
        $service->setStorageRootForTesting($this->tmp.'/leases');

        return $service;
    }

    private function repo(): string
    {
        $repo = $this->tmp.'/repo';
        File::ensureDirectoryExists($repo);

        return $repo;
    }
}
