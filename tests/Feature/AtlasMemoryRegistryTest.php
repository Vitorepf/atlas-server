<?php

namespace Tests\Feature;

use App\Models\AiMemoryDelta;
use App\Models\AiSession;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Models\AtlasMemoryEntryUsage;
use App\Models\AtlasMemoryProviderProjectionAudit;
use App\Models\AtlasOpenBrainAccessLog;
use App\Models\AtlasProject;
use App\Models\AtlasTask;
use App\Models\AtlasVerbatimMemory;
use App\Models\SemanticNote;
use App\Services\Ai\AiContextPackBuilder;
use App\Services\Ai\AiContextSnapshotRecorder;
use App\Services\Ai\AiConversationContextBuilder;
use App\Services\Ai\AiPrompt;
use App\Services\Ai\AtlasMemoryDeltaPromotionService;
use App\Services\Ai\AtlasMemoryGovernanceService;
use App\Services\Ai\AtlasMemoryRegistryService;
use App\Services\Ai\AtlasMemorySourcePrivacyPolicy;
use App\Services\Ai\AtlasMemoryUsageService;
use App\Services\Ai\AtlasOpenBrainContextInjectionService;
use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Ai\AtlasProviderProjectionAuditPurgePolicy;
use App\Services\Ai\AtlasProviderProjectionService;
use App\Services\Ai\AtlasVerbatimMemoryService;
use App\Services\Ai\Runtime\WorkspaceProfile;
use App\Services\Ai\Runtime\WorkspaceProfiler;
use App\Services\Ai\ValueObjects\AiContextPack;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use App\Services\Engineering\EngineeringContextPackService;
use App\Services\Semantic\SemanticSearchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasMemoryRegistryTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->dropTables();
        $this->createRelatedTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_memory_migration_and_model_create_typed_entry(): void
    {
        $this->migrateMemoryTable();

        $entry = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'body' => 'Atlas memory registry is the source of truth for durable AI context.',
            'importance' => 5,
            'priority' => 95,
            'source_type' => 'manual',
            'metadata' => ['phase' => 1],
        ]);

        $this->assertTrue(Schema::hasColumns('atlas_memory_entries', [
            'memory_type',
            'scope_type',
            'scope_id',
            'source_type',
            'source_id',
            'metadata',
            'archived_at',
            'deleted_at',
        ]));
        $this->assertNotEmpty($entry->id);
        $this->assertSame('decision', $entry->memory_type);
        $this->assertSame(['phase' => 1], $entry->metadata);
    }

    public function test_memory_registry_service_records_and_fetches_relevant_task_memory(): void
    {
        $this->migrateMemoryTable();
        [$project, $task, $run] = $this->fixtures();
        $service = app(AtlasMemoryRegistryService::class);

        $service->record([
            'memory_type' => 'preference',
            'scope_type' => 'global',
            'body' => 'Prefer small, reversible implementation phases.',
            'priority' => 60,
        ]);
        $service->record([
            'memory_type' => 'decision',
            'scope_type' => 'project',
            'project_id' => $project->id,
            'body' => 'Atlas AI memory belongs to Atlas, not to a provider.',
            'priority' => 90,
        ]);
        $taskEntry = $service->record([
            'memory_type' => 'technical_context',
            'scope_type' => 'task',
            'task_id' => $task->id,
            'body' => 'Use /opt/homebrew/bin/php for Artisan in this project.',
            'priority' => 80,
        ]);
        $learning = $service->recordHarnessLearning($run, ['test' => 'service']);

        $entries = $service->relevantForTask($task);

        $this->assertNotNull($learning);
        $this->assertSame($task->id, $taskEntry->task->id);
        $this->assertSame('decision', $entries->first()->memory_type);
        $this->assertContains('harness_learning', $entries->pluck('memory_type')->all());
        $this->assertContains('technical_context', $entries->pluck('memory_type')->all());
    }

    public function test_memory_api_creates_lists_scoped_entries_and_archives(): void
    {
        $this->migrateMemoryTable();
        [, $task] = $this->fixtures();

        $response = $this->postJson('/ai/memory', [
            'memory_type' => 'decision',
            'scope_type' => 'task',
            'task_id' => $task->id,
            'title' => 'PHP binary',
            'body' => 'Use /opt/homebrew/bin/php for Artisan commands.',
            'priority' => 88,
            'source_type' => 'manual',
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('memory.memory_type', 'decision')
            ->assertJsonPath('memory.task_id', $task->id);

        $memoryId = (string) data_get($response->json(), 'memory.id');

        $this->getJson("/ai/memory/{$memoryId}", $this->headers)
            ->assertOk()
            ->assertJsonPath('memory.id', $memoryId)
            ->assertJsonPath('memory.body', 'Use /opt/homebrew/bin/php for Artisan commands.');

        $this->getJson("/tasks/{$task->id}/memory", $this->headers)
            ->assertOk()
            ->assertJsonPath('memories.0.id', $memoryId)
            ->assertJsonPath('memories.0.scope_type', 'task');

        $this->patchJson("/ai/memory/{$memoryId}", [
            'status' => 'archived',
            'metadata' => ['archived_reason' => 'covered by test'],
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('memory.status', 'archived')
            ->assertJsonPath('memory.metadata.archived_reason', 'covered by test');

        $this->getJson("/tasks/{$task->id}/memory", $this->headers)
            ->assertOk()
            ->assertJsonCount(0, 'memories');
    }

    public function test_memory_cli_adds_and_lists_entries(): void
    {
        $this->migrateMemoryTable();
        [, $task] = $this->fixtures();

        $addExit = Artisan::call('atlas:memory:add', [
            'body' => ['CLI memory entry for task scoped recall.'],
            '--type' => 'technical_context',
            '--scope-type' => 'task',
            '--task-id' => $task->id,
            '--priority' => '77',
            '--json' => true,
        ]);
        $addPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $addExit);
        $this->assertSame('technical_context', data_get($addPayload, 'memory.memory_type'));

        $listExit = Artisan::call('atlas:memory:list', [
            '--task-id' => $task->id,
            '--json' => true,
        ]);
        $listPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $listExit);
        $this->assertSame('CLI memory entry for task scoped recall.', data_get($listPayload, 'memories.0.body'));
    }

    public function test_ai_context_pack_includes_registry_memory_refs_and_prompt_section(): void
    {
        $this->migrateMemoryTable();
        [$project, $task] = $this->fixtures();
        app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'decision',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Artisan PHP binary',
            'body' => 'Use /opt/homebrew/bin/php para comandos Artisan neste projeto.',
            'priority' => 95,
            'importance' => 5,
            'source_type' => 'manual',
        ]);

        $search = $this->createMock(SemanticSearchService::class);
        $conversation = $this->createMock(AiConversationContextBuilder::class);
        $conversation->method('build')->willReturn([
            'thread_id' => null,
            'thread_title' => null,
            'thread_summary' => null,
            'active_state' => null,
            'latest_compaction' => null,
            'latest_provider_handoff' => null,
            'source' => 'none',
            'instruction' => 'Use contexto Atlas.',
            'recent_turns' => [],
        ]);

        $builder = new AiContextPackBuilder($search, $conversation, app(AtlasMemoryRegistryService::class));
        $taskRequest = AiTaskRequest::fromInput('Continue a implementação profissional.', [
            'source_type' => 'manual',
            'payload' => [
                'project_id' => $project->id,
                'task_id' => $task->id,
                'workspace' => base_path(),
                'task_type' => 'dev',
            ],
        ], [
            'agent' => 'desenvolvedor',
            'intent' => 'test',
        ]);

        $pack = $builder->build('Continue a implementação profissional.', $taskRequest, [
            'payload' => [
                'project_id' => $project->id,
                'task_id' => $task->id,
                'workspace' => base_path(),
            ],
            'include_semantic_context' => false,
        ]);

        $this->assertSame('decision', data_get($pack->toArray(), 'memory.registry.0.type'));
        $this->assertSame('Artisan PHP binary', data_get($pack->toArray(), 'memory.registry.0.title'));
        $this->assertContains('atlas_memory_entry', collect($pack->contextRefs())->pluck('type')->all());
        $this->assertStringContainsString('Memoria Registrada Atlas', $pack->toPromptSection());
        $this->assertStringContainsString('/opt/homebrew/bin/php', $pack->toPromptSection());
        $this->assertStringContainsString('motivo de inclusao', $pack->toPromptSection());
    }

    public function test_context_pack_applies_source_privacy_policy_to_semantic_notes(): void
    {
        $note = new SemanticNote([
            'note_key' => 'vault/privacy-note',
            'path' => 'Areas/Atlas/privacy-note.md',
            'title' => 'Sensitive semantic source',
            'type' => 'decision',
            'status' => 'active',
            'summary' => 'Contains private exact operational context.',
            'body_excerpt' => 'Do not leak Bearer abcdefghijklmno to providers.',
            'frontmatter' => [
                'privacy_class' => 'sensitive',
            ],
            'metadata' => [],
        ]);
        $note->forceFill(['id' => (string) Str::uuid()]);
        $note->score = 0.91;

        $search = $this->createMock(SemanticSearchService::class);
        $search->method('search')->willReturn(collect([$note]));
        $conversation = $this->createMock(AiConversationContextBuilder::class);
        $conversation->method('build')->willReturn([
            'thread_id' => null,
            'thread_title' => null,
            'thread_summary' => null,
            'active_state' => null,
            'latest_compaction' => null,
            'latest_provider_handoff' => null,
            'source' => 'none',
            'instruction' => 'Use contexto Atlas.',
            'recent_turns' => [],
        ]);

        $builder = new AiContextPackBuilder(
            $search,
            $conversation,
            app(AtlasMemoryRegistryService::class),
            null,
            app(AtlasMemorySourcePrivacyPolicy::class),
        );
        $taskRequest = AiTaskRequest::fromInput('Use contexto semântico com segurança.', [
            'source_type' => 'manual',
            'payload' => [
                'workspace' => base_path(),
                'task_type' => 'dev',
            ],
        ], [
            'agent' => 'desenvolvedor',
            'intent' => 'test',
        ]);

        $pack = $builder->build('Use contexto semântico com segurança.', $taskRequest, [
            'payload' => [
                'workspace' => base_path(),
            ],
            'include_memory_registry' => false,
        ]);

        $this->assertTrue((bool) data_get($pack->toArray(), 'memory.semantic.0.blocked'));
        $this->assertSame('sensitive', data_get($pack->toArray(), 'memory.semantic.0.privacy_class'));
        $this->assertFalse((bool) data_get($pack->toArray(), 'memory.semantic.0.external_ai_allowed'));
        $this->assertSame('redacted', data_get($pack->toArray(), 'memory.semantic.0.redaction_status'));
        $this->assertStringContainsString('source privacy policy', data_get($pack->toArray(), 'memory.semantic.0.excerpt'));
        $this->assertSame('sensitive', data_get($pack->contextRefs(), '0.privacy_class'));
        $this->assertStringNotContainsString('abcdefghijklmno', $pack->toPromptSection());
    }

    public function test_engineering_context_pack_includes_registry_memory_refs(): void
    {
        $this->migrateMemoryTable();
        [$project, $task, $run] = $this->fixtures();
        $memory = app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'technical_context',
            'scope_type' => 'project',
            'project_id' => $project->id,
            'title' => 'Runner memory refs',
            'body' => 'Engineering context packs must carry memory_refs.',
            'priority' => 84,
        ]);

        $profiler = $this->createMock(WorkspaceProfiler::class);
        $profiler->method('profile')->willReturn(new WorkspaceProfile(
            workspace: base_path(),
            repoRoot: base_path(),
            branch: 'main',
            head: 'abc123',
            stack: ['laravel', 'php'],
            packageManager: 'composer',
            testCommands: ['php artisan test'],
            importantFiles: ['app/Services/Ai/AiContextPackBuilder.php'],
        ));

        $payload = (new EngineeringContextPackService($profiler, app(AtlasMemoryRegistryService::class)))->build(
            task: $task,
            run: $run,
            workspace: base_path(),
            contract: ['goal' => 'Testar memory refs', 'likely_files' => []],
            blueprint: ['blueprint_id' => 'bp_memory_refs'],
            controls: [],
        );

        $this->assertContains($memory->id, collect($payload['memory_refs'])->pluck('id')->all());
        $this->assertSame('atlas_memory_entry', data_get($payload, 'memory_refs.0.type'));
    }

    public function test_context_snapshot_records_memory_usage_and_api_feedback(): void
    {
        $this->migrateMemoryTable();
        $this->migrateMemoryUsageTable();
        [$project, $task] = $this->fixtures();
        [$thread, $session, $trace] = $this->aiTraceFixture();
        $memory = app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'decision',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Auditavel',
            'body' => 'Esta memoria deve aparecer na auditoria do trace.',
            'source_type' => 'manual',
            'priority' => 91,
        ]);
        $trace->forceFill([
            'thread_id' => $thread->id,
            'session_id' => $session->id,
            'context_refs' => [[
                'type' => 'atlas_memory_entry',
                'id' => $memory->id,
                'memory_type' => 'decision',
                'scope_type' => 'task',
                'scope_id' => $task->id,
                'priority' => 91,
                'source_type' => 'manual',
            ]],
        ])->save();

        app(AiContextSnapshotRecorder::class)->record($trace->refresh(), $session, new AiPrompt(
            prompt: 'prompt',
            agentSlug: 'desenvolvedor',
            intent: 'test',
            skillVersions: [],
            contextRefs: $trace->context_refs,
            contextPack: [
                'memory' => [
                    'registry' => [[
                        'id' => $memory->id,
                        'type' => 'decision',
                        'scope' => 'task:'.$task->id,
                        'scope_type' => 'task',
                        'scope_id' => $task->id,
                        'title' => 'Auditavel',
                        'body' => 'Esta memoria deve aparecer na auditoria do trace.',
                        'reason' => 'memoria ligada a tarefa atual',
                    ]],
                ],
                'conversation' => ['recent_turns' => []],
                'continuity' => [],
            ],
        ));

        $usage = AtlasMemoryEntryUsage::query()->firstOrFail();
        $this->assertSame($memory->id, $usage->memory_entry_id);
        $this->assertSame($trace->id, $usage->trace_id);
        $this->assertSame('memoria ligada a tarefa atual', $usage->included_reason);

        $this->getJson("/ai/memory/audit/traces/{$trace->id}", $this->headers)
            ->assertOk()
            ->assertJsonPath('audit.trace_id', $trace->id)
            ->assertJsonPath('audit.memory_usage_count', 1)
            ->assertJsonPath('audit.memories.0.memory_entry_id', $memory->id)
            ->assertJsonPath('audit.memories.0.included_reason', 'memoria ligada a tarefa atual');

        $this->postJson("/ai/memory/usages/{$usage->id}/feedback", [
            'feedback_action' => 'wrong_context',
            'feedback_score' => 1,
            'feedback_comment' => 'Nao era relevante para esta tarefa.',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('usage.feedback_action', 'wrong_context')
            ->assertJsonPath('usage.feedback_score', 1);
    }

    public function test_memory_audit_cli_outputs_trace_usage(): void
    {
        $this->migrateMemoryTable();
        $this->migrateMemoryUsageTable();
        [$project, $task] = $this->fixtures();
        [, , $trace] = $this->aiTraceFixture();
        $memory = app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'technical_context',
            'scope_type' => 'project',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'CLI audit memory',
            'body' => 'Memory audit command should list this usage.',
        ]);
        AtlasMemoryEntryUsage::query()->create([
            'memory_entry_id' => $memory->id,
            'trace_id' => $trace->id,
            'memory_type' => $memory->memory_type,
            'scope_type' => $memory->scope_type,
            'scope_id' => $memory->scope_id,
            'position' => 1,
            'included_reason' => 'memoria ligada ao projeto atual',
            'source_ref_json' => ['type' => 'atlas_memory_entry', 'id' => $memory->id],
            'context_payload_json' => ['title' => 'CLI audit memory'],
            'metadata' => [],
        ]);

        $exitCode = Artisan::call('atlas:memory:audit', [
            '--trace-id' => $trace->id,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame($trace->id, data_get($payload, 'audit.trace_id'));
        $this->assertSame($memory->id, data_get($payload, 'audit.memories.0.memory_entry_id'));
    }

    public function test_accepted_memory_delta_promotes_to_workspace_registry_memory(): void
    {
        $this->migrateMemoryTable();
        $this->createMemoryDeltaTable();
        $delta = AiMemoryDelta::query()->create([
            'source_workspace' => base_path(),
            'type' => 'preference',
            'claim' => 'Preferir entregas pequenas, revisaveis e com testes focados.',
            'evidence' => [['kind' => 'operator_instruction', 'excerpt' => 'por fases']],
            'scope' => 'workspace:'.base_path(),
            'confidence' => 0.86,
            'valid_from' => now(),
            'valid_until' => now()->addDays(30),
            'use_when' => ['trabalho neste workspace'],
            'do_not_use_when' => ['usuario pedir implementacao monolitica'],
            'requires_confirmation' => true,
            'status' => 'accepted',
        ]);

        $entry = app(AtlasMemoryDeltaPromotionService::class)->promote($delta);
        $again = app(AtlasMemoryDeltaPromotionService::class)->promote($delta->refresh());
        $relevant = app(AtlasMemoryRegistryService::class)->relevantForContext([
            'workspace' => base_path(),
        ]);

        $this->assertSame($entry->id, $again->id);
        $this->assertSame(1, AtlasMemoryEntry::query()->where('source_type', 'ai_memory_delta')->where('source_id', $delta->id)->count());
        $this->assertSame('preference', $entry->memory_type);
        $this->assertSame('workspace', $entry->scope_type);
        $this->assertSame(hash('sha256', realpath(base_path()) ?: base_path()), $entry->scope_id);
        $this->assertSame('promoted', $delta->refresh()->status);
        $this->assertSame($entry->id, $delta->promoted_memory_entry_id);
        $this->assertContains($entry->id, $relevant->pluck('id')->all());
    }

    public function test_memory_delta_promotion_api_and_cli(): void
    {
        $this->migrateMemoryTable();
        $this->createMemoryDeltaTable();
        $apiDelta = $this->memoryDelta('process', 'accepted', 'Promover delta aceito via API.');
        $cliDelta = $this->memoryDelta('error_pattern', 'accepted', 'Promover padrao de erro via CLI.');

        $this->postJson("/ai/memory/deltas/{$apiDelta->id}/promote", [
            'memory_type' => 'technical_context',
            'metadata' => ['reviewed_by' => 'feature-test'],
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('memory.source_type', 'ai_memory_delta')
            ->assertJsonPath('memory.source_id', $apiDelta->id)
            ->assertJsonPath('memory_delta.status', 'promoted');

        $exitCode = Artisan::call('atlas:cli:memory', [
            'action' => 'promote',
            'delta' => $cliDelta->id,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('issue', data_get($payload, 'memory.memory_type'));
        $this->assertSame($cliDelta->id, data_get($payload, 'memory.source_id'));
        $this->assertSame('promoted', $cliDelta->refresh()->status);
    }

    public function test_usage_feedback_governance_degrades_inactivates_and_archives_memory(): void
    {
        $this->migrateMemoryTable();
        $this->migrateMemoryUsageTable();
        $this->migrateGovernanceTable();

        $memory = AtlasMemoryEntry::query()->create([
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'title' => 'Feedback governed memory',
            'body' => 'Memory that should be demoted after repeated wrong-context feedback.',
            'priority' => 80,
            'importance' => 3,
            'source_type' => 'manual',
            'metadata' => [],
        ]);
        $usageA = $this->usage($memory);
        $usageB = $this->usage($memory);

        app(AtlasMemoryUsageService::class)->recordFeedback($usageA, [
            'feedback_action' => 'wrong_context',
            'feedback_score' => 1,
            'feedback_source' => 'test',
        ]);
        app(AtlasMemoryUsageService::class)->recordFeedback($usageB, [
            'feedback_action' => 'wrong_context',
            'feedback_score' => 1,
            'feedback_source' => 'test',
        ]);

        $memory->refresh();
        $this->assertSame('inactive', $memory->status);
        $this->assertLessThan(80, $memory->priority);
        $this->assertSame(2, data_get($memory->metadata, 'governance.feedback.wrong_context_count'));
        $this->assertSame('inactivated_by_negative_feedback', data_get($memory->metadata, 'governance.last_action'));

        $stale = AtlasMemoryEntry::query()->create([
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'title' => 'Stale governed memory',
            'body' => 'Memory that should be archived after repeated stale feedback.',
            'priority' => 70,
            'importance' => 3,
            'source_type' => 'manual',
            'metadata' => [],
        ]);

        app(AtlasMemoryUsageService::class)->recordFeedback($this->usage($stale), [
            'feedback_action' => 'stale',
            'feedback_score' => 1,
            'feedback_source' => 'test',
        ]);
        app(AtlasMemoryUsageService::class)->recordFeedback($this->usage($stale), [
            'feedback_action' => 'stale',
            'feedback_score' => 1,
            'feedback_source' => 'test',
        ]);

        $this->assertSame('archived', $stale->refresh()->status);
        $this->assertNotNull($stale->archived_at);
        $this->assertSame('archived_by_stale_feedback', data_get($stale->metadata, 'governance.last_action'));
    }

    public function test_memory_governance_scan_detects_duplicates_conflicts_api_and_cli(): void
    {
        $this->migrateMemoryTable();
        $this->migrateGovernanceTable();

        $canonical = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Duplicate policy',
            'body' => 'Use small reversible phases for Atlas AI memory work.',
            'priority' => 90,
            'importance' => 4,
            'source_type' => 'manual',
            'metadata' => [],
        ]);
        $duplicate = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Duplicate policy copy',
            'body' => 'Use small reversible phases for Atlas AI memory work.',
            'priority' => 50,
            'importance' => 3,
            'source_type' => 'manual',
            'metadata' => [],
        ]);
        $this->conflictingMemory('PHP binary', 'Use /opt/homebrew/bin/php for Artisan commands.', 82);
        $this->conflictingMemory('PHP binary', 'Use php from PATH for Artisan commands.', 76);

        $this->postJson('/ai/memory/governance/scan', [
            'scope_type' => 'global',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('governance.scanned', 4)
            ->assertJsonPath('governance.duplicates.0.duplicate_memory_entry_id', $duplicate->id)
            ->assertJsonPath('governance.duplicates.0.canonical_memory_entry_id', $canonical->id)
            ->assertJsonPath('governance.conflicts.0.reason', 'Mesmo titulo/tipo/escopo com conteudo diferente; requer revisao humana.');

        $this->assertSame('inactive', $duplicate->refresh()->status);
        $this->assertSame($canonical->id, data_get($duplicate->metadata, 'governance.duplicate_of_memory_entry_id'));
        $this->assertSame(1, AtlasMemoryEntryRelation::query()->where('relation_type', 'duplicate')->count());
        $this->assertSame(1, AtlasMemoryEntryRelation::query()->where('relation_type', 'conflict')->count());

        $cliA = $this->conflictingMemory('Dry run duplicate', 'Dry-run duplicate body.', 62);
        $cliB = $this->conflictingMemory('Dry run duplicate copy', 'Dry-run duplicate body.', 61);
        $exitCode = Artisan::call('atlas:memory:govern', [
            'action' => 'scan',
            '--scope-type' => 'global',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertContains($cliB->id, collect(data_get($payload, 'governance.duplicates', []))->pluck('duplicate_memory_entry_id')->all());
        $this->assertSame('active', $cliB->refresh()->status);
        $this->assertSame('active', $cliA->refresh()->status);
    }

    public function test_memory_relation_review_api_and_cli_resolve_conflicts_and_dismiss_duplicates(): void
    {
        $this->migrateMemoryTable();
        $this->migrateGovernanceTable();

        $canonical = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Relation duplicate canonical',
            'body' => 'Keep small reversible phases for relation review.',
            'priority' => 90,
            'importance' => 4,
            'source_type' => 'manual',
            'metadata' => [],
        ]);
        $duplicate = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Relation duplicate copy',
            'body' => 'Keep small reversible phases for relation review.',
            'priority' => 45,
            'importance' => 3,
            'source_type' => 'manual',
            'metadata' => [],
        ]);
        $source = $this->conflictingMemory('Relation conflict', 'Use /opt/homebrew/bin/php for Artisan commands.', 82);
        $target = $this->conflictingMemory('Relation conflict', 'Use php from PATH for Artisan commands.', 76);

        app(AtlasMemoryGovernanceService::class)->scan(['scope_type' => 'global']);

        $conflict = AtlasMemoryEntryRelation::query()
            ->where('relation_type', 'conflict')
            ->where('source_memory_entry_id', $source->id)
            ->where('target_memory_entry_id', $target->id)
            ->firstOrFail();
        $duplicateRelation = AtlasMemoryEntryRelation::query()
            ->where('relation_type', 'duplicate')
            ->where('source_memory_entry_id', $duplicate->id)
            ->where('target_memory_entry_id', $canonical->id)
            ->firstOrFail();

        $this->getJson('/ai/memory/relations?type=conflict&status=open', $this->headers)
            ->assertOk()
            ->assertJsonPath('relations.0.id', $conflict->id)
            ->assertJsonPath('relations.0.source_memory.title', 'Relation conflict');

        $this->postJson("/ai/memory/relations/{$conflict->id}/review", [
            'status' => 'resolved',
            'target_status' => 'archived',
            'resolution_action' => 'keep_source',
            'reviewed_by' => 'feature-test',
            'review_note' => 'Source memory is canonical for Artisan on Atlas.',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('relation.status', 'resolved')
            ->assertJsonPath('relation.metadata.last_review.resolution_action', 'keep_source');

        $this->assertSame('active', $source->refresh()->status);
        $this->assertSame('archived', $target->refresh()->status);
        $this->assertSame($conflict->id, data_get($target->metadata, 'governance.last_relation_review_id'));

        $exitCode = Artisan::call('atlas:memory:relations', [
            'action' => 'dismiss',
            'relation' => $duplicateRelation->id,
            '--note' => 'Duplicate relation reviewed and intentionally dismissed.',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('dismissed', data_get($payload, 'relation.status'));
        $this->assertSame('dismissed', $duplicateRelation->refresh()->status);

        $listExit = Artisan::call('atlas:memory:relations', [
            'action' => 'list',
            '--status' => 'dismissed',
            '--json' => true,
        ]);
        $listPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $listExit);
        $this->assertContains($duplicateRelation->id, collect(data_get($listPayload, 'relations', []))->pluck('id')->all());
    }

    public function test_memory_registry_privacy_guard_redacts_blocks_and_releases_context_pack(): void
    {
        $this->migrateMemoryTable();
        $this->migrateMemoryPrivacyTable();
        [$project, $task] = $this->fixtures();
        $token = 'Bearer abcdefghijklmno';

        $response = $this->postJson('/ai/memory', [
            'memory_type' => 'decision',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Registry privacy decision',
            'body' => 'Never expose '.$token.' inside provider context.',
            'summary' => 'Decision includes '.$token,
            'privacy_class' => 'sensitive',
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('memory.privacy_class', 'sensitive')
            ->assertJsonPath('memory.external_ai_allowed', false)
            ->assertJsonPath('memory.redaction_status', 'redacted');

        $memoryId = (string) data_get($response->json(), 'memory.id');
        $entry = AtlasMemoryEntry::query()->findOrFail($memoryId);
        $this->assertStringContainsString('Bearer [redacted]', $entry->redacted_body);
        $this->assertSame([], data_get($this->contextPackForTask($project, $task)->toArray(), 'memory.registry'));

        $this->postJson("/ai/memory/{$memoryId}/privacy", [
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redacted_body' => 'Provider-safe decision with token removed.',
            'redacted_summary' => 'Provider-safe summary.',
            'reviewed_by' => 'feature-test',
            'review_note' => 'Reviewed for provider use.',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('memory.privacy_class', 'normal')
            ->assertJsonPath('memory.external_ai_allowed', true)
            ->assertJsonPath('memory.redacted_body', 'Provider-safe decision with token removed.');

        $pack = $this->contextPackForTask($project, $task);
        $this->assertSame($memoryId, data_get($pack->toArray(), 'memory.registry.0.id'));
        $this->assertStringContainsString('Provider-safe decision with token removed.', $pack->toPromptSection());
        $this->assertStringNotContainsString('abcdefghijklmno', $pack->toPromptSection());

        $exitCode = Artisan::call('atlas:memory:privacy', [
            'action' => 'review',
            'memory' => $memoryId,
            '--block-external-ai' => true,
            '--note' => 'Block again from CLI.',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertFalse(data_get($payload, 'memory.external_ai_allowed'));
        $this->assertSame([], data_get($this->contextPackForTask($project, $task)->toArray(), 'memory.registry'));

        $scanExit = Artisan::call('atlas:memory:privacy', [
            'action' => 'scan',
            '--task-id' => $task->id,
            '--json' => true,
        ]);
        $scanPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $scanExit);
        $this->assertSame(1, data_get($scanPayload, 'privacy.scanned'));
    }

    public function test_memory_review_queue_aggregates_privacy_verbatim_and_relations_api_and_cli(): void
    {
        $this->migrateMemoryTable();
        $this->migrateMemoryPrivacyTable();
        $this->migrateGovernanceTable();
        $this->migrateVerbatimMemoryTable();
        [$project, $task] = $this->fixtures();
        $registry = app(AtlasMemoryRegistryService::class);

        $sensitiveEntry = $registry->record([
            'memory_type' => 'decision',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Sensitive queue memory',
            'body' => 'Review this before provider use because it contains Bearer abcdefghijklmno.',
            'privacy_class' => 'sensitive',
        ]);
        $registry->record([
            'memory_type' => 'technical_context',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Normal queue memory',
            'body' => 'This clean memory should not enter the default review queue.',
            'privacy_class' => 'normal',
        ]);
        $verbatim = app(AtlasVerbatimMemoryService::class)->record([
            'verbatim_type' => 'decision',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Sensitive queue verbatim',
            'verbatim_text' => 'Exact sensitive quote with Bearer abcdefghijklmno.',
            'privacy_class' => 'sensitive',
        ]);
        $source = $registry->record([
            'memory_type' => 'decision',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Queue relation source',
            'body' => 'Use one decision for review queue conflict.',
            'privacy_class' => 'normal',
        ]);
        $target = $registry->record([
            'memory_type' => 'decision',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Queue relation target',
            'body' => 'Use another decision for review queue conflict.',
            'privacy_class' => 'normal',
        ]);
        $relation = AtlasMemoryEntryRelation::query()->create([
            'source_memory_entry_id' => $source->id,
            'target_memory_entry_id' => $target->id,
            'relation_type' => 'conflict',
            'status' => 'open',
            'confidence' => 0.72,
            'reason' => 'Manual queue test conflict.',
            'metadata' => [],
        ]);

        $this->getJson("/ai/memory/review-queue?task_id={$task->id}", $this->headers)
            ->assertOk()
            ->assertJsonPath('review_queue.total', 3)
            ->assertJsonPath('review_queue.counts.memory_privacy', 1)
            ->assertJsonPath('review_queue.counts.verbatim_privacy', 1)
            ->assertJsonPath('review_queue.counts.relation', 1);

        $payload = $this->getJson("/ai/memory/review-queue?task_id={$task->id}", $this->headers)->json();
        $items = collect(data_get($payload, 'review_queue.items', []));
        $this->assertContains('memory_privacy', $items->pluck('kind')->all());
        $this->assertContains('verbatim_privacy', $items->pluck('kind')->all());
        $this->assertContains('relation', $items->pluck('kind')->all());
        $this->assertContains('memory_privacy:'.$sensitiveEntry->id, $items->pluck('id')->all());
        $this->assertContains('verbatim_privacy:'.$verbatim->id, $items->pluck('id')->all());
        $this->assertContains('relation:'.$relation->id, $items->pluck('id')->all());

        $exitCode = Artisan::call('atlas:memory:review-queue', [
            '--task-id' => $task->id,
            '--json' => true,
        ]);
        $cliPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(3, data_get($cliPayload, 'review_queue.total'));
        $this->assertSame(1, data_get($cliPayload, 'review_queue.counts.memory_privacy'));
        $this->assertSame(1, data_get($cliPayload, 'review_queue.counts.verbatim_privacy'));
        $this->assertSame(1, data_get($cliPayload, 'review_queue.counts.relation'));
    }

    public function test_verbatim_memory_service_redacts_links_registry_and_blocks_provider_context(): void
    {
        $this->migrateMemoryTable();
        $this->migrateVerbatimMemoryTable();
        [$project, $task] = $this->fixtures();
        $secret = 'Bearer abcdefghijklmno';

        $memory = app(AtlasVerbatimMemoryService::class)->record([
            'verbatim_type' => 'decision',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Sensitive exact decision',
            'verbatim_text' => 'Exact decision: never expose '.$secret.' to an external provider.',
            'summary' => 'Sensitive decision with token.',
            'privacy_class' => 'sensitive',
            'source_type' => 'trace',
            'source_id' => 'trace-123',
            'tags' => ['security'],
        ]);

        $this->assertTrue(Schema::hasColumns('atlas_verbatim_memories', [
            'verbatim_text',
            'redacted_text',
            'privacy_class',
            'external_ai_allowed',
            'redaction_status',
            'memory_entry_id',
        ]));
        $this->assertSame('sensitive', $memory->privacy_class);
        $this->assertFalse($memory->external_ai_allowed);
        $this->assertSame('redacted', $memory->redaction_status);
        $this->assertStringContainsString($secret, $memory->verbatim_text);
        $this->assertStringContainsString('Bearer [redacted]', $memory->redacted_text);
        $this->assertNotNull($memory->memory_entry_id);

        $entry = AtlasMemoryEntry::query()->findOrFail($memory->memory_entry_id);
        $this->assertSame('atlas_verbatim_memory', $entry->source_type);
        $this->assertSame($memory->id, $entry->source_id);
        $this->assertFalse(data_get($entry->metadata, 'privacy.external_ai_allowed'));
        $this->assertStringNotContainsString('abcdefghijklmno', $entry->body);

        $search = $this->createMock(SemanticSearchService::class);
        $conversation = $this->createMock(AiConversationContextBuilder::class);
        $conversation->method('build')->willReturn([
            'thread_id' => null,
            'thread_title' => null,
            'thread_summary' => null,
            'active_state' => null,
            'latest_compaction' => null,
            'latest_provider_handoff' => null,
            'source' => 'none',
            'instruction' => 'Use contexto Atlas.',
            'recent_turns' => [],
        ]);

        $builder = new AiContextPackBuilder($search, $conversation, app(AtlasMemoryRegistryService::class));
        $taskRequest = AiTaskRequest::fromInput('Use memory without leaking secrets.', [
            'source_type' => 'manual',
            'payload' => [
                'project_id' => $project->id,
                'task_id' => $task->id,
                'workspace' => base_path(),
                'task_type' => 'dev',
            ],
        ], [
            'agent' => 'desenvolvedor',
            'intent' => 'test',
        ]);

        $pack = $builder->build('Use memory without leaking secrets.', $taskRequest, [
            'payload' => [
                'project_id' => $project->id,
                'task_id' => $task->id,
                'workspace' => base_path(),
            ],
            'include_semantic_context' => false,
        ]);

        $this->assertSame([], data_get($pack->toArray(), 'memory.registry'));
        $this->assertStringNotContainsString('abcdefghijklmno', $pack->toPromptSection());
    }

    public function test_verbatim_memory_api_and_cli_create_show_and_archive(): void
    {
        $this->migrateMemoryTable();
        $this->migrateVerbatimMemoryTable();
        [, $task] = $this->fixtures();
        $exact = 'Exact command output: php artisan test --filter=AtlasMemoryRegistryTest passed.';

        $response = $this->postJson('/ai/memory/verbatim', [
            'verbatim_type' => 'command',
            'scope_type' => 'task',
            'task_id' => $task->id,
            'title' => 'Focused test command',
            'verbatim_text' => $exact,
            'privacy_class' => 'normal',
            'tags' => ['tests'],
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('verbatim_memory.verbatim_type', 'command')
            ->assertJsonPath('verbatim_memory.verbatim_text', null)
            ->assertJsonPath('verbatim_memory.redaction_status', 'clean')
            ->assertJsonPath('verbatim_memory.task_id', $task->id);

        $id = (string) data_get($response->json(), 'verbatim_memory.id');
        $memoryEntryId = (string) data_get($response->json(), 'verbatim_memory.memory_entry_id');

        $this->getJson("/ai/memory/verbatim/{$id}?include_verbatim=1", $this->headers)
            ->assertOk()
            ->assertJsonPath('verbatim_memory.verbatim_text', $exact)
            ->assertJsonPath('verbatim_memory.redacted_text', $exact);

        $this->getJson('/ai/memory/verbatim?type=command&tag=tests', $this->headers)
            ->assertOk()
            ->assertJsonPath('verbatim_memories.0.id', $id)
            ->assertJsonPath('verbatim_memories.0.verbatim_text', null);

        $exitCode = Artisan::call('atlas:memory:verbatim', [
            'action' => 'add',
            'value' => ['CLI exact requirement text.'],
            '--type' => 'requirement',
            '--scope-type' => 'global',
            '--privacy' => 'normal',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('requirement', data_get($payload, 'verbatim_memory.verbatim_type'));
        $this->assertNull(data_get($payload, 'verbatim_memory.verbatim_text'));

        $this->patchJson("/ai/memory/verbatim/{$id}", [
            'status' => 'archived',
            'metadata' => ['archived_reason' => 'covered by api test'],
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('verbatim_memory.status', 'archived')
            ->assertJsonPath('verbatim_memory.metadata.archived_reason', 'covered by api test');

        $this->assertSame('archived', AtlasMemoryEntry::query()->findOrFail($memoryEntryId)->status);
        $this->assertNotNull(AtlasVerbatimMemory::query()->findOrFail($id)->archived_at);
    }

    public function test_verbatim_review_controls_sync_registry_and_context_pack_privacy(): void
    {
        $this->migrateMemoryTable();
        $this->migrateVerbatimMemoryTable();
        [$project, $task] = $this->fixtures();
        $token = 'Bearer abcdefghijklmno';

        $memory = app(AtlasVerbatimMemoryService::class)->record([
            'verbatim_type' => 'decision',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Provider review decision',
            'verbatim_text' => 'Exact operator decision with '.$token.' inside.',
            'privacy_class' => 'sensitive',
            'tags' => ['provider-review'],
        ]);

        $this->assertFalse($memory->external_ai_allowed);
        $this->assertSame([], data_get($this->contextPackForTask($project, $task)->toArray(), 'memory.registry'));

        $this->postJson("/ai/memory/verbatim/{$memory->id}/review", [
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redacted_text' => 'Exact operator decision with provider-safe token redaction.',
            'summary' => 'Provider-safe reviewed decision.',
            'reviewed_by' => 'feature-test',
            'review_note' => 'Manual review approved redacted provider use.',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('verbatim_memory.privacy_class', 'normal')
            ->assertJsonPath('verbatim_memory.external_ai_allowed', true)
            ->assertJsonPath('verbatim_memory.redaction_status', 'redacted')
            ->assertJsonPath('verbatim_memory.metadata.privacy.external_ai_allowed', true);

        $memory->refresh();
        $entry = AtlasMemoryEntry::query()->findOrFail($memory->memory_entry_id);
        $this->assertTrue(data_get($entry->metadata, 'privacy.external_ai_allowed'));
        $this->assertStringContainsString('provider-safe token redaction', $entry->body);
        $this->assertStringNotContainsString('abcdefghijklmno', $entry->body);
        $this->assertSame($entry->id, data_get($this->contextPackForTask($project, $task)->toArray(), 'memory.registry.0.id'));

        $exitCode = Artisan::call('atlas:memory:verbatim', [
            'action' => 'block',
            'value' => [$memory->id],
            '--review-note' => 'Do not use this in providers anymore.',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertFalse(data_get($payload, 'verbatim_memory.external_ai_allowed'));
        $this->assertFalse(data_get(AtlasMemoryEntry::query()->findOrFail($entry->id)->metadata, 'privacy.external_ai_allowed'));
        $this->assertSame([], data_get($this->contextPackForTask($project, $task)->toArray(), 'memory.registry'));
    }

    public function test_context_pack_includes_budgeted_provider_safe_verbatim_recall(): void
    {
        $this->migrateMemoryTable();
        $this->migrateVerbatimMemoryTable();
        [$project, $task, $run] = $this->fixtures();
        $service = app(AtlasVerbatimMemoryService::class);

        $safe = $service->record([
            'verbatim_type' => 'command',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Focused test output',
            'verbatim_text' => 'Exact command output: /opt/homebrew/bin/php artisan test --filter=AtlasMemoryRegistryTest passed. This tail should be trimmed by the context budget.',
            'summary' => 'Focused test output.',
            'privacy_class' => 'normal',
            'source_type' => 'engineering_run',
            'source_id' => $run->id,
            'link_registry' => false,
        ]);

        $blocked = $service->record([
            'verbatim_type' => 'decision',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Blocked sensitive verbatim',
            'verbatim_text' => 'Exact sensitive quote with Bearer abcdefghijklmno.',
            'privacy_class' => 'sensitive',
            'link_registry' => false,
        ]);

        $this->assertContains($safe->id, $service->relevantForContext(['task_id' => $task->id])->pluck('id')->all());
        $this->assertContains($blocked->id, $service->relevantForContext(['task_id' => $task->id])->pluck('id')->all());

        $pack = $this->contextPackForTask($project, $task, [
            'verbatim_recall_budget_chars' => 96,
            'verbatim_recall_item_chars' => 96,
        ]);
        $items = data_get($pack->toArray(), 'memory.verbatim', []);
        $prompt = $pack->toPromptSection();

        $this->assertCount(1, $items);
        $this->assertSame($safe->id, data_get($items, '0.id'));
        $this->assertLessThanOrEqual(96, Str::length((string) data_get($items, '0.snippet')));
        $this->assertStringContainsString('/opt/homebrew/bin/php artisan test', (string) data_get($items, '0.snippet'));
        $this->assertStringNotContainsString('tail should be trimmed', (string) data_get($items, '0.snippet'));
        $this->assertStringContainsString('Recall Verbatim Atlas', $prompt);
        $this->assertStringContainsString('/opt/homebrew/bin/php artisan test', $prompt);
        $this->assertStringNotContainsString('abcdefghijklmno', $prompt);

        $verbatimRefs = collect($pack->contextRefs())->where('type', 'atlas_verbatim_memory')->pluck('id')->values()->all();
        $this->assertSame([$safe->id], $verbatimRefs);
    }

    public function test_context_pack_composes_ranked_recall_across_memory_sources(): void
    {
        $this->migrateMemoryTable();
        $this->migrateVerbatimMemoryTable();
        [$project, $task] = $this->fixtures();

        $canonical = app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'decision',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Canonical implementation rule',
            'body' => 'Use deterministic Atlas memory recall before considering any vector backend.',
            'summary' => 'Deterministic recall wins before vector backend.',
            'priority' => 98,
            'importance' => 5,
            'privacy_class' => 'normal',
            'source_type' => 'manual',
        ]);
        $lowPriority = app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'title' => 'Low priority context',
            'body' => 'This should lose ranking pressure when the recall limit is tight.',
            'priority' => 5,
            'importance' => 1,
            'privacy_class' => 'normal',
            'source_type' => 'manual',
        ]);
        $verbatim = app(AtlasVerbatimMemoryService::class)->record([
            'verbatim_type' => 'decision',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Exact reviewed decision',
            'verbatim_text' => 'Exact reviewed decision: keep recall provider-safe and budgeted.',
            'summary' => 'Reviewed verbatim decision.',
            'privacy_class' => 'normal',
            'link_registry' => false,
        ]);

        $semantic = new SemanticNote([
            'note_key' => 'vault/provider-safe-note',
            'path' => 'Areas/Atlas/provider-safe-note.md',
            'title' => 'Provider-safe semantic note',
            'type' => 'technical_context',
            'status' => 'active',
            'summary' => 'Semantic fallback can support the ranked recall plan.',
            'body_excerpt' => 'Semantic fallback should appear after canonical and verbatim memories.',
            'frontmatter' => ['privacy_class' => 'normal'],
            'metadata' => [],
        ]);
        $semantic->forceFill(['id' => (string) Str::uuid()]);
        $semantic->score = 0.76;

        $blockedSemantic = new SemanticNote([
            'note_key' => 'vault/blocked-note',
            'path' => 'Areas/Atlas/blocked-note.md',
            'title' => 'Blocked semantic note',
            'type' => 'decision',
            'status' => 'active',
            'summary' => 'Should not enter ranked recall.',
            'body_excerpt' => 'Bearer abcdefghijklmno must not appear.',
            'frontmatter' => ['privacy_class' => 'sensitive'],
            'metadata' => [],
        ]);
        $blockedSemantic->forceFill(['id' => (string) Str::uuid()]);
        $blockedSemantic->score = 0.99;

        $search = $this->createMock(SemanticSearchService::class);
        $search->method('search')->willReturn(collect([$semantic, $blockedSemantic]));
        $conversation = $this->createMock(AiConversationContextBuilder::class);
        $conversation->method('build')->willReturn([
            'thread_id' => null,
            'thread_title' => null,
            'thread_summary' => null,
            'active_state' => null,
            'latest_compaction' => null,
            'latest_provider_handoff' => null,
            'source' => 'none',
            'instruction' => 'Use contexto Atlas.',
            'recent_turns' => [],
        ]);

        $taskRequest = AiTaskRequest::fromInput('Rank memory recall deterministically.', [
            'source_type' => 'manual',
            'payload' => [
                'project_id' => $project->id,
                'task_id' => $task->id,
                'workspace' => base_path(),
                'task_type' => 'dev',
            ],
        ], [
            'agent' => 'desenvolvedor',
            'intent' => 'test',
        ]);

        $pack = (new AiContextPackBuilder($search, $conversation, app(AtlasMemoryRegistryService::class)))->build(
            'Rank memory recall deterministically.',
            $taskRequest,
            [
                'payload' => [
                    'project_id' => $project->id,
                    'task_id' => $task->id,
                    'workspace' => base_path(),
                ],
                'memory_recall_limit' => 3,
                'memory_recall_budget_chars' => 320,
                'memory_recall_item_chars' => 120,
            ],
        );

        $recall = data_get($pack->toArray(), 'memory.recall', []);
        $prompt = $pack->toPromptSection();

        $this->assertSame(['registry', 'verbatim', 'semantic'], collect($recall)->pluck('source')->all());
        $this->assertSame($canonical->id, data_get($recall, '0.source_ref_id'));
        $this->assertSame($verbatim->id, data_get($recall, '1.source_ref_id'));
        $this->assertSame($semantic->id, data_get($recall, '2.source_ref_id'));
        $this->assertNotContains($lowPriority->id, collect($recall)->pluck('source_ref_id')->all());
        $this->assertNotContains($blockedSemantic->id, collect($recall)->pluck('source_ref_id')->all());
        $this->assertLessThanOrEqual(320, collect($recall)->sum('estimated_chars'));
        $this->assertStringContainsString('Recall Atlas Priorizado', $prompt);
        $this->assertStringContainsString('Canonical implementation rule', $prompt);
        $this->assertStringNotContainsString('abcdefghijklmno', $prompt);
        $this->assertStringNotContainsString($canonical->id, $prompt);
        $this->assertStringNotContainsString($verbatim->id, $prompt);
    }

    public function test_hybrid_memory_recall_api_and_cli_respect_provider_safety(): void
    {
        $this->migrateMemoryTable();
        $this->migrateVerbatimMemoryTable();
        [$project, $task] = $this->fixtures();

        app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'decision',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Recall safe architecture',
            'body' => 'Hybrid recall must prefer provider-safe registry memory before exact evidence.',
            'summary' => 'Hybrid recall uses provider-safe registry first.',
            'priority' => 96,
            'importance' => 5,
            'privacy_class' => 'normal',
            'source_type' => 'manual',
        ]);
        app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'technical_context',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Blocked secret memory',
            'body' => 'Bearer abcdefghijklmno must never enter provider recall.',
            'privacy_class' => 'secret',
            'source_type' => 'manual',
        ]);
        app(AtlasVerbatimMemoryService::class)->record([
            'verbatim_type' => 'evidence',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Exact recall evidence',
            'verbatim_text' => 'Exact evidence: provider-safe recall keeps exact text redacted and budgeted.',
            'summary' => 'Exact provider-safe recall evidence.',
            'privacy_class' => 'normal',
            'link_registry' => false,
        ]);

        $response = $this->postJson('/ai/memory/recall', [
            'query' => 'hybrid provider-safe recall architecture evidence',
            'context' => [
                'project_id' => $project->id,
                'task_id' => $task->id,
                'workspace' => base_path(),
            ],
            'options' => [
                'limit' => 4,
                'include_semantic' => false,
            ],
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('memory_recall.summary.policy', 'provider_safe_only');

        $recallText = json_encode(data_get($response->json(), 'memory_recall.recall'), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($recallText);
        $this->assertStringContainsString('Recall safe architecture', $recallText);
        $this->assertStringContainsString('Exact recall evidence', $recallText);
        $this->assertStringNotContainsString('abcdefghijklmno', $recallText);

        $exit = Artisan::call('atlas:memory:recall', [
            'query' => ['hybrid', 'provider-safe', 'recall'],
            '--task-id' => $task->id,
            '--no-semantic' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'memory_recall.summary.recall_count'));
        $this->assertStringNotContainsString('abcdefghijklmno', Artisan::output());
    }

    public function test_open_brain_context_pack_api_and_cli_are_audited(): void
    {
        $this->migrateMemoryTable();
        $this->migrateVerbatimMemoryTable();
        $this->migrateOpenBrainAuditTable();
        [$project, $task] = $this->fixtures();

        app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'decision',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Open Brain context source',
            'body' => 'Open Brain exports audited provider-safe context packs from Atlas memory.',
            'summary' => 'Open Brain export must be audited and provider-safe.',
            'priority' => 97,
            'importance' => 5,
            'privacy_class' => 'normal',
            'source_type' => 'manual',
        ]);

        $response = $this->postJson('/ai/open-brain/context-pack', [
            'objective' => 'Export Open Brain context for memory implementation.',
            'workspace' => base_path(),
            'task_type' => 'dev',
            'requester' => 'test-api',
            'include_prompt' => true,
            'payload' => [
                'project_id' => $project->id,
                'task_id' => $task->id,
            ],
            'options' => [
                'include_semantic_context' => false,
            ],
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('open_brain.ok', true)
            ->assertJsonPath('open_brain.audit.requester', 'test-api');

        $this->assertStringContainsString('Open Brain context source', (string) data_get($response->json(), 'open_brain.prompt_section'));
        $this->assertDatabaseHas('atlas_open_brain_access_logs', [
            'requester' => 'test-api',
            'action' => 'context_pack_export',
            'surface' => 'api',
        ]);

        $exit = Artisan::call('atlas:open-brain:context', [
            'objective' => ['Export', 'Open', 'Brain', 'context'],
            '--workspace' => base_path(),
            '--task-type' => 'dev',
            '--requester' => 'test-cli',
            '--payload-json' => json_encode([
                'project_id' => $project->id,
                'task_id' => $task->id,
            ]),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(true, data_get($payload, 'open_brain.ok'));
        $this->assertNotEmpty(data_get($payload, 'open_brain.context_pack_hash'));
        $this->assertDatabaseHas('atlas_open_brain_access_logs', [
            'requester' => 'test-cli',
            'surface' => 'cli',
        ]);
        $this->assertSame(2, AtlasOpenBrainAccessLog::query()->count());
    }

    public function test_open_brain_context_injection_audits_dev_prompt_context(): void
    {
        $this->migrateOpenBrainAuditTable();

        $payload = [
            'app_surface' => 'atlas_cli',
            'atlas_workflow_mode' => 'dev',
            'workspace' => base_path(),
            'open_brain' => [
                'surface' => 'cli_chat',
                'mode' => 'auto',
            ],
        ];
        $task = AiTaskRequest::fromInput('implemente a injecao Open Brain', [
            'source_type' => 'manual',
            'payload' => $payload,
        ], [
            'agent' => 'desenvolvedor',
            'intent' => 'open_brain_context_injection_test',
        ]);
        $pack = $this->openBrainTestPack('dev');

        $result = app(AtlasOpenBrainContextInjectionService::class)->inject(
            'implemente a injecao Open Brain',
            $task,
            $pack,
            [
                'source_type' => 'manual',
                'payload' => $payload,
            ],
        );

        $this->assertTrue($result['enabled']);
        $this->assertContains($result['status'], ['injected', 'degraded']);
        $this->assertSame('cli_chat', $result['surface']);
        $this->assertNotEmpty($result['context_pack_hash']);
        $this->assertStringContainsString('Atlas Open Brain Context', (string) $result['prompt_section']);
        $this->assertStringContainsString('Memory decision', (string) $result['prompt_section']);
        $this->assertDatabaseHas('atlas_open_brain_access_logs', [
            'surface' => 'cli_chat',
            'action' => 'context_injection',
        ]);
    }

    public function test_open_brain_context_injection_skips_direct_mode_by_default(): void
    {
        $this->migrateOpenBrainAuditTable();

        $payload = [
            'app_surface' => 'atlas_cli',
            'atlas_workflow_mode' => 'direct',
            'workspace' => base_path(),
        ];
        $task = AiTaskRequest::fromInput('resuma meu dia', [
            'source_type' => 'manual',
            'payload' => $payload,
        ], [
            'agent' => 'orquestrador',
            'intent' => 'direct_chat',
        ]);

        $result = app(AtlasOpenBrainContextInjectionService::class)->inject(
            'resuma meu dia',
            $task,
            $this->openBrainTestPack('direct'),
            [
                'source_type' => 'manual',
                'payload' => $payload,
            ],
        );

        $this->assertFalse($result['enabled']);
        $this->assertSame('skipped', $result['status']);
        $this->assertSame('policy_off', $result['reason']);
        $this->assertNull($result['prompt_section']);
        $this->assertSame(0, AtlasOpenBrainAccessLog::query()->count());
    }

    public function test_open_brain_mcp_lists_tools_recalls_memory_and_audits_context_pack(): void
    {
        $this->migrateMemoryTable();
        $this->migrateVerbatimMemoryTable();
        $this->migrateOpenBrainAuditTable();
        [$project, $task] = $this->fixtures();

        app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'decision',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'MCP provider-safe recall',
            'body' => 'Atlas MCP exposes provider-safe recall and audited Open Brain context for programming.',
            'summary' => 'MCP should expose provider-safe recall.',
            'priority' => 98,
            'importance' => 5,
            'privacy_class' => 'normal',
            'source_type' => 'manual',
        ]);
        app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'technical_context',
            'scope_type' => 'task',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'title' => 'Blocked MCP secret',
            'body' => 'Never expose Bearer abcdefghijklmno through MCP recall.',
            'priority' => 99,
            'importance' => 5,
            'privacy_class' => 'secret',
            'external_ai_allowed' => false,
            'source_type' => 'manual',
        ]);

        $listExit = Artisan::call('atlas:open-brain:mcp', [
            '--once' => json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
            ]),
        ]);
        $listPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $listExit);
        $this->assertContains('atlas_memory_recall', collect(data_get($listPayload, 'result.tools'))->pluck('name')->all());
        $this->assertContains('atlas_open_brain_context_pack', collect(data_get($listPayload, 'result.tools'))->pluck('name')->all());
        $this->assertContains('atlas_memory_maintenance_status', collect(data_get($listPayload, 'result.tools'))->pluck('name')->all());

        $recallExit = Artisan::call('atlas:open-brain:mcp', [
            '--once' => json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'atlas_memory_recall',
                    'arguments' => [
                        'query' => 'MCP provider-safe programming recall',
                        'context' => [
                            'workspace' => base_path(),
                            'project_id' => $project->id,
                            'task_id' => $task->id,
                        ],
                        'options' => [
                            'include_semantic' => false,
                            'limit' => 4,
                        ],
                    ],
                ],
            ]),
        ]);
        $recallPayload = json_decode(Artisan::output(), true);
        $recallText = json_encode(data_get($recallPayload, 'result.structuredContent'), JSON_UNESCAPED_UNICODE);

        $this->assertSame(0, $recallExit);
        $this->assertSame(false, data_get($recallPayload, 'result.isError'));
        $this->assertSame('provider_safe_only', data_get($recallPayload, 'result.structuredContent.memory_recall.summary.policy'));
        $this->assertStringContainsString('MCP provider-safe recall', (string) $recallText);
        $this->assertStringNotContainsString('abcdefghijklmno', (string) $recallText);

        $contextExit = Artisan::call('atlas:open-brain:mcp', [
            '--once' => json_encode([
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'atlas_open_brain_context_pack',
                    'arguments' => [
                        'objective' => 'Use MCP Open Brain for Atlas memory programming.',
                        'workspace' => base_path(),
                        'requester' => 'mcp-test',
                        'include_prompt' => true,
                        'payload' => [
                            'project_id' => $project->id,
                            'task_id' => $task->id,
                        ],
                        'options' => [
                            'include_semantic_context' => false,
                        ],
                    ],
                ],
            ]),
        ]);
        $contextPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $contextExit);
        $this->assertSame(false, data_get($contextPayload, 'result.isError'));
        $this->assertSame(true, data_get($contextPayload, 'result.structuredContent.open_brain.ok'));
        $this->assertSame('mcp', data_get($contextPayload, 'result.structuredContent.open_brain.audit.surface'));
        $this->assertDatabaseHas('atlas_open_brain_access_logs', [
            'requester' => 'mcp-test',
            'surface' => 'mcp',
            'action' => 'context_pack_export',
        ]);
    }

    public function test_open_brain_mcp_http_endpoint_is_authenticated_origin_guarded_and_read_only(): void
    {
        config()->set('atlas.open_brain.mcp.http_enabled', true);
        config()->set('atlas.open_brain.mcp.allowed_origins', ['http://localhost:3000']);

        $headers = [
            ...$this->headers,
            'Origin' => 'http://localhost:3000',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => AtlasOpenBrainMcpService::PROTOCOL_VERSION,
        ];

        $statusPayload = $this->getJson('/ai/open-brain/mcp', [
            ...$headers,
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertHeader('MCP-Protocol-Version', AtlasOpenBrainMcpService::PROTOCOL_VERSION)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('read_only', true)
            ->json();
        $this->assertContains('tools/list', data_get($statusPayload, 'methods', []));

        $this->get('/ai/open-brain/mcp', [
            ...$headers,
            'Accept' => 'text/event-stream',
        ])
            ->assertStatus(405)
            ->assertHeader('Allow', 'POST')
            ->assertHeader('MCP-Protocol-Version', AtlasOpenBrainMcpService::PROTOCOL_VERSION);

        $listPayload = $this->postJson('/ai/open-brain/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ], $headers)
            ->assertOk()
            ->assertHeader('MCP-Protocol-Version', AtlasOpenBrainMcpService::PROTOCOL_VERSION)
            ->json();

        $this->assertContains('atlas_memory_recall', collect(data_get($listPayload, 'result.tools'))->pluck('name')->all());
        $this->assertContains('atlas_open_brain_context_pack', collect(data_get($listPayload, 'result.tools'))->pluck('name')->all());
        $this->assertContains('atlas_memory_maintenance_status', collect(data_get($listPayload, 'result.tools'))->pluck('name')->all());

        $this->postJson('/ai/open-brain/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ], [
            ...$this->headers,
            'Origin' => 'https://evil.example',
        ])->assertForbidden()
            ->assertJsonPath('error.code', 'ORIGIN_NOT_ALLOWED');

        $this->postJson('/ai/open-brain/mcp', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/list',
        ], [
            ...$headers,
            'MCP-Protocol-Version' => '2099-01-01',
        ])
            ->assertBadRequest()
            ->assertJsonPath('error.message', 'Unsupported MCP protocol version.');
    }

    public function test_memory_maintenance_command_reports_health_and_next_actions(): void
    {
        $this->migrateMemoryTable();
        $this->migrateVerbatimMemoryTable();
        $this->migrateProjectionAuditTable();
        $this->migrateOpenBrainAuditTable();

        $exit = Artisan::call('atlas:memory:maintain', [
            '--workspace' => base_path(),
            '--no-sync' => true,
            '--no-index-code' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertFalse($payload['ok']);
        $this->assertSame('needs_memory', data_get($payload, 'stages.mcp_health.overall_status'));
        $this->assertSame('skipped', data_get($payload, 'stages.knowledge_sync.status'));
        $this->assertSame('skipped', data_get($payload, 'stages.code_index.status'));
        $this->assertContains('/opt/homebrew/bin/php artisan atlas:memory:seed-core', data_get($payload, 'stages.mcp_health.next_actions', []));
    }

    public function test_memory_maintenance_api_reports_health_without_terminal(): void
    {
        $this->migrateMemoryTable();
        $this->migrateVerbatimMemoryTable();
        $this->migrateProjectionAuditTable();
        $this->migrateOpenBrainAuditTable();

        $payload = $this->postJson('/ai/memory/maintain', [
            'workspace' => base_path(),
            'sync' => false,
            'index_code' => false,
        ], $this->headers)
            ->assertStatus(409)
            ->assertJsonPath('memory_maintenance.ok', false)
            ->assertJsonPath('memory_maintenance.status', 'needs_memory')
            ->assertJsonPath('memory_maintenance.stages.knowledge_sync.status', 'skipped')
            ->assertJsonPath('memory_maintenance.stages.code_index.status', 'skipped')
            ->json('memory_maintenance');

        $this->assertContains('/opt/homebrew/bin/php artisan atlas:memory:seed-core', data_get($payload, 'stages.mcp_health.next_actions', []));
    }

    public function test_provider_projection_generates_provider_safe_files_and_detects_drift(): void
    {
        $this->migrateMemoryTable();
        $this->migrateProjectionAuditTable();
        $workspace = sys_get_temp_dir().'/atlas_projection_'.str_replace('-', '', (string) Str::uuid());
        mkdir($workspace, 0777, true);

        try {
            $safe = app(AtlasMemoryRegistryService::class)->record([
                'memory_type' => 'decision',
                'scope_type' => 'workspace',
                'workspace' => $workspace,
                'title' => 'Projection safe rule',
                'body' => 'Use Atlas registry as the canonical memory source for provider bootstrap files.',
                'summary' => 'Atlas registry stays canonical for provider projections.',
                'priority' => 95,
                'importance' => 5,
                'privacy_class' => 'normal',
                'source_type' => 'manual',
            ]);
            app(AtlasMemoryRegistryService::class)->record([
                'memory_type' => 'decision',
                'scope_type' => 'workspace',
                'workspace' => $workspace,
                'title' => 'Projection blocked rule',
                'body' => 'Do not leak Bearer abcdefghijklmno into generated provider files.',
                'privacy_class' => 'sensitive',
                'source_type' => 'manual',
            ]);

            $projection = app(AtlasProviderProjectionService::class)->generate('claude', [
                'workspace' => $workspace,
            ], [
                'max_lines' => 28,
                'memory_limit' => 10,
            ]);

            $this->assertSame('CLAUDE.md', $projection['filename']);
            $this->assertLessThanOrEqual(28, $projection['line_count']);
            $this->assertStringContainsString('atlas_provider_projection_v1', $projection['content']);
            $this->assertStringContainsString('Projection safe rule', $projection['content']);
            $this->assertStringContainsString('Atlas memory is canonical', $projection['content']);
            $this->assertStringNotContainsString('abcdefghijklmno', $projection['content']);
            $this->assertStringNotContainsString($safe->id, $projection['content']);

            $exitCode = Artisan::call('atlas:memory:projection', [
                'action' => 'write',
                '--target' => 'claude',
                '--workspace' => $workspace,
                '--max-lines' => 28,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true);
            $path = $workspace.'/CLAUDE.md';

            $this->assertSame(0, $exitCode);
            $this->assertTrue(data_get($payload, 'projections.0.written'));
            $this->assertFileExists($path);
            $this->assertStringContainsString('Projection safe rule', (string) file_get_contents($path));

            $inspectCode = Artisan::call('atlas:memory:projection', [
                'action' => 'inspect',
                '--target' => 'claude',
                '--workspace' => $workspace,
                '--max-lines' => 28,
                '--json' => true,
            ]);
            $inspect = json_decode(Artisan::output(), true);

            $this->assertSame(0, $inspectCode);
            $this->assertFalse(data_get($inspect, 'projections.0.manual_drift'));
            $this->assertFalse(data_get($inspect, 'projections.0.stale'));

            file_put_contents($path, "\nmanual edit", FILE_APPEND);

            Artisan::call('atlas:memory:projection', [
                'action' => 'inspect',
                '--target' => 'claude',
                '--workspace' => $workspace,
                '--max-lines' => 28,
                '--json' => true,
            ]);
            $drift = json_decode(Artisan::output(), true);

            $this->assertTrue(data_get($drift, 'projections.0.manual_drift'));
            $this->assertSame('checksum_mismatch', data_get($drift, 'projections.0.reason'));

            Artisan::call('atlas:memory:projection', [
                'action' => 'write',
                '--target' => 'claude',
                '--workspace' => $workspace,
                '--max-lines' => 28,
                '--json' => true,
            ]);
            $blockedWrite = json_decode(Artisan::output(), true);

            $this->assertFalse(data_get($blockedWrite, 'projections.0.written'));
            $this->assertSame('checksum_mismatch', data_get($blockedWrite, 'projections.0.error'));
            $this->assertStringContainsString('manual edit', (string) file_get_contents($path));

            Artisan::call('atlas:memory:projection', [
                'action' => 'write',
                '--target' => 'claude',
                '--workspace' => $workspace,
                '--max-lines' => 28,
                '--force' => true,
                '--json' => true,
            ]);
            $forcedWrite = json_decode(Artisan::output(), true);

            $this->assertTrue(data_get($forcedWrite, 'projections.0.written'));
            $this->assertStringNotContainsString('manual edit', (string) file_get_contents($path));

            file_put_contents(
                $path,
                str_replace(
                    AtlasProviderProjectionService::MANUAL_END,
                    "Human provider note preserved by Atlas.\n".AtlasProviderProjectionService::MANUAL_END,
                    (string) file_get_contents($path),
                ),
            );

            Artisan::call('atlas:memory:projection', [
                'action' => 'inspect',
                '--target' => 'claude',
                '--workspace' => $workspace,
                '--max-lines' => 28,
                '--json' => true,
            ]);
            $manualInspect = json_decode(Artisan::output(), true);

            $this->assertFalse(data_get($manualInspect, 'projections.0.manual_drift'));
            $this->assertTrue(data_get($manualInspect, 'projections.0.manual_section_present'));

            Artisan::call('atlas:memory:projection', [
                'action' => 'write',
                '--target' => 'claude',
                '--workspace' => $workspace,
                '--max-lines' => 28,
                '--json' => true,
            ]);

            $this->assertStringContainsString('Human provider note preserved by Atlas.', (string) file_get_contents($path));

            $agentsPath = $workspace.'/AGENTS.md';
            file_put_contents($agentsPath, "Existing human AGENTS rules.\nKeep local shell constraints.");

            Artisan::call('atlas:memory:projection', [
                'action' => 'review',
                '--target' => 'agents',
                '--workspace' => $workspace,
                '--max-lines' => 28,
                '--json' => true,
            ]);
            $review = json_decode(Artisan::output(), true);

            $this->assertSame('needs_review', data_get($review, 'status'));
            $this->assertSame('adopt', data_get($review, 'projections.0.change_type'));
            $this->assertStringContainsString('Existing human AGENTS rules.', data_get($review, 'projections.0.diff'));
            $this->assertStringContainsString(AtlasProviderProjectionService::MANUAL_START, data_get($review, 'projections.0.diff'));
            $this->assertSame("Existing human AGENTS rules.\nKeep local shell constraints.", (string) file_get_contents($agentsPath));

            $applyBlockedCode = Artisan::call('atlas:memory:projection', [
                'action' => 'apply',
                '--target' => 'agents',
                '--workspace' => $workspace,
                '--max-lines' => 28,
                '--json' => true,
            ]);
            $applyBlocked = json_decode(Artisan::output(), true);

            $this->assertSame(1, $applyBlockedCode);
            $this->assertSame('confirmation_required', data_get($applyBlocked, 'error'));
            $this->assertSame("Existing human AGENTS rules.\nKeep local shell constraints.", (string) file_get_contents($agentsPath));

            Artisan::call('atlas:memory:projection', [
                'action' => 'apply',
                '--target' => 'agents',
                '--workspace' => $workspace,
                '--max-lines' => 28,
                '--yes' => true,
                '--json' => true,
            ]);
            $apply = json_decode(Artisan::output(), true);

            $this->assertTrue(data_get($apply, 'ok'));
            $this->assertTrue(data_get($apply, 'applied.0.written'));
            $this->assertSame('adopt', data_get($apply, 'applied.0.change_type'));
            $this->assertSame('cli', data_get($apply, 'audit.initiator'));
            $this->assertSame('flag', data_get($apply, 'audit.confirmation_mode'));
            $this->assertStringContainsString('Existing human AGENTS rules.', (string) file_get_contents($agentsPath));
            $this->assertStringContainsString(AtlasProviderProjectionService::MANUAL_START, (string) file_get_contents($agentsPath));
            $this->assertSame(1, AtlasMemoryProviderProjectionAudit::query()->where('initiator', 'cli')->count());

            $statusCode = Artisan::call('atlas:memory:projection', [
                'action' => 'status',
                '--target' => 'all',
                '--workspace' => $workspace,
                '--max-lines' => 28,
                '--json' => true,
            ]);
            $status = json_decode(Artisan::output(), true);

            $this->assertSame(0, $statusCode);
            $this->assertSame('passed', data_get($status, 'status'));
            $this->assertSame(2, data_get($status, 'summary.ready'));
            $this->assertSame([], data_get($status, 'next_actions'));

            Artisan::call('atlas:memory:projection', [
                'action' => 'review',
                '--target' => 'all',
                '--workspace' => $workspace,
                '--max-lines' => 28,
                '--json' => true,
            ]);
            $cleanReview = json_decode(Artisan::output(), true);

            $this->assertSame('passed', data_get($cleanReview, 'status'));
            $this->assertSame(0, data_get($cleanReview, 'summary.changed'));
            $this->assertSame('', data_get($cleanReview, 'projections.0.diff'));
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_provider_projection_status_flags_empty_provider_safe_memory(): void
    {
        $this->migrateMemoryTable();
        $workspace = sys_get_temp_dir().'/atlas_projection_empty_'.str_replace('-', '', (string) Str::uuid());
        mkdir($workspace, 0777, true);

        try {
            Artisan::call('atlas:memory:projection', [
                'action' => 'write',
                '--target' => 'claude',
                '--workspace' => $workspace,
                '--json' => true,
            ]);

            $this->assertFileExists($workspace.'/CLAUDE.md');
            $this->assertStringContainsString('No provider-safe Atlas memory was available', (string) file_get_contents($workspace.'/CLAUDE.md'));

            $exitCode = Artisan::call('atlas:memory:projection', [
                'action' => 'status',
                '--target' => 'claude',
                '--workspace' => $workspace,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true);

            $this->assertSame(0, $exitCode);
            $this->assertSame('needs_review', data_get($payload, 'status'));
            $this->assertSame(1, data_get($payload, 'summary.empty_memory'));
            $this->assertSame(0, data_get($payload, 'summary.provider_safe_memory_count'));
            $this->assertStringContainsString('seed-core', implode("\n", (array) data_get($payload, 'next_actions', [])));
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_core_memory_seed_makes_provider_projection_actionable(): void
    {
        $this->migrateMemoryTable();
        $workspace = sys_get_temp_dir().'/atlas_projection_seed_'.str_replace('-', '', (string) Str::uuid());
        mkdir($workspace, 0777, true);

        try {
            $seedCode = Artisan::call('atlas:memory:seed-core', [
                '--json' => true,
            ]);
            $seed = json_decode(Artisan::output(), true);

            $this->assertSame(0, $seedCode);
            $this->assertGreaterThanOrEqual(5, data_get($seed, 'seeded'));

            $projectionCode = Artisan::call('atlas:memory:projection', [
                'action' => 'write',
                '--target' => 'claude',
                '--workspace' => $workspace,
                '--json' => true,
            ]);
            $projection = json_decode(Artisan::output(), true);

            $this->assertSame(0, $projectionCode);
            $this->assertGreaterThan(0, data_get($projection, 'projections.0.memory_count'));
            $this->assertStringContainsString('Atlas owns canonical memory', (string) file_get_contents($workspace.'/CLAUDE.md'));

            Artisan::call('atlas:memory:projection', [
                'action' => 'status',
                '--target' => 'claude',
                '--workspace' => $workspace,
                '--json' => true,
            ]);
            $status = json_decode(Artisan::output(), true);

            $this->assertSame('passed', data_get($status, 'status'));
            $this->assertSame(0, data_get($status, 'summary.empty_memory'));
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_provider_projection_api_reviews_and_applies_with_explicit_confirmation(): void
    {
        $this->migrateMemoryTable();
        $this->migrateProjectionAuditTable();
        $workspace = sys_get_temp_dir().'/atlas_projection_api_'.str_replace('-', '', (string) Str::uuid());
        mkdir($workspace, 0777, true);

        try {
            app(AtlasMemoryRegistryService::class)->record([
                'memory_type' => 'decision',
                'scope_type' => 'workspace',
                'workspace' => $workspace,
                'title' => 'Provider API rule',
                'body' => 'Provider projection API writes only after explicit confirmation.',
                'summary' => 'API apply requires confirmed review.',
                'priority' => 92,
                'importance' => 5,
                'privacy_class' => 'normal',
                'source_type' => 'manual',
            ]);

            $query = http_build_query([
                'target' => 'all',
                'workspace' => $workspace,
                'max_lines' => 28,
                'memory_limit' => 10,
            ]);

            $this->getJson("/ai/memory/provider-projection/status?{$query}", $this->headers)
                ->assertOk()
                ->assertJsonPath('provider_projection.status', 'needs_review')
                ->assertJsonPath('provider_projection.summary.missing', 2);

            $this->getJson("/ai/memory/provider-projection/review?{$query}", $this->headers)
                ->assertOk()
                ->assertJsonPath('provider_projection.status', 'needs_review')
                ->assertJsonPath('provider_projection.summary.create', 2)
                ->assertJsonPath('provider_projection.projections.0.change_type', 'create');

            $this->postJson('/ai/memory/provider-projection/apply', [
                'target' => 'all',
                'workspace' => $workspace,
                'max_lines' => 28,
                'memory_limit' => 10,
            ], $this->headers)
                ->assertStatus(422)
                ->assertJsonValidationErrors('confirm');

            $this->assertFileDoesNotExist($workspace.'/CLAUDE.md');
            $this->assertFileDoesNotExist($workspace.'/AGENTS.md');

            $this->postJson('/ai/memory/provider-projection/apply', [
                'target' => 'all',
                'workspace' => $workspace,
                'max_lines' => 28,
                'memory_limit' => 10,
                'confirm' => true,
            ], $this->headers)
                ->assertOk()
                ->assertJsonPath('provider_projection.ok', true)
                ->assertJsonPath('provider_projection.summary.applied', 2)
                ->assertJsonPath('audit.ok', true)
                ->assertJsonPath('audit.initiator', 'api');

            $this->assertFileExists($workspace.'/CLAUDE.md');
            $this->assertFileExists($workspace.'/AGENTS.md');
            $this->assertStringContainsString('Provider API rule', (string) file_get_contents($workspace.'/CLAUDE.md'));
            $this->assertSame(1, AtlasMemoryProviderProjectionAudit::query()->where('ok', true)->count());

            $this->getJson("/ai/memory/provider-projection/status?{$query}", $this->headers)
                ->assertOk()
                ->assertJsonPath('provider_projection.status', 'passed')
                ->assertJsonPath('provider_projection.summary.ready', 2);

            file_put_contents($workspace.'/CLAUDE.md', "\nmanual edit", FILE_APPEND);

            $this->postJson('/ai/memory/provider-projection/apply', [
                'target' => 'all',
                'workspace' => $workspace,
                'max_lines' => 28,
                'memory_limit' => 10,
                'confirm' => true,
            ], $this->headers)
                ->assertStatus(409)
                ->assertJsonPath('provider_projection.ok', false)
                ->assertJsonPath('provider_projection.summary.blocked', 1)
                ->assertJsonPath('provider_projection.summary.applied', 0)
                ->assertJsonPath('audit.ok', false)
                ->assertJsonPath('audit.summary.blocked', 1);

            $this->assertStringContainsString('manual edit', (string) file_get_contents($workspace.'/CLAUDE.md'));
            $this->getJson('/ai/memory/provider-projection/audits?'.http_build_query([
                'workspace' => $workspace,
                'target' => 'all',
                'initiator' => 'api',
                'ok' => false,
            ]), $this->headers)
                ->assertOk()
                ->assertJsonCount(1, 'provider_projection_audits')
                ->assertJsonPath('provider_projection_audits.0.initiator', 'api')
                ->assertJsonPath('provider_projection_audits.0.ok', false);
            $this->assertSame(2, AtlasMemoryProviderProjectionAudit::query()->where('workspace', $workspace)->count());

            AtlasMemoryProviderProjectionAudit::query()->create([
                'action' => 'apply',
                'target' => 'all',
                'workspace' => $workspace,
                'initiator' => 'cli',
                'confirmation_mode' => 'flag',
                'status' => 'passed',
                'ok' => true,
                'summary_json' => ['applied' => 1],
                'applied_json' => [
                    [
                        'target' => 'claude',
                        'change_type' => 'update',
                        'path' => $workspace.'/CLAUDE.md',
                        'written' => true,
                    ],
                ],
                'blocked_json' => [],
                'failed_json' => [],
                'review_summary_json' => ['update' => 1],
                'metadata' => ['fixture' => true],
                'applied_at' => now(),
            ]);

            $this->getJson('/ai/memory/provider-projection/audits?'.http_build_query([
                'workspace' => $workspace,
                'target' => 'all',
                'initiator' => 'cli',
            ]), $this->headers)
                ->assertOk()
                ->assertJsonCount(1, 'provider_projection_audits')
                ->assertJsonPath('provider_projection_audits.0.initiator', 'cli');

            $this->getJson('/ai/memory/provider-projection/audits?'.http_build_query([
                'workspace' => $workspace,
                'initiator' => 'manual',
            ]), $this->headers)
                ->assertStatus(422)
                ->assertJsonValidationErrors('initiator');
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_provider_projection_api_can_apply_single_target_without_touching_other_file(): void
    {
        $this->migrateMemoryTable();
        $this->migrateProjectionAuditTable();
        $workspace = sys_get_temp_dir().'/atlas_projection_api_target_'.str_replace('-', '', (string) Str::uuid());
        mkdir($workspace, 0777, true);

        try {
            app(AtlasMemoryRegistryService::class)->record([
                'memory_type' => 'technical_context',
                'scope_type' => 'workspace',
                'workspace' => $workspace,
                'title' => 'Single target projection',
                'body' => 'Single-target provider projection should not create unrelated provider files.',
                'summary' => 'Single target apply stays scoped.',
                'priority' => 88,
                'importance' => 4,
                'privacy_class' => 'normal',
                'source_type' => 'manual',
            ]);

            $this->getJson('/ai/memory/provider-projection/review?'.http_build_query([
                'target' => 'claude',
                'workspace' => $workspace,
                'max_lines' => 28,
                'memory_limit' => 10,
            ]), $this->headers)
                ->assertOk()
                ->assertJsonPath('provider_projection.targets.0', 'claude')
                ->assertJsonPath('provider_projection.summary.create', 1);

            $this->postJson('/ai/memory/provider-projection/apply', [
                'target' => 'claude',
                'workspace' => $workspace,
                'max_lines' => 28,
                'memory_limit' => 10,
                'confirm' => true,
            ], $this->headers)
                ->assertOk()
                ->assertJsonPath('provider_projection.ok', true)
                ->assertJsonPath('provider_projection.summary.applied', 1)
                ->assertJsonPath('provider_projection.applied.0.target', 'claude')
                ->assertJsonPath('audit.target', 'claude')
                ->assertJsonPath('audit.summary.applied', 1);

            $this->assertFileExists($workspace.'/CLAUDE.md');
            $this->assertFileDoesNotExist($workspace.'/AGENTS.md');

            $this->getJson('/ai/memory/provider-projection/status?'.http_build_query([
                'target' => 'all',
                'workspace' => $workspace,
                'max_lines' => 28,
                'memory_limit' => 10,
            ]), $this->headers)
                ->assertOk()
                ->assertJsonPath('provider_projection.summary.ready', 1)
                ->assertJsonPath('provider_projection.summary.missing', 1);
            $this->assertSame('claude', AtlasMemoryProviderProjectionAudit::query()->first()?->target);
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_provider_projection_audit_summary_and_retention_api_and_cli(): void
    {
        $this->migrateProjectionAuditTable();
        $workspace = sys_get_temp_dir().'/atlas_projection_audit_ops_'.str_replace('-', '', (string) Str::uuid());

        $recentApi = AtlasMemoryProviderProjectionAudit::query()->create([
            'action' => 'apply',
            'target' => 'all',
            'workspace' => $workspace,
            'initiator' => 'api',
            'confirmation_mode' => 'api_confirm',
            'status' => 'passed',
            'ok' => true,
            'summary_json' => ['applied' => 1],
            'applied_json' => [['target' => 'claude', 'change_type' => 'update', 'path' => $workspace.'/CLAUDE.md', 'written' => true]],
            'blocked_json' => [],
            'failed_json' => [],
            'review_summary_json' => ['update' => 1],
            'metadata' => ['fixture' => 'recent_api'],
            'applied_at' => now()->subDays(2),
        ]);
        AtlasMemoryProviderProjectionAudit::query()->create([
            'action' => 'apply',
            'target' => 'all',
            'workspace' => $workspace,
            'initiator' => 'cli',
            'confirmation_mode' => 'flag',
            'status' => 'needs_review',
            'ok' => false,
            'summary_json' => ['blocked' => 1, 'manual_drift' => 1],
            'applied_json' => [],
            'blocked_json' => [['target' => 'agents', 'change_type' => 'manual_drift', 'path' => $workspace.'/AGENTS.md']],
            'failed_json' => [],
            'review_summary_json' => ['manual_drift' => 1],
            'metadata' => ['fixture' => 'recent_cli'],
            'applied_at' => now()->subDays(4),
        ]);
        AtlasMemoryProviderProjectionAudit::query()->create([
            'action' => 'apply',
            'target' => 'all',
            'workspace' => $workspace,
            'initiator' => 'api',
            'confirmation_mode' => 'api_confirm',
            'status' => 'passed',
            'ok' => true,
            'summary_json' => ['applied' => 1],
            'applied_json' => [['target' => 'claude', 'change_type' => 'update', 'path' => $workspace.'/CLAUDE.md', 'written' => true]],
            'blocked_json' => [],
            'failed_json' => [],
            'review_summary_json' => ['update' => 1],
            'metadata' => ['fixture' => 'old_api'],
            'applied_at' => now()->subDays(120),
        ]);

        $query = http_build_query([
            'workspace' => $workspace,
            'target' => 'all',
            'days' => 30,
        ]);

        $this->getJson("/ai/memory/provider-projection/audits/summary?{$query}", $this->headers)
            ->assertOk()
            ->assertJsonPath('provider_projection_audit_summary.total', 2)
            ->assertJsonPath('provider_projection_audit_summary.applied', 1)
            ->assertJsonPath('provider_projection_audit_summary.blocked', 1)
            ->assertJsonPath('provider_projection_audit_summary.by_initiator.api.total', 1)
            ->assertJsonPath('provider_projection_audit_summary.by_initiator.cli.blocked', 1);

        Artisan::call('atlas:memory:projection', [
            'action' => 'audit-summary',
            '--workspace' => $workspace,
            '--target' => 'all',
            '--audit-days' => 30,
            '--json' => true,
        ]);
        $cliSummary = json_decode(Artisan::output(), true);

        $this->assertSame(2, data_get($cliSummary, 'total'));
        $this->assertSame(1, data_get($cliSummary, 'by_target.all.applied'));

        Artisan::call('atlas:memory:projection', [
            'action' => 'audit-purge',
            '--workspace' => $workspace,
            '--target' => 'all',
            '--retention-days' => 90,
            '--json' => true,
        ]);
        $cliDryRun = json_decode(Artisan::output(), true);

        $this->assertTrue(data_get($cliDryRun, 'dry_run'));
        $this->assertSame(1, data_get($cliDryRun, 'matched'));
        $this->assertSame(0, data_get($cliDryRun, 'deleted'));
        $this->assertSame(3, AtlasMemoryProviderProjectionAudit::query()->where('workspace', $workspace)->count());

        $dryRunResponse = $this->postJson('/ai/memory/provider-projection/audits/purge', [
            'workspace' => $workspace,
            'target' => 'all',
            'older_than_days' => 90,
            'dry_run' => true,
        ], $this->headers);
        $dryRunResponse
            ->assertOk()
            ->assertJsonPath('provider_projection_audit_purge.dry_run', true)
            ->assertJsonPath('provider_projection_audit_purge.matched', 1)
            ->assertJsonPath('provider_projection_audit_purge.deleted', 0)
            ->assertJsonStructure(['provider_projection_audit_purge' => ['confirmation_fingerprint']]);
        $fingerprint = $dryRunResponse->json('provider_projection_audit_purge.confirmation_fingerprint');

        $this->postJson('/ai/memory/provider-projection/audits/purge', [
            'workspace' => $workspace,
            'target' => 'all',
            'older_than_days' => 90,
            'dry_run' => false,
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm');

        $this->postJson('/ai/memory/provider-projection/audits/purge', [
            'workspace' => $workspace,
            'target' => 'all',
            'older_than_days' => 90,
            'dry_run' => false,
            'confirm' => true,
        ], $this->headers)
            ->assertStatus(409)
            ->assertJsonPath('provider_projection_audit_purge.ok', false)
            ->assertJsonPath('provider_projection_audit_purge.status', 'confirmation_fingerprint_mismatch')
            ->assertJsonPath('provider_projection_audit_purge.deleted', 0);

        config()->set('atlas.ai.provider_projection_audit_purge.require_operator', true);
        config()->set('atlas.ai.provider_projection_audit_purge.operator_header', 'X-Atlas-Operator');
        config()->set('atlas.ai.provider_projection_audit_purge.operator_token', null);

        $this->postJson('/ai/memory/provider-projection/audits/purge', [
            'workspace' => $workspace,
            'target' => 'all',
            'older_than_days' => 90,
            'dry_run' => true,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('provider_projection_audit_purge.policy.requires_operator', false);

        $this->postJson('/ai/memory/provider-projection/audits/purge', [
            'workspace' => $workspace,
            'target' => 'all',
            'older_than_days' => 90,
            'dry_run' => false,
            'confirm' => true,
            'confirmation_fingerprint' => $fingerprint,
        ], $this->headers)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'OPERATOR_PERMISSION_REQUIRED')
            ->assertJsonPath('provider_projection_audit_purge.status', 'operator_permission_required')
            ->assertJsonPath('provider_projection_audit_purge.deleted', 0)
            ->assertJsonPath('provider_projection_audit_purge.policy.requires_operator', true)
            ->assertJsonPath('provider_projection_audit_purge.policy.authorized', false);

        $this->postJson('/ai/memory/provider-projection/audits/purge', [
            'workspace' => $workspace,
            'target' => 'all',
            'older_than_days' => 90,
            'dry_run' => false,
            'confirm' => true,
            'confirmation_fingerprint' => $fingerprint,
        ], $this->headers + ['X-Atlas-Operator' => 'owner'])
            ->assertOk()
            ->assertJsonPath('provider_projection_audit_purge.dry_run', false)
            ->assertJsonPath('provider_projection_audit_purge.matched', 1)
            ->assertJsonPath('provider_projection_audit_purge.deleted', 1)
            ->assertJsonPath('provider_projection_audit_purge.policy.authorized', true);

        $this->assertSame(2, AtlasMemoryProviderProjectionAudit::query()->where('workspace', $workspace)->count());
        $this->assertTrue(AtlasMemoryProviderProjectionAudit::query()->whereKey($recentApi->id)->exists());
    }

    public function test_provider_projection_audit_purge_policy_requires_operator_token_when_configured(): void
    {
        config()->set('atlas.ai.provider_projection_audit_purge.require_operator', true);
        config()->set('atlas.ai.provider_projection_audit_purge.operator_header', 'X-Atlas-Operator');
        config()->set('atlas.ai.provider_projection_audit_purge.operator_token', 'secret-operator-token');

        $policy = app(AtlasProviderProjectionAuditPurgePolicy::class);

        $this->assertSame('X-Atlas-Operator', $policy->headerName());
        $this->assertFalse(data_get($policy->evaluate(false, 'wrong-token'), 'authorized'));
        $this->assertSame('operator_token', data_get($policy->evaluate(false, 'wrong-token'), 'mode'));
        $this->assertTrue(data_get($policy->evaluate(false, 'secret-operator-token'), 'authorized'));
        $this->assertTrue(data_get($policy->evaluate(true, null), 'authorized'));
        $this->assertFalse(data_get($policy->evaluate(true, null), 'requires_operator'));
    }

    private function contextPackForTask(AtlasProject $project, AtlasTask $task, array $options = [])
    {
        $search = $this->createMock(SemanticSearchService::class);
        $conversation = $this->createMock(AiConversationContextBuilder::class);
        $conversation->method('build')->willReturn([
            'thread_id' => null,
            'thread_title' => null,
            'thread_summary' => null,
            'active_state' => null,
            'latest_compaction' => null,
            'latest_provider_handoff' => null,
            'source' => 'none',
            'instruction' => 'Use contexto Atlas.',
            'recent_turns' => [],
        ]);

        $taskRequest = AiTaskRequest::fromInput('Use reviewed memory safely.', [
            'source_type' => 'manual',
            'payload' => [
                'project_id' => $project->id,
                'task_id' => $task->id,
                'workspace' => base_path(),
                'task_type' => 'dev',
            ],
        ], [
            'agent' => 'desenvolvedor',
            'intent' => 'test',
        ]);

        $contextOptions = array_replace_recursive([
            'payload' => [
                'project_id' => $project->id,
                'task_id' => $task->id,
                'workspace' => base_path(),
            ],
            'include_semantic_context' => false,
        ], $options);

        return (new AiContextPackBuilder($search, $conversation, app(AtlasMemoryRegistryService::class)))->build(
            'Use reviewed memory safely.',
            $taskRequest,
            $contextOptions,
        );
    }

    /**
     * @return array{0:AtlasProject,1:AtlasTask,2:AtlasEngineeringRun}
     */
    private function fixtures(): array
    {
        $project = AtlasProject::query()->create([
            'title' => 'Atlas Memory Core',
            'description' => 'Central memory registry phase.',
            'status' => 'active',
            'domain' => 'atlas',
            'metadata' => [],
        ]);
        $task = AtlasTask::query()->create([
            'project_id' => $project->id,
            'title' => 'Implement memory registry',
            'description' => 'Fase 1.',
            'status' => 'open',
            'priority' => 'high',
            'domain' => 'atlas',
            'metadata' => [],
        ]);
        $run = AtlasEngineeringRun::query()->create([
            'task_id' => $task->id,
            'project_id' => $project->id,
            'workspace_path_hash' => hash('sha256', base_path()),
            'workspace_label' => 'atlas-server',
            'provider_strategy_json' => ['no_provider' => true],
            'context_pack_hash' => str_repeat('a', 64),
            'status' => 'passed',
            'decision' => 'resolved',
            'score' => 100,
            'attempt_count' => 1,
            'metadata' => ['blocking_reasons' => []],
        ]);

        return [$project, $task, $run];
    }

    private function migrateMemoryTable(): void
    {
        $migration = require database_path('migrations/2026_05_02_000000_create_atlas_memory_entries_table.php');
        $migration->up();
    }

    private function migrateMemoryUsageTable(): void
    {
        $migration = require database_path('migrations/2026_05_02_001000_create_atlas_memory_entry_usages_table.php');
        $migration->up();
    }

    private function migrateGovernanceTable(): void
    {
        $migration = require database_path('migrations/2026_05_02_003000_create_atlas_memory_entry_relations_table.php');
        $migration->up();
    }

    private function migrateMemoryPrivacyTable(): void
    {
        $migration = require database_path('migrations/2026_05_02_005000_add_privacy_columns_to_atlas_memory_entries.php');
        $migration->up();
    }

    private function migrateVerbatimMemoryTable(): void
    {
        $migration = require database_path('migrations/2026_05_02_004000_create_atlas_verbatim_memories_table.php');
        $migration->up();
    }

    private function migrateProjectionAuditTable(): void
    {
        $migration = require database_path('migrations/2026_05_02_008000_create_atlas_memory_provider_projection_audits_table.php');
        $migration->up();
    }

    private function migrateOpenBrainAuditTable(): void
    {
        $migration = require database_path('migrations/2026_05_03_130000_create_atlas_open_brain_access_logs_table.php');
        $migration->up();
    }

    private function openBrainTestPack(string $mode): AiContextPack
    {
        $memoryId = (string) Str::uuid();

        return new AiContextPack([
            'schema_version' => 1,
            'task' => [
                'type' => $mode === 'direct' ? 'direct' : 'dev',
                'desired_mode' => $mode,
                'objective' => 'implemente a injecao Open Brain',
                'success_criteria' => [],
                'risk_level' => 'low',
                'domain' => 'atlas',
                'intent' => 'test',
            ],
            'surface' => [
                'kind' => 'mac_cli',
                'workspace' => base_path(),
                'source_type' => 'manual',
                'source_id' => null,
            ],
            'conversation' => [
                'thread_id' => null,
                'thread_title' => null,
                'thread_summary' => null,
                'source' => 'none',
                'context_window' => null,
                'recent_turns' => [],
                'instruction' => 'Use contexto Atlas.',
            ],
            'continuity' => [
                'active_state' => null,
                'latest_compaction' => null,
                'latest_provider_handoff' => null,
            ],
            'constraints' => [
                'must_do' => ['Usar portugues brasileiro claro.'],
                'must_not_do' => ['Nao inventar fatos.'],
                'privacy_class' => 'normal',
            ],
            'memory' => [
                'recall' => [],
                'registry' => [[
                    'id' => $memoryId,
                    'type' => 'decision',
                    'scope' => 'global',
                    'scope_type' => 'global',
                    'scope_id' => null,
                    'title' => 'Memory decision',
                    'summary' => 'Open Brain injection must be audited.',
                    'body' => 'Atlas must inject provider-safe Open Brain context automatically for dev tasks.',
                    'importance' => 5,
                    'priority' => 95,
                    'source_type' => 'manual',
                    'source_id' => null,
                    'reason' => 'test_memory',
                ]],
                'verbatim' => [],
                'semantic' => [],
            ],
            'open_questions' => [],
            'excluded_context' => [],
        ], [[
            'type' => 'atlas_memory_entry',
            'id' => $memoryId,
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'scope_id' => null,
            'priority' => 95,
            'source_type' => 'manual',
            'source_id' => null,
        ]]);
    }

    private function usage(AtlasMemoryEntry $memory): AtlasMemoryEntryUsage
    {
        return AtlasMemoryEntryUsage::query()->create([
            'memory_entry_id' => $memory->id,
            'memory_type' => $memory->memory_type,
            'scope_type' => $memory->scope_type,
            'scope_id' => $memory->scope_id,
            'position' => 1,
            'included_reason' => 'test usage',
            'source_ref_json' => ['type' => 'atlas_memory_entry', 'id' => $memory->id],
            'context_payload_json' => ['title' => $memory->title],
            'metadata' => [],
        ]);
    }

    private function conflictingMemory(string $title, string $body, int $priority): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create([
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'title' => $title,
            'body' => $body,
            'priority' => $priority,
            'importance' => 3,
            'source_type' => 'manual',
            'metadata' => [],
        ]);
    }

    private function createMemoryDeltaTable(): void
    {
        Schema::create('ai_memory_deltas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_trace_id')->nullable()->index();
            $table->uuid('source_session_id')->nullable()->index();
            $table->string('source_workspace')->nullable();
            $table->string('type', 32)->default('process');
            $table->text('claim');
            $table->json('evidence');
            $table->string('scope', 255)->default('global');
            $table->float('confidence')->default(0.5);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->json('use_when')->nullable();
            $table->json('do_not_use_when')->nullable();
            $table->boolean('requires_confirmation')->default(true);
            $table->string('status', 16)->default('pending');
            $table->uuid('superseded_by')->nullable();
            $table->uuid('promoted_memory_entry_id')->nullable()->index();
            $table->timestamp('promoted_at')->nullable()->index();
            $table->timestamps();
        });
    }

    private function memoryDelta(string $type, string $status, string $claim): AiMemoryDelta
    {
        return AiMemoryDelta::query()->create([
            'source_workspace' => base_path(),
            'type' => $type,
            'claim' => $claim,
            'evidence' => [['kind' => 'test', 'excerpt' => $claim]],
            'scope' => 'workspace:'.base_path(),
            'confidence' => 0.74,
            'valid_from' => now(),
            'valid_until' => now()->addDays(14),
            'use_when' => ['teste focado'],
            'do_not_use_when' => [],
            'requires_confirmation' => true,
            'status' => $status,
        ]);
    }

    /**
     * @return array{0:AiThread,1:AiSession,2:AiTrace}
     */
    private function aiTraceFixture(): array
    {
        $thread = AiThread::query()->create([
            'title' => 'Memory audit trace',
            'status' => 'active',
            'surface' => 'app',
            'metadata' => [],
        ]);
        $session = AiSession::query()->create([
            'thread_id' => $thread->id,
            'status' => 'active',
            'provider_primary' => 'test',
            'provider_last' => 'test',
            'metadata' => [],
        ]);
        $trace = AiTrace::query()->create([
            'trace_key' => 'trace_memory_audit_'.str_replace('-', '', (string) Str::uuid()),
            'thread_id' => $thread->id,
            'session_id' => $session->id,
            'source_type' => 'manual',
            'status' => 'queued',
            'operator_input' => 'Teste auditoria de memoria.',
            'intent' => 'test',
            'agent_slug' => 'desenvolvedor',
            'provider' => 'test',
            'model' => 'test-model',
            'skill_versions' => [],
            'context_refs' => [],
            'metadata' => [],
        ]);

        return [$thread, $session, $trace];
    }

    private function createRelatedTables(): void
    {
        Schema::create('ai_threads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('title');
            $table->text('summary')->nullable();
            $table->string('status')->default('active');
            $table->string('surface')->default('app');
            $table->string('workspace')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('last_trace_id')->nullable();
            $table->string('last_provider')->nullable();
            $table->integer('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->string('status')->default('active');
            $table->string('purpose')->nullable();
            $table->string('provider_primary')->nullable();
            $table->string('provider_last')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('message_count')->default(0);
            $table->integer('token_estimate')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->unique();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->default('manual');
            $table->uuid('source_id')->nullable();
            $table->string('status')->default('queued');
            $table->text('operator_input');
            $table->string('intent')->nullable();
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->default('{}');
            $table->json('context_refs')->default('[]');
            $table->string('prompt_hash')->nullable();
            $table->string('response_hash')->nullable();
            $table->text('response_text')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->smallInteger('feedback_score')->nullable();
            $table->string('feedback_action')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_context_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_hash')->nullable();
            $table->json('context_pack')->default('{}');
            $table->json('messages_included')->default('[]');
            $table->uuid('compaction_id')->nullable();
            $table->uuid('provider_handoff_id')->nullable();
            $table->integer('token_estimate')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('atlas_projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->string('domain')->default('atlas');
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('atlas_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('open');
            $table->string('priority')->default('normal');
            $table->string('domain')->default('atlas');
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('atlas_engineering_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('project_id')->nullable();
            $table->uuid('project_step_id')->nullable();
            $table->uuid('blueprint_snapshot_id')->nullable();
            $table->string('blueprint_id', 120)->nullable();
            $table->uuid('trace_id')->nullable();
            $table->uuid('context_pack_id')->nullable();
            $table->string('workspace_path_hash', 64);
            $table->string('workspace_label', 180);
            $table->json('provider_strategy_json')->default('{}');
            $table->string('context_pack_hash', 64)->nullable();
            $table->unsignedSmallInteger('harnessability_score')->nullable();
            $table->string('status', 32)->default('queued');
            $table->string('decision', 32)->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedSmallInteger('max_attempts')->default(1);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }

    private function dropTables(): void
    {
        foreach ([
            'atlas_open_brain_access_logs',
            'atlas_memory_provider_projection_audits',
            'atlas_verbatim_memories',
            'atlas_memory_entry_usages',
            'atlas_memory_entry_relations',
            'ai_memory_deltas',
            'atlas_memory_entries',
            'atlas_engineering_runs',
            'atlas_tasks',
            'atlas_projects',
            'ai_context_snapshots',
            'ai_traces',
            'ai_sessions',
            'ai_threads',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
