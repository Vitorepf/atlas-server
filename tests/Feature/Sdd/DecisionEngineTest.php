<?php

namespace Tests\Feature\Sdd;

use App\Models\AtlasOperation;
use App\Models\AtlasPlan;
use App\Models\AtlasSpec;
use App\Services\Ai\Programming\Sdd\AssumptionLedger;
use App\Services\Ai\Programming\Sdd\DecisionEngine;
use App\Services\Ai\Programming\Sdd\Enums\AutonomyLevel;
use App\Services\Ai\Programming\Sdd\Enums\ConfidenceClass;
use App\Services\Ai\Programming\Sdd\Gates\SddClarificationGate;
use App\Services\Ai\Programming\Sdd\Pipeline\ContextPack;
use App\Services\Ai\Programming\Sdd\Pipeline\Intent;
use App\Services\Ai\Programming\Sdd\Pipeline\SddPipelineOperationEnvelope as OperationEnvelope;
use Tests\Concerns\CreatesAtlasSddTables;
use Tests\TestCase;

class DecisionEngineTest extends TestCase
{
    use CreatesAtlasSddTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasSddTables();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasSddTables();
        parent::tearDown();
    }

    public function test_creates_signed_receipt_with_canonical_payload(): void
    {
        [$envelope, $intent, $op, $spec, $plan, $tasks, $ctx] = $this->scaffold();

        $receipt = (new DecisionEngine)->createReceipt($envelope, $op, $spec, $plan, $tasks, $ctx, $intent);

        $this->assertTrue($receipt->isActive());
        $this->assertSame(AutonomyLevel::L2AutoPatch->value, $receipt->autonomy_level);
        $this->assertNotEmpty($receipt->signature);
        $this->assertSame(64, strlen($receipt->input_hash));
        $this->assertSame(64, strlen($receipt->output_hash));
        $this->assertContains('write_allowed_files', (array) $receipt->allowed_actions_json);
        $this->assertContains('mutate_kernel_policy', (array) $receipt->forbidden_actions_json);
        $this->assertContains('app/Foo.php', (array) $receipt->allowed_files_json);
        $this->assertContains('evidence-required', (array) $receipt->required_gates_json);
        $this->assertSame($ctx->digest, data_get($receipt->context_pack_refs_json, 'digest'));
    }

    public function test_blocking_ambiguity_forces_l0_manual(): void
    {
        [$envelope, , $op, $spec, $plan, $tasks, $ctx] = $this->scaffold();
        $intent = new Intent('feature', 'programming', 'medium', ConfidenceClass::BlockingAmbiguity, false);

        $receipt = (new DecisionEngine)->createReceipt($envelope, $op, $spec, $plan, $tasks, $ctx, $intent);

        $this->assertSame(AutonomyLevel::L0Manual->value, $receipt->autonomy_level);
        $this->assertNotContains('write_allowed_files', (array) $receipt->allowed_actions_json);
        $this->assertContains('write_files', (array) $receipt->forbidden_actions_json);
    }

    public function test_authorize_file_write_respects_allowed_and_forbidden(): void
    {
        [$envelope, $intent, $op, $spec, $plan, $tasks, $ctx] = $this->scaffold();
        $engine = new DecisionEngine;
        $receipt = $engine->createReceipt($envelope, $op, $spec, $plan, $tasks, $ctx, $intent);

        $this->assertTrue($engine->authorizesFileWrite($receipt, 'app/Foo.php'));
        $this->assertFalse($engine->authorizesFileWrite($receipt, 'app/Models/AtlasUser.php'));
        $this->assertFalse($engine->authorizesFileWrite($receipt, 'random/Bar.php'));
    }

    public function test_revoked_receipt_does_not_authorize(): void
    {
        [$envelope, $intent, $op, $spec, $plan, $tasks, $ctx] = $this->scaffold();
        $engine = new DecisionEngine;
        $receipt = $engine->createReceipt($envelope, $op, $spec, $plan, $tasks, $ctx, $intent);

        $engine->revoke($receipt, 'compromised');
        $this->assertFalse($receipt->refresh()->isActive());
        $this->assertFalse($engine->authorizesFileWrite($receipt->refresh(), 'app/Foo.php'));
    }

    public function test_clarification_gate_blocks_when_blocking_assumption_exists(): void
    {
        [$envelope, $intent, $op, $spec, $plan, $tasks, $ctx] = $this->scaffold();
        $ledger = new AssumptionLedger;
        $ledger->record($spec, [
            'text' => 'Is the export endpoint authenticated?',
            'confidence_class' => 'blocking_ambiguity',
            'questions' => ['Confirm whether token is required'],
        ]);

        $report = (new SddClarificationGate($ledger))->evaluate($spec);

        $this->assertSame('needs_clarification', $report['status']);
        $this->assertSame(1, $report['blocking_count']);
        $this->assertContains('Confirm whether token is required', $report['questions']);
    }

    public function test_resolved_assumption_unblocks_gate(): void
    {
        [$envelope, $intent, $op, $spec, $plan, $tasks, $ctx] = $this->scaffold();
        $ledger = new AssumptionLedger;
        $a = $ledger->record($spec, [
            'text' => 'unsure',
            'confidence_class' => 'blocking_ambiguity',
        ]);
        $ledger->resolve($a, 'answered', resolvedConfidence: 'confirmed_fact');

        $report = (new SddClarificationGate($ledger))->evaluate($spec);
        $this->assertSame('passed', $report['status']);
    }

    /**
     * @return array{0:OperationEnvelope,1:Intent,2:AtlasOperation,3:AtlasSpec,4:AtlasPlan,5:list<array<string,mixed>>,6:ContextPack}
     */
    private function scaffold(): array
    {
        $envelope = new OperationEnvelope('Add export endpoint', userId: 'vitor');
        $intent = new Intent('feature', 'programming', 'medium', ConfidenceClass::ConfirmedFact, true);

        $op = AtlasOperation::query()->create([
            'raw_input' => 'Add export endpoint',
            'status' => 'routed',
            'risk_level' => 'medium',
            'domain' => 'programming',
            'confidence_class' => 'confirmed_fact',
            'routing_metadata_json' => [],
        ]);
        $spec = AtlasSpec::query()->create([
            'operation_id' => $op->id,
            'title' => 'Export endpoint',
            'type' => 'feature',
            'status' => 'approved',
            'version' => 1,
            'risk_level' => 'medium',
            'content_hash' => str_repeat('a', 64),
            'content_json' => ['objective' => 'export'],
        ]);
        $plan = AtlasPlan::query()->create([
            'spec_id' => $spec->id,
            'content_hash' => str_repeat('b', 64),
            'content_json' => ['phase' => 'p1'],
            'target_files_json' => ['app/Foo.php'],
            'forbidden_files_json' => ['app/Models/AtlasUser.php'],
            'hot_file_ownership_json' => ['app/Foo.php' => 'service'],
            'test_plan_json' => [['kind' => 'phpunit', 'command' => 'phpunit']],
            'rollback_plan_json' => ['description' => 'revert'],
            'status' => 'draft',
        ]);
        $tasks = [
            ['code' => 'T-01-SERV', 'allowed_files' => ['app/Foo.php'], 'forbidden_files' => []],
            ['code' => 'T-02-TEST', 'allowed_files' => [], 'forbidden_files' => []],
        ];
        $ctx = new ContextPack('kernel-programming', ['atlas.base.v1'], ['envelope' => $envelope->toArray()], str_repeat('c', 64));

        return [$envelope, $intent, $op, $spec, $plan, $tasks, $ctx];
    }
}
