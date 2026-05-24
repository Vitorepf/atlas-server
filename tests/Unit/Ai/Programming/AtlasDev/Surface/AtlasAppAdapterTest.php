<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Surface;

use App\Services\Ai\Programming\AtlasDev\Discovery\CodeDiscoveryEngine;
use App\Services\Ai\Programming\AtlasDev\Discovery\DocContextTierSelector;
use App\Services\Ai\Programming\AtlasDev\Discovery\OpenBrainProjectionAdapter;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\DelegationSuggestion;
use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RiskLevelScorer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecision;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecisionEngine;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RunIdGenerator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\SpecComposer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassifier;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptQualityChecker;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptRenderer;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\Surface\AtlasAppAdapter;
use App\Services\Ai\Programming\AtlasDev\Surface\SurfaceResponseFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Feature\Ai\Programming\AtlasDev\Http\FakeAtlasOpenBrainService;

final class AtlasAppAdapterTest extends TestCase
{
    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-app-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-app-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace.'/app/Services/Foo', 0o755, true);
        mkdir($this->tmpWorkspace.'/tests/Unit/Services/Foo', 0o755, true);
        mkdir($this->tmpWorkspace.'/.git/refs/heads', 0o755, true);
        file_put_contents($this->tmpWorkspace.'/.git/HEAD', 'ref: refs/heads/main');
        file_put_contents(
            $this->tmpWorkspace.'/.git/refs/heads/main',
            '0123456789abcdef0123456789abcdef01234567',
        );
        file_put_contents(
            $this->tmpWorkspace.'/app/Services/Foo/FooService.php',
            "<?php\nclass FooService {}\n",
        );
        file_put_contents(
            $this->tmpWorkspace.'/tests/Unit/Services/Foo/FooServiceTest.php',
            "<?php\nclass FooServiceTest {}\n",
        );
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    public function test_surface_id_is_canonical_atlas_app(): void
    {
        $this->assertSame('atlas_app', $this->adapter()->surfaceId());
    }

    public function test_build_envelope_produces_atlas_app_with_atlas_dev_flow_id(): void
    {
        $envelope = $this->adapter()->buildEnvelope([
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
            'thread_id' => 'thr_app_1',
            'flow_origin' => 'atlas_ai_router',
            'command_intent' => 'dev',
        ]);

        $this->assertSame('atlas_app', $envelope->surfaceId);
        $this->assertSame('atlas_dev', $envelope->flowId);
        $this->assertSame('atlas_ai_router', $envelope->flowOrigin);
        $this->assertSame('dev', $envelope->commandIntent);
        $this->assertSame('thr_app_1', $envelope->surfaceContext->threadId);
    }

    public function test_build_envelope_rejects_unsupported_surface_id_override(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->buildEnvelope([
            'surface_id' => 'atlas_desktop_ai',
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => 'corrija o teste',
        ]);
    }

    public function test_format_plan_only_compact_shape_has_no_absolute_paths(): void
    {
        $result = $this->runPlanOnly('corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php');

        $response = $this->adapter()->formatPlanOnly($result);

        $this->assertSame('plan_only', $response['kind']);
        $this->assertSame('atlas_app', $response['surface_id']);
        $this->assertSame('atlas_dev', $response['flow_id']);
        $this->assertSame('ready', $response['status']);
        $this->assertArrayHasKey('summary', $response);
        $this->assertArrayHasKey('badges', $response);
        $this->assertArrayHasKey('artifact_refs', $response);
        $this->assertArrayNotHasKey('ui_hints', $response, 'mobile must not surface Desktop ui_hints');
        $this->assertArrayNotHasKey('artifacts', $response, 'mobile MUST NOT inline full artifact bodies');

        $this->assertNoAbsolutePathLeaks($response, $this->tmpWorkspace, $this->tmpStorage);

        foreach ($response['artifact_refs'] as $ref) {
            $this->assertStringStartsWith('receipts/', $ref, "artifact refs must be relative: {$ref}");
        }
    }

    public function test_format_plan_only_badges_reflect_risk_and_task_kind(): void
    {
        $result = $this->runPlanOnly('corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php');
        $badges = $this->adapter()->formatPlanOnly($result)['badges'];

        $labels = array_column($badges, 'label');
        $this->assertContains($result->riskLevel, $labels);
        $this->assertContains($result->classification->taskKind, $labels);
    }

    public function test_delegate_to_other_flow_preserves_suggested_flow(): void
    {
        $result = $this->makeDelegateResult('atlas_research', 'workspace_not_resolved');
        $response = $this->adapter()->formatPlanOnly($result);

        $this->assertSame('delegated', $response['status']);
        $this->assertSame(RoutingDecision::DELEGATE_TO_OTHER_FLOW, $response['routing_decision']);
        $this->assertSame('atlas_research', $response['suggested_flow']);
        $labels = array_column($response['badges'], 'label');
        $this->assertContains('delegated', $labels);
    }

    public function test_format_run_result_emits_compact_run_shape_with_redacted_refs(): void
    {
        $result = $this->runPlanOnly('corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php');
        $envelope = $result->envelope;

        $runResult = [
            'completion_state' => 'passed',
            'scope_guard_status' => 'passed',
            'verification_status' => 'passed',
            'verification_receipt_hash' => str_repeat('a', 64),
            'scope_guard_receipt_hash' => str_repeat('b', 64),
            'diff_hash' => str_repeat('c', 64),
            'provider_call' => [
                'provider' => 'claude_cli',
                'model_family' => 'sonnet',
                'duration_ms' => 1200,
                'tokens_in' => 100,
                'tokens_out' => 50,
                'estimated_cost_usd' => 0.01,
            ],
            'persisted_receipt_paths' => [
                'verification_receipt' => $this->tmpStorage.'/'.$envelope->runId.'/verification_receipt.json',
            ],
        ];

        $response = $this->adapter()->formatRunResult($runResult, $envelope, $envelope->runId);

        $this->assertSame('run', $response['kind']);
        $this->assertSame('passed', $response['status']);
        $this->assertSame('atlas_app', $response['surface_id']);
        $this->assertSame('atlas_dev', $response['flow_id']);
        $this->assertStringStartsWith(
            'receipts/'.$envelope->runId.'/',
            $response['receipt_refs']['verification_receipt'],
        );
        $this->assertNoAbsolutePathLeaks($response, $this->tmpWorkspace, $this->tmpStorage);
    }

    public function test_adapter_does_not_import_desktop_namespace(): void
    {
        $contents = (string) file_get_contents(
            __DIR__.'/../../../../../../app/Services/Ai/Programming/AtlasDev/Surface/AtlasAppAdapter.php',
        );

        // Look only at PHP `use` statements — docblock prose mentioning the
        // Desktop adapter for context is fine; actually importing it isn't.
        $useStatements = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $trim = ltrim($line);
            if (str_starts_with($trim, 'use ')) {
                $useStatements[] = $trim;
            }
        }
        $imports = implode("\n", $useStatements);
        $this->assertStringNotContainsString('Desktop', $imports, 'App adapter must not import Desktop classes');
        $this->assertStringNotContainsString('AtlasDesktopAiAdapter', $contents);
        $this->assertStringNotContainsString('DesktopUiHintsBuilder', $contents);
        $this->assertStringNotContainsString('ui_hints', $contents, 'App must not surface Desktop ui_hints vocabulary');
    }

    public function test_adapter_does_not_depend_on_a_provider_class(): void
    {
        $reflection = new ReflectionClass(AtlasAppAdapter::class);
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType) {
                $name = $type->getName();
                $this->assertStringNotContainsString('Provider', $name);
                $this->assertStringNotContainsString('ClaudeCli', $name);
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function adapter(): AtlasAppAdapter
    {
        return new AtlasAppAdapter(
            intake: new IntakeNormalizer(new RunIdGenerator),
            formatter: new SurfaceResponseFormatter,
        );
    }

    private function runPlanOnly(string $rawIntent): PlanOnlyResult
    {
        $orchestrator = new AtlasDevFastPathOrchestrator(
            intake: new IntakeNormalizer(new RunIdGenerator),
            classifier: new TaskClassifier,
            riskScorer: new RiskLevelScorer,
            specComposer: new SpecComposer,
            tierSelector: new DocContextTierSelector,
            codeDiscovery: new CodeDiscoveryEngine,
            openBrainAdapter: new OpenBrainProjectionAdapter(new FakeAtlasOpenBrainService),
            promptBuilder: new ProviderPromptBuilder(
                sectionsMapper: new PromptSectionsMapper,
                renderer: new PromptRenderer,
                qualityChecker: new PromptQualityChecker,
            ),
            routingEngine: new RoutingDecisionEngine,
            receiptStorage: new ReceiptStorage($this->tmpStorage),
        );

        return $orchestrator->planOnly(
            surfaceId: 'atlas_app',
            workspace: $this->tmpWorkspace,
            rawIntent: $rawIntent,
            surfaceHints: ['flow_origin' => 'atlas_ai_router', 'command_intent' => 'dev'],
        );
    }

    /**
     * Build a synthetic PlanOnlyResult whose RoutingDecision is delegation.
     * Re-uses the orchestrator's real artifacts but swaps the routing.
     */
    private function makeDelegateResult(string $suggestedFlow, string $reason): PlanOnlyResult
    {
        $real = $this->runPlanOnly('explique como o FooService resolve workspace');

        $delegation = new DelegationSuggestion(
            suggestedFlow: $suggestedFlow,
            reason: $reason,
        );

        $delegated = new RoutingDecision(
            kind: RoutingDecision::DELEGATE_TO_OTHER_FLOW,
            reasons: [$reason],
            blockers: [],
            delegation: $delegation,
        );

        return new PlanOnlyResult(
            envelope: $real->envelope,
            classification: $real->classification,
            riskLevel: $real->riskLevel,
            compactSdd: $real->compactSdd,
            contextPlan: $real->contextPlan,
            discovery: $real->discovery,
            projection: $real->projection,
            miniSpec: $real->miniSpec,
            taskContract: $real->taskContract,
            promptProjection: $real->promptProjection,
            routing: $delegated,
            persistedArtifactPaths: $real->persistedArtifactPaths,
            blockers: [],
        );
    }

    private function assertNoAbsolutePathLeaks(array $payload, string $workspace, string $storage): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);
        $this->assertStringNotContainsString($workspace, $json, 'response leaked workspace absolute path');
        $this->assertStringNotContainsString($storage, $json, 'response leaked storage absolute path');
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->rmrf($path);
            } else {
                @chmod($path, 0o644);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
