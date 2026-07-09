<?php

namespace Tests\Feature\Ai;

use App\Models\AiInboxItem;
use App\Services\Ai\Mobile\InboxActionRegistry;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use App\Services\Ai\SelfConstruction\AtlasTaskLandingReviewPublisher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GAP-COCKPIT-01/02 · the review-cockpit producer + verdict for LIVE autonomous landings:
 * resolved receipt -> idempotent inbox review item -> operator approve/reject (never git).
 */
class TaskLandingReviewCockpitTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        (require database_path('migrations/2026_04_30_151000_create_ai_context_bundles_table.php'))->up();
        (require database_path('migrations/2026_04_30_152000_create_ai_inbox_items_table.php'))->up();
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        $this->workDir = sys_get_temp_dir().'/atlas-task-landing-review-'.uniqid();
        File::makeDirectory($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workDir);
        $this->dropTables();

        parent::tearDown();
    }

    public function test_publisher_emits_idempotent_review_item_per_landed_commit(): void
    {
        $receipts = $this->receiptsFile([
            [
                'schema_version' => 'atlas.self_construction.resolved_receipt.v1',
                'resolved_at' => '2026-07-09T10:00:00Z',
                'task_packet_id' => 'codex-terminal-24h-example-01',
                'allowed_files' => ['app/Services/Ai/Example.php'],
                'objective_excerpt' => 'Exemplo de landing do autonomo',
                'commit_sha' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
                'agent_id' => 'hermes-worker-1',
            ],
        ]);

        $publisher = $this->publisher($receipts);
        $first = $publisher->publish(10);

        $this->assertSame('ok', $first['status']);
        $this->assertSame(1, $first['published_count']);
        $this->assertSame(1, AiInboxItem::query()->count());

        $item = AiInboxItem::query()->firstOrFail();
        $this->assertSame('task-landing-review:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2', $item->dedupe_key);
        $this->assertSame('atlas_task_landing', $item->source_type);
        // source_id stays null on purpose: it is a uuid column in production pgsql.
        $this->assertNull($item->source_id);
        $this->assertSame('codex-terminal-24h-example-01', data_get($item->payload, 'task_landing_review.task_packet_id'));
        $this->assertSame('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2', data_get($item->payload, 'task_landing_review.sha'));
        $this->assertSame(['app/Services/Ai/Example.php'], data_get($item->payload, 'task_landing_review.allowed_files'));

        $actionIds = array_column($item->available_actions ?? [], 'id');
        $this->assertContains('task_landing_review_approve', $actionIds);
        $this->assertContains('task_landing_review_reject', $actionIds);
        $this->assertContains('review_patch', $actionIds);

        // Republish: same landing must return the existing open item, never a duplicate.
        $second = $publisher->publish(10);
        $this->assertSame(1, $second['published_count']);
        $this->assertSame(1, AiInboxItem::query()->count());
    }

    public function test_operator_approve_verdict_resolves_landing_item(): void
    {
        $item = $this->publishedItem('bbb2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2', 'task-approve-01');

        $result = app(InboxActionRegistry::class)->handle($item, 'task_landing_review_approve', [], 'idem-approve-'.$item->id);

        $this->assertTrue($result['ok']);
        $this->assertSame('approved', data_get($result, 'result.payload.task_landing_review.verdict'));
        $this->assertNull(data_get($result, 'result.payload.task_landing_review.revert_command'));

        $item->refresh();
        $this->assertSame('resolved', $item->status);
        $this->assertNotNull($item->resolved_at);
        $this->assertSame('task_landing_review_approve', data_get($item->response, 'action'));
    }

    public function test_operator_reject_verdict_records_revert_command_without_touching_git(): void
    {
        $item = $this->publishedItem('ccc2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2', 'task-reject-01');

        $result = app(InboxActionRegistry::class)->handle(
            $item,
            'task_landing_review_reject',
            ['reason' => 'diff toca área errada'],
            'idem-reject-'.$item->id,
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('rejected', data_get($result, 'result.payload.task_landing_review.verdict'));
        $this->assertSame('diff toca área errada', data_get($result, 'result.payload.task_landing_review.reason'));
        // Post-commit contract: the verdict only RECORDS; the revert stays an explicit governed command.
        $this->assertSame(
            'php artisan atlas:task:revert --task=task-reject-01',
            data_get($result, 'result.payload.task_landing_review.revert_command'),
        );

        $item->refresh();
        $this->assertSame('resolved', $item->status);
    }

    public function test_batch_decide_command_approves_and_rejects_landings_in_one_call(): void
    {
        $approve = $this->publishedItem('dddd11d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2', 'task-batch-approve');
        $reject = $this->publishedItem('eeee22d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2', 'task-batch-reject');

        // O5: veredito em lote — 1 comando decide N landings (por sha e por task id).
        $this->artisan('atlas:task:review:decide', [
            'targets' => ['dddd11d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2'],
            '--reason' => 'lote aprovado',
        ])->assertExitCode(0);
        $this->artisan('atlas:task:review:decide', [
            'targets' => ['task-batch-reject'],
            '--reject' => true,
        ])->assertExitCode(0);

        $approve->refresh();
        $reject->refresh();
        $this->assertSame('resolved', $approve->status);
        $this->assertSame('approved', data_get($approve->response, 'result.payload.task_landing_review.verdict'));
        $this->assertSame('resolved', $reject->status);
        $this->assertSame('rejected', data_get($reject->response, 'result.payload.task_landing_review.verdict'));
        $this->assertStringContainsString('atlas:task:revert', (string) data_get($reject->response, 'result.payload.task_landing_review.revert_command'));

        // Alvo já decidido não re-resolve (sem item aberto) → exit 1, sem exceção.
        $this->artisan('atlas:task:review:decide', ['targets' => ['task-batch-reject']])->assertExitCode(1);
    }

    private function publishedItem(string $sha, string $taskPacketId): AiInboxItem
    {
        $receipts = $this->receiptsFile([
            [
                'schema_version' => 'atlas.self_construction.resolved_receipt.v1',
                'resolved_at' => '2026-07-09T11:00:00Z',
                'task_packet_id' => $taskPacketId,
                'allowed_files' => ['app/Example.php'],
                'objective_excerpt' => 'Landing para veredito',
                'commit_sha' => $sha,
                'agent_id' => 'hermes-worker-2',
            ],
        ]);

        $this->publisher($receipts)->publish(10);

        return AiInboxItem::query()->where('dedupe_key', 'task-landing-review:'.$sha)->firstOrFail();
    }

    /**
     * @param  list<array<string,mixed>>  $receipts
     */
    private function receiptsFile(array $receipts): string
    {
        $path = $this->workDir.'/resolved.jsonl';
        File::put($path, implode("\n", array_map(
            static fn (array $r): string => (string) json_encode($r),
            $receipts,
        ))."\n");

        return $path;
    }

    private function publisher(string $receiptsPath): AtlasTaskLandingReviewPublisher
    {
        // repo-root override points at a plain temp dir: `git show` fails there, proving the
        // diff-excerpt enrichment is fail-open and the publisher never needs the live repo.
        return new AtlasTaskLandingReviewPublisher(app(ProposalInboxEmitter::class), $receiptsPath, $this->workDir);
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('ai_inbox_items');
        Schema::dropIfExists('ai_context_bundles');
    }
}
