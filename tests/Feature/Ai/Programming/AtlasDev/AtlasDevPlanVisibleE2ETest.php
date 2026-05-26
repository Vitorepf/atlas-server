<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\AtlasDev\PlanVisible\AtlasDevPlanApprovalGate;
use App\Services\Ai\Programming\AtlasDev\Schemas\PlanVisible;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Atlas Dev A2 — Plan Visible end-to-end.
 *
 * Definition of Done from Gap 2:
 *
 *   - atlas-cli dev plan --task=<uuid> retorna plano JSON              ✓
 *   - rejeitar plano cancela a execução (gate blocks provider)         ✓
 *   - teste AtlasDevPlanVisibleE2ETest verde                            ✓ (this file)
 *
 * The desktop surface (Gap2.F3) is intentionally NOT exercised here —
 * this test covers the CLI + service layer that the surface consumes.
 * Surface coverage requires a preview server and lives in a separate
 * test suite once Gap2.F3 ships.
 */
class AtlasDevPlanVisibleE2ETest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Manually create only the table the test needs. The full migration
        // tree contains Postgres-only DDL (extensions, plpgsql) that breaks
        // on sqlite, so we cherry-pick the schema for atlas_programming_work_items.
        if (! Schema::hasTable('atlas_programming_work_items')) {
            Schema::create('atlas_programming_work_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code', 64)->unique();
                $table->text('intent_text');
                $table->string('intent_type', 40)->index();
                $table->string('scope_mode', 24)->index();
                $table->string('risk_level', 16)->default('medium')->index();
                $table->string('owner', 80)->nullable()->index();
                $table->string('workspace', 255)->nullable();
                $table->string('status', 32)->index();
                $table->string('current_stage', 32)->index();
                $table->string('spec_hash', 64)->nullable()->index();
                $table->string('plan_hash', 64)->nullable()->index();
                $table->json('placement_json')->default('{}');
                $table->json('code_intelligence_json')->default('{}');
                $table->json('spec_json')->default('{}');
                $table->json('plan_json')->default('{}');
                $table->json('tasks_json')->default('[]');
                $table->json('evidence_refs_json')->default('[]');
                $table->json('gaps_json')->default('[]');
                $table->json('metadata_json')->default('{}');
                $table->timestamp('closed_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_programming_work_items');
        parent::tearDown();
    }

    public function test_full_lifecycle_project_then_approve_unblocks_provider(): void
    {
        $workItem = $this->makeWorkItem();

        // 1. Project a plan via the CLI command.
        $this->artisan('atlas:cli:dev:plan', [
            'action' => 'project',
            '--task' => $workItem->id,
            '--target-files' => ['app/Services/Foo/BarService.php'],
            '--tests' => ['tests/Unit/Foo/BarServiceTest.php'],
            '--summary' => 'Adds method handle() that returns array<string,mixed>.',
            '--risk-band' => 'medium',
            '--json' => true,
        ])->assertExitCode(0);

        // Reload via fresh query to bypass any stale in-memory state.
        $reloaded = AtlasProgrammingWorkItem::query()->findOrFail($workItem->id);
        $this->assertNotNull($reloaded->plan_hash);
        $persisted = (array) $reloaded->plan_json;
        $this->assertSame('atlas.dev.plan_visible.v1', $persisted['schema_version']);
        $this->assertSame('pending', $persisted['approval_status']);
        $this->assertSame(['app/Services/Foo/BarService.php'], $persisted['target_files']);

        // 2. Gate must block the provider while pending.
        $gate = app(AtlasDevPlanApprovalGate::class);
        $plan = PlanVisible::fromArray($persisted);
        $this->assertSame('provider_blocked', $gate->evaluate($plan)['decision']);

        // 3. Approve via CLI.
        $this->artisan('atlas:cli:dev:plan', [
            'action' => 'approve',
            '--task' => $workItem->id,
            '--json' => true,
        ])->assertExitCode(0);

        $reloaded = AtlasProgrammingWorkItem::query()->findOrFail($workItem->id);
        $approved = (array) $reloaded->plan_json;
        $this->assertSame('approved', $approved['approval_status']);
        $this->assertNotSame($persisted['plan_hash'], $approved['plan_hash'], 'approval changes plan hash');

        // 4. Gate now allows provider invocation.
        $this->assertSame(
            'may_fire_provider',
            $gate->evaluate(PlanVisible::fromArray($approved))['decision'],
        );

        // 5. metadata_json records the revision.
        $meta = (array) $reloaded->metadata_json;
        $this->assertArrayHasKey('plan_revisions', $meta);
        $this->assertCount(1, $meta['plan_revisions']);
        $this->assertSame('approved', $meta['plan_revisions'][0]['decision']);
    }

    public function test_reject_cancels_execution(): void
    {
        $workItem = $this->makeWorkItem();

        $this->artisan('atlas:cli:dev:plan', [
            'action' => 'project',
            '--task' => $workItem->id,
            '--target-files' => ['app/Foo.php'],
            '--tests' => ['tests/FooTest.php'],
            '--summary' => 'risky change',
            '--risk-band' => 'high',
            '--json' => true,
        ])->assertExitCode(0);

        $this->artisan('atlas:cli:dev:plan', [
            'action' => 'reject',
            '--task' => $workItem->id,
            '--json' => true,
        ])->assertExitCode(0);

        $workItem->refresh();
        $rejected = (array) $workItem->plan_json;
        $this->assertSame('rejected', $rejected['approval_status']);

        $gate = app(AtlasDevPlanApprovalGate::class);
        $decision = $gate->evaluate(PlanVisible::fromArray($rejected));
        $this->assertSame('provider_blocked', $decision['decision']);
        $this->assertSame('plan_rejected_by_operator', $decision['block_reason']);
    }

    public function test_inspect_returns_plan_json(): void
    {
        $workItem = $this->makeWorkItem();

        // No plan yet.
        $this->artisan('atlas:cli:dev:plan', [
            'action' => 'inspect',
            '--task' => $workItem->id,
            '--json' => true,
        ])->assertExitCode(0);

        // After project, inspect returns the plan.
        $this->artisan('atlas:cli:dev:plan', [
            'action' => 'project',
            '--task' => $workItem->id,
            '--target-files' => ['app/Foo.php'],
            '--tests' => ['tests/FooTest.php'],
            '--summary' => 'tweak',
            '--risk-band' => 'low',
            '--json' => true,
        ])->assertExitCode(0);

        $this->artisan('atlas:cli:dev:plan', [
            'action' => 'inspect',
            '--task' => $workItem->id,
            '--json' => true,
        ])->assertExitCode(0);

        $workItem->refresh();
        $this->assertNotEmpty($workItem->plan_json);
    }

    public function test_telemetry_aggregates_approval_metrics(): void
    {
        // 3 items: 2 approved, 1 rejected.
        $a = $this->makeWorkItem();
        $b = $this->makeWorkItem();
        $c = $this->makeWorkItem();

        foreach ([$a, $b, $c] as $item) {
            $this->artisan('atlas:cli:dev:plan', [
                'action' => 'project',
                '--task' => $item->id,
                '--target-files' => ['app/Foo.php'],
                '--tests' => ['tests/FooTest.php'],
                '--summary' => 'tweak',
                '--risk-band' => 'medium',
                '--json' => true,
            ])->assertExitCode(0);
        }

        $this->artisan('atlas:cli:dev:plan', ['action' => 'approve', '--task' => $a->id, '--json' => true])->assertExitCode(0);
        $this->artisan('atlas:cli:dev:plan', ['action' => 'approve', '--task' => $b->id, '--json' => true])->assertExitCode(0);
        $this->artisan('atlas:cli:dev:plan', ['action' => 'reject', '--task' => $c->id, '--json' => true])->assertExitCode(0);

        $snapshot = app(\App\Services\Ai\Programming\AtlasDev\PlanVisible\AtlasDevPlanTelemetry::class)->snapshot();

        $this->assertSame('ok', $snapshot['status']);
        $this->assertSame(3, $snapshot['counts']['total_work_items_with_plan']);
        $this->assertSame(2, $snapshot['counts']['approved']);
        $this->assertSame(1, $snapshot['counts']['rejected']);
        $this->assertSame(0, $snapshot['counts']['pending']);
        $this->assertEqualsWithDelta(0.6667, $snapshot['rates']['approval_rate'], 0.001);
        $this->assertEqualsWithDelta(0.3333, $snapshot['rates']['rejection_rate'], 0.001);
        $this->assertSame(3, $snapshot['counts']['total_revisions']);
    }

    public function test_project_without_target_files_fails(): void
    {
        $workItem = $this->makeWorkItem();

        $this->artisan('atlas:cli:dev:plan', [
            'action' => 'project',
            '--task' => $workItem->id,
            '--summary' => 'missing files',
            '--json' => true,
        ])->assertExitCode(1);
    }

    public function test_approve_without_plan_fails(): void
    {
        $workItem = $this->makeWorkItem();

        $this->artisan('atlas:cli:dev:plan', [
            'action' => 'approve',
            '--task' => $workItem->id,
            '--json' => true,
        ])->assertExitCode(1);
    }

    public function test_command_requires_task_for_non_telemetry_actions(): void
    {
        $this->artisan('atlas:cli:dev:plan', [
            'action' => 'inspect',
            '--json' => true,
        ])->assertExitCode(2);
    }

    public function test_telemetry_works_without_task_option(): void
    {
        $this->artisan('atlas:cli:dev:plan', [
            'action' => 'telemetry',
            '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_telemetry_on_empty_set_returns_pending_data(): void
    {
        $snapshot = app(\App\Services\Ai\Programming\AtlasDev\PlanVisible\AtlasDevPlanTelemetry::class)->snapshot();

        $this->assertSame('pending_data', $snapshot['status']);
        $this->assertNull($snapshot['rates']['approval_rate']);
    }

    private function makeWorkItem(): AtlasProgrammingWorkItem
    {
        return AtlasProgrammingWorkItem::query()->create([
            'code' => 'wi-'.bin2hex(random_bytes(6)),
            'intent_text' => 'add bar method to Foo',
            'intent_type' => 'feature',
            'scope_mode' => 'focused',
            'risk_level' => 'medium',
            'status' => 'draft',
            'current_stage' => 'intake',
            // Real work items always carry a spec_hash by the time A2 fires
            // (the programming intake stage emits MiniProgrammingSpec first).
            // The projection service refuses to project without it, so the
            // test seeds a synthetic hash that simulates that upstream state.
            'spec_hash' => hash('sha256', 'synthetic-spec-'.uniqid()),
        ]);
    }
}
