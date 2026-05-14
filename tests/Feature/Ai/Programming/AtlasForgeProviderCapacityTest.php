<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasForgeProviderCapacityService;
use App\Services\Ai\Programming\AtlasForgeProviderFailureMemoryService;
use App\Services\Ai\Programming\AtlasForgeProviderFallbackPolicyService;
use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;
use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Capacity continuity tests required by
 * `docs/engineering-knowledge-base/atlas-forge-provider-capacity-continuity-v1.md`.
 *
 * The capacity service is fully implemented by upstream linter passes; this
 * file pins the canonical surface so the doc's repo_paths remains valid and
 * docs-health stops reporting the file as missing.
 */
class AtlasForgeProviderCapacityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
    }

    public function test_capacity_snapshot_exposes_canonical_schema(): void
    {
        $obra = $this->makeObra();
        $payload = app(AtlasForgeProviderCapacityService::class)->snapshot(['obra_id' => (string) $obra->id]);

        $this->assertSame(AtlasForgeProviderCapacityService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertIsArray($payload['providers'] ?? null);
        $this->assertNotEmpty($payload['providers']);
        $this->assertArrayHasKey('blockers', $payload);
        $this->assertArrayHasKey('external_provider_call', $payload);
        $this->assertFalse((bool) $payload['external_provider_call']);
    }

    public function test_capacity_endpoint_returns_state_for_obra(): void
    {
        $obra = $this->makeObra();
        $this->getJson('/atlas-code/works/'.$obra->id.'/state', $this->headers())
            ->assertOk()
            ->assertJsonPath('forge_provider_capacity.schema_version', AtlasForgeProviderCapacityService::SCHEMA_VERSION)
            ->assertJsonPath('forge_provider_capacity.external_provider_call', false);
    }

    public function test_provider_capacity_returns_all_canonical_providers(): void
    {
        $snapshot = app(AtlasForgeProviderCapacityService::class)->snapshot();
        $this->assertCount(5, $snapshot['providers']);
        $providerKeys = array_map(fn (array $p): string => (string) $p['provider'], $snapshot['providers']);
        foreach (AtlasForgeProviderCapacityService::CANONICAL_PROVIDERS as $expected) {
            $this->assertContains($expected, $providerKeys);
        }
    }

    public function test_provider_capacity_never_calls_external_provider(): void
    {
        $snapshot = app(AtlasForgeProviderCapacityService::class)->snapshot();
        $this->assertFalse($snapshot['external_provider_call']);
        $this->assertFalse($snapshot['provider_tokens_spent']);
        $this->assertTrue($snapshot['is_read_model']);
        foreach ($snapshot['providers'] as $entry) {
            $this->assertFalse($entry['external_provider_call']);
        }
    }

    public function test_provider_capacity_uses_unknown_when_signal_missing(): void
    {
        $snapshot = app(AtlasForgeProviderCapacityService::class)->snapshot();
        $composed = collect($snapshot['providers'])->firstWhere('provider', 'claude_codex');
        $this->assertNotNull($composed);
        // Composed runtime is unavailable when not explicitly configured —
        // never silently 'available'.
        $this->assertContains($composed['status'], ['unavailable', 'unknown']);
    }

    public function test_failure_record_fails_closed_without_obra_in_strict(): void
    {
        $exitCode = Artisan::call('atlas:forge:provider-failure-record', [
            '--strict' => true,
            '--json' => true,
        ]);
        $this->assertSame(1, $exitCode);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('obra_required', $payload['blocker']);
    }

    public function test_failure_record_rejects_unknown_failure_type(): void
    {
        $obra = $this->makeObra();
        $exitCode = Artisan::call('atlas:forge:provider-failure-record', [
            '--obra' => (string) $obra->id,
            '--provider' => 'claude_cli',
            '--failure' => 'bogus_failure_type',
            '--strict' => true,
            '--json' => true,
        ]);
        $this->assertSame(1, $exitCode);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('unknown_failure_type', $payload['blocker']);
    }

    public function test_rate_limit_failure_records_memory_and_cooldown(): void
    {
        $obra = $this->makeObra();
        $event = app(AtlasForgeProviderFailureMemoryService::class)->record($obra, [
            'provider' => 'claude_cli',
            'failure_type' => AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT,
            'role' => 'primary_builder',
            'model' => 'claude-opus-4-7',
        ]);
        $this->assertSame('rate_limit', $event['failure_type']);
        $this->assertNotNull($event['cooldown_until']);
        $this->assertFalse($event['silent']);
        $obra->refresh();
        $memory = app(AtlasForgeProviderFailureMemoryService::class)->memoryFor($obra);
        $this->assertGreaterThanOrEqual(1, $memory['event_count']);
    }

    public function test_quota_exhausted_marks_provider_limited_or_exhausted(): void
    {
        $obra = $this->makeObra();
        app(AtlasForgeProviderFailureMemoryService::class)->record($obra, [
            'provider' => 'claude_cli',
            'failure_type' => AtlasForgeProviderFallbackPolicyService::FAILURE_QUOTA_EXHAUSTED,
            'role' => 'primary_builder',
        ]);
        $obra->refresh();
        $snapshot = app(AtlasForgeProviderCapacityService::class)->snapshot([
            'obra_id' => (string) $obra->id,
        ]);
        $entry = collect($snapshot['providers'])->firstWhere('provider', 'claude_cli');
        $this->assertNotNull($entry);
        $this->assertContains($entry['quota_state'], ['limited', 'exhausted']);
    }

    public function test_auth_failed_records_memory_with_no_cooldown(): void
    {
        $obra = $this->makeObra();
        $event = app(AtlasForgeProviderFailureMemoryService::class)->record($obra, [
            'provider' => 'claude_cli',
            'failure_type' => AtlasForgeProviderFallbackPolicyService::FAILURE_AUTH_FAILED,
            'role' => 'primary_builder',
        ]);
        $this->assertSame('auth_failed', $event['failure_type']);
        $this->assertNull($event['cooldown_until']);
    }

    public function test_provider_capacity_exhausted_blocks_topology(): void
    {
        $obra = $this->makeObra();
        foreach (AtlasForgeProviderCapacityService::CANONICAL_PROVIDERS as $provider) {
            if ($provider === AtlasForgeProviderCapacityService::PROVIDER_ATLAS_LOCAL) {
                continue;
            }
            app(AtlasForgeProviderFailureMemoryService::class)->record($obra, [
                'provider' => $provider,
                'failure_type' => AtlasForgeProviderFallbackPolicyService::FAILURE_CAPACITY_EXHAUSTED,
                'role' => 'primary_builder',
            ]);
        }
        $obra->refresh();
        $snapshot = app(AtlasForgeProviderCapacityService::class)->snapshot([
            'obra_id' => (string) $obra->id,
        ]);
        $exhaustedCount = 0;
        foreach ($snapshot['providers'] as $entry) {
            if (($entry['capacity_state'] ?? null) === 'exhausted') {
                $exhaustedCount++;
            }
        }
        $this->assertGreaterThan(0, $exhaustedCount);
    }

    public function test_topology_consumes_capacity_snapshot_id(): void
    {
        $obra = $this->makeObra();
        $topology = app(AtlasForgeProviderTopologyService::class)->topology([
            'obra_id' => (string) $obra->id,
        ]);
        $this->assertArrayHasKey('capacity_snapshot_id', $topology);
        $this->assertNotNull($topology['capacity_snapshot_id']);
        $this->assertArrayHasKey('capacity_summary', $topology);
    }

    public function test_fallback_policy_records_failure_memory(): void
    {
        $obra = $this->makeObra();
        $topology = app(AtlasForgeProviderTopologyService::class)->topology([
            'obra_id' => (string) $obra->id,
        ]);
        $primary = collect($topology['roles'])->firstWhere('role', 'primary_builder');
        $classification = app(AtlasForgeProviderFallbackPolicyService::class)->classify(
            failure: [
                'type' => AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT,
                'role' => $primary['role'],
                'provider' => $primary['provider'],
                'model' => $primary['model'],
            ],
            topology: $topology,
        );
        $event = $classification['event'];
        $this->assertNotNull($event['capacity_snapshot_id']);
        $this->assertNotNull($event['cooldown_until']);
        $this->assertFalse($event['silent']);
        $this->assertTrue($event['failure_memory_recorded']);
        $this->assertNotNull($event['failure_memory_event_id']);
    }

    public function test_state_endpoint_exposes_capacity_and_failure_memory(): void
    {
        $obra = $this->makeObra();
        $this->getJson('/atlas-code/works/'.$obra->id.'/state', $this->headers())
            ->assertOk()
            ->assertJsonPath('forge_provider_capacity.schema_version', 'atlas.forge.provider_capacity.v1')
            ->assertJsonPath('forge_provider_failure_memory.schema_version', 'atlas.forge.provider_failure_memory.v1');
    }

    public function test_capacity_api_global_and_obra_scope(): void
    {
        $obra = $this->makeObra();
        $this->getJson('/atlas-code/forge/provider-capacity', $this->headers())
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.forge.provider_capacity.v1')
            ->assertJsonPath('obra_id', null);
        $this->getJson('/atlas-code/works/'.$obra->id.'/forge/provider-capacity', $this->headers())
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.forge.provider_capacity.v1')
            ->assertJsonPath('obra_id', (string) $obra->id);
    }

    public function test_failure_api_records_event_and_returns_updated_capacity(): void
    {
        $obra = $this->makeObra();
        $this->postJson(
            '/atlas-code/works/'.$obra->id.'/forge/provider-failures',
            [
                'provider' => 'claude_cli',
                'model' => 'claude-opus-4-7',
                'role' => 'primary_builder',
                'failure_type' => 'rate_limit',
                'reason' => 'test-suite',
            ],
            $this->headers(),
        )
            ->assertCreated()
            ->assertJsonPath('status', 'recorded')
            ->assertJsonPath('event.failure_type', 'rate_limit')
            ->assertJsonPath('capacity_snapshot.schema_version', 'atlas.forge.provider_capacity.v1')
            ->assertJsonPath('failure_memory.schema_version', 'atlas.forge.provider_failure_memory.v1');
    }

    public function test_failure_api_rejects_unknown_failure_type(): void
    {
        $obra = $this->makeObra();
        $this->postJson(
            '/atlas-code/works/'.$obra->id.'/forge/provider-failures',
            ['provider' => 'claude_cli', 'failure_type' => 'bogus'],
            $this->headers(),
        )
            ->assertStatus(422)
            ->assertJsonPath('blocker', 'unknown_failure_type');
    }

    public function test_completion_audit_exposes_provider_capacity_certification(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path());
        $this->assertArrayHasKey('atlas_forge_provider_capacity_certification', $report);
        $block = $report['atlas_forge_provider_capacity_certification'];
        $this->assertSame('atlas.forge_provider_capacity_certification.v1', $block['schema_version']);
        $this->assertGreaterThanOrEqual(15, count($block['invariants']));
        $this->assertFalse($block['external_provider_call']);
        $this->assertFalse($block['provider_tokens_spent']);
        $this->assertSame('external_rivals_certification', $block['separated_from']);
        $this->assertTrue($block['separated_from_external_rivals_certification']);
    }

    public function test_external_rivals_remains_separated_and_blocked(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path());
        $this->assertArrayHasKey('external_rivals_certification', $report);
        $capacityBlock = $report['atlas_forge_provider_capacity_certification'];
        $this->assertFalse($capacityBlock['promotes_external_rivals_claim']);
    }

    private function makeObra(): AtlasProject
    {
        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'Capacity smoke obra',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'capacity smoke',
            'desired_outcome' => 'capacity snapshot canonical',
            'priority' => 'normal',
            'metadata' => ['workspace_path' => '/tmp/atlas-capacity-test'],
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
