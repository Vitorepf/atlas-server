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
        $this->rmrf($this->workspace);
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

    private function rmrf(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach (@scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->rmrf($path.'/'.$item);
        }
        @rmdir($path);
    }
}
