<?php

namespace Tests\Unit;

use App\Models\AiJob;
use App\Models\AiMemoryDelta;
use App\Models\AiScheduledTask;
use App\Services\Ai\ClaudeCliProvider;
use App\Services\Ai\CodexCliProvider;
use App\Services\Ai\GeminiCliProvider;
use App\Services\Ai\HermesCliProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiCliProviderRuntimeArgsTest extends TestCase
{
    private string $workspace;

    private string $operatorRoot;

    private string $attachmentFixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/atlas-provider-runtime-test-'.bin2hex(random_bytes(4));
        $this->operatorRoot = $base.'/home';
        $this->workspace = $this->operatorRoot.'/Develop/atlas';

        File::ensureDirectoryExists($this->workspace);
        File::ensureDirectoryExists($this->operatorRoot.'/Develop/Blackink');
        $this->operatorRoot = realpath($this->operatorRoot) ?: $this->operatorRoot;
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
        $this->attachmentFixtureRoot = storage_path('app/ai/attachments/unit-test-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->attachmentFixtureRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->attachmentFixtureRoot);
        File::deleteDirectory(dirname($this->operatorRoot));

        parent::tearDown();
    }

    public function test_claude_provider_maps_danger_runtime_to_bypass_permissions_and_allowed_roots(): void
    {
        $binary = $this->fakeClaudeBinary();

        config([
            'atlas.ai.providers.claude_cli.binary' => $binary,
            'atlas.ai.providers.claude_cli.args' => ['-p', '--output-format', 'stream-json', '--verbose', '--no-session-persistence'],
        ]);

        $result = app(ClaudeCliProvider::class)->runStreaming($this->job(), 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertContains('--permission-mode', $result->command);
        $this->assertSame('bypassPermissions', $result->command[array_search('--permission-mode', $result->command, true) + 1]);
        $this->assertContains('--add-dir', $result->command);
        $this->assertContains($this->operatorRoot, $result->command);
        $this->assertContains($this->workspace, $result->command);
    }

    public function test_hermes_provider_builds_governed_chat_invocation(): void
    {
        $binary = $this->fakeHermesBinary();

        config([
            'atlas.ai.providers.hermes_cli.binary' => $binary,
            'atlas.ai.providers.hermes_cli.args' => [
                'chat',
                '--quiet',
                '--query',
                'stale prompt',
                '--model',
                'old-model',
                '--provider',
                'old-provider',
                '--toolsets',
                'old-tools',
                '--skills',
                'old-skills',
                '--max-turns',
                '1',
                '--ignore-rules',
                '--yolo',
            ],
            'atlas.ai.providers.hermes_cli.accept_hooks' => true,
            'atlas.ai.providers.hermes_cli.checkpoints' => true,
        ]);

        $job = $this->job([
            'hermes' => [
                'provider' => 'openrouter',
                'toolsets' => 'shell,filesystem',
                'skills' => 'hermes-agent',
                'source' => 'tool',
                'max_turns' => 7,
                'memory_policy' => 'off',
                'schedule_policy' => 'off',
            ],
        ]);
        $job->provider = 'hermes_cli';
        $job->model = 'anthropic/claude-sonnet-4';

        $result = app(HermesCliProvider::class)->runStreaming($job, 'prompt secreto');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertContains('chat', $result->command);
        $this->assertContains('--quiet', $result->command);
        $this->assertContains('--query', $result->command);
        $this->assertSame('[prompt:redacted]', $result->command[array_search('--query', $result->command, true) + 1]);
        $this->assertSame('anthropic/claude-sonnet-4', $result->command[array_search('--model', $result->command, true) + 1]);
        $this->assertSame('openrouter', $result->command[array_search('--provider', $result->command, true) + 1]);
        $this->assertSame('shell,filesystem', $result->command[array_search('--toolsets', $result->command, true) + 1]);
        $this->assertSame('hermes-agent', $result->command[array_search('--skills', $result->command, true) + 1]);
        $this->assertSame('tool', $result->command[array_search('--source', $result->command, true) + 1]);
        $this->assertSame('7', $result->command[array_search('--max-turns', $result->command, true) + 1]);
        $this->assertContains('--accept-hooks', $result->command);
        $this->assertContains('--checkpoints', $result->command);
        $this->assertContains('--yolo', $result->command);
        $this->assertNotContains('stale prompt', $result->command);
        $this->assertNotContains('old-model', $result->command);
        $this->assertNotContains('old-provider', $result->command);
        $this->assertNotContains('old-tools', $result->command);
        $this->assertNotContains('old-skills', $result->command);
        $this->assertNotContains('--ignore-rules', $result->command);

        $fingerprint = data_get($result->metadata, 'cli_invocation');
        $this->assertIsArray($fingerprint);
        $this->assertSame('hermes_cli', $fingerprint['provider']);
        $this->assertSame('anthropic/claude-sonnet-4', $fingerprint['model']);
        $this->assertSame('Hermes fake 1.0', $fingerprint['binary_version']);
        $this->assertNotSame(hash('sha256', 'prompt secreto'), $fingerprint['prompt_hash']);
        $this->assertSame('executive_runtime', $fingerprint['runtime_role']);
        $this->assertSame('off', $fingerprint['memory_policy']);
        $this->assertSame('off', $fingerprint['schedule_policy']);
        $mission = data_get($result->metadata, 'executive_mission');
        $this->assertIsArray($mission);
        $this->assertSame('atlas.hermes.executive_mission.v1', $mission['schema_version']);
        $this->assertSame('atls', $mission['issued_by']);
        $this->assertSame(hash('sha256', 'prompt secreto'), data_get($mission, 'context_pack.prompt_hash'));
        $this->assertSame('atlas-hermes-coder', data_get($mission, 'runtime.profile'));
        $this->assertSame('off', data_get($mission, 'memory_policy.hermes_memory'));
        $this->assertFalse((bool) data_get($mission, 'memory_policy.promotion_allowed_now'));
        $this->assertTrue((bool) data_get($mission, 'sovereignty.atlas_is_sovereign'));
        $this->assertTrue((bool) data_get($mission, 'sovereignty.provider_is_executor_only'));
        $this->assertSame($mission['mission_hash'], data_get($fingerprint, 'executive_mission_hash'));

        $packet = data_get($result->metadata, 'hermes_result_packet');
        $this->assertIsArray($packet);
        $this->assertSame('atlas.hermes.result_packet.v1', $packet['schema_version']);
        $this->assertSame($mission['mission_id'], $packet['mission_id']);
        $this->assertSame($mission['mission_hash'], $packet['mission_hash']);
        $this->assertSame('succeeded', $packet['status']);
        $this->assertSame('atlas', data_get($packet, 'gateway.delivery_authority'));
        $this->assertFalse((bool) data_get($packet, 'memory_gate.promotion_allowed_now'));
        $this->assertSame(1, data_get($packet, 'memory_gate.candidate_count'));
        $this->assertSame('quarantined_for_atlas_review', data_get($packet, 'memory_gate.candidates.0.gate_status'));
        $this->assertFalse((bool) data_get($packet, 'memory_gate.candidates.0.promotion_allowed_now'));
        $this->assertSame(1, data_get($packet, 'procedure_gate.candidate_count'));
        $this->assertSame('SkillPackPromotionGate', data_get($packet, 'procedure_gate.promotion_gate'));
        $this->assertSame('quarantined_for_atlas_skill_review', data_get($packet, 'procedure_gate.candidates.0.gate_status'));
        $this->assertFalse((bool) data_get($packet, 'procedure_gate.candidates.0.promotion_allowed_now'));
        $this->assertSame(1, data_get($packet, 'schedule_gate.candidate_count'));
        $this->assertSame('ScheduledJobStopConditionGate', data_get($packet, 'schedule_gate.stop_condition_gate'));
        $this->assertSame('quarantined_for_atlas_schedule_review', data_get($packet, 'schedule_gate.candidates.0.gate_status'));
        $this->assertFalse((bool) data_get($packet, 'schedule_gate.candidates.0.activation_allowed_now'));

        $memoryAdapter = data_get($result->metadata, 'hermes_memory_adapter');
        $this->assertIsArray($memoryAdapter);
        $this->assertSame('atlas.hermes.memory_adapter_receipt.v1', $memoryAdapter['schema_version']);
        $this->assertSame('skipped_by_policy', $memoryAdapter['status']);
        $this->assertSame(1, $memoryAdapter['candidate_count']);
        $this->assertSame(0, $memoryAdapter['persisted_count']);
        $this->assertSame(1, $memoryAdapter['skipped_count']);
        $this->assertFalse((bool) $memoryAdapter['promotion_allowed_now']);

        $scheduleAdapter = data_get($result->metadata, 'hermes_schedule_adapter');
        $this->assertIsArray($scheduleAdapter);
        $this->assertSame('atlas.hermes.schedule_adapter_receipt.v1', $scheduleAdapter['schema_version']);
        $this->assertSame('skipped_by_policy', $scheduleAdapter['status']);
        $this->assertSame(1, $scheduleAdapter['candidate_count']);
        $this->assertSame(0, $scheduleAdapter['persisted_count']);
        $this->assertSame(1, $scheduleAdapter['skipped_count']);
        $this->assertFalse((bool) $scheduleAdapter['activation_allowed_now']);

        $this->assertSame('executive_runtime', data_get($result->metadata, 'hermes_runtime.role'));
        $this->assertTrue((bool) data_get($result->metadata, 'hermes_runtime.atlas_is_sovereign'));
        $this->assertSame($mission['mission_hash'], data_get($result->metadata, 'hermes_runtime.executive_mission_hash'));
        $this->assertSame($packet['result_hash'], data_get($result->metadata, 'hermes_runtime.result_packet_hash'));
        $this->assertSame(1, data_get($result->metadata, 'hermes_runtime.memory_delta_candidate_count'));
        $this->assertSame('skipped_by_policy', data_get($result->metadata, 'hermes_runtime.memory_adapter_status'));
        $this->assertSame(0, data_get($result->metadata, 'hermes_runtime.memory_adapter_persisted_count'));
        $this->assertSame(1, data_get($result->metadata, 'hermes_runtime.procedure_candidate_count'));
        $this->assertSame(1, data_get($result->metadata, 'hermes_runtime.schedule_candidate_count'));
        $this->assertSame('skipped_by_policy', data_get($result->metadata, 'hermes_runtime.schedule_adapter_status'));
        $this->assertSame(0, data_get($result->metadata, 'hermes_runtime.schedule_adapter_persisted_count'));

        $procedureAdapter = data_get($result->metadata, 'hermes_procedure_adapter');
        $this->assertIsArray($procedureAdapter);
        $this->assertSame('atlas.hermes.procedure_adapter_receipt.v1', $procedureAdapter['schema_version']);
        $this->assertSame('skipped_by_policy', $procedureAdapter['status']);
        $this->assertSame(1, $procedureAdapter['candidate_count']);
        $this->assertSame(0, $procedureAdapter['persisted_count']);
        $this->assertFalse((bool) $procedureAdapter['promotion_allowed_now']);

        $gatewayAdapter = data_get($result->metadata, 'hermes_gateway_adapter');
        $this->assertIsArray($gatewayAdapter);
        $this->assertSame('atlas.hermes.gateway_adapter_receipt.v1', $gatewayAdapter['schema_version']);
        $this->assertSame('no_gateway_ingress', $gatewayAdapter['status']);
        $this->assertSame('atlas', $gatewayAdapter['delivery_authority']);
        $this->assertFalse((bool) $gatewayAdapter['hermes_gateway_can_decide']);
        $this->assertFalse((bool) $gatewayAdapter['delivery_allowed_now']);
        $this->assertNotEmpty($gatewayAdapter['receipt_hash']);

        $runtimeRouter = data_get($result->metadata, 'hermes_runtime_router');
        $this->assertIsArray($runtimeRouter);
        $this->assertSame('atlas.hermes.runtime_router.v1', $runtimeRouter['schema_version']);
        $this->assertSame('executive_runtime', $runtimeRouter['runtime_role']);

        $this->assertSame('skipped_by_policy', data_get($result->metadata, 'hermes_runtime.procedure_adapter_status'));
        $this->assertSame('no_gateway_ingress', data_get($result->metadata, 'hermes_runtime.gateway_adapter_status'));
        $this->assertSame('atlas', data_get($result->metadata, 'hermes_runtime.gateway_delivery_authority'));
        $this->assertFalse((bool) data_get($result->metadata, 'hermes_runtime.gateway_delivery_allowed_now'));
    }

    public function test_hermes_memory_adapter_persists_candidates_for_atlas_review(): void
    {
        Schema::dropIfExists('ai_memory_deltas');
        $this->createMemoryDeltaTable();

        try {
            $binary = $this->fakeHermesBinary();

            config([
                'atlas.ai.providers.hermes_cli.binary' => $binary,
                'atlas.ai.providers.hermes_cli.args' => ['chat', '--quiet'],
            ]);

            $job = $this->job([
                'hermes' => [
                    'source' => 'tool',
                    'memory_policy' => 'atlas_adapter',
                ],
                'tool_permissions' => [
                    'mode' => 'read',
                    'workspace' => $this->workspace,
                    'allowed_roots' => [$this->workspace],
                ],
            ]);
            $job->provider = 'hermes_cli';

            $result = app(HermesCliProvider::class)->runStreaming($job, 'capture reusable Hermes learning');

            $this->assertTrue($result->ok, $result->errorMessage ?? '');
            $receipt = data_get($result->metadata, 'hermes_memory_adapter');
            $this->assertIsArray($receipt);
            $this->assertSame('persisted_for_review', $receipt['status']);
            $this->assertSame('atlas_adapter', $receipt['memory_policy']);
            $this->assertSame(1, $receipt['candidate_count']);
            $this->assertSame(1, $receipt['eligible_candidate_count']);
            $this->assertSame(1, $receipt['persisted_count']);
            $this->assertSame(0, $receipt['duplicate_count']);
            $this->assertFalse((bool) $receipt['promotion_allowed_now']);
            $this->assertNotEmpty($receipt['receipt_hash']);

            $delta = AiMemoryDelta::query()->first();
            $this->assertInstanceOf(AiMemoryDelta::class, $delta);
            $this->assertSame('pending', $delta->status);
            $this->assertSame('process', $delta->type);
            $this->assertSame('Use Hermes as an ATLS-governed executor only.', $delta->claim);
            $this->assertSame('workspace:'.$this->workspace, $delta->scope);
            $this->assertSame($this->workspace, $delta->source_workspace);
            $this->assertSame(0.82, $delta->confidence);
            $this->assertTrue((bool) $delta->requires_confirmation);
            $this->assertSame('hermes_result_packet', data_get($delta->evidence, '1.kind'));
            $this->assertFalse((bool) data_get($delta->evidence, '1.promotion_allowed_now'));
            $this->assertSame($delta->id, data_get($receipt, 'persisted_delta_ids.0'));
            $this->assertSame('persisted_for_review', data_get($result->metadata, 'hermes_runtime.memory_adapter_status'));
            $this->assertSame(1, data_get($result->metadata, 'hermes_runtime.memory_adapter_persisted_count'));

            $again = app(HermesCliProvider::class)->runStreaming($job, 'capture reusable Hermes learning');
            $againReceipt = data_get($again->metadata, 'hermes_memory_adapter');
            $this->assertSame('deduplicated', data_get($againReceipt, 'status'));
            $this->assertSame(0, data_get($againReceipt, 'persisted_count'));
            $this->assertSame(1, data_get($againReceipt, 'duplicate_count'));
            $this->assertSame($delta->id, data_get($againReceipt, 'duplicate_delta_ids.0'));
            $this->assertSame(1, AiMemoryDelta::query()->count());
        } finally {
            Schema::dropIfExists('ai_memory_deltas');
        }
    }

    public function test_hermes_schedule_adapter_persists_candidates_for_atlas_review(): void
    {
        Schema::dropIfExists('ai_scheduled_tasks');
        $this->createScheduledTasksTable();

        try {
            $binary = $this->fakeHermesBinary();

            config([
                'atlas.ai.providers.hermes_cli.binary' => $binary,
                'atlas.ai.providers.hermes_cli.args' => ['chat', '--quiet'],
            ]);

            $job = $this->job([
                'hermes' => [
                    'source' => 'tool',
                    'schedule_policy' => 'atlas_adapter',
                ],
                'tool_permissions' => [
                    'mode' => 'read',
                    'workspace' => $this->workspace,
                    'allowed_roots' => [$this->workspace],
                ],
            ]);
            $job->provider = 'hermes_cli';

            $result = app(HermesCliProvider::class)->runStreaming($job, 'capture reusable Hermes schedule');

            $this->assertTrue($result->ok, $result->errorMessage ?? '');
            $receipt = data_get($result->metadata, 'hermes_schedule_adapter');
            $this->assertIsArray($receipt);
            $this->assertSame('persisted_for_review', $receipt['status']);
            $this->assertSame('atlas_adapter', $receipt['schedule_policy']);
            $this->assertSame(1, $receipt['candidate_count']);
            $this->assertSame(1, $receipt['eligible_candidate_count']);
            $this->assertSame(1, $receipt['persisted_count']);
            $this->assertSame(0, $receipt['duplicate_count']);
            $this->assertFalse((bool) $receipt['activation_allowed_now']);
            $this->assertNotEmpty($receipt['receipt_hash']);

            $task = AiScheduledTask::query()->first();
            $this->assertInstanceOf(AiScheduledTask::class, $task);
            $this->assertSame('Hermes weekly gateway health review', $task->title);
            $this->assertSame('Review Hermes gateway health and report evidence to ATLS.', $task->prompt);
            $this->assertSame('candidate:cron:0 9 * * 1', $task->schedule);
            $this->assertSame('candidate', $task->kind);
            $this->assertFalse((bool) $task->enabled);
            $this->assertNull($task->next_run_at);
            $this->assertSame($this->workspace, $task->workspace);
            $this->assertSame('pending', data_get($task->metadata, 'review_status'));
            $this->assertFalse((bool) data_get($task->metadata, 'activation_allowed_now'));
            $this->assertTrue((bool) data_get($task->metadata, 'activation_requires_atlas_schedule_gate'));
            $this->assertTrue((bool) data_get($task->metadata, 'direct_resume_blocked_by_candidate_schedule'));
            $this->assertSame('ScheduledJobStopConditionGate', data_get($task->metadata, 'stop_condition_gate'));
            $this->assertSame('valid_for_review', data_get($task->metadata, 'schedule_parse.status'));
            $this->assertSame('cron', data_get($task->metadata, 'schedule_parse.kind'));
            $this->assertSame('hermes_schedule_candidate_', substr((string) data_get($task->metadata, 'hermes_schedule_candidate.candidate_id'), 0, 26));
            $this->assertSame('persisted_for_atlas_schedule_review', data_get($task->metadata, 'hermes_schedule_candidate.gate_status'));
            $this->assertSame('operator pauses schedule', data_get($task->metadata, 'hermes_schedule_candidate.stop_conditions.1'));
            $this->assertSame('hermes_schedule_adapter', data_get($task->metadata, 'created_by'));
            $this->assertSame($task->id, data_get($receipt, 'persisted_task_ids.0'));
            $this->assertSame('persisted_for_review', data_get($result->metadata, 'hermes_runtime.schedule_adapter_status'));
            $this->assertSame(1, data_get($result->metadata, 'hermes_runtime.schedule_adapter_persisted_count'));

            $again = app(HermesCliProvider::class)->runStreaming($job, 'capture reusable Hermes schedule');
            $againReceipt = data_get($again->metadata, 'hermes_schedule_adapter');
            $this->assertSame('deduplicated', data_get($againReceipt, 'status'));
            $this->assertSame(0, data_get($againReceipt, 'persisted_count'));
            $this->assertSame(1, data_get($againReceipt, 'duplicate_count'));
            $this->assertSame($task->id, data_get($againReceipt, 'duplicate_task_ids.0'));
            $this->assertSame(1, AiScheduledTask::query()->count());
        } finally {
            Schema::dropIfExists('ai_scheduled_tasks');
        }
    }

    public function test_claude_provider_ignores_stale_model_permission_and_add_dir_args_from_config(): void
    {
        $binary = $this->fakeClaudeBinary();

        config([
            'atlas.ai.providers.claude_cli.binary' => $binary,
            'atlas.ai.providers.claude_cli.args' => [
                '-p',
                '--model',
                'old-claude',
                '--model=other-claude',
                '--permission-mode',
                'plan',
                '--permission-mode=default',
                '--add-dir',
                '/tmp/outside-one',
                '/tmp/outside-two',
                '--no-session-persistence',
                '--dangerously-skip-permissions=true',
            ],
        ]);

        $job = $this->job();
        $job->model = 'claude-opus-4-7';
        $result = app(ClaudeCliProvider::class)->runStreaming($job, 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertSame(1, collect($result->command)->filter(fn (mixed $arg): bool => $arg === '--model')->count());
        $this->assertSame('claude-opus-4-7', $result->command[array_search('--model', $result->command, true) + 1]);
        $this->assertSame(1, collect($result->command)->filter(fn (mixed $arg): bool => $arg === '--permission-mode')->count());
        $this->assertSame('bypassPermissions', $result->command[array_search('--permission-mode', $result->command, true) + 1]);
        $this->assertNotContains('old-claude', $result->command);
        $this->assertNotContains('other-claude', $result->command);
        $this->assertNotContains('plan', $result->command);
        $this->assertNotContains('/tmp/outside-one', $result->command);
        $this->assertNotContains('/tmp/outside-two', $result->command);
        $this->assertNotContains('--dangerously-skip-permissions=true', $result->command);
    }

    public function test_claude_provider_records_invocation_fingerprint(): void
    {
        $binary = $this->fakeClaudeBinary();

        config([
            'atlas.ai.providers.claude_cli.binary' => $binary,
            'atlas.ai.providers.claude_cli.args' => ['-p', '--output-format', 'stream-json', '--verbose', '--no-session-persistence'],
        ]);

        $job = $this->job([
            'fair_mode' => ['fair_mode' => true],
            'dev_execution_plan' => [
                'plan_id' => 'plan_test',
                'fair_mode' => ['fair_mode' => true],
            ],
            'context_pack' => ['context_pack_hash' => 'ctx_test'],
        ]);
        $job->provider = 'claude_cli';
        $job->model = 'claude-opus-4-7';

        $result = app(ClaudeCliProvider::class)->runStreaming($job, 'teste');

        $fingerprint = data_get($result->metadata, 'claude_invocation_fingerprint');
        $this->assertIsArray($fingerprint);
        $this->assertSame('claude_cli', $fingerprint['provider']);
        $this->assertSame('claude-opus-4-7', $fingerprint['model']);
        $this->assertSame('Claude Code fake 1.0', $fingerprint['binary_version']);
        $this->assertSame('stream-json', $fingerprint['output_format']);
        $this->assertSame('bypassPermissions', $fingerprint['permission_mode']);
        $this->assertSame('ctx_test', $fingerprint['context_pack_hash']);
        $this->assertSame('plan_test', $fingerprint['dev_plan_id']);
        $this->assertTrue($fingerprint['fair_mode']);
        $this->assertSame(hash('sha256', 'teste'), $fingerprint['prompt_hash']);
    }

    public function test_claude_provider_maps_atlas_compute_effort_to_cli_effort(): void
    {
        $binary = $this->fakeClaudeBinary();

        config([
            'atlas.ai.providers.claude_cli.binary' => $binary,
            'atlas.ai.providers.claude_cli.args' => ['-p', '--effort', 'low'],
        ]);

        $result = app(ClaudeCliProvider::class)->runStreaming($this->job([
            'compute_effort' => 'max',
        ]), 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertSame(1, collect($result->command)->filter(fn (mixed $arg): bool => $arg === '--effort')->count());
        $this->assertSame('max', $result->command[array_search('--effort', $result->command, true) + 1]);
        $this->assertSame('max', data_get($result->metadata, 'claude_invocation_fingerprint.compute_effort.atlas_level'));
    }

    public function test_codex_provider_maps_danger_runtime_to_full_access_never_approval_and_allowed_roots(): void
    {
        $binary = $this->fakeCodexBinary();

        config([
            'atlas.ai.providers.codex_cli.binary' => $binary,
            'atlas.ai.providers.codex_cli.args' => ['exec', '--skip-git-repo-check'],
        ]);

        $result = app(CodexCliProvider::class)->runStreaming($this->job(), 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertContains('--sandbox', $result->command);
        $this->assertSame('danger-full-access', $result->command[array_search('--sandbox', $result->command, true) + 1]);
        $this->assertContains('--dangerously-bypass-approvals-and-sandbox', $result->command);
        $this->assertNotContains('--ask-for-approval', $result->command);
        $this->assertContains('--add-dir', $result->command);
        $this->assertContains($this->operatorRoot, $result->command);
        $this->assertContains($this->workspace, $result->command);
    }

    public function test_codex_provider_ignores_stale_approval_args_from_config(): void
    {
        $binary = $this->fakeCodexBinary();

        config([
            'atlas.ai.providers.codex_cli.binary' => $binary,
            'atlas.ai.providers.codex_cli.args' => [
                'exec',
                '--skip-git-repo-check',
                '--ask-for-approval',
                'never',
                '--ask-for-approval=never',
                '--full-auto',
            ],
        ]);

        $result = app(CodexCliProvider::class)->runStreaming($this->job(), 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertNotContains('--ask-for-approval', $result->command);
        $this->assertNotContains('--ask-for-approval=never', $result->command);
        $this->assertNotContains('--full-auto', $result->command);
        $this->assertContains('--dangerously-bypass-approvals-and-sandbox', $result->command);
    }

    public function test_codex_provider_ignores_stale_model_sandbox_output_and_image_args_from_config(): void
    {
        $binary = $this->fakeCodexBinary();

        config([
            'atlas.ai.providers.codex_cli.binary' => $binary,
            'atlas.ai.providers.codex_cli.args' => [
                'exec',
                '--skip-git-repo-check',
                '--sandbox',
                'read-only',
                '--sandbox=workspace-write',
                '--model',
                'old-codex',
                '--model=other-codex',
                '--output-last-message',
                '/tmp/old-last-message.txt',
                '--image',
                '/tmp/old-image.png',
                '--full-auto=false',
            ],
        ]);

        $job = $this->job();
        $job->model = 'gpt-5.5';
        $result = app(CodexCliProvider::class)->runStreaming($job, 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertSame(1, collect($result->command)->filter(fn (mixed $arg): bool => $arg === '--sandbox')->count());
        $this->assertSame('danger-full-access', $result->command[array_search('--sandbox', $result->command, true) + 1]);
        $this->assertSame(1, collect($result->command)->filter(fn (mixed $arg): bool => $arg === '--model')->count());
        $this->assertSame('gpt-5.5', $result->command[array_search('--model', $result->command, true) + 1]);
        $this->assertSame(1, collect($result->command)->filter(fn (mixed $arg): bool => $arg === '--output-last-message')->count());
        $this->assertNotContains('old-codex', $result->command);
        $this->assertNotContains('other-codex', $result->command);
        $this->assertNotContains('read-only', $result->command);
        $this->assertNotContains('workspace-write', $result->command);
        $this->assertNotContains('/tmp/old-last-message.txt', $result->command);
        $this->assertNotContains('/tmp/old-image.png', $result->command);
        $this->assertNotContains('--full-auto=false', $result->command);
    }

    public function test_codex_provider_maps_atlas_compute_effort_to_reasoning_config_override(): void
    {
        $binary = $this->fakeCodexBinary();

        config([
            'atlas.ai.providers.codex_cli.binary' => $binary,
            'atlas.ai.providers.codex_cli.args' => ['exec', '--skip-git-repo-check'],
        ]);

        $result = app(CodexCliProvider::class)->runStreaming($this->job([
            'compute_effort' => 'deep',
        ]), 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertContains('-c', $result->command);
        $this->assertContains('model_reasoning_effort="high"', $result->command);
        $this->assertSame('deep', data_get($result->metadata, 'compute_effort.atlas_level'));
    }

    public function test_codex_provider_forwards_image_attachments_to_cli(): void
    {
        $binary = $this->fakeCodexBinary();
        $image = $this->workspace.'/screenshot.png';
        File::put($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));

        config([
            'atlas.ai.providers.codex_cli.binary' => $binary,
            'atlas.ai.providers.codex_cli.args' => ['exec', '--skip-git-repo-check'],
        ]);

        $result = app(CodexCliProvider::class)->runStreaming($this->job([
            'attachments' => [
                'images' => [
                    ['path' => $image],
                ],
            ],
        ]), 'analise a imagem');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertContains('--image', $result->command);
        $this->assertSame($image, $result->command[array_search('--image', $result->command, true) + 1]);
    }

    public function test_codex_provider_maps_container_storage_image_path_to_local_storage(): void
    {
        $binary = $this->fakeCodexBinary();
        $image = $this->attachmentFixtureRoot.'/mobile-upload.png';
        File::put($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));

        config([
            'atlas.ai.providers.codex_cli.binary' => $binary,
            'atlas.ai.providers.codex_cli.args' => ['exec', '--skip-git-repo-check'],
        ]);

        $containerPath = '/app/storage/'.str_replace('\\', '/', ltrim(str_replace(storage_path(), '', $image), DIRECTORY_SEPARATOR));
        $result = app(CodexCliProvider::class)->runStreaming($this->job([
            'attachments' => [
                'images' => [
                    ['path' => $containerPath],
                ],
            ],
        ]), 'analise a imagem');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertContains('--image', $result->command);
        $this->assertSame(realpath($image), $result->command[array_search('--image', $result->command, true) + 1]);
        $this->assertContains('--add-dir', $result->command);
        $this->assertContains(realpath(dirname($image)), $result->command);
    }

    public function test_codex_provider_exposes_text_file_attachment_paths_to_prompt(): void
    {
        $capturePrompt = $this->attachmentFixtureRoot.'/codex-prompt.txt';
        $binary = $this->fakeCodexBinary($capturePrompt);
        $document = $this->attachmentFixtureRoot.'/atlas-long-message.md';
        File::put($document, "# Mensagem longa\n\nConteudo completo preservado.");

        config([
            'atlas.ai.providers.codex_cli.binary' => $binary,
            'atlas.ai.providers.codex_cli.args' => ['exec', '--skip-git-repo-check'],
        ]);

        $containerPath = '/app/storage/'.str_replace('\\', '/', ltrim(str_replace(storage_path(), '', $document), DIRECTORY_SEPARATOR));
        $result = app(CodexCliProvider::class)->runStreaming($this->job([
            'attachments' => [
                'files' => [
                    [
                        'path' => $containerPath,
                        'original_name' => 'atlas-long-message.md',
                        'mime_type' => 'text/markdown',
                        'bytes' => File::size($document),
                    ],
                ],
            ],
        ]), 'responda a mensagem longa');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertContains('--add-dir', $result->command);
        $this->assertContains(realpath(dirname($document)), $result->command);
        $prompt = File::get($capturePrompt);
        $this->assertStringContainsString('Acesso local aos arquivos anexados para Codex', $prompt);
        $this->assertStringContainsString('atlas-long-message.md', $prompt);
        $this->assertStringContainsString(realpath($document), $prompt);
    }

    public function test_provider_default_model_identity_is_not_sent_as_cli_model_argument(): void
    {
        $binary = $this->fakeClaudeBinary();

        config([
            'atlas.ai.providers.claude_cli.binary' => $binary,
            'atlas.ai.providers.claude_cli.args' => ['-p'],
        ]);

        $job = $this->job([
            'model_identity_source' => 'provider_default_identity',
        ]);
        $job->model = 'claude_cli_default';

        $result = app(ClaudeCliProvider::class)->runStreaming($job, 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertNotContains('--model', $result->command);
    }

    public function test_gemini_provider_uses_resolved_flash_model_and_stdin_prompt(): void
    {
        $binary = $this->fakeGeminiBinary();
        $policy = dirname($this->operatorRoot).'/gemini-policy.toml';
        File::put($policy, 'policy');

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
            'atlas.ai.providers.gemini_cli.home' => dirname($this->operatorRoot).'/gemini-home',
            'atlas.ai.providers.gemini_cli.admin_policy' => $policy,
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job([
            'tool_permissions' => [
                'mode' => 'read',
                'workspace' => $this->workspace,
                'allowed_roots' => [$this->workspace],
            ],
        ]), 'prompt secreto');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertContains('--model', $result->command);
        $this->assertSame('gemini-3.5-flash', $result->command[array_search('--model', $result->command, true) + 1]);
        $this->assertContains('--approval-mode=yolo', $result->command);
        $this->assertContains('--skip-trust', $result->command);
        $this->assertNotContains('--approval-mode', $result->command);
        $this->assertNotContains('--yolo', $result->command);
        $this->assertNotContains('--sandbox', $result->command);
        $this->assertNotContains('--admin-policy', $result->command);
        $this->assertNotContains('prompt secreto', $result->command);
        $this->assertSame('gemini_flash', $result->metadata['selected_model_alias']);
        $this->assertSame(['gemini-3.5-flash'], $result->metadata['observed_models']);
    }

    public function test_gemini_provider_uses_pro_alias_for_deep_compute_effort(): void
    {
        $binary = $this->fakeGeminiBinary('gemini-3.1-pro-preview');

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job([
            'compute_effort' => ['atlas_level' => 'deep'],
        ]), 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertSame('gemini-3.1-pro-preview', $result->command[array_search('--model', $result->command, true) + 1]);
        $this->assertSame('gemini_pro', $result->metadata['selected_model_alias']);
        $this->assertSame('atlas_decide', $result->metadata['selection_source']);
    }

    public function test_gemini_provider_fails_closed_for_unknown_manual_model_alias(): void
    {
        $binary = $this->fakeGeminiBinary();

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
        ]);

        $job = $this->job([
            'selected_model_alias' => 'gemini_ultra',
        ]);
        $job->model = 'gemini_ultra';

        $result = app(GeminiCliProvider::class)->runStreaming($job, 'teste');

        $this->assertFalse($result->ok);
        $this->assertSame('model_not_allowed', $result->errorCode);
        $this->assertSame([], $result->command);
        $this->assertSame('gemini_model_not_allowed', data_get($result->metadata, 'policy_violation'));
    }

    public function test_gemini_provider_records_compute_effort_as_observed_only_until_sdk_driver(): void
    {
        $binary = $this->fakeGeminiBinary();

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job([
            'compute_effort' => 'max',
        ]), 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertSame('max', data_get($result->metadata, 'compute_effort.atlas_level'));
        $this->assertSame('observed_only_until_gemini_sdk_driver', data_get($result->metadata, 'compute_effort.provider_mapping.control_status'));
        $this->assertNotContains('thinkingBudget', $result->command);
    }

    public function test_gemini_provider_rejects_model_downgrade_from_cli_output(): void
    {
        $binary = $this->fakeGeminiBinary('gemini-2.5-pro');
        $policy = $this->fakeGeminiPolicy();

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
            'atlas.ai.providers.gemini_cli.home' => dirname($this->operatorRoot).'/gemini-home',
            'atlas.ai.providers.gemini_cli.admin_policy' => $policy,
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job([
            'tool_permissions' => [
                'mode' => 'read',
                'workspace' => $this->workspace,
                'allowed_roots' => [$this->workspace],
            ],
        ]), 'teste');

        $this->assertFalse($result->ok);
        $this->assertSame('policy_violation', $result->errorCode);
        $this->assertSame('claude_cli', $result->metadata['fallback_provider']);
    }

    public function test_gemini_provider_does_not_require_admin_policy(): void
    {
        $binary = $this->fakeGeminiBinary();

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
            'atlas.ai.providers.gemini_cli.admin_policy' => dirname($this->operatorRoot).'/missing-policy.toml',
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job([
            'tool_permissions' => [
                'mode' => 'read',
                'workspace' => $this->workspace,
                'allowed_roots' => [$this->workspace],
            ],
        ]), 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertNotContains('--admin-policy', $result->command);
    }

    public function test_gemini_health_does_not_require_admin_policy(): void
    {
        $binary = $this->fakeGeminiBinary();

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.admin_policy' => dirname($this->operatorRoot).'/missing-policy.toml',
        ]);

        $health = app(GeminiCliProvider::class)->health();

        $this->assertSame('online', $health->status);
        $this->assertSame('native_yolo', $health->metadata['runtime_policy']);
        $this->assertSame('passed', data_get($health->metadata, 'runtime_contract.status'));
        $this->assertSame('gemini_cli_provider.v1', data_get($health->metadata, 'runtime_contract.name'));
    }

    public function test_provider_health_validates_runtime_contracts_for_all_cli_providers(): void
    {
        config([
            'atlas.ai.providers.claude_cli.binary' => $this->fakeClaudeBinary(),
            'atlas.ai.providers.codex_cli.binary' => $this->fakeCodexBinary(),
            'atlas.ai.providers.gemini_cli.binary' => $this->fakeGeminiBinary(),
        ]);

        $claude = app(ClaudeCliProvider::class)->health();
        $codex = app(CodexCliProvider::class)->health();
        $gemini = app(GeminiCliProvider::class)->health();

        $this->assertSame('online', $claude->status);
        $this->assertSame('claude_cli_provider.v1', data_get($claude->metadata, 'runtime_contract.name'));
        $this->assertSame('passed', data_get($claude->metadata, 'runtime_contract.status'));
        $this->assertSame([], data_get($claude->metadata, 'runtime_contract.missing_tokens'));

        $this->assertSame('online', $codex->status);
        $this->assertSame('codex_cli_provider.v1', data_get($codex->metadata, 'runtime_contract.name'));
        $this->assertSame('passed', data_get($codex->metadata, 'runtime_contract.status'));
        $this->assertSame([], data_get($codex->metadata, 'runtime_contract.missing_tokens'));

        $this->assertSame('online', $gemini->status);
        $this->assertSame('gemini_cli_provider.v1', data_get($gemini->metadata, 'runtime_contract.name'));
        $this->assertSame('passed', data_get($gemini->metadata, 'runtime_contract.status'));
        $this->assertSame([], data_get($gemini->metadata, 'runtime_contract.missing_tokens'));
    }

    public function test_provider_health_degrades_when_runtime_contract_is_missing_required_flag(): void
    {
        $binary = $this->fakeExecutable('gemini', <<<'SH'
#!/usr/bin/env bash
if [ "$1" = "--version" ]; then
  printf 'gemini fake 1.0'
  exit 0
fi
if [ "$1" = "--help" ]; then
  printf '%s\n' 'Usage: gemini --model --prompt --output-format stream-json --skip-trust --include-directories'
  exit 0
fi
cat >/dev/null
printf '{"type":"result","response":"ok","stats":{"models":["gemini-3.5-flash"]}}'
SH);

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
        ]);

        $health = app(GeminiCliProvider::class)->health();

        $this->assertSame('degraded', $health->status);
        $this->assertSame('missing_required_tokens', data_get($health->metadata, 'runtime_contract.status'));
        $this->assertContains('--approval-mode', data_get($health->metadata, 'runtime_contract.missing_tokens'));
    }

    public function test_gemini_provider_allows_output_without_model_attestation_for_measurement(): void
    {
        $binary = $this->fakeExecutable('gemini', <<<'SH'
#!/usr/bin/env bash
cat >/dev/null
printf '{"type":"result","response":"ok"}'
SH);
        $policy = $this->fakeGeminiPolicy();

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
            'atlas.ai.providers.gemini_cli.admin_policy' => $policy,
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job([
            'tool_permissions' => [
                'mode' => 'read',
                'workspace' => $this->workspace,
                'allowed_roots' => [$this->workspace],
            ],
        ]), 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertSame([], $result->metadata['observed_models']);
    }

    public function test_gemini_provider_ignores_user_role_events_in_stream_json_output(): void
    {
        $binary = $this->fakeExecutable('gemini', <<<'SH'
#!/usr/bin/env bash
cat >/dev/null
printf '{"type":"message","role":"user","content":"prompt secreto"}\n'
printf '{"type":"message","role":"assistant","content":"resposta limpa","delta":true}\n'
printf '{"type":"result","status":"success","stats":{"models":["gemini-3.5-flash"]}}\n'
SH);
        $policy = $this->fakeGeminiPolicy();

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
            'atlas.ai.providers.gemini_cli.admin_policy' => $policy,
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job(), 'prompt secreto');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertSame('resposta limpa', $result->output);
        $this->assertStringNotContainsString('prompt secreto', $result->output);
    }

    public function test_gemini_provider_classifies_admin_policy_denial_as_policy_violation(): void
    {
        $binary = $this->fakeExecutable('gemini', <<<'SH'
#!/usr/bin/env bash
cat >/dev/null
printf 'admin policy denied tool read_many_files\n' >&2
exit 1
SH);
        $policy = $this->fakeGeminiPolicy();

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
            'atlas.ai.providers.gemini_cli.admin_policy' => $policy,
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job([
            'tool_permissions' => [
                'mode' => 'read',
                'workspace' => $this->workspace,
                'allowed_roots' => [$this->workspace],
            ],
        ]), 'teste');

        $this->assertFalse($result->ok);
        $this->assertSame('policy_violation', $result->errorCode);
    }

    public function test_gemini_provider_aborts_interactive_auth_prompt_for_claude_fallback(): void
    {
        $binary = $this->fakeExecutable('gemini', <<<'SH'
#!/usr/bin/env bash
printf 'Opening authentication page in your browser. Do you want to continue? [Y/n]: '
sleep 10
SH);
        $policy = $this->fakeGeminiPolicy();

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
            'atlas.ai.providers.gemini_cli.admin_policy' => $policy,
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job([
            'tool_permissions' => [
                'mode' => 'read',
                'workspace' => $this->workspace,
                'allowed_roots' => [$this->workspace],
            ],
        ]), 'teste');

        $this->assertFalse($result->ok);
        $this->assertSame('auth_expired', $result->errorCode);
        $this->assertLessThan(5000, $result->durationMs);
    }

    public function test_gemini_provider_exposes_attachment_paths_for_read_file_without_putting_prompt_in_command(): void
    {
        $capturedPrompt = dirname($this->operatorRoot).'/gemini-stdin.txt';
        $binary = $this->fakeGeminiBinary(capturePromptPath: $capturedPrompt);
        $policy = $this->fakeGeminiPolicy();
        $image = $this->attachmentFixtureRoot.'/screenshot.png';
        $pageDir = $this->attachmentFixtureRoot.'/pdf-pages';
        File::ensureDirectoryExists($pageDir);
        $page = $pageDir.'/page-1.png';
        $document = $this->attachmentFixtureRoot.'/brief.pdf';
        File::put($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
        File::put($page, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
        File::put($document, '%PDF-1.4');

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
            'atlas.ai.providers.gemini_cli.home' => null,
            'atlas.ai.providers.gemini_cli.admin_policy' => $policy,
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job([
            'tool_permissions' => [
                'mode' => 'read',
                'workspace' => $this->workspace,
                'allowed_roots' => [$this->workspace],
            ],
            'attachments' => [
                'images' => [
                    ['path' => $image, 'mime_type' => 'image/png'],
                ],
                'files' => [
                    [
                        'path' => $document,
                        'original_name' => 'brief.pdf',
                        'mime_type' => 'application/pdf',
                        'pdf_rendered_pages' => [
                            ['path' => $page, 'page' => 1],
                        ],
                    ],
                ],
            ],
        ]), 'analise os anexos');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertContains('--include-directories', $result->command);
        $includeDir = $result->command[array_search('--include-directories', $result->command, true) + 1];
        $this->assertStringContainsString(storage_path('app/ai/gemini-attachments'), $includeDir);
        $this->assertNotSame(realpath(storage_path('app/ai/attachments')), $includeDir);
        $this->assertNotContains('analise os anexos', $result->command);

        $prompt = File::get($capturedPrompt);
        $this->assertStringContainsString('Acesso local aos anexos para Gemini', $prompt);
        $this->assertStringContainsString('attachment-01.png', $prompt);
        $this->assertStringContainsString('brief.pdf', $prompt);
        $this->assertStringContainsString('gemini-attachments', $prompt);
        $this->assertStringNotContainsString($this->attachmentFixtureRoot, $prompt);
        $this->assertStringContainsString('read_file', $prompt);
    }

    public function test_gemini_provider_ignores_unsafe_configured_args(): void
    {
        $binary = $this->fakeGeminiBinary();
        $policy = $this->fakeGeminiPolicy();

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [
                '--model', 'gemini-2.5-pro',
                '--prompt', 'inline prompt',
                '--admin-policy', '/tmp/other-policy.toml',
                '--include-directories', '/',
                '--approval-mode=yolo',
                '--approval-mode', 'plan',
                '--no-sandbox',
                '--sandbox=true',
                '--sandbox', 'false',
                '-s', 'true',
                '--yolo',
                '--yolo=true',
                '-y', 'false',
                '--skip-trust=false',
            ],
            'atlas.ai.providers.gemini_cli.admin_policy' => $policy,
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job([
            'tool_permissions' => [
                'mode' => 'read',
                'workspace' => $this->workspace,
                'allowed_roots' => [$this->workspace],
            ],
        ]), 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertSame('gemini-3.5-flash', $result->command[array_search('--model', $result->command, true) + 1]);
        $this->assertSame('', $result->command[array_search('--prompt', $result->command, true) + 1]);
        $this->assertContains('--approval-mode=yolo', $result->command);
        $this->assertContains('--skip-trust', $result->command);
        $this->assertNotContains('--approval-mode', $result->command);
        $this->assertNotContains('--admin-policy', $result->command);
        $this->assertNotContains('gemini-2.5-pro', $result->command);
        $this->assertNotContains('inline prompt', $result->command);
        $this->assertNotContains('plan', $result->command);
        $this->assertNotContains('/tmp/other-policy.toml', $result->command);
        $this->assertNotContains('--include-directories', $result->command);
        $this->assertNotContains('--no-sandbox', $result->command);
        $this->assertNotContains('--sandbox=true', $result->command);
        $this->assertNotContains('--sandbox', $result->command);
        $this->assertNotContains('-s', $result->command);
        $this->assertNotContains('--yolo', $result->command);
        $this->assertNotContains('--yolo=true', $result->command);
        $this->assertNotContains('-y', $result->command);
        $this->assertNotContains('--skip-trust=false', $result->command);
        $this->assertNotContains('false', $result->command);
        $this->assertNotContains('true', $result->command);
    }

    private function job(array $payload = []): AiJob
    {
        return new AiJob([
            'timeout_seconds' => 15,
            'payload' => array_replace_recursive([
                'workspace' => $this->workspace,
                'tool_permissions' => [
                    'mode' => 'danger',
                    'workspace' => $this->workspace,
                    'codex_sandbox' => 'danger-full-access',
                    'allowed_roots' => [$this->operatorRoot, $this->workspace],
                ],
            ], $payload),
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

    private function createScheduledTasksTable(): void
    {
        Schema::create('ai_scheduled_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('prompt');
            $table->string('schedule');
            $table->string('kind', 16);
            $table->json('skill_ids')->nullable();
            $table->string('target_platform', 32)->default('local');
            $table->uuid('target_device_id')->nullable();
            $table->text('workspace')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->text('last_output_path')->nullable();
            $table->integer('repeat_remaining')->nullable();
            $table->json('context_from_task_ids')->nullable();
            $table->boolean('wrap_response')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function fakeClaudeBinary(): string
    {
        return $this->fakeExecutable('claude', <<<'SH'
#!/usr/bin/env bash
if [ "$1" = "--version" ]; then
  printf 'Claude Code fake 1.0'
  exit 0
fi
if [ "$1" = "--help" ]; then
  cat <<'HELP'
Usage: claude [options] [prompt]
  --add-dir <directories...>
  --model <model>
  --effort <level>
  --output-format <format> text json stream-json
  --permission-mode <mode>
  --no-session-persistence
HELP
  exit 0
fi
printf '{"result":"ok"}'
SH);
    }

    private function fakeCodexBinary(?string $capturePromptPath = null): string
    {
        $capture = $capturePromptPath
            ? "stdin=\$(cat)\nprintf '%s' \"\$stdin\" > ".escapeshellarg($capturePromptPath)
            : ':';

        return $this->fakeExecutable('codex', <<<SH
#!/usr/bin/env bash
if [ "\$1" = "--version" ]; then
  printf 'codex fake 1.0'
  exit 0
fi
if [ "\$1" = "exec" ] && [ "\$2" = "--help" ]; then
  cat <<'HELP'
Usage: codex exec [OPTIONS] [PROMPT]
  --model <MODEL>
  --sandbox <SANDBOX_MODE>
  --dangerously-bypass-approvals-and-sandbox
  --add-dir <DIR>
  --image <FILE>
  --output-last-message <FILE>
HELP
  exit 0
fi
previous=''
for arg in "\$@"; do
  if [ "\$previous" = "--output-last-message" ]; then
    printf 'ok' > "\$arg"
  fi
  previous="\$arg"
done
{$capture}
printf '{"result":"ok"}'
SH);
    }

    private function fakeGeminiBinary(string $model = 'gemini-3.5-flash', ?string $capturePromptPath = null): string
    {
        $capture = $capturePromptPath
            ? "printf '%s' \"\$stdin\" > ".escapeshellarg($capturePromptPath)
            : ':';

        return $this->fakeExecutable('gemini', <<<SH
#!/usr/bin/env bash
if [ "\$1" = "--version" ]; then
  printf 'gemini fake 1.0'
  exit 0
fi
if [ "\$1" = "--help" ]; then
  cat <<'HELP'
Usage: gemini [options] [command]
  --model
  --prompt
  --output-format text json stream-json
  --approval-mode default auto_edit yolo plan
  --skip-trust
  --include-directories
HELP
  exit 0
fi
stdin=\$(cat)
{$capture}
printf '{"type":"result","response":"ok","stats":{"models":["{$model}"]}}'
SH);
    }

    private function fakeHermesBinary(): string
    {
        return $this->fakeExecutable('hermes', <<<'SH'
#!/usr/bin/env bash
if [ "$1" = "--version" ]; then
  printf 'Hermes fake 1.0'
  exit 0
fi
if [ "$1" = "chat" ] && [ "$2" = "--help" ]; then
  cat <<'HELP'
Usage: hermes chat [options]
  -q, --query QUERY
  --image IMAGE
  -m, --model MODEL
  -t, --toolsets TOOLSETS
  -s, --skills SKILLS
  --provider PROVIDER
  -Q, --quiet
  --resume SESSION_ID
  --continue [SESSION_NAME]
  --worktree
  --accept-hooks
  --checkpoints
  --max-turns N
  --yolo
  --source SOURCE
HELP
  exit 0
fi
cat <<'OUT'
hermes ok
```json
{"schema_version":"atlas.hermes.memory_delta_candidates.v1","candidates":[{"claim":"Use Hermes as an ATLS-governed executor only.","evidence":["unit test output"],"confidence":0.82,"class":"procedure","suggested_action":"quarantine"}]}
```
```json
{"schema_version":"atlas.hermes.procedure_candidates.v1","candidates":[{"name":"Hermes governed setup checklist","purpose":"Turn a repeated Hermes setup flow into an ATLS-reviewed procedure candidate.","steps":["Inspect current config","Run health check","Return evidence packet"],"required_tools":["shell","filesystem"],"risk_level":"medium"}]}
```
```json
{"schema_version":"atlas.hermes.schedule_candidates.v1","candidates":[{"name":"Hermes weekly gateway health review","trigger":"cron","cadence":"0 9 * * 1","objective":"Review Hermes gateway health and report evidence to ATLS.","stop_conditions":["gateway disabled","operator pauses schedule"]}]}
```
OUT
SH);
    }

    private function fakeGeminiPolicy(): string
    {
        $policy = dirname($this->operatorRoot).'/gemini-policy.toml';
        File::put($policy, 'policy');

        return $policy;
    }

    private function fakeExecutable(string $name, string $contents): string
    {
        $path = dirname($this->operatorRoot).'/bin/'.$name;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents."\n");
        chmod($path, 0755);

        return $path;
    }
}
