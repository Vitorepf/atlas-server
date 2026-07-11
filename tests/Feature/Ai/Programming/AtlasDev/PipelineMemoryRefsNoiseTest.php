<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Models\AiRagFeedbackEvent;
use App\Services\Ai\Context\AtlasSpuriousMemoryNoisePurgeService;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

final class PipelineMemoryRefsNoiseTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    private const MEMORY_REF = 'memory:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas_dev.elevations.e3.mode', 'off');
        config()->set('atlas_dev.elevations.e4.mode', 'off');
        config()->set('atlas_dev.elevations.e5.mode', 'off');
        config()->set('atlas_dev.elevations.e6.mode', 'off');
        config()->set('atlas_dev.elevations.weak_output.mode', 'off');
        config()->set('atlas.programming.sovereign_floor_enforced', false);
        config()->set('atlas.programming.strict_retrieval_gate', false);

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-mem-noise-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-mem-noise-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);

        $this->bindAwisGate();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmpStorage);
        File::deleteDirectory($this->tmpWorkspace);
        parent::tearDown();
    }

    public function test_passed_run_does_not_mark_unmentioned_memory_ref_as_noise(): void
    {
        $this->bootFeedbackSchema();

        try {
            $event = $this->executeRunWithProjection(
                memoryRefs: [self::MEMORY_REF],
                diff: <<<'DIFF'
--- a/tests/Unit/Services/Foo/FooServiceTest.php
+++ b/tests/Unit/Services/Foo/FooServiceTest.php
@@ -1,2 +1,2 @@
 <?php
-assert(false);
+assert(true);
DIFF,
            );

            $noiseRefs = data_get($event->payload, 'payload.context_ref_attribution.noise_refs', []);
            $memoryNoise = array_values(array_filter($noiseRefs, static fn (array $entry): bool => ($entry['source_type'] ?? '') === 'memory'));

            $this->assertSame([], $memoryNoise, 'unmentioned memory ref must not become noise');
            $this->assertNull($event->source_utility[self::MEMORY_REF] ?? null);
            $this->assertNotContains(self::MEMORY_REF, data_get($event->payload, 'payload.next_context_policy.demote_context_refs', []));
        } finally {
            $this->dropFeedbackSchema();
        }
    }

    public function test_passed_run_marks_memory_ref_as_used_when_mentioned_in_diff(): void
    {
        $this->bootFeedbackSchema();

        try {
            $event = $this->executeRunWithProjection(
                memoryRefs: [self::MEMORY_REF],
                diff: <<<'DIFF'
--- a/tests/Unit/Services/Foo/FooServiceTest.php
+++ b/tests/Unit/Services/Foo/FooServiceTest.php
@@ -1,2 +1,2 @@
 <?php
-assert(false);
+assert(true);

pack_context_use: memory:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
DIFF,
            );

            $usedRefs = data_get($event->payload, 'payload.context_ref_attribution.used_refs', []);
            $usedLabels = array_map(static fn (array $entry): string => (string) ($entry['ref'] ?? ''), $usedRefs);

            $deliveredRefs = data_get($event->payload, 'payload.context_ref_attribution.delivered_refs', []);
            $deliveredLabels = array_map(static fn (array $entry): string => (string) ($entry['ref'] ?? ''), $deliveredRefs);

            $this->assertContains(self::MEMORY_REF, $deliveredLabels, 'memory ref must be delivered');

            $this->assertContains(
                self::MEMORY_REF,
                $usedLabels,
                json_encode([
                    'used_labels' => $usedLabels,
                    'delivered_labels' => $deliveredLabels,
                    'source_utility' => $event->source_utility,
                    'noise_refs' => data_get($event->payload, 'payload.context_ref_attribution.noise_refs', []),
                ], JSON_THROW_ON_ERROR),
            );
            $this->assertSame('used', $event->source_utility[self::MEMORY_REF] ?? null);
        } finally {
            $this->dropFeedbackSchema();
        }
    }

    public function test_purge_removes_spurious_memory_demote_votes_from_historical_events(): void
    {
        $this->bootFeedbackSchema();

        try {
            AiRagFeedbackEvent::query()->create([
                'schema_version' => 'atlas.ai.rag.feedback.v1',
                'retrieval_receipt_id' => 'receipt-fee06-purge',
                'flow_id' => 'atlas.dev',
                'query_plan_hash' => hash('sha256', 'receipt-fee06-purge'),
                'included_sources' => 2,
                'used_sources' => 1,
                'noise_sources' => 1,
                'missed_required_sources' => [],
                'context_sufficiency' => 1,
                'post_execution_utility' => 1,
                'source_utility' => [
                    self::MEMORY_REF => 'noise',
                    'app/Unrelated.php' => 'noise',
                ],
                'outcome_status' => 'passed',
                'failure_reason' => null,
                'next_retrieval_hint' => null,
                'payload' => [
                    'payload' => [
                        'context_ref_attribution' => [
                            'noise_refs' => [
                                ['ref' => self::MEMORY_REF, 'source_type' => 'memory', 'basis' => 'explicit_noise'],
                                ['ref' => 'app/Unrelated.php', 'source_type' => 'context_ref', 'basis' => 'explicit_noise'],
                            ],
                            'noise_count' => 2,
                        ],
                        'next_context_policy' => [
                            'actions' => ['demote_noise_context_refs'],
                            'demote_context_refs' => [self::MEMORY_REF, 'app/Unrelated.php'],
                        ],
                    ],
                ],
                'feedback_hash' => hash('sha256', 'fee06-purge-fixture'),
            ]);

            $result = app(AtlasSpuriousMemoryNoisePurgeService::class)->purge(dryRun: false);
            $this->assertSame(1, $result['matched']);
            $this->assertSame(1, $result['purged']);

            $event = AiRagFeedbackEvent::query()->firstOrFail();
            $this->assertArrayNotHasKey(self::MEMORY_REF, (array) $event->source_utility);
            $this->assertSame('noise', $event->source_utility['app/Unrelated.php'] ?? null);
            $this->assertNotContains(self::MEMORY_REF, data_get($event->payload, 'payload.next_context_policy.demote_context_refs', []));
            $this->assertContains('app/Unrelated.php', data_get($event->payload, 'payload.next_context_policy.demote_context_refs', []));

            $memoryNoise = array_values(array_filter(
                (array) data_get($event->payload, 'payload.context_ref_attribution.noise_refs', []),
                static fn (array $entry): bool => ($entry['source_type'] ?? '') === 'memory',
            ));
            $this->assertSame([], $memoryNoise);
        } finally {
            $this->dropFeedbackSchema();
        }
    }

    private function executeRunWithProjection(array $memoryRefs, string $diff): AiRagFeedbackEvent
    {
        app()->instance(
            \App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService::class,
            app()->make(\App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService::class),
        );

        $runId = 'dev-mem-noise-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        @unlink($this->tmpStorage.'/'.$runId.'/'.ArtifactNames::OPEN_BRAIN_PROJECTION);
        $storage->writeAtomic($runId, ArtifactNames::OPEN_BRAIN_PROJECTION, [
            'context_pack_hash' => 'atlas-dev:context_pack:mem-noise',
            'code_refs' => [
                ['kind' => 'file', 'ref' => 'tests/Unit/Services/Foo/FooServiceTest.php', 'reason' => 'target'],
            ],
            'memory_refs' => array_map(
                static fn (string $ref): array => ['kind' => 'memory', 'ref' => $ref, 'reason' => 'decision'],
                $memoryRefs,
            ),
        ]);

        $target = $this->tmpWorkspace.'/tests/Unit/Services/Foo/FooServiceTest.php';
        @mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nassert(false);\n");

        $executor = $this->makeExecutor($storage, $diff);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['tests/Unit/Services/Foo/FooServiceTest.php'],
            'expected_max_files' => 2,
            'max_files_changed' => 2,
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertSame('passed', $result->completionState);

        return AiRagFeedbackEvent::query()->latest('id')->firstOrFail();
    }

    private function makeExecutor(ReceiptStorage $storage, string $gatewayStdout): PipelineRunExecutor
    {
        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $gatewayStdout));

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: 'echo verified',
            exitCode: 0,
            stdout: 'ok',
            stderr: '',
            durationMs: 10,
        ));

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);

        return new PipelineRunExecutor($container, $storage);
    }

    private function envelope(): OperationEnvelope
    {
        $intent = 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php';

        return new OperationEnvelope(
            runId: 'unused-by-executor',
            surfaceId: 'atlas_desktop_ai',
            surfaceContext: new SurfaceContext(
                productSurface: 'atlas_ai_desktop_mac',
                composerMode: 'programming',
                composerTask: 'dev',
                providerChoice: null,
            ),
            workspace: $this->tmpWorkspace,
            workspaceHash: hash('sha256', $this->tmpWorkspace),
            gitState: new GitState(headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0),
            rawIntent: $intent,
            normalizedIntent: $intent,
            userConstraints: [],
            intentClarityLevel: 'high',
            dirtyWorktreePolicy: 'preserve_pre_existing_changes',
            preflight: new Preflight(
                workspaceResolved: true,
                permissionMode: 'write_allowed',
                writeAllowed: true,
                operatorExplicit: false,
            ),
            envelopeHash: str_repeat('e', 64),
        );
    }

    private function bindAwisGate(): void
    {
        $this->app->instance(AtlasWorkspaceIntelligenceExecutionGateService::class, new class
        {
            public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
            {
                return [
                    'allowed' => true,
                    'status' => 'ready',
                    'mode' => $mode,
                    'blockers' => [],
                ];
            }
        });
    }

    private function bootFeedbackSchema(): void
    {
        $migration = require base_path('database/migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php');
        $migration->down();
        $migration->up();
        $migration2 = require base_path('database/migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php');
        $migration2->up();
    }

    private function dropFeedbackSchema(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('ai_learning_proposals');
        $migration = require base_path('database/migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php');
        $migration->down();
        app()->forgetInstance(\App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService::class);
    }
}
