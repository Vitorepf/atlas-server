<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasAntigravitySdkRuntimeExecutor;
use App\Services\Ai\Programming\AtlasForgeAntigravitySdkInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AtlasForgeAntigravitySdkDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
        putenv('ANTIGRAVITY_API_KEY');
        config()->set('atlas.ai.providers.antigravity_sdk.enabled', false);
        config()->set('atlas.ai.providers.antigravity_sdk.python', 'python3');
        config()->set('atlas.ai.providers.antigravity_sdk.module', 'google.antigravity');
        config()->set('atlas.ai.providers.antigravity_sdk.adapter_path', 'runtimes/python/antigravity_sdk/adapter.py');
    }

    protected function tearDown(): void
    {
        putenv('ANTIGRAVITY_API_KEY');
        parent::tearDown();
    }

    public function test_router_lists_antigravity_sdk_as_canonical_fail_closed_driver(): void
    {
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);
        $status = $router->driverStatus();

        $providers = array_map(fn (array $driver): string => (string) $driver['provider'], $status['drivers']);

        $this->assertContains('antigravity_sdk', $providers);
        $this->assertTrue($router->supports('antigravity_sdk'));
        $this->assertTrue($router->hasRuntimeDriver('antigravity_sdk'));
        $this->assertFalse($router->isConfigured('antigravity_sdk'));
        $this->assertContains('atlas-local', $status['configured_drivers']);
        $this->assertNotContains('antigravity_sdk', $status['configured_drivers']);
    }

    public function test_status_blocks_when_disabled_without_provider_call(): void
    {
        $driver = app(AtlasForgeAntigravitySdkInvocationDriver::class);
        $status = $driver->configured();

        $this->assertSame('atlas.provider.antigravity_sdk.status.v1', $status['schema_version']);
        $this->assertFalse($status['configured']);
        $this->assertContains('antigravity_sdk_disabled', $status['blockers']);
        $this->assertTrue($status['external_provider_call_possible']);
        $this->assertTrue($status['provider_tokens_may_be_spent']);
    }

    public function test_plan_never_calls_provider_and_requires_scope(): void
    {
        config()->set('atlas.ai.providers.antigravity_sdk.enabled', true);
        config()->set('atlas.ai.providers.antigravity_sdk.module', 'json');
        putenv('ANTIGRAVITY_API_KEY=test-key');

        $driver = app(AtlasForgeAntigravitySdkInvocationDriver::class);
        $plan = $driver->plan([
            'model' => 'gemini-3.5-flash',
            'prompt' => [
                'schema_version' => 'atlas.forge.provider_invocation_prompt.v1',
                'decision_receipt_id' => 'receipt_1',
                'decision_receipt_hash' => hash('sha256', 'receipt_1'),
                'scope_contract' => [
                    'allowed_files' => [],
                    'forbidden_files' => ['.env'],
                ],
            ],
            'cwd' => base_path(),
            'decision_receipt_id' => 'receipt_1',
            'decision_receipt_hash' => hash('sha256', 'receipt_1'),
        ]);

        $this->assertSame('atlas.forge.provider_driver_plan.v1', $plan['schema_version']);
        $this->assertFalse($plan['provider_called']);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertFalse($plan['provider_tokens_spent']);
        $this->assertContains('antigravity_sdk_allowed_files_required', $plan['blockers']);
    }

    public function test_invoke_blocks_before_adapter_when_decision_receipt_or_scope_is_missing(): void
    {
        config()->set('atlas.ai.providers.antigravity_sdk.enabled', true);
        config()->set('atlas.ai.providers.antigravity_sdk.module', 'json');
        putenv('ANTIGRAVITY_API_KEY=test-key');

        $driver = app(AtlasForgeAntigravitySdkInvocationDriver::class);
        $result = $driver->invoke([
            'model' => 'gemini-3.5-flash',
            'prompt' => [
                'schema_version' => 'atlas.forge.provider_invocation_prompt.v1',
                'scope_contract' => [
                    'allowed_files' => [],
                    'forbidden_files' => ['.env'],
                ],
            ],
            'cwd' => base_path(),
        ]);

        $this->assertFalse($result['provider_called']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertContains('decision_receipt_required', $result['blockers']);
        $this->assertContains('antigravity_sdk_allowed_files_required', $result['blockers']);
    }

    public function test_runtime_invokes_adapter_only_when_config_receipt_and_scope_are_valid(): void
    {
        config()->set('atlas.ai.providers.antigravity_sdk.enabled', true);
        config()->set('atlas.ai.providers.antigravity_sdk.module', 'json');
        putenv('ANTIGRAVITY_API_KEY=test-key');

        $runtime = app(AtlasAntigravitySdkRuntimeExecutor::class);
        $runtime->setProcessFactory(function (array $argv, ?string $cwd, ?array $env, int $timeout): Process {
            $payload = json_encode([
                'schema_version' => 'atlas.provider.antigravity_sdk.invocation_result.v1',
                'provider_called' => true,
                'external_provider_call' => true,
                'provider_tokens_spent' => 'unknown',
                'model_observed' => 'gemini-3.5-flash',
                'changed_files' => ['app/Foo.php'],
                'artifacts' => [['kind' => 'text', 'sha256' => hash('sha256', 'ok')]],
                'performance_signal' => [
                    'schema_version' => 'atlas.provider.antigravity_sdk.performance_signal.v1',
                    'provider' => 'antigravity_sdk',
                    'status' => 'succeeded',
                    'routing_effect' => 'none',
                    'advisory_only' => true,
                ],
                'blockers' => [],
                'note' => 'fake adapter ok',
            ], JSON_UNESCAPED_SLASHES);

            return new Process([PHP_BINARY, '-r', 'echo '.var_export($payload, true).';'], $cwd, $env, null, $timeout);
        });

        $driver = new AtlasForgeAntigravitySdkInvocationDriver($runtime);
        $result = $driver->invoke($this->validRequest());

        $this->assertTrue($result['provider_called']);
        $this->assertTrue($result['external_provider_call']);
        $this->assertSame('gemini-3.5-flash', $result['model_observed']);
        $this->assertSame(['app/Foo.php'], $result['changed_files']);
        $this->assertSame('none', data_get($result, 'performance_signal.routing_effect'));
    }

    public function test_forge_service_blocks_antigravity_execute_without_confirmations(): void
    {
        $obra = $this->makeObraWithDispatch();
        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
            'mode' => 'execute',
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('operator_provider_approval_required', $payload['blockers']);
        $this->assertContains('budget_approval_required', $payload['blockers']);
        $this->assertContains('runtime_dispatch_confirmation_required', $payload['blockers']);
        $this->assertFalse($payload['provider_called']);
    }

    public function test_antigravity_provider_usage_events_are_consumable_by_performance_projection_shape(): void
    {
        config()->set('atlas.ai.providers.antigravity_sdk.enabled', true);
        config()->set('atlas.ai.providers.antigravity_sdk.module', 'json');
        putenv('ANTIGRAVITY_API_KEY=test-key');

        $runtime = app(AtlasAntigravitySdkRuntimeExecutor::class);
        $runtime->setProcessFactory(function (array $argv, ?string $cwd, ?array $env, int $timeout): Process {
            $payload = json_encode([
                'provider_called' => true,
                'external_provider_call' => true,
                'model_observed' => 'gemini-3.5-flash',
                'changed_files' => ['app/Foo.php'],
                'blockers' => [],
                'performance_signal' => [
                    'schema_version' => 'atlas.provider.antigravity_sdk.performance_signal.v1',
                    'provider' => 'antigravity_sdk',
                    'status' => 'succeeded',
                    'routing_effect' => 'none',
                    'advisory_only' => true,
                ],
            ], JSON_UNESCAPED_SLASHES);

            return new Process([PHP_BINARY, '-r', 'echo '.var_export($payload, true).';'], $cwd, $env, null, $timeout);
        });
        $this->app->instance(AtlasAntigravitySdkRuntimeExecutor::class, $runtime);

        $obra = $this->makeObraWithDispatch();
        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
            'mode' => 'execute',
            'confirm_provider_call' => true,
            'confirm_budget' => true,
            'confirm_runtime_dispatch' => true,
        ]);

        $this->assertSame('executed', $payload['status']);
        $this->assertSame('atlas.provider.antigravity_sdk.performance_signal.v1', data_get($payload, 'provider_performance_signal.schema_version'));
        $event = \App\Models\AtlasLedgerEvent::query()
            ->where('event_type', \App\Services\Ai\Kernel\Evidence\LedgerEventType::ProviderReturned->value)
            ->latest('created_at')
            ->first();
        $this->assertSame('antigravity_sdk', data_get($event?->payload, 'provider_cli'));
        $this->assertSame('programming.forge', data_get($event?->payload, 'flow'));
        $this->assertSame('none', data_get($event?->payload, 'provider_performance_signal.routing_effect'));
    }

    /**
     * @return array<string,mixed>
     */
    private function validRequest(): array
    {
        return [
            'model' => 'gemini-3.5-flash',
            'prompt' => [
                'schema_version' => 'atlas.forge.provider_invocation_prompt.v1',
                'decision_receipt_id' => 'receipt_1',
                'decision_receipt_hash' => hash('sha256', 'receipt_1'),
                'scope_contract' => [
                    'allowed_files' => ['app/Foo.php'],
                    'forbidden_files' => ['.env'],
                ],
            ],
            'cwd' => base_path(),
            'decision_receipt_id' => 'receipt_1',
            'decision_receipt_hash' => hash('sha256', 'receipt_1'),
            'timeout_seconds' => 5,
        ];
    }

    private function makeObraWithDispatch(): AtlasProject
    {
        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'antigravity sdk smoke',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'antigravity sdk smoke',
            'desired_outcome' => 'driver governado smoke',
            'priority' => 'normal',
            'metadata' => [
                'workspace_path' => base_path(),
                'allowed_files' => ['app/Foo.php'],
                'forbidden_files' => ['.env'],
                'latest_atlas_forge_runtime_dispatch' => [
                    'schema_version' => 'atlas.forge.runtime_dispatch_plan.v1',
                    'status' => 'dispatch_planned',
                    'dispatch_id' => 'dispatch_test_antigravity',
                    'decision_source' => 'live_atlas_decide',
                    'decision_receipt_id' => 'receipt_test_antigravity',
                    'decision_receipt_hash' => hash('sha256', 'receipt_test_antigravity'),
                    'runtime_dispatch_allowed' => true,
                    'role' => 'primary_builder',
                    'provider' => 'antigravity_sdk',
                    'model' => 'gemini-3.5-flash',
                    'provider_topology_id' => 'topology_test_antigravity',
                    'workspace_execution_gate' => [
                        'schema_version' => 'atlas.workspace_intelligence.execution_gate.v1',
                        'allowed' => true,
                        'mode' => 'forge',
                        'workspace_id' => 'atlas',
                    ],
                    'quality_gates' => ['scope_guard', 'tests'],
                    'blockers' => [],
                ],
            ],
        ]);
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('atlas_projects')) {
            Schema::create('atlas_projects', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->text('description')->nullable();
                $t->string('status')->default('active');
                $t->string('domain')->default('atlas');
                $t->uuid('source_capture_id')->nullable();
                $t->text('goal')->nullable();
                $t->text('next_action')->nullable();
                $t->string('project_type')->nullable();
                $t->text('desired_outcome')->nullable();
                $t->text('minimum_viable_outcome')->nullable();
                $t->text('definition_of_done')->nullable();
                $t->text('why_now')->nullable();
                $t->timestamp('deadline_at')->nullable();
                $t->string('deadline_kind')->nullable();
                $t->string('priority')->default('normal');
                $t->string('energy_profile')->nullable();
                $t->text('avoidance_reason')->nullable();
                $t->uuid('active_next_task_id')->nullable();
                $t->uuid('current_step_id')->nullable();
                $t->timestamp('last_touched_at')->nullable();
                $t->timestamp('next_review_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamp('paused_until')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        if (! Schema::hasTable('atlas_ledger_events')) {
            Schema::create('atlas_ledger_events', function (Blueprint $t) {
                $t->string('event_id')->primary();
                $t->string('schema_version')->nullable();
                $t->string('tenant_id')->nullable();
                $t->string('operator_id')->nullable();
                $t->string('envelope_id')->nullable();
                $t->string('receipt_id')->nullable();
                $t->string('trace_id')->nullable();
                $t->string('correlation_id')->nullable();
                $t->string('causation_id')->nullable();
                $t->string('event_type')->nullable();
                $t->string('emitter_stage')->nullable();
                $t->string('emitter_version')->nullable();
                $t->json('payload')->nullable();
                $t->string('payload_hash')->nullable();
                $t->timestamp('occurred_at')->nullable();
                $t->timestamps();
            });
        }
    }
}
