<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasInitiativeRun;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiSelfImprovementScheduleApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        Schema::dropIfExists('atlas_initiative_runs');
        Schema::create('atlas_initiative_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 48);
            $table->string('status', 24)->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('scope')->default('{}');
            $table->json('findings')->default('[]');
            $table->json('emitted_inbox_item_ids')->default('[]');
            $table->text('error_message')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_initiative_runs');

        parent::tearDown();
    }

    public function test_self_improvement_schedule_api_returns_recurring_plan(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'weekly_architecture_audit', 'repair_loop_review', 'kernel_pipeline_review', 'agent_behavior_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');
        config()->set('atlas_ai.self_improvement.hours', 24);
        config()->set('atlas_ai.self_improvement.limit', 5);
        config()->set('atlas_ai.self_improvement.emit', false);

        $response = $this->getJson('/ai/self-improvement/schedule', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 1)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('plan_hash_algorithm', 'sha256')
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('schedulable', true)
            ->assertJsonPath('scheduler_registration.status', 'registered')
            ->assertJsonPath('scheduler_registration.registered_command_count', 5)
            ->assertJsonPath('cadence_counts.daily', 4)
            ->assertJsonPath('cadence_counts.weekly', 1)
            ->assertJsonPath('time', '02:00')
            ->assertJsonPath('timezone', config('app.timezone'))
            ->assertJsonPath('count', 5)
            ->assertJsonPath('configured_flows.0', 'nightly_review')
            ->assertJsonPath('configured_flows.1', 'weekly_architecture_audit')
            ->assertJsonPath('configured_flows.2', 'repair_loop_review')
            ->assertJsonPath('configured_flows.3', 'kernel_pipeline_review')
            ->assertJsonPath('invalid_flows', [])
            ->assertJsonPath('defaulted', false)
            ->assertJsonPath('health.status', 'healthy')
            ->assertJsonPath('flows.0', 'nightly_review')
            ->assertJsonPath('flows.1', 'weekly_architecture_audit')
            ->assertJsonPath('flows.2', 'repair_loop_review')
            ->assertJsonPath('flows.3', 'kernel_pipeline_review')
            ->assertJsonPath('commands.1.command', 'atlas:ai:self-improve --flow=weekly_architecture_audit --hours=24 --limit=5 --json')
            ->assertJsonPath('commands.1.cadence', 'weekly')
            ->assertJsonPath('commands.1.week_day', 1)
            ->assertJsonPath('commands.2.command', 'atlas:ai:self-improve --flow=repair_loop_review --hours=24 --limit=5 --json')
            ->assertJsonPath('commands.2.cadence', 'daily')
            ->assertJsonPath('commands.3.command', 'atlas:ai:self-improve --flow=kernel_pipeline_review --hours=24 --limit=5 --json')
            ->assertJsonPath('commands.3.cadence', 'daily')
            ->assertJsonPath('emit', false);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response->json('plan_hash'));

        $this->assertSame(0, AtlasInitiativeRun::query()->count(), 'Schedule API must not create initiative runs.');
    }

    public function test_self_improvement_schedule_api_reports_invalid_configured_flows(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['unknown_flow', 'self_improvement.repair_loop_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');
        config()->set('atlas_ai.self_improvement.hours', 24);
        config()->set('atlas_ai.self_improvement.limit', 5);
        config()->set('atlas_ai.self_improvement.emit', false);

        $this->getJson('/ai/self-improvement/schedule', $this->headers)
            ->assertOk()
            ->assertJsonPath('configured_flows.0', 'unknown_flow')
            ->assertJsonPath('configured_flows.1', 'self_improvement.repair_loop_review')
            ->assertJsonPath('invalid_flows.0', 'unknown_flow')
            ->assertJsonPath('defaulted', false)
            ->assertJsonPath('health.status', 'warning')
            ->assertJsonPath('health.issues.0', 'invalid_self_improvement_flows_configured')
            ->assertJsonPath('flows.0', 'repair_loop_review')
            ->assertJsonPath('commands.0.command', 'atlas:ai:self-improve --flow=repair_loop_review --hours=24 --limit=5 --json');
    }

    public function test_self_improvement_schedule_health_api_returns_compact_summary(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['unknown_flow', 'self_improvement.repair_loop_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');
        config()->set('atlas_ai.self_improvement.hours', 24);
        config()->set('atlas_ai.self_improvement.limit', 5);
        config()->set('atlas_ai.self_improvement.emit', false);

        $response = $this->getJson('/ai/self-improvement/schedule/health', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 1)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('plan_hash_algorithm', 'sha256')
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('schedulable', true)
            ->assertJsonPath('scheduler_registration.status', 'registered')
            ->assertJsonPath('scheduler_registration.registered_command_count', 1)
            ->assertJsonPath('time', '02:00')
            ->assertJsonPath('timezone', config('app.timezone'))
            ->assertJsonPath('flow_count', 1)
            ->assertJsonStructure(['next_run_at'])
            ->assertJsonPath('invalid_flow_count', 1)
            ->assertJsonPath('defaulted', false)
            ->assertJsonPath('emit', false)
            ->assertJsonPath('health.status', 'warning')
            ->assertJsonPath('health.issues.0', 'invalid_self_improvement_flows_configured')
            ->assertJsonMissingPath('commands')
            ->assertJsonMissingPath('configured_flows');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response->json('plan_hash'));
    }

    public function test_self_improvement_schedule_health_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/self-improvement/schedule/health')
            ->assertUnauthorized();
    }

    public function test_self_improvement_schedule_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/self-improvement/schedule')
            ->assertUnauthorized();
    }
}
