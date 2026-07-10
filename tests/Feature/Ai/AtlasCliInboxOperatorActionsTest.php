<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiInboxItem;
use App\Services\Ai\Mobile\AtlasInboxService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasCliInboxOperatorActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureTables();
    }

    public function test_list_json_includes_action_summary_and_origin_refs(): void
    {
        $item = $this->createInboxItem([
            'type' => 'job_result',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Job finalizado: test_kind',
            'source_type' => 'ai_job',
            'source_id' => 'job-123',
            'available_actions' => [
                ['id' => 'view_trace', 'label' => 'Ver trace', 'style' => 'primary'],
                ['id' => 'approve_job_result', 'label' => 'Aprovar', 'style' => 'success'],
                ['id' => 'reject_job_result', 'label' => 'Rejeitar', 'style' => 'danger'],
            ],
            'payload' => [
                'job_id' => 'job-123',
                'trace_id' => 'trace-456',
                'importance' => 'high',
            ],
        ]);

        $exit = Artisan::call('atlas:cli:inbox', [
            'action' => 'list',
            '--filter' => 'all',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $output = json_decode(Artisan::output(), true);
        $this->assertIsArray($output);
        $this->assertArrayHasKey('items', $output);

        $found = collect($output['items'])->first(fn (array $row): bool => $row['id'] === $item->id);
        $this->assertNotNull($found);
        $this->assertSame('job_result', $found['type']);
        $this->assertSame('warning', $found['severity']);
        $this->assertArrayHasKey('action_summary', $found);
        $this->assertSame(3, $found['action_summary']['count']);
        $this->assertContains('approve_job_result', $found['action_summary']['ids']);
        $this->assertArrayHasKey('origin', $found);
        $this->assertSame('ai_job', $found['origin']['source_type']);
        $this->assertSame('job-123', $found['origin']['source_id']);
        $this->assertArrayHasKey('proof_refs', $found);
        $this->assertContains('trace-456', $found['proof_refs']);
    }

    public function test_show_human_prints_response_commands(): void
    {
        $item = $this->createInboxItem([
            'type' => 'proposal',
            'severity' => 'critical',
            'status' => 'unread',
            'title' => 'Proposta de patch',
            'available_actions' => [
                ['id' => 'review_patch', 'label' => 'Revisar proposta', 'style' => 'primary'],
                ['id' => 'discuss', 'label' => 'Discutir', 'style' => 'default'],
                ['id' => 'discard', 'label' => 'Descartar', 'style' => 'destructive'],
            ],
            'payload' => [
                'proposal_contract' => [
                    'file_refs' => [['path' => 'app/Foo.php']],
                    'diff_refs' => [['id' => 'diff-1']],
                ],
            ],
        ]);

        $exit = Artisan::call('atlas:cli:inbox', [
            'action' => 'show',
            'id' => $item->id,
        ]);

        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('review_patch', $output);
        $this->assertStringContainsString('discuss', $output);
        $this->assertStringContainsString('discard', $output);
    }

    public function test_show_json_exposes_trace_and_job_refs(): void
    {
        $item = $this->createInboxItem([
            'type' => 'job_result',
            'severity' => 'warning',
            'status' => 'read',
            'title' => 'Job result',
            'source_type' => 'ai_job',
            'source_id' => 'job-789',
            'available_actions' => [
                ['id' => 'approve_job_result', 'label' => 'Aprovar', 'style' => 'success'],
            ],
            'payload' => [
                'job_id' => 'job-789',
                'trace_id' => 'trace-abc',
            ],
        ]);

        $exit = Artisan::call('atlas:cli:inbox', [
            'action' => 'show',
            'id' => $item->id,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $output = json_decode(Artisan::output(), true);
        $this->assertIsArray($output);
        $this->assertArrayHasKey('item', $output);
        $this->assertArrayHasKey('response_commands', $output);
        $this->assertNotEmpty($output['response_commands']);
        $this->assertSame('job-789', $output['item']['payload']['job_id'] ?? null);
        $this->assertSame('trace-abc', $output['item']['payload']['trace_id'] ?? null);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createInboxItem(array $overrides): AiInboxItem
    {
        return app(AtlasInboxService::class)->create([
            'user_id' => 'vitor',
            'type' => $overrides['type'] ?? 'alert',
            'severity' => $overrides['severity'] ?? 'info',
            'status' => $overrides['status'] ?? 'unread',
            'title' => $overrides['title'] ?? 'Item',
            'summary' => $overrides['summary'] ?? null,
            'body' => $overrides['body'] ?? null,
            'source_type' => $overrides['source_type'] ?? null,
            'source_id' => $overrides['source_id'] ?? null,
            'initiator' => $overrides['initiator'] ?? 'system',
            'available_actions' => $overrides['available_actions'] ?? [],
            'payload' => $overrides['payload'] ?? [],
            'push_policy' => $overrides['push_policy'] ?? [],
            'priority_score' => $overrides['priority_score'] ?? 50,
            'expires_at' => $overrides['expires_at'] ?? now()->addDays(7),
        ]);
    }

    private function ensureTables(): void
    {
        if (! Schema::hasTable('ai_inbox_items')) {
            Schema::create('ai_inbox_items', function ($table) {
                $table->uuid('id')->primary();
                $table->string('user_id')->nullable();
                $table->string('type')->default('alert');
                $table->string('category')->nullable();
                $table->string('severity')->default('info');
                $table->string('status')->default('unread');
                $table->string('title')->nullable();
                $table->text('summary')->nullable();
                $table->text('body')->nullable();
                $table->string('source_type')->nullable();
                $table->string('source_id')->nullable();
                $table->string('initiator')->default('system');
                $table->string('context_bundle_id')->nullable();
                $table->string('dedupe_key')->nullable();
                $table->json('available_actions')->nullable();
                $table->json('response')->nullable();
                $table->json('payload')->nullable();
                $table->string('deep_link')->nullable();
                $table->json('push_policy')->nullable();
                $table->integer('priority_score')->default(50);
                $table->float('confidence_score')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('snoozed_until')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('dismissed_at')->nullable();
                $table->timestamps();
            });
        }
    }
}
