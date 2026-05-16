<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\RunIndex;

use App\Services\Ai\Programming\AtlasDev\RunIndex\AtlasDevRunIndexRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

final class AtlasDevRunIndexRepositoryTest extends TestCase
{
    private AtlasDevRunIndexRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createRunIndexTable();
        config()->set('atlas_dev.run_index.list_default_limit', 50);
        config()->set('atlas_dev.run_index.list_max_limit', 200);
        $this->repo = new AtlasDevRunIndexRepository;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('atlas_dev_run_index');
        parent::tearDown();
    }

    public function test_upsert_creates_then_updates_row(): void
    {
        $entry = $this->repo->upsertFromPlan(
            runId: 'run-100',
            surfaceId: 'cli',
            workspaceHash: 'ws-hash',
            routingDecision: 'plan_only',
            taskKind: 'repair',
            riskLevel: 'R2',
            threadId: 'thread-a',
        );

        $this->assertSame('run-100', $entry->runId);
        $this->assertSame('plan_only', $entry->routingDecision);
        $this->assertSame('thread-a', $entry->threadId);

        $updated = $this->repo->upsertFromPlan(
            runId: 'run-100',
            surfaceId: 'cli',
            workspaceHash: 'ws-hash',
            routingDecision: 'fast_path',
            taskKind: 'patch',
            riskLevel: 'R3',
            threadId: 'thread-a',
        );

        $this->assertSame('run-100', $updated->runId);
        $this->assertSame('fast_path', $updated->routingDecision);
        $this->assertSame('patch', $updated->taskKind);
        $this->assertSame('R3', $updated->riskLevel);
    }

    public function test_update_completion_returns_null_for_unknown_run(): void
    {
        $this->assertNull($this->repo->updateCompletion('not-there', 'completed'));
    }

    public function test_update_completion_sets_state_and_receipt(): void
    {
        $this->repo->upsertFromPlan(
            runId: 'run-101',
            surfaceId: 'cli',
            workspaceHash: 'ws',
            routingDecision: 'fast_path',
            taskKind: 'repair',
            riskLevel: 'R2',
        );

        $entry = $this->repo->updateCompletion('run-101', 'completed', 'receipt-hash-xyz');

        $this->assertNotNull($entry);
        $this->assertSame('completed', $entry->completionState);
        $this->assertSame('receipt-hash-xyz', $entry->lastReceiptHash);
    }

    public function test_find_returns_entry_or_null(): void
    {
        $this->assertNull($this->repo->find('missing'));

        $this->repo->upsertFromPlan(
            runId: 'run-102',
            surfaceId: 'cli',
            workspaceHash: 'ws',
            routingDecision: 'plan_only',
            taskKind: 'question',
            riskLevel: 'R0',
        );

        $entry = $this->repo->find('run-102');
        $this->assertNotNull($entry);
        $this->assertSame('plan_only', $entry->routingDecision);
    }

    public function test_list_by_thread_orders_newest_first(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-16 10:00:00'));
        $this->repo->upsertFromPlan('run-a', 'cli', 'ws', 'plan_only', 'repair', 'R2', 'thread-x');

        Carbon::setTestNow(Carbon::parse('2026-05-16 10:05:00'));
        $this->repo->upsertFromPlan('run-b', 'cli', 'ws', 'plan_only', 'repair', 'R2', 'thread-x');

        Carbon::setTestNow(Carbon::parse('2026-05-16 10:10:00'));
        $this->repo->upsertFromPlan('run-c', 'cli', 'ws', 'plan_only', 'repair', 'R2', 'thread-y');

        $list = $this->repo->listByThread('thread-x');
        $this->assertCount(2, $list);
        $this->assertSame('run-b', $list[0]->runId);
        $this->assertSame('run-a', $list[1]->runId);

        $this->assertSame([], $this->repo->listByThread(''));
    }

    public function test_list_by_workspace_respects_limit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-16 10:00:00'));
        for ($i = 0; $i < 5; $i++) {
            Carbon::setTestNow(Carbon::parse('2026-05-16 10:0'.$i.':00'));
            $this->repo->upsertFromPlan("run-{$i}", 'cli', 'ws-shared', 'plan_only', 'repair', 'R2');
        }

        $list = $this->repo->listByWorkspace('ws-shared', limit: 2);
        $this->assertCount(2, $list);
        $this->assertSame('run-4', $list[0]->runId);
        $this->assertSame('run-3', $list[1]->runId);

        // Caller-supplied limit is clamped to list_max_limit.
        config()->set('atlas_dev.run_index.list_max_limit', 3);
        $clamped = $this->repo->listByWorkspace('ws-shared', limit: 999);
        $this->assertCount(3, $clamped);
    }

    public function test_upsert_rejects_empty_run_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repo->upsertFromPlan('', 'cli', 'ws', 'plan_only', 'repair', 'R2');
    }

    public function test_entry_to_array_uses_alphabetical_keys(): void
    {
        $entry = $this->repo->upsertFromPlan('run-200', 'cli', 'ws', 'plan_only', 'repair', 'R2');
        $arr = $entry->toArray();
        $keys = array_keys($arr);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys);
    }

    private function createRunIndexTable(): void
    {
        Schema::dropIfExists('atlas_dev_run_index');
        Schema::create('atlas_dev_run_index', function (Blueprint $table): void {
            $table->string('run_id', 128)->primary();
            $table->string('surface_id', 80)->index();
            $table->string('workspace_hash', 128)->index();
            $table->string('thread_id', 128)->nullable()->index();
            $table->string('routing_decision', 32);
            $table->string('task_kind', 40);
            $table->string('risk_level', 8);
            $table->string('completion_state', 32)->nullable();
            $table->string('last_receipt_hash', 128)->nullable();
            $table->timestamps();
        });
    }
}
