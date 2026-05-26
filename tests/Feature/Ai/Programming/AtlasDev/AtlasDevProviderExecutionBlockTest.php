<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\AtlasDev\PlanVisible\AtlasDevProviderExecutionBlock;
use App\Services\Ai\Programming\AtlasDev\PlanVisible\ProviderExecutionCancelledException;
use App\Services\Ai\Programming\AtlasDev\Schemas\PlanVisible;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Gap2.F4 DoD invariant — "rejeitar plano cancela a execução".
 *
 *   - assertMayFire throws when plan is rejected.
 *   - assertMayFire throws when plan is pending.
 *   - assertMayFire throws when no plan persisted.
 *   - assertMayFire returns envelope when plan is approved.
 *   - evaluate (non-throwing) emits canonical schema.
 *   - cancellationEnvelope encodes rejection reason canonically.
 */
class AtlasDevProviderExecutionBlockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_programming_work_items')) {
            Schema::create('atlas_programming_work_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('code', 64)->unique();
                $table->text('intent_text');
                $table->string('intent_type', 40);
                $table->string('scope_mode', 24);
                $table->string('risk_level', 16)->default('medium');
                $table->string('status', 32);
                $table->string('current_stage', 32);
                $table->string('spec_hash', 64)->nullable();
                $table->string('plan_hash', 64)->nullable();
                $table->json('placement_json')->default('{}');
                $table->json('plan_json')->default('{}');
                $table->json('metadata_json')->default('{}');
                $table->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_programming_work_items');
        parent::tearDown();
    }

    public function test_throws_when_no_plan_persisted(): void
    {
        $wi = $this->makeWorkItem();
        $block = $this->app->make(AtlasDevProviderExecutionBlock::class);

        try {
            $block->assertMayFire($wi);
            $this->fail('expected ProviderExecutionCancelledException');
        } catch (ProviderExecutionCancelledException $e) {
            $this->assertStringContainsString('no_plan_persisted', $e->getMessage());
            $this->assertSame('provider_blocked', $e->gateDecision['decision']);
        }
    }

    public function test_throws_when_plan_pending(): void
    {
        $wi = $this->makeWorkItemWithPlan(PlanVisible::APPROVAL_STATUS_PENDING);
        $block = $this->app->make(AtlasDevProviderExecutionBlock::class);

        $this->expectException(ProviderExecutionCancelledException::class);
        $block->assertMayFire($wi);
    }

    public function test_throws_when_plan_rejected_dod_rejeitar_cancela(): void
    {
        $wi = $this->makeWorkItemWithPlan(PlanVisible::APPROVAL_STATUS_REJECTED);
        $block = $this->app->make(AtlasDevProviderExecutionBlock::class);

        try {
            $block->assertMayFire($wi);
            $this->fail('rejected plan must cancel execution');
        } catch (ProviderExecutionCancelledException $e) {
            $this->assertStringContainsString('plan_rejected_by_operator', $e->getMessage());
        }
    }

    public function test_returns_envelope_when_plan_approved(): void
    {
        $wi = $this->makeWorkItemWithPlan(PlanVisible::APPROVAL_STATUS_APPROVED);
        $block = $this->app->make(AtlasDevProviderExecutionBlock::class);

        $envelope = $block->assertMayFire($wi);

        $this->assertTrue($envelope['execution_allowed']);
        $this->assertSame('approved', $envelope['approval_status']);
        $this->assertSame('atlas.dev.provider_execution_block.v1', $envelope['schema_version']);
    }

    public function test_evaluate_does_not_throw_for_rejected_plan(): void
    {
        $wi = $this->makeWorkItemWithPlan(PlanVisible::APPROVAL_STATUS_REJECTED);
        $block = $this->app->make(AtlasDevProviderExecutionBlock::class);

        $envelope = $block->evaluate($wi);

        $this->assertFalse($envelope['execution_allowed']);
        $this->assertSame('plan_rejected_by_operator', $envelope['cancelled_reason']);
        $this->assertSame('rejected', $envelope['approval_status']);
    }

    public function test_evaluate_emits_canonical_schema(): void
    {
        $wi = $this->makeWorkItemWithPlan(PlanVisible::APPROVAL_STATUS_APPROVED);
        $block = $this->app->make(AtlasDevProviderExecutionBlock::class);

        $envelope = $block->evaluate($wi);

        $this->assertSame([
            'schema_version',
            'execution_allowed',
            'cancelled_reason',
            'plan_hash',
            'approval_status',
            'gate_decision',
        ], array_keys($envelope));
    }

    public function test_cancellation_envelope_encodes_rejection_canonically(): void
    {
        $block = $this->app->make(AtlasDevProviderExecutionBlock::class);
        $wi = $this->makeWorkItem();

        $approvedPlan = PlanVisible::issue('r', 'sha:c', ['app/Foo.php'], ['t.php'],
            PlanVisible::RISK_BAND_MEDIUM, 'x', PlanVisible::APPROVAL_STATUS_REJECTED);

        $env = $block->cancellationEnvelope($wi, $approvedPlan);

        $this->assertSame('plan_rejected_by_operator', $env['cancelled_reason']);
        $this->assertFalse($env['execution_allowed']);
        $this->assertSame($wi->id, $env['work_item_id']);
        $this->assertNotEmpty($env['cancelled_at']);
    }

    private function makeWorkItem(): AtlasProgrammingWorkItem
    {
        return AtlasProgrammingWorkItem::query()->create([
            'code' => 'wi-'.bin2hex(random_bytes(6)),
            'intent_text' => 'x',
            'intent_type' => 'feature',
            'scope_mode' => 'focused',
            'risk_level' => 'medium',
            'status' => 'draft',
            'current_stage' => 'intake',
            'spec_hash' => 'sha:test',
        ]);
    }

    private function makeWorkItemWithPlan(string $status): AtlasProgrammingWorkItem
    {
        $wi = $this->makeWorkItem();
        $plan = PlanVisible::issue(
            runId: $wi->id,
            taskContractHash: 'sha:contract',
            targetFiles: ['app/Foo.php'],
            testsToRun: ['tests/FooTest.php'],
            riskBand: PlanVisible::RISK_BAND_MEDIUM,
            proposedDiffSummary: 'adds bar',
            approvalStatus: $status,
        );
        $wi->plan_json = $plan->toCanonicalArray();
        $wi->plan_hash = $plan->hash();
        $wi->save();

        return $wi;
    }
}
