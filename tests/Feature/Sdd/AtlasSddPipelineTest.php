<?php

namespace Tests\Feature\Sdd;

use App\Models\AtlasDecisionReceipt;
use App\Models\AtlasOperation;
use App\Models\AtlasSddDriftReport;
use App\Models\AtlasSddTask;
use App\Models\AtlasSpec;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Ai\Programming\Sdd\AtlasSddPipeline;
use App\Services\Ai\Programming\Sdd\Pipeline\SddPipelineOperationEnvelope as OperationEnvelope;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesAtlasSddTables;
use Tests\TestCase;

class AtlasSddPipelineTest extends TestCase
{
    use CreatesAtlasSddTables;

    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasSddTables();
        $this->workspace = sys_get_temp_dir().'/sdd_pipe_'.bin2hex(random_bytes(4));
        mkdir($this->workspace.'/app', 0755, true);
        $this->bindStubs();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        $this->dropAtlasSddTables();
        parent::tearDown();
    }

    public function test_run_full_pipeline_completes_for_clean_intent(): void
    {
        $envelope = new OperationEnvelope(
            rawInput: 'Refatorar runner para suportar cobertura completa de testes',
            userId: 'vitor',
            workspace: $this->workspace,
        );

        $output = app(AtlasSddPipeline::class)->run(
            envelope: $envelope,
            proposedWrites: [['path' => 'app/NewService.php', 'contents' => "<?php // x\n"]],
            proposedCommands: [],
        );

        $this->assertContains($output->status, ['completed', 'blocked'], 'pipeline must reach a terminal status');
        $payload = $output->payload;
        $this->assertNotEmpty($payload['operation_id']);
        $this->assertNotEmpty($payload['spec_id']);
        $this->assertNotEmpty($payload['plan_id']);
        $this->assertNotEmpty($payload['receipt_id']);
        $this->assertSame(1, AtlasOperation::query()->count());
        $this->assertSame(1, AtlasSpec::query()->count());
        $this->assertSame(1, AtlasDecisionReceipt::query()->count());
        $this->assertGreaterThanOrEqual(1, AtlasSddTask::query()->count());
        $this->assertSame(1, AtlasSddDriftReport::query()->count());
    }

    public function test_blocking_ambiguity_short_circuits_to_clarification(): void
    {
        $envelope = new OperationEnvelope(rawInput: '   ', workspace: $this->workspace);

        $output = app(AtlasSddPipeline::class)->run($envelope);

        $this->assertSame('needs_clarification', $output->status);
        $this->assertArrayHasKey('critique', $output->payload);
        // Spec should NOT be persisted when clarification is required.
        $this->assertSame(0, AtlasSpec::query()->count());
        $this->assertSame(0, AtlasDecisionReceipt::query()->count());
    }

    public function test_pipeline_produces_drift_report_for_persisted_spec(): void
    {
        $envelope = new OperationEnvelope(
            rawInput: 'Adicionar nova feature de export pdf',
            workspace: $this->workspace,
        );

        $output = app(AtlasSddPipeline::class)->run($envelope);
        if ($output->status === 'needs_clarification') {
            $this->markTestSkipped('Critic asked for clarification — exercise covered elsewhere.');
        }

        $drift = AtlasSddDriftReport::query()->first();
        $this->assertNotNull($drift);
        $this->assertContains($drift->status, ['pass', 'warn', 'fail']);
    }

    public function test_output_state_classification_is_needs_clarification_on_blocking_ambiguity(): void
    {
        $envelope = new OperationEnvelope(rawInput: '   ', workspace: $this->workspace);

        $output = app(AtlasSddPipeline::class)->run($envelope);

        $this->assertSame('needs_clarification', $output->status);
        $classification = $output->payload['output_state_classification'];
        $this->assertIsArray($classification);
        $this->assertSame('atlas.sdd_output_state_classification.v1', $classification['schema_version']);
        // Observe-only field agrees with the pipeline's own terminal verdict.
        $this->assertSame('needs_clarification', $classification['state']);
        $this->assertFalse($classification['plan_permitted']);
        $this->assertSame(2, $classification['precedence_rank']);
        $this->assertSame('clarification_required:intent:blocking_ambiguity', $classification['dominant_reason']);
        $this->assertSame(1, $classification['signals']['blocking_issue_count']);
        $this->assertTrue($classification['signals']['core_unresolved']);
    }

    public function test_output_state_classification_is_ready_for_plan_on_clean_terminal_run(): void
    {
        $envelope = new OperationEnvelope(
            rawInput: 'Refatorar runner para suportar cobertura completa de testes',
            userId: 'vitor',
            workspace: $this->workspace,
        );

        $output = app(AtlasSddPipeline::class)->run(
            envelope: $envelope,
            proposedWrites: [['path' => 'app/NewService.php', 'contents' => "<?php // x\n"]],
            proposedCommands: [],
        );

        $this->assertContains($output->status, ['completed', 'blocked'], 'pipeline must reach a terminal status');
        $classification = $output->payload['output_state_classification'];
        $this->assertIsArray($classification);
        // The pipeline actually compiled a plan on this path, so the
        // classifier must agree that planning was permitted.
        $this->assertSame('ready_for_plan', $classification['state']);
        $this->assertTrue($classification['plan_permitted']);
        $this->assertSame(4, $classification['precedence_rank']);
        $this->assertSame(0, $classification['signals']['blocking_issue_count']);
        $this->assertFalse($classification['signals']['core_unresolved']);
        $this->assertNotEmpty($output->payload['plan_id']);
    }

    private function bindStubs(): void
    {
        $this->app->bind(AtlasFeaturePlacementService::class, function () {
            return new class extends AtlasFeaturePlacementService
            {
                public function __construct() {}

                public function place(string $intent, array $hints = []): array
                {
                    return [
                        'status' => 'ok',
                        'placement' => ['layer' => 'kernel', 'domain' => 'programming'],
                        'owner_docs' => [['path' => 'app/Services/X.php', 'exists' => true]],
                    ];
                }
            };
        });
        $this->app->bind(EngineeringCodeIntelligenceService::class, function () {
            return new class extends EngineeringCodeIntelligenceService
            {
                public function __construct() {}

                public function summary(array $options = []): array
                {
                    return ['status' => 'ready', 'module_count' => 23, 'symbol_count' => 39419];
                }
            };
        });
    }

}
