<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeForgeWorkIntakeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasCodeForgeWorkIntakeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
    }

    public function test_intake_fails_closed_without_obra(): void
    {
        $exitCode = $this->artisan('atlas:code:forge-intake', ['--json' => true, '--strict' => true])->run();
        $this->assertSame(1, $exitCode);
    }

    public function test_intake_blocks_missing_business_rule(): void
    {
        $obra = $this->makeObra();
        $intake = app(AtlasCodeForgeWorkIntakeService::class)->save($obra, [
            'objective' => 'Refatorar contrato Forge',
            'acceptance_criteria' => ['suite verde'],
            'canonical_docs' => ['docs/engineering-knowledge-base/atlas-programming-forge-flow.md'],
        ]);

        $this->assertSame('blocked', $intake['readiness_status']);
        $this->assertContains('blocked_missing_business_rule', $intake['blockers']);
    }

    public function test_intake_blocks_missing_acceptance_criteria(): void
    {
        $obra = $this->makeObra();
        $intake = app(AtlasCodeForgeWorkIntakeService::class)->save($obra, [
            'objective' => 'Refatorar contrato Forge',
            'business_rule' => 'Toda Obra precisa de intake antes do Forge',
            'canonical_docs' => ['docs/engineering-knowledge-base/atlas-programming-forge-flow.md'],
        ]);

        $this->assertSame('blocked', $intake['readiness_status']);
        $this->assertContains('blocked_missing_acceptance_criteria', $intake['blockers']);
    }

    public function test_intake_ready_with_full_payload(): void
    {
        $obra = $this->makeObra();
        $intake = app(AtlasCodeForgeWorkIntakeService::class)->save($obra, [
            'objective' => 'Refatorar contrato Forge',
            'business_rule' => 'Toda Obra precisa de intake governado',
            'acceptance_criteria' => ['suite verde'],
            'canonical_docs' => ['docs/engineering-knowledge-base/atlas-programming-forge-flow.md'],
        ]);

        $this->assertSame('ready', $intake['readiness_status']);
        $this->assertTrue($intake['enterprise_ready']);
        $this->assertSame([], $intake['blockers']);
        $this->assertSame('atlas.code.forge_work_intake.v1', $intake['schema_version']);
    }

    public function test_intake_persists_latest_and_history(): void
    {
        $obra = $this->makeObra();
        $service = app(AtlasCodeForgeWorkIntakeService::class);

        $first = $service->save($obra, [
            'objective' => 'A',
            'business_rule' => 'B',
            'acceptance_criteria' => ['ok'],
            'canonical_docs' => ['docs/engineering-knowledge-base/atlas-programming-forge-flow.md'],
        ]);
        $service->save($obra->refresh(), [
            'objective' => 'A2',
            'business_rule' => 'B2',
            'acceptance_criteria' => ['ok2'],
            'canonical_docs' => ['docs/engineering-knowledge-base/atlas-programming-forge-flow.md'],
        ]);

        $obra->refresh();
        $latest = data_get($obra->metadata, 'latest_atlas_code_forge_work_intake');
        $this->assertSame('A2', $latest['objective']);
        $history = data_get($obra->metadata, 'atlas_code_forge_work_intake_history');
        $this->assertIsArray($history);
        $this->assertGreaterThanOrEqual(1, count((array) $history));
        $this->assertSame($first['intake_id'], $latest['intake_id'], 'intake_id deve permanecer canonico para a mesma Obra');
    }

    public function test_state_endpoint_exposes_forge_work_intake(): void
    {
        $obra = $this->makeObra();
        app(AtlasCodeForgeWorkIntakeService::class)->save($obra, [
            'objective' => 'Refatorar contrato Forge',
            'business_rule' => 'Toda Obra precisa de intake governado',
            'acceptance_criteria' => ['ok'],
            'canonical_docs' => ['docs/engineering-knowledge-base/atlas-programming-forge-flow.md'],
        ]);

        // Reusa o helper canonico do controller via reflection (state endpoint precisa de muitas tabelas).
        $controller = new \App\Http\Controllers\AtlasCodeWorkController();
        $method = (new \ReflectionClass($controller))->getMethod('forgeWorkIntakeForWork');
        $method->setAccessible(true);
        $intake = $method->invoke($controller, $obra->refresh());

        $this->assertIsArray($intake);
        $this->assertSame((string) $obra->id, $intake['obra_id']);
        $this->assertSame('ready', $intake['readiness_status']);
    }

    public function test_cli_strict_exits_non_zero_when_blocked(): void
    {
        $obra = $this->makeObra();
        $exit = $this->artisan('atlas:code:forge-intake', [
            '--obra' => (string) $obra->id,
            '--json' => true,
            '--strict' => true,
        ])->run();
        $this->assertSame(1, $exit, 'sem business_rule/acceptance deve falhar em strict');
    }

    public function test_completion_audit_exposes_intake_certification(): void
    {
        $payload = (array) app(\App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService::class)
            ->report(base_path(), false);

        $this->assertArrayHasKey('atlas_code_forge_work_intake_certification', $payload);
        $cert = $payload['atlas_code_forge_work_intake_certification'];
        $this->assertSame('atlas.code.forge_work_intake_certification.v1', $cert['schema_version']);
        $this->assertTrue($cert['invariants']['business_rule_required']);
        $this->assertTrue($cert['invariants']['acceptance_criteria_required']);
        $this->assertTrue($cert['invariants']['canonical_docs_required']);
        $this->assertTrue($cert['invariants']['readiness_gate_available']);
        $this->assertTrue($cert['invariants']['no_provider_call']);
    }

    public function test_no_provider_call(): void
    {
        $obra = $this->makeObra();
        $intake = app(AtlasCodeForgeWorkIntakeService::class)->save($obra, [
            'objective' => 'A',
            'business_rule' => 'B',
            'acceptance_criteria' => ['ok'],
            'canonical_docs' => ['docs/engineering-knowledge-base/atlas-programming-forge-flow.md'],
        ]);

        $this->assertFalse($intake['external_provider_call']);
    }

    public function test_work_item_link_available_when_governance_exists(): void
    {
        $obra = $this->makeObra();

        $workItem = new \App\Models\AtlasProgrammingWorkItem();
        $workItem->fill([
            'code' => 'WI-STUB',
            'intent_text' => 'stub intent',
            'intent_type' => 'refactor',
            'scope_mode' => 'structural',
            'risk_level' => 'medium',
            'status' => 'open',
            'current_stage' => 'intake',
        ]);
        $workItem->save();
        $workItemId = (string) $workItem->getKey();

        $metadata = is_array($obra->metadata) ? $obra->metadata : [];
        $metadata['programming_work_item_id'] = $workItemId;
        $metadata['programming_work_item_code'] = 'WI-STUB';
        $obra->forceFill(['metadata' => $metadata])->save();

        $intake = app(AtlasCodeForgeWorkIntakeService::class)->save($obra->refresh(), [
            'objective' => 'A',
            'business_rule' => 'B',
            'acceptance_criteria' => ['ok'],
            'canonical_docs' => ['docs/engineering-knowledge-base/atlas-programming-forge-flow.md'],
        ]);

        $this->assertSame($workItemId, $intake['work_item_id']);
        $this->assertSame('WI-STUB', $intake['work_item_code']);
    }

    private function makeObra(): AtlasProject
    {
        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'Intake Test Obra',
            'description' => 'Atlas Code Forge Work Intake test',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'Validar intake',
            'desired_outcome' => 'Validar intake e governance',
            'priority' => 'normal',
            'metadata' => ['workspace_path' => '/tmp/atlas-intake-test'],
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

        if (! Schema::hasTable('atlas_programming_work_items')) {
            Schema::create('atlas_programming_work_items', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('code', 64)->unique();
                $t->text('intent_text');
                $t->string('intent_type', 40)->index();
                $t->string('scope_mode', 24)->index();
                $t->string('risk_level', 16)->default('medium')->index();
                $t->string('owner', 80)->nullable()->index();
                $t->string('workspace', 255)->nullable();
                $t->string('status', 32)->index();
                $t->string('current_stage', 32)->index();
                $t->string('spec_hash', 64)->nullable()->index();
                $t->string('plan_hash', 64)->nullable()->index();
                $t->json('placement_json')->default('{}');
                $t->json('code_intelligence_json')->default('{}');
                $t->json('spec_json')->default('{}');
                $t->json('plan_json')->default('{}');
                $t->json('tasks_json')->default('[]');
                $t->json('evidence_refs_json')->default('[]');
                $t->json('gaps_json')->default('[]');
                $t->json('metadata_json')->default('{}');
                $t->timestamp('closed_at')->nullable()->index();
                $t->timestamps();
            });
        }
    }
}
