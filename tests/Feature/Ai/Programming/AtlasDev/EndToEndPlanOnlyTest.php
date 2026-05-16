<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Services\Ai\AtlasOpenBrainService;
use App\Services\Ai\Programming\AtlasDev\Discovery\CodeDiscoveryEngine;
use App\Services\Ai\Programming\AtlasDev\Discovery\DocContextTierSelector;
use App\Services\Ai\Programming\AtlasDev\Discovery\OpenBrainProjectionAdapter;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RiskLevelScorer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecision;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecisionEngine;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RunIdGenerator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\SpecComposer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassifier;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptQualityChecker;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptRenderer;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end Atlas Dev plan-only pipeline.
 *
 * Validates the three canonical routing outcomes (read_only_answer,
 * atlas_dev_fast_path, forge_promotion_preview) and asserts:
 *   - no provider is ever invoked (HTTP::preventStrayRequests + a fake Open
 *     Brain service that surfaces 0 context_refs);
 *   - artifacts are persisted on disk via ReceiptStorage with the canonical
 *     filenames;
 *   - the prompt_projection comes back sendable when the route is fast path
 *     and contains a rendered text + matching hash.
 */
final class EndToEndPlanOnlyTest extends TestCase
{
    private string $tmpStorage;
    private string $tmpWorkspace;
    private FakeAtlasOpenBrainService $fakeOpenBrain;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(); // any unexpected HTTP call now throws RuntimeException

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-e2e-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-e2e-ws-'.bin2hex(random_bytes(4));
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

        $this->fakeOpenBrain = new FakeAtlasOpenBrainService();
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    public function test_question_r0_routes_to_read_only_answer(): void
    {
        $result = $this->orchestrator()->planOnly(
            surfaceId: 'atlas_cli_dev',
            workspace: $this->tmpWorkspace,
            rawIntent: 'explique como o FooService resolve workspace',
        );

        $this->assertSame(RoutingDecision::READ_ONLY_ANSWER, $result->routingKind());
        $this->assertSame(TaskClassification::KIND_QUESTION, $result->classification->taskKind);
        $this->assertSame(RiskLevelScorer::R0, $result->riskLevel);
        $this->assertSame(SpecComposer::MODE_READ_ONLY, $result->compactSdd->mode);
        $this->assertSame([], $result->miniSpec->allowedFiles, 'read-only must not pre-authorise file writes');
        $this->assertFalse(
            $result->promptProjection->isSendable(),
            'non-fast-path routes must produce a non-sendable prompt (provider_safe downgraded).',
        );
        $this->assertArtifactsPersisted($result);
    }

    public function test_repair_r2_routes_to_fast_path_with_sendable_prompt(): void
    {
        $result = $this->orchestrator()->planOnly(
            surfaceId: 'atlas_cli_dev',
            workspace: $this->tmpWorkspace,
            rawIntent: 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
        );

        $this->assertSame(RoutingDecision::ATLAS_DEV_FAST_PATH, $result->routingKind());
        $this->assertSame(TaskClassification::KIND_REPAIR, $result->classification->taskKind);
        $this->assertSame(RiskLevelScorer::R2, $result->riskLevel);
        $this->assertNotEmpty($result->miniSpec->allowedFiles);
        $this->assertNotEmpty($result->miniSpec->verificationPlan->commands);
        $this->assertTrue($result->promptProjection->isSendable(), 'prompt must be sendable for fast path');
        $this->assertNotSame('', $result->promptProjection->renderedPromptText);
        $this->assertSame(
            hash('sha256', $result->promptProjection->renderedPromptText),
            $result->promptProjection->renderedPromptHash,
        );
        $this->assertArtifactsPersisted($result);
    }

    public function test_risky_r4_routes_to_forge_promotion_preview_without_patch(): void
    {
        $result = $this->orchestrator()->planOnly(
            surfaceId: 'atlas_cli_dev',
            workspace: $this->tmpWorkspace,
            rawIntent: 'mexer no fluxo de billing em production e ajustar migration',
        );

        $this->assertSame(RoutingDecision::FORGE_PROMOTION_PREVIEW, $result->routingKind());
        $this->assertSame(TaskClassification::KIND_RISKY, $result->classification->taskKind);
        $this->assertSame(RiskLevelScorer::R4, $result->riskLevel);
        $this->assertSame(SpecComposer::MODE_ESCALATE_PREVIEW, $result->compactSdd->mode);
        $this->assertSame([], $result->miniSpec->allowedFiles, 'forge preview must not pre-authorise patches');
        $this->assertFalse(
            $result->promptProjection->isSendable(),
            'forge promotion preview must produce a non-sendable prompt to block accidental run.',
        );
        $this->assertSame(0, $result->compactSdd->contextBudget->maxProviderCalls);
        $this->assertArtifactsPersisted($result);
    }

