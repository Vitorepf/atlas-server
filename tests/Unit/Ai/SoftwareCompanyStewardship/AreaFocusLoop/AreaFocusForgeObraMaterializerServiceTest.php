<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Models\AtlasProject;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusForgeObraMaterializerService as Materializer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOperatorDecisionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * S2 · Obra materializer: operator accept (AP-724) + forge handoff (AP-729) ->
 * a REAL governed Obra id, with receipt-authenticity (SEC-005) and identity
 * idempotency (SEC-006). Creating the Obra is the only mutation and is never
 * conflated with forge execution.
 */
final class AreaFocusForgeObraMaterializerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('atlas_projects')) {
            Schema::create('atlas_projects', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->text('description')->nullable();
                $t->string('status')->default('active');
                $t->string('domain')->default('atlas');
                $t->text('goal')->nullable();
                $t->text('next_action')->nullable();
                $t->text('desired_outcome')->nullable();
                $t->string('priority')->default('normal');
                $t->timestamp('last_touched_at')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }

    private function decision(): AreaFocusOperatorDecisionService
    {
        return app(AreaFocusOperatorDecisionService::class);
    }

    private function materializer(): Materializer
    {
        return app(Materializer::class);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function acceptReceipt(array $overrides = []): array
    {
        return $this->decision()->decide(array_replace([
            'operator_actor' => 'operator:vitor',
            'decision' => 'accept',
            'finding_hash' => 'fh_abc123',
            'work_order_id' => 'wo_001',
            'area_id' => 'agentic_engineering_os',
            'risk' => 'low',
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $candidateOverrides
     * @return array<string,mixed>
     */
    private function handoff(array $candidateOverrides = [], string $handoffId = 'affh_test01'): array
    {
        return [
            'handoff_id' => $handoffId,
            'area_id' => 'agentic_engineering_os',
            'obra_candidate' => array_replace([
                'title' => 'Consume inert contract X in Y decision path',
                'objective' => 'Wire the inert contract into the live decision path.',
                'scope' => ['allowed_paths' => ['app/Services/Ai/X.php'], 'forbidden_paths' => []],
                'risk_policy' => ['risk_level' => 'medium', 'acceptance_gates' => ['tests green']],
                'source_work_order_id' => 'wo_001',
                'forge_executed' => false,
            ], $candidateOverrides),
        ];
    }

    public function test_operator_accept_materializes_one_real_obra(): void
    {
        $result = $this->materializer()->materialize([
            'operator_actor' => 'operator:vitor',
            'decision_receipt' => $this->acceptReceipt(),
            'handoff' => $this->handoff(),
        ]);

        $this->assertSame(Materializer::STATUS_OBRA_CREATED, $result['status']);
        $this->assertNotEmpty($result['created_obra_id']);
        $this->assertFalse($result['forge_executed']);
        $this->assertFalse($result['mutates_target_repo']);
        $this->assertTrue($result['requires_owner_execution']);
        $this->assertSame(1, AtlasProject::query()->count());
        $this->assertNotNull(AtlasProject::query()->find($result['created_obra_id']));
        $this->assertArrayHasKey('work_intake', $result);
    }

    public function test_re_materializing_same_identity_is_idempotent(): void
    {
        $receipt = $this->acceptReceipt();
        $first = $this->materializer()->materialize([
            'operator_actor' => 'operator:vitor', 'decision_receipt' => $receipt, 'handoff' => $this->handoff(),
        ]);
        $second = $this->materializer()->materialize([
            'operator_actor' => 'operator:vitor', 'decision_receipt' => $receipt, 'handoff' => $this->handoff(),
        ]);

        $this->assertSame(Materializer::STATUS_IDEMPOTENT, $second['status']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['created_obra_id'], $second['created_obra_id']);
        $this->assertSame(1, AtlasProject::query()->count());
    }

    public function test_tampered_receipt_is_rejected_by_integrity_check(): void
    {
        $receipt = $this->acceptReceipt();
        // Mutate a field WITHOUT recomputing the hash — exactly a forged blob.
        $receipt['finding_hash'] = 'fh_attacker_swapped';

        $result = $this->materializer()->materialize([
            'operator_actor' => 'operator:vitor', 'decision_receipt' => $receipt, 'handoff' => $this->handoff(),
        ]);

        $this->assertSame(Materializer::STATUS_BLOCKED, $result['status']);
        $this->assertSame(Materializer::BLOCK_RECEIPT_INTEGRITY, $result['blocker']);
        $this->assertSame(0, AtlasProject::query()->count());
    }

    public function test_non_accept_decision_never_materializes(): void
    {
        $result = $this->materializer()->materialize([
            'operator_actor' => 'operator:vitor',
            'decision_receipt' => $this->acceptReceipt(['decision' => 'reject']),
            'handoff' => $this->handoff(),
        ]);

        $this->assertSame(Materializer::STATUS_BLOCKED, $result['status']);
        $this->assertSame(Materializer::BLOCK_NOT_ACCEPT, $result['blocker']);
        $this->assertSame(0, AtlasProject::query()->count());
    }

    public function test_work_order_mismatch_blocks(): void
    {
        $result = $this->materializer()->materialize([
            'operator_actor' => 'operator:vitor',
            'decision_receipt' => $this->acceptReceipt(['work_order_id' => 'wo_001']),
            'handoff' => $this->handoff(['source_work_order_id' => 'wo_999']),
        ]);

        $this->assertSame(Materializer::STATUS_BLOCKED, $result['status']);
        $this->assertSame(Materializer::BLOCK_WORK_ORDER_MISMATCH, $result['blocker']);
        $this->assertSame(0, AtlasProject::query()->count());
    }

    public function test_missing_operator_actor_blocks(): void
    {
        $result = $this->materializer()->materialize([
            'decision_receipt' => $this->acceptReceipt(),
            'handoff' => $this->handoff(),
        ]);

        $this->assertSame(Materializer::STATUS_BLOCKED, $result['status']);
        $this->assertSame(Materializer::BLOCK_ACTOR_REQUIRED, $result['blocker']);
        $this->assertSame(0, AtlasProject::query()->count());
    }
}
