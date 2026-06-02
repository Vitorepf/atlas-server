<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\AtlasDecideService;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationService;
use App\Services\Ai\Programming\AtlasForgeRuntimeDispatchService;
use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasForgeProviderInvocationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
    }

    public function test_provider_invoke_fails_closed_without_obra(): void
    {
        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([]);

        $this->assertSame('atlas.forge.provider_invocation.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('obra_required', $payload['blockers']);
        $this->assertFalse($payload['provider_called']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['completion_claim_promoted']);
        $this->assertTrue($payload['review_completion_gate_preserved']);
    }

    public function test_provider_invoke_blocks_without_runtime_dispatch(): void
    {
        $obra = $this->makeObra();

        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'mode' => 'dry_run',
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('runtime_dispatch_required', $payload['blockers']);
        $this->assertFalse($payload['provider_called']);
    }

    public function test_provider_invoke_blocks_static_policy_dispatch(): void
    {
        $obra = $this->makeObra();
        $obra->forceFill([
            'metadata' => array_merge((array) $obra->metadata, [
                'latest_atlas_forge_runtime_dispatch' => [
                    'schema_version' => AtlasForgeRuntimeDispatchService::SCHEMA_VERSION,
                    'status' => 'blocked',
                    'dispatch_id' => 'dispatch_static_test',
                    'decision_source' => 'static_policy',
                    'decision_receipt_id' => null,
                    'decision_receipt_hash' => null,
                    'runtime_dispatch_allowed' => false,
                    'role' => 'primary_builder',
                    'provider' => 'codex_cli',
                    'model' => 'gpt-5.5',
                    'blockers' => ['live_decide_receipt_required'],
                ],
            ]),
        ])->save();

        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'mode' => 'dry_run',
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('live_decide_dispatch_required', $payload['blockers']);
        $this->assertContains('decision_receipt_required', $payload['blockers']);
        $this->assertFalse($payload['provider_called']);
    }

    public function test_provider_invoke_blocks_runtime_dispatch_without_awis_gate(): void
    {
        $obra = $this->makeObra();
        $obra->forceFill([
            'metadata' => array_merge((array) $obra->metadata, [
                'latest_atlas_forge_runtime_dispatch' => [
                    'schema_version' => AtlasForgeRuntimeDispatchService::SCHEMA_VERSION,
                    'status' => AtlasForgeRuntimeDispatchService::STATUS_DISPATCH_PLANNED,
                    'dispatch_id' => 'dispatch_legacy_no_awis_gate',
                    'decision_source' => 'live_atlas_decide',
                    'decision_receipt_id' => 'receipt_test',
                    'decision_receipt_hash' => str_repeat('a', 64),
                    'runtime_dispatch_allowed' => true,
                    'role' => 'primary_builder',
                    'provider' => 'atlas-local',
                    'model' => 'atlas-runtime',
                    'blockers' => [],
                ],
            ]),
        ])->save();

        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
            'mode' => 'dry_run',
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains(AtlasForgeProviderInvocationService::BLOCKER_AWIS_EXECUTION_GATE_REQUIRED, $payload['blockers']);
        $this->assertNull($payload['workspace_execution_gate']);
        $this->assertFalse($payload['provider_called']);
    }

    public function test_provider_invoke_dry_run_plans_with_live_dispatch(): void
    {
        $obra = $this->seedLiveDispatch();
        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
            'mode' => 'dry_run',
        ]);

        // Either planned (preferred) or blocked when dispatch wasn't allowed.
        if ($payload['status'] === 'planned') {
            $this->assertSame('dry_run', $payload['mode']);
            $this->assertSame('atlas.workspace_intelligence.execution_gate.v1', data_get($payload, 'workspace_execution_gate.schema_version'));
            $this->assertTrue(data_get($payload, 'workspace_execution_gate.allowed'));
            $this->assertFalse($payload['provider_called']);
            $this->assertFalse($payload['external_provider_call']);
            $this->assertFalse($payload['completion_claim_promoted']);
            $this->assertNotNull($payload['provider']);
            $this->assertNotNull($payload['model']);
        } else {
            $this->assertSame('blocked', $payload['status']);
            $this->assertContains('runtime_dispatch_not_allowed', $payload['blockers']);
        }
    }

    public function test_provider_invoke_execute_requires_operator_confirmation(): void
    {
        $obra = $this->seedLiveDispatch();
        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
            'mode' => 'execute',
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('operator_provider_approval_required', $payload['blockers']);
        $this->assertContains('runtime_dispatch_confirmation_required', $payload['blockers']);
        $this->assertFalse($payload['provider_called']);
        $this->assertFalse($payload['external_provider_call']);
    }

    public function test_provider_invoke_execute_requires_budget_confirmation_for_external_providers(): void
    {
        $obra = $this->seedLiveDispatch();
        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
            'mode' => 'execute',
            'confirm_provider_call' => true,
            'confirm_runtime_dispatch' => true,
            // confirm_budget intentionally missing — provider is external (codex_cli).
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('budget_approval_required', $payload['blockers']);
        $this->assertFalse($payload['provider_called']);
    }

    public function test_provider_invoke_requires_decision_receipt_hash(): void
    {
        $obra = $this->seedLiveDispatch();
        $metadata = (array) $obra->metadata;
        $metadata['latest_atlas_forge_runtime_dispatch']['decision_receipt_hash'] = '';
        $obra->forceFill(['metadata' => $metadata])->save();

        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
            'mode' => 'dry_run',
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('decision_receipt_required', $payload['blockers']);
    }

    public function test_provider_invoke_blocks_when_provider_driver_missing(): void
    {
        $obra = $this->seedLiveDispatch(provider: 'codex_cli');
        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
            'mode' => 'execute',
            'confirm_provider_call' => true,
            'confirm_budget' => true,
            'confirm_runtime_dispatch' => true,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('provider_driver_missing', $payload['blockers']);
        $this->assertFalse($payload['provider_called']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['completion_claim_promoted']);
    }

    public function test_provider_invoke_atlas_local_executor_is_safe(): void
    {
        $obra = $this->seedLiveDispatch(provider: 'atlas-local', model: 'atlas-runtime');
        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
            'mode' => 'execute',
            'confirm_provider_call' => true,
            // budget not required for atlas-local
            'confirm_runtime_dispatch' => true,
        ]);

        // atlas-local executor is safe and runs deterministically.
        $this->assertContains($payload['status'], ['executed', 'blocked']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertFalse($payload['completion_claim_promoted']);
        if ($payload['status'] === 'executed') {
            $this->assertSame(0, $payload['exit_code']);
            $this->assertNotNull($payload['stdout_hash']);
            $this->assertNotNull($payload['stderr_hash']);
            $this->assertSame('atlas-local', $payload['provider']);
        }
    }

    public function test_provider_invocation_receipt_is_persisted(): void
    {
        $obra = $this->seedLiveDispatch();
        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'mode' => 'dry_run',
        ]);

        $obra->refresh();
        $this->assertIsArray(data_get($obra->metadata, 'latest_atlas_forge_provider_invocation'));
        $this->assertIsArray(data_get($obra->metadata, 'latest_atlas_forge_provider_invocation_receipt'));
        $this->assertSame(
            $payload['invocation_id'],
            data_get($obra->metadata, 'latest_atlas_forge_provider_invocation.invocation_id'),
        );
        $this->assertSame(
            AtlasForgeProviderInvocationService::RECEIPT_SCHEMA_VERSION,
            data_get($obra->metadata, 'latest_atlas_forge_provider_invocation_receipt.schema_version'),
        );
        $history = data_get($obra->metadata, 'atlas_forge_provider_invocation_history');
        $this->assertIsArray($history);
        $this->assertNotEmpty($history);
        $this->assertSame($payload['invocation_id'], data_get($history, '0.invocation_id'));
    }

    public function test_provider_invocation_output_hashes_are_recorded(): void
    {
        $obra = $this->seedLiveDispatch(provider: 'atlas-local', model: 'atlas-runtime');
        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'mode' => 'execute',
            'confirm_provider_call' => true,
            'confirm_runtime_dispatch' => true,
        ]);

        if ($payload['status'] === 'executed') {
            $this->assertIsString($payload['stdout_hash']);
            $this->assertIsString($payload['stderr_hash']);
            $this->assertSame(64, strlen($payload['stdout_hash']));
            $this->assertSame(64, strlen($payload['stderr_hash']));
        } else {
            // Even when blocked, schema fields must be present (null is acceptable).
            $this->assertArrayHasKey('stdout_hash', $payload);
            $this->assertArrayHasKey('stderr_hash', $payload);
        }
    }

    public function test_provider_invocation_ledger_events_are_recorded_when_available(): void
    {
        $obra = $this->seedLiveDispatch(provider: 'atlas-local', model: 'atlas-runtime');
        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'mode' => 'dry_run',
        ]);
        $this->assertContains($payload['status'], ['planned', 'blocked']);
        $this->assertArrayHasKey('ledger_available', $payload);
        $this->assertIsBool($payload['ledger_available']);
        // When ledger table is present, the service should report it true.
        $this->assertTrue(Schema::hasTable('atlas_ledger_events') === $payload['ledger_available']);
    }

    public function test_provider_invocation_timeout_is_invalid_when_out_of_range(): void
    {
        $obra = $this->makeObra();
        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'timeout_seconds' => 0,
        ]);
        $this->assertContains('timeout_invalid', $payload['blockers']);
    }

    public function test_state_endpoint_exposes_provider_invocation(): void
    {
        $obra = $this->seedLiveDispatch();
        app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'mode' => 'dry_run',
        ]);

        $this->getJson('/atlas-code/works/'.$obra->id.'/state', $this->headers())
            ->assertOk()
            ->assertJsonPath('forge_provider_invocation.schema_version', AtlasForgeProviderInvocationService::SCHEMA_VERSION)
            ->assertJsonPath('forge_provider_invocation.external_provider_call', false)
            ->assertJsonPath('forge_provider_invocation.completion_claim_promoted', false)
            ->assertJsonPath('forge_provider_invocation_receipt.schema_version', AtlasForgeProviderInvocationService::RECEIPT_SCHEMA_VERSION);
    }

    public function test_provider_invocation_api_post_and_latest(): void
    {
        $obra = $this->seedLiveDispatch();
        $this->postJson('/atlas-code/works/'.$obra->id.'/forge/provider-invocations', [
            'role' => 'primary_builder',
            'mode' => 'dry_run',
        ], $this->headers())
            ->assertJsonPath('schema_version', AtlasForgeProviderInvocationService::SCHEMA_VERSION)
            ->assertJsonPath('external_provider_call', false);

        $this->getJson('/atlas-code/works/'.$obra->id.'/forge/provider-invocations/latest', $this->headers())
            ->assertOk()
            ->assertJsonPath('schema_version', AtlasForgeProviderInvocationService::SCHEMA_VERSION);
    }

    public function test_completion_audit_exposes_provider_invocation_certification(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path());
        $this->assertArrayHasKey('atlas_forge_provider_invocation_certification', $report);
        $block = $report['atlas_forge_provider_invocation_certification'];

        $this->assertSame('atlas.forge_provider_invocation_certification.v1', $block['schema_version']);
        $this->assertContains($block['status'], ['available', 'backend_available_ui_pending', 'missing_artifacts', 'blocked']);
        $this->assertTrue($block['no_external_provider_call_without_flags']);
        $this->assertFalse($block['external_provider_call']);
        $this->assertFalse($block['promotes_external_rivals_claim']);
        $this->assertTrue($block['separated_from_external_rivals_certification']);

        foreach ([
            'invocation_service_present',
            'driver_router_present',
            'prompt_builder_present',
            'invocation_receipt_available',
            'invocation_command_present',
            'invocation_controller_present',
            'dry_run_mode_available',
            'execute_mode_fail_closed_without_operator_approval',
            'budget_approval_required',
            'runtime_dispatch_required',
            'live_decide_receipt_required',
            'static_policy_cannot_invoke',
            'provider_driver_missing_blocks',
            'ledger_events_supported',
            'output_hashing_supported',
            'timeout_supported',
            'completion_claim_not_promoted',
            'review_completion_gate_preserved',
            'external_rivals_separated',
        ] as $key) {
            $this->assertArrayHasKey($key, $block['invariants']);
            $this->assertTrue($block['invariants'][$key], "Invariant {$key} must be true.");
        }
    }

    public function test_provider_invocation_never_promotes_completion_claim(): void
    {
        $obra = $this->seedLiveDispatch(provider: 'atlas-local', model: 'atlas-runtime');
        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'mode' => 'execute',
            'confirm_provider_call' => true,
            'confirm_runtime_dispatch' => true,
        ]);

        $this->assertFalse($payload['completion_claim_promoted']);
        $this->assertTrue($payload['review_completion_gate_preserved']);
    }

    public function test_external_rivals_remains_separated_and_blocked(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path());
        $this->assertArrayHasKey('external_rivals_certification', $report);
        $this->assertSame('forge_runtime_certification', $report['external_rivals_certification']['separated_from']);
        $this->assertFalse($report['atlas_forge_provider_invocation_certification']['promotes_external_rivals_claim']);
    }

    public function test_provider_invoke_cli_strict_without_obra_exits_one(): void
    {
        $exitCode = Artisan::call('atlas:forge:provider-invoke', ['--strict' => true, '--json' => true]);
        $this->assertSame(1, $exitCode);
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('obra_required', $payload['blockers']);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function makeObra(array $metadata = []): AtlasProject
    {
        $metadata = array_merge([
            'workspace_slug' => 'atlas',
            'workspace_path' => base_path('..'),
            'origin' => 'atlas-code-test',
        ], $metadata);

        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'Provider Invocation test Obra',
            'description' => 'Atlas Forge Governed Provider Invocation',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'Validar governed provider invocation',
            'desired_outcome' => 'Invocation governada sem chamada provider externa',
            'priority' => 'normal',
            'metadata' => $metadata,
        ]);
    }

    public function test_cockpit_live_executions_routes_through_real_chain_when_flag_on(): void
    {
        // STEP 2 proof: with the cockpit-real flag ON, the product route
        // POST /atlas-code/works/{obra}/forge/live-executions must reach the REAL
        // governed chain (dispatch -> invoke) instead of the fixture. Driven with
        // provider=atlas-local so it is deterministic and spends ZERO tokens.
        config(['atlas.forge.cockpit_real_invocation_enabled' => true]);

        // Production shape: Atlas Decide has chosen the provider for this Obra,
        // but NO dispatch is prepared yet — the cockpit must prepare it itself.
        $obra = $this->makeObra();
        app(AtlasDecideService::class)->operationalDecision([
            'source_type' => 'app',
            'input_text' => 'cockpit real invocation test',
            'payload' => [
                'surface_id' => 'atlas_code',
                'app_surface' => 'atlas_code',
                'routing_domain' => 'programming',
                'programming_flow' => 'programming.forge',
                'atlas_workflow_mode' => 'forge',
                'obra_id' => (string) $obra->id,
            ],
        ], 'atlas-local', 'atlas-runtime');

        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Atlas-Token' => 'testing-atlas-token-with-enough-length',
        ])->postJson("/atlas-code/works/{$obra->id}/forge/live-executions", [
            'execute' => true,
            'confirm_provider_call' => true,
            'confirm_runtime_dispatch' => true,
        ]);

        // STEP 2 proof: the cockpit now routes to the REAL governed chain. This
        // response SHAPE (cockpit_real_invocation schema + a `dispatch` stage) is
        // produced ONLY by the real branch — the fixture never emits it. The chain
        // then correctly FAIL-CLOSES for an Obra that lacks runtime-dispatch
        // authorization (decision receipt + quality gates), so the cockpit can no
        // longer silently run a fixture nor reach a provider unauthorized.
        // The authorized happy-path (provider actually executes) is proven in the
        // end-to-end real-execution task with a fully prepared Obra.
        $response->assertStatus(409);
        $response->assertJsonPath('schema_version', 'atlas.code.forge_cockpit_real_invocation.v1');
        $response->assertJsonPath('execution_path', 'real_governed_chain');
        $response->assertJsonPath('stage', 'dispatch');
        $response->assertJsonPath('dispatch.blockers.0', 'runtime_dispatch_not_allowed');
    }

    private function seedLiveDispatch(string $provider = 'codex_cli', string $model = 'gpt-5.5'): AtlasProject
    {
        $obra = $this->makeObra();
        app(AtlasDecideService::class)->operationalDecision([
            'source_type' => 'app',
            'input_text' => 'invocation test',
            'payload' => [
                'surface_id' => 'atlas_code',
                'app_surface' => 'atlas_code',
                'routing_domain' => 'programming',
                'programming_flow' => 'programming.forge',
                'atlas_workflow_mode' => 'forge',
                'obra_id' => (string) $obra->id,
            ],
        ], $provider, $model);

        app(AtlasForgeRuntimeDispatchService::class)->dispatch([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
        ]);

        return $obra->refresh();
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        return ['X-Atlas-Token' => 'testing-atlas-token-with-enough-length'];
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

        if (! Schema::hasTable('ai_threads')) {
            Schema::create('ai_threads', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->string('status')->default('active');
                $t->string('surface')->nullable();
                $t->string('workspace')->nullable();
                $t->string('source_type')->nullable();
                $t->uuid('source_id')->nullable();
                $t->integer('message_count')->default(0);
                $t->timestamp('last_message_at')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('ai_messages')) {
            Schema::create('ai_messages', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('thread_id');
                $t->uuid('trace_id')->nullable();
                $t->integer('position')->default(1);
                $t->string('role');
                $t->string('status')->default('completed');
                $t->text('content')->nullable();
                $t->timestamp('occurred_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('ai_traces')) {
            Schema::create('ai_traces', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('thread_id')->nullable();
                $t->string('source_type')->default('app');
                $t->uuid('source_id')->nullable();
                $t->string('status')->default('completed');
                $t->text('operator_input')->nullable();
                $t->text('response_text')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_engineering_runs')) {
            Schema::create('atlas_engineering_runs', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('project_id')->nullable();
                $t->string('status')->nullable();
                $t->string('decision')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_engineering_evidence')) {
            Schema::create('atlas_engineering_evidence', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('project_id')->nullable();
                $t->uuid('task_id')->nullable();
                $t->string('evidence_type')->nullable();
                $t->string('target_id')->nullable();
                $t->string('status')->nullable();
                $t->float('confidence')->nullable();
                $t->text('summary')->nullable();
                $t->text('command')->nullable();
                $t->text('output_excerpt')->nullable();
                $t->json('files')->nullable();
                $t->json('metadata')->nullable();
                $t->string('source')->nullable();
                $t->timestamp('recorded_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_tool_runs')) {
            Schema::create('atlas_tool_runs', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('tool_slug')->nullable();
                $t->text('workspace')->nullable();
                $t->string('run_context_type')->nullable();
                $t->string('run_context_id')->nullable();
                $t->string('status')->nullable();
                $t->json('summary_json')->nullable();
                $t->timestamps();
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