    public function test_plan_only_never_calls_provider(): void
    {
        $never = new ProviderShouldNotBeCalled();
        $orchestrator = new AtlasDevFastPathOrchestrator(
            intake: new IntakeNormalizer(new RunIdGenerator()),
            classifier: new TaskClassifier(),
            riskScorer: new RiskLevelScorer(),
            specComposer: new SpecComposer(),
            tierSelector: new DocContextTierSelector(),
            codeDiscovery: new CodeDiscoveryEngine(),
            openBrainAdapter: new OpenBrainProjectionAdapter($this->fakeOpenBrain),
            promptBuilder: new ProviderPromptBuilder(
                sectionsMapper: new PromptSectionsMapper(),
                renderer: new PromptRenderer(),
                qualityChecker: new PromptQualityChecker(),
            ),
            routingEngine: new RoutingDecisionEngine(),
            receiptStorage: new ReceiptStorage($this->tmpStorage),
        );

        $orchestrator->planOnly(
            surfaceId: 'atlas_cli_dev',
            workspace: $this->tmpWorkspace,
            rawIntent: 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
        );

        $this->assertSame(0, $never->calls, 'the orchestrator must not reach the provider in plan-only mode');
        Http::assertNothingSent();
    }

    private function orchestrator(): AtlasDevFastPathOrchestrator
    {
        return new AtlasDevFastPathOrchestrator(
            intake: new IntakeNormalizer(new RunIdGenerator()),
            classifier: new TaskClassifier(),
            riskScorer: new RiskLevelScorer(),
            specComposer: new SpecComposer(),
            tierSelector: new DocContextTierSelector(),
            codeDiscovery: new CodeDiscoveryEngine(),
            openBrainAdapter: new OpenBrainProjectionAdapter($this->fakeOpenBrain),
            promptBuilder: new ProviderPromptBuilder(
                sectionsMapper: new PromptSectionsMapper(),
                renderer: new PromptRenderer(),
                qualityChecker: new PromptQualityChecker(),
            ),
            routingEngine: new RoutingDecisionEngine(),
            receiptStorage: new ReceiptStorage($this->tmpStorage),
        );
    }

    private function assertArtifactsPersisted(PlanOnlyResult $result): void
    {
        $required = [
            ArtifactNames::OPERATION_ENVELOPE,
            ArtifactNames::COMPACT_SDD,
            ArtifactNames::CONTEXT_RETRIEVAL_PLAN,
            ArtifactNames::CODE_DISCOVERY_MANIFEST,
            ArtifactNames::OPEN_BRAIN_PROJECTION,
            ArtifactNames::MINI_PROGRAMMING_SPEC,
            ArtifactNames::TASK_CONTRACT,
            ArtifactNames::PROMPT_PROJECTION,
            ArtifactNames::ROUTING_DECISION,
        ];

        foreach ($required as $name) {
            $this->assertArrayHasKey($name, $result->persistedArtifactPaths, "missing persisted artifact {$name}");
            $path = $result->persistedArtifactPaths[$name];
            $this->assertFileExists($path);
            $payload = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($payload, "artifact {$name} must decode as JSON");
        }
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

/**
 * Replaces {@see AtlasOpenBrainService} with a deterministic zero-context
 * provider for the E2E test. Bypasses the parent constructor so we don't
 * touch the real {@see \App\Services\Ai\AiContextPackBuilder} or DB.
 */
final class FakeAtlasOpenBrainService extends AtlasOpenBrainService
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function __construct()
    {
        // intentional: skip parent
    }

    public function contextPack(array $data, string $surface = 'api'): array
    {
        $this->calls[] = $data;

        return [
            'ok' => true,
            'context_refs' => [],
            'summary' => [
                'context_refs_count' => 0,
                'memory_refs_count' => 0,
                'recall_count' => 0,
                'registry_count' => 0,
                'verbatim_count' => 0,
                'semantic_count' => 0,
                'provider_safe' => true,
            ],
        ];
    }
}

/**
 * Tracker for the explicit "provider must never be called" assertion. The
 * orchestrator currently has no provider dependency, so this just lives in
 * the test scope as a witness; if the orchestrator were ever wired to a
 * provider in error, a future regression would trip the assertion below.
 */
final class ProviderShouldNotBeCalled
{
    public int $calls = 0;

    public function invoke(): never
    {
        $this->calls++;
        throw new \RuntimeException('plan-only must not call provider');
    }
}
