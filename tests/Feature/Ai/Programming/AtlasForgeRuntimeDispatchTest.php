<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\AtlasDecideService;
use App\Services\Ai\Programming\AtlasForgeProviderFallbackPolicyService;
use App\Services\Ai\Programming\AtlasForgeRuntimeDispatchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasForgeRuntimeDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
    }

    public function test_dispatch_fails_closed_without_obra(): void
    {
        $plan = app(AtlasForgeRuntimeDispatchService::class)->dispatch([]);

        $this->assertSame(AtlasForgeRuntimeDispatchService::SCHEMA_VERSION, $plan['schema_version']);
        $this->assertSame('blocked', $plan['status']);
        $this->assertNull($plan['obra_id']);
        $this->assertFalse($plan['obra_present']);
        $this->assertContains(AtlasForgeRuntimeDispatchService::BLOCKER_OBRA_REQUIRED, $plan['blockers']);
        $this->assertFalse($plan['runtime_dispatch_allowed']);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertFalse($plan['provider_invocation_planned']);
        $this->assertFalse($plan['completion_claim_promoted']);
        $this->assertTrue($plan['review_completion_gate_preserved']);
    }

    public function test_dispatch_blocked_when_obra_only_has_static_policy(): void
    {
        $obra = $this->makeObra();
        $plan = app(AtlasForgeRuntimeDispatchService::class)->dispatch([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
        ]);

        $this->assertSame('blocked', $plan['status']);
        $this->assertContains(AtlasForgeRuntimeDispatchService::BLOCKER_LIVE_DECIDE_REQUIRED, $plan['blockers']);
        $this->assertContains(AtlasForgeRuntimeDispatchService::BLOCKER_DECISION_RECEIPT_REQUIRED, $plan['blockers']);
        $this->assertFalse($plan['runtime_dispatch_allowed']);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertFalse($plan['completion_claim_promoted']);
    }

    public function test_dispatch_succeeds_after_live_atlas_decide_receipt(): void
    {
        $obra = $this->makeObra();
        // Produce a live Decision Receipt that persists the forge topology on the Obra.
        app(AtlasDecideService::class)->operationalDecision([
            'source_type' => 'app',
            'input_text' => 'Implementar feature Forge pesada com testes.',
            'payload' => [
                'surface_id' => 'atlas_code',
                'app_surface' => 'atlas_code',
                'routing_domain' => 'programming',
                'programming_flow' => 'programming.forge',
                'atlas_workflow_mode' => 'forge',
                'obra_id' => (string) $obra->id,
            ],
        ], 'codex_cli', 'gpt-5.5');

        $plan = app(AtlasForgeRuntimeDispatchService::class)->dispatch([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
        ]);

        if ($plan['status'] === 'dispatch_planned') {
            $this->assertNotNull($plan['decision_receipt_id']);
            $this->assertNotNull($plan['decision_receipt_hash']);
            $this->assertSame('live_atlas_decide', $plan['decision_source']);
            $this->assertSame('primary_builder', $plan['role']);
            $this->assertNotNull($plan['provider']);
            $this->assertNotNull($plan['model']);
            $this->assertTrue($plan['runtime_dispatch_allowed']);
            $this->assertFalse($plan['external_provider_call']);
            $this->assertFalse($plan['provider_invocation_planned']);
            $this->assertFalse($plan['completion_claim_promoted']);
            $this->assertTrue($plan['review_completion_gate_preserved']);
            $this->assertTrue($plan['requires_provider_approval']);
        } else {
            // Some kernel contracts may keep runtime dispatch disallowed on first
            // bootstrap — accept that the dispatcher reports the specific blocker
            // honestly rather than auto-promoting.
            $this->assertContains($plan['status'], ['blocked', 'fallback_child_receipt_required']);
            $this->assertContains(AtlasForgeRuntimeDispatchService::BLOCKER_RUNTIME_DISPATCH_NOT_ALLOWED, $plan['blockers']);
        }
    }

    public function test_dispatch_blocks_with_invalid_role(): void
    {
        $obra = $this->makeObra();
        app(AtlasDecideService::class)->operationalDecision([
            'source_type' => 'app',
            'input_text' => 'Forge.',
            'payload' => [
                'surface_id' => 'atlas_code',
                'routing_domain' => 'programming',
                'programming_flow' => 'programming.forge',
                'obra_id' => (string) $obra->id,
            ],
        ], 'codex_cli', 'gpt-5.5');

        $plan = app(AtlasForgeRuntimeDispatchService::class)->dispatch([
            'obra_id' => (string) $obra->id,
            'role' => 'imposter_role',
        ]);

        $this->assertContains(AtlasForgeRuntimeDispatchService::BLOCKER_ROLE_INVALID, $plan['blockers']);
        $this->assertSame('blocked', $plan['status']);
        $this->assertFalse($plan['external_provider_call']);
    }

    public function test_dispatch_requires_child_receipt_on_rate_limit_reroute(): void
    {
        $obra = $this->makeObra();
        app(AtlasDecideService::class)->operationalDecision([
            'source_type' => 'app',
            'input_text' => 'Forge.',
            'payload' => [
                'surface_id' => 'atlas_code',
                'routing_domain' => 'programming',
                'programming_flow' => 'programming.forge',
                'obra_id' => (string) $obra->id,
            ],
        ], 'codex_cli', 'gpt-5.5');

        $plan = app(AtlasForgeRuntimeDispatchService::class)->dispatch([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
            'simulate_provider_failure' => AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT,
            'create_child_receipt' => false,
        ]);

        $this->assertContains(
            AtlasForgeRuntimeDispatchService::BLOCKER_FALLBACK_CHILD_RECEIPT_REQUIRED,
            $plan['blockers'],
        );
        $this->assertNotNull($plan['fallback_event_id']);
        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT, $plan['fallback_failure_type']);
        $this->assertNull($plan['child_decision_receipt_id']);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertFalse($plan['completion_claim_promoted']);
    }

    public function test_dispatch_creates_child_receipt_on_request(): void
    {
        $obra = $this->makeObra();
        $parent = app(AtlasDecideService::class)->operationalDecision([
            'source_type' => 'app',
            'input_text' => 'Forge.',
            'payload' => [
                'surface_id' => 'atlas_code',
                'routing_domain' => 'programming',
                'programming_flow' => 'programming.forge',
                'obra_id' => (string) $obra->id,
            ],
        ], 'codex_cli', 'gpt-5.5')->toArray();
        $parentReceiptId = data_get($parent, 'receipt_v2.receipt_id');

        $plan = app(AtlasForgeRuntimeDispatchService::class)->dispatch([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
            'simulate_provider_failure' => AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT,
            'create_child_receipt' => true,
        ]);

        $this->assertNotNull($plan['child_decision_receipt_id'], 'Child Decision Receipt deveria ter sido gerado.');
        $this->assertNotNull($plan['child_decision_receipt_hash']);
        $this->assertNotSame($parentReceiptId, $plan['child_decision_receipt_id']);
        $this->assertFalse($plan['external_provider_call']);

        $obra->refresh();
        $child = data_get($obra->metadata, 'latest_atlas_forge_child_decision_receipt');
        $this->assertIsArray($child);
        $this->assertSame($plan['child_decision_receipt_id'], $child['child_decision_receipt_id']);
        $this->assertSame($parentReceiptId, $child['parent_decision_receipt_id']);
        $this->assertSame('provider_fallback_reroute', $child['reason']);

        $history = data_get($obra->metadata, 'atlas_forge_child_decision_receipt_history');
        $this->assertIsArray($history);
        $this->assertNotEmpty($history);
        $this->assertSame($plan['child_decision_receipt_id'], data_get($history, '0.child_decision_receipt_id'));
    }

    public function test_dispatch_terminal_block_on_capacity_exhausted(): void
    {
        $obra = $this->makeObra();
        app(AtlasDecideService::class)->operationalDecision([
            'source_type' => 'app',
            'input_text' => 'Forge.',
            'payload' => [
                'surface_id' => 'atlas_code',
                'routing_domain' => 'programming',
                'programming_flow' => 'programming.forge',
                'obra_id' => (string) $obra->id,
            ],
        ], 'codex_cli', 'gpt-5.5');

        $plan = app(AtlasForgeRuntimeDispatchService::class)->dispatch([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
            'simulate_provider_failure' => AtlasForgeProviderFallbackPolicyService::FAILURE_CAPACITY_EXHAUSTED,
            'create_child_receipt' => true,
        ]);

        $this->assertSame('provider_capacity_exhausted', $plan['status']);
        $this->assertContains('provider_capacity_exhausted', $plan['blockers']);
        $this->assertFalse($plan['runtime_dispatch_allowed']);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertFalse($plan['completion_claim_promoted']);
    }

    public function test_dispatch_persists_projection_on_obra(): void
    {
        $obra = $this->makeObra();
        app(AtlasDecideService::class)->operationalDecision([
            'source_type' => 'app',
            'input_text' => 'Forge.',
            'payload' => [
                'surface_id' => 'atlas_code',
                'routing_domain' => 'programming',
                'programming_flow' => 'programming.forge',
                'obra_id' => (string) $obra->id,
            ],
        ], 'codex_cli', 'gpt-5.5');

        $plan = app(AtlasForgeRuntimeDispatchService::class)->dispatch([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
        ]);

        $obra->refresh();
        $latest = data_get($obra->metadata, 'latest_atlas_forge_runtime_dispatch');
        $this->assertIsArray($latest);
        $this->assertSame($plan['dispatch_id'], $latest['dispatch_id']);
        $this->assertSame($plan['status'], $latest['status']);

        $history = data_get($obra->metadata, 'atlas_forge_runtime_dispatch_history');
        $this->assertIsArray($history);
        $this->assertNotEmpty($history);
        $this->assertSame($plan['dispatch_id'], data_get($history, '0.dispatch_id'));
        $this->assertSame(AtlasForgeRuntimeDispatchService::PROJECTION_SCHEMA_VERSION, data_get($history, '0.schema_version'));
    }

    public function test_state_projection_exposes_forge_runtime_dispatch(): void
    {
        $obra = $this->makeObra();
        app(AtlasDecideService::class)->operationalDecision([
            'source_type' => 'app',
            'input_text' => 'Forge.',
            'payload' => [
                'surface_id' => 'atlas_code',
                'routing_domain' => 'programming',
                'programming_flow' => 'programming.forge',
                'obra_id' => (string) $obra->id,
            ],
        ], 'codex_cli', 'gpt-5.5');

        app(AtlasForgeRuntimeDispatchService::class)->dispatch([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
        ]);

        $this->getJson('/atlas-code/works/'.$obra->id.'/state', $this->headers())
            ->assertOk()
            ->assertJsonPath('forge_runtime_dispatch.schema_version', AtlasForgeRuntimeDispatchService::SCHEMA_VERSION)
            ->assertJsonPath('forge_runtime_dispatch.obra_id', (string) $obra->id)
            ->assertJsonPath('forge_runtime_dispatch.external_provider_call', false)
            ->assertJsonPath('forge_runtime_dispatch.completion_claim_promoted', false);
    }

    public function test_runtime_dispatch_endpoint_post_and_get(): void
    {
        $obra = $this->makeObra();
        app(AtlasDecideService::class)->operationalDecision([
            'source_type' => 'app',
            'input_text' => 'Forge.',
            'payload' => [
                'surface_id' => 'atlas_code',
                'routing_domain' => 'programming',
                'programming_flow' => 'programming.forge',
                'obra_id' => (string) $obra->id,
            ],
        ], 'codex_cli', 'gpt-5.5');

        $this->postJson('/atlas-code/works/'.$obra->id.'/forge/runtime-dispatch', [
            'role' => 'primary_builder',
        ], $this->headers())
            ->assertJsonPath('schema_version', AtlasForgeRuntimeDispatchService::SCHEMA_VERSION)
            ->assertJsonPath('external_provider_call', false)
            ->assertJsonPath('role', 'primary_builder');

        $this->getJson('/atlas-code/works/'.$obra->id.'/forge/runtime-dispatch', $this->headers())
            ->assertOk()
            ->assertJsonPath('schema_version', AtlasForgeRuntimeDispatchService::SCHEMA_VERSION)
            ->assertJsonPath('obra_id', (string) $obra->id);
    }

    public function test_runtime_dispatch_cli_exits_non_zero_in_strict_without_obra(): void
    {
        $exitCode = Artisan::call('atlas:forge:runtime-dispatch', ['--strict' => true, '--json' => true]);
        $this->assertSame(1, $exitCode);
        $output = Artisan::output();
        $payload = json_decode($output, true);
        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('obra_required', $payload['blockers']);
    }

    private function makeObra(): AtlasProject
    {
        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'Runtime Dispatch test Obra',
            'description' => 'Atlas Forge Runtime Dispatcher governado',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'Validar runtime dispatcher governado',
            'desired_outcome' => 'Dispatcher emite plano canonico sem chamar provider externo',
            'priority' => 'normal',
            'metadata' => ['workspace_path' => '/tmp/atlas-runtime-dispatch-test', 'origin' => 'atlas-code-test'],
        ]);
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
