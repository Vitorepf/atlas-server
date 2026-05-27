<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\ProductMode;

use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeRuntimeResultEventService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ProductModeRuntimeResultEventServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_pmre_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): ProductModeRuntimeResultEventService
    {
        $service = new ProductModeRuntimeResultEventService();
        $service->setStorageRootForTesting($this->tmp.'/events');

        return $service;
    }

    public function test_record_is_append_only_and_idempotent(): void
    {
        $service = $this->service();
        $event = $this->event();

        $first = $service->record($event);
        $second = $service->record($event);

        $this->assertSame('recorded', $first['event_storage_status']);
        $this->assertSame('existing', $second['event_storage_status']);
        $this->assertSame($first['event_id'], $second['event_id']);
        $this->assertNotSame('', (string) $first['event_hash']);

        $path = $service->eventFilePath('agentic_engineering_os');
        $this->assertFileExists($path);
        $this->assertCount(1, file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function test_list_and_replay(): void
    {
        $service = $this->service();
        $recorded = $service->record($this->event());

        $listed = $service->list('agentic_engineering_os');
        $this->assertSame(ProductModeRuntimeResultEventService::LEDGER_SCHEMA, $listed['schema_version']);
        $this->assertSame(1, $listed['event_count']);
        $this->assertSame($recorded['event_id'], $listed['events'][0]['event_id']);

        $replayed = $service->replay($recorded['event_id']);
        $this->assertNotNull($replayed);
        $this->assertSame('srrb_demo', $replayed['result_bridge_id']);

        $this->assertNull($service->replay('pmre_does_not_exist'));
    }

    public function test_derives_event_id_when_missing(): void
    {
        $service = $this->service();
        $event = $this->event();
        unset($event['event_id']);

        $recorded = $service->record($event);

        $this->assertStringStartsWith('pmre_', (string) $recorded['event_id']);
    }

    /**
     * @return array<string,mixed>
     */
    private function event(): array
    {
        return [
            'event_id' => 'pmre_demo',
            'area_id' => 'agentic_engineering_os',
            'owner' => 'atlas_dev',
            'result_bridge_id' => 'srrb_demo',
            'loop' => ['finding_id' => 'aff_demo', 'spec_id' => 'spec_demo', 'handoff_id' => 'afho_demo'],
            'sandbox' => ['sandbox_id' => 'afsb_demo', 'branch_ref' => 'atlas/demo', 'worktree_ref' => ''],
            'execution' => ['result_status' => 'completed'],
            'result' => ['status' => 'completed', 'summary' => 'done'],
            'evidence' => ['evidence_pack_id' => 'srep_demo', 'evidence_pack_hash' => 'sha256:demo'],
            'inbox' => ['inbox_item_id' => null, 'dedupe_key' => 'stewardship:ap765:srrb_demo'],
        ];
    }
}
