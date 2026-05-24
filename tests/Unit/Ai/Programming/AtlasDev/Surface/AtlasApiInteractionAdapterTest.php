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
use App\Services\Ai\Programming\AtlasDev\Surface\AtlasApiInteractionAdapter;
use App\Services\Ai\Programming\AtlasDev\Surface\SurfaceResponseFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Tests\Feature\Ai\Programming\AtlasDev\Http\FakeAtlasOpenBrainService;

final class AtlasApiInteractionAdapterTest extends TestCase
{
    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-api-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-api-ws-'.bin2hex(random_bytes(4));
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

    public function test_surface_id_is_canonical_atlas_api_interaction(): void
    {
        $this->assertSame('atlas_api_interaction', $this->adapter()->surfaceId());
    }

    public function test_build_envelope_produces_atlas_api_interaction_with_flow_metadata(): void
    {
        $envelope = $this->adapter()->buildEnvelope([
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
            'flow_origin' => 'atlas_ai_router',
            'command_intent' => 'dev',
        ]);

        $this->assertSame('atlas_api_interaction', $envelope->surfaceId);
        $this->assertSame('atlas_dev', $envelope->flowId);
        $this->assertSame('atlas_ai_router', $envelope->flowOrigin);
        $this->assertSame('dev', $envelope->commandIntent);
    }

    public function test_build_envelope_defaults_to_direct_when_no_flow_origin(): void
    {
        $envelope = $this->adapter()->buildEnvelope([
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => 'corrija o teste',
        ]);

        $this->assertSame('direct', $envelope->flowOrigin);
        $this->assertNull($envelope->commandIntent);
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

    public function test_format_plan_only_returns_openapi_friendly_shape(): void
    {
        $result = $this->runPlanOnly('corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php');
        $response = $this->adapter()->formatPlanOnly($result);

        $this->assertSame('plan_only', $response['kind']);
        $this->assertSame('atlas_api_interaction', $response['surface_id']);
        $this->assertSame('atlas_dev', $response['flow_id']);
        $this->assertSame('ready', $response['status']);
        $this->assertSame(RoutingDecision::ATLAS_DEV_FAST_PATH, $response['routing_decision']);
        $this->assertNull($response['completion_state']);
        $this->assertArrayHasKey('artifacts', $response);
        $this->assertArrayHasKey('refs', $response['artifacts']);
        // OpenAPI-friendly: refs ONLY. The full artifact bodies are fetched
        // via the existing /show endpoint; inlining them here is wasteful and
        // risks leaking embedded absolute paths (e.g. rollback_or_containment).
        $this->assertArrayNotHasKey('inline', $response['artifacts']);
        $this->assertNoAbsolutePathLeaks($response, $this->tmpWorkspace, $this->tmpStorage);

        foreach ($response['artifacts']['refs'] as $ref) {
            $this->assertStringStartsWith('receipts/', $ref);
        }
    }

    public function test_format_plan_only_does_not_emit_ui_hints(): void
    {
        $result = $this->runPlanOnly('corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php');
        $response = $this->adapter()->formatPlanOnly($result);

        $this->assertArrayNotHasKey('ui_hints', $response);
        $this->assertArrayNotHasKey('badges', $response);
    }

    public function test_delegate_to_other_flow_preserves_suggested_flow_at_top_level(): void
    {
        $result = $this->makeDelegateResult('atlas_research', 'workspace_not_resolved');
        $response = $this->adapter()->formatPlanOnly($result);

        $this->assertSame('delegated', $response['status']);
        $this->assertSame(RoutingDecision::DELEGATE_TO_OTHER_FLOW, $response['routing_decision']);
        $this->assertSame('atlas_research', $response['suggested_flow']);
    }

    public function test_format_run_result_emits_openapi_run_shape_with_redacted_refs(): void
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
                'provider_calls' => 1,
                'exit_code' => 0,
                'duration_ms' => 1200,
                'tokens_in' => 100,
                'tokens_out' => 50,
                'estimated_cost_usd' => 0.01,
                'error_codes' => [],
            ],
            'persisted_receipt_paths' => [
                'verification_receipt' => $this->tmpStorage.'/'.$envelope->runId.'/verification_receipt.json',
                'scope_guard_receipt' => $this->tmpStorage.'/'.$envelope->runId.'/scope_guard_receipt.json',
            ],
        ];

        $response = $this->adapter()->formatRunResult($runResult, $envelope, $envelope->runId);

        $this->assertSame('run', $response['kind']);
        $this->assertSame('passed', $response['status']);
        $this->assertSame('passed', $response['completion_state']);
        $this->assertSame('atlas_api_interaction', $response['surface_id']);
        $this->assertSame('atlas_dev', $response['flow_id']);
        $this->assertSame($runResult['provider_call'], $response['provider_call']);

        foreach ($response['persisted_receipt_refs'] as $ref) {
            $this->assertStringStartsWith('receipts/'.$envelope->runId.'/', $ref);
        }
        $this->assertNoAbsolutePathLeaks($response, $this->tmpWorkspace, $this->tmpStorage);
    }

    public function test_adapter_does_not_import_desktop_namespace_or_build_prompt(): void
    {
        $contents = (string) file_get_contents(
            __DIR__.'/../../../../../../app/Services/Ai/Programming/AtlasDev/Surface/AtlasApiInteractionAdapter.php',
        );

        // Check `use` imports only — docblock prose may mention Desktop for
        // context. The adapter must never import or instantiate Desktop or
        // prompt-builder classes.
        $useStatements = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $trim = ltrim($line);
            if (str_starts_with($trim, 'use ')) {
                $useStatements[] = $trim;
            }
        }
        $imports = implode("\n", $useStatements);
        $this->assertStringNotContainsString('Desktop', $imports, 'API adapter must not import Desktop classes');
        $this->assertStringNotContainsString('AtlasDesktopAiAdapter', $contents);
        $this->assertStringNotContainsString('DesktopUiHintsBuilder', $contents);
        $this->assertStringNotContainsString('PromptRenderer', $imports);
        $this->assertStringNotContainsString('PromptSectionsMapper', $imports);
        $this->assertStringNotContainsString('ProviderPromptBuilder', $imports);
    }

    public function test_adapter_does_not_depend_on_a_provider_class(): void
    {
        $reflection = new ReflectionClass(AtlasApiInteractionAdapter::class);
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType) {
                $name = $type->getName();
                $this->assertStringNotContainsString('Provider', $name);
                $this->assertStringNotContainsString('ClaudeCli', $name);
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function adapter(): AtlasApiInteractionAdapter
    {
        return new AtlasApiInteractionAdapter(
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
            surfaceId: 'atlas_api_interaction',
            workspace: $this->tmpWorkspace,
            rawIntent: $rawIntent,
            surfaceHints: ['flow_origin' => 'atlas_ai_router'],
        );
    }

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
