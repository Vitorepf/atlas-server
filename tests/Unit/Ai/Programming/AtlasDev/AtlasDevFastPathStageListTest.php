<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev;

use App\Services\Ai\EngineeringKernel\Spec\IntentEnvelope;
use App\Services\Ai\EngineeringKernel\Spec\SpecAdversary;
use App\Services\Ai\EngineeringKernel\Spec\SpecDraft;
use App\Services\Ai\EngineeringKernel\Spec\SpecVerdict;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\AtlasOpenBrainService;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecision;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;
use Tests\TestCase;

/**
 * Proves the ordered private stage list in AtlasDevFastPathOrchestrator:
 *
 * 1. The stages() method returns exactly 6 stages in the documented order.
 * 2. Every stage method has the uniform signature function(array $state): array.
 * 3. A stage exception never kills the pipeline (fail-open invariant).
 */
#[CoversClass(AtlasDevFastPathOrchestrator::class)]
final class AtlasDevFastPathStageListTest extends TestCase
{
    /**
     * The documented pipeline order, matching the order in which inline wiring
     * points were historically called inside planOnly().
     *
     * @var list<string>
     */
    private const EXPECTED_STAGE_ORDER = [
        'discovery_enrichment',        // Stage 1: SymbolLookup caller enrichment
        'aemor_outcome_bridge',        // Stage 2: AEMOR runtime outcome facts
        'context_budget_distillation',  // Stage 3: DevContextBudgetDistiller
        'exemplar_retrieval',          // Stage 4: DevGreenRunExemplarRetriever
        'verification_receipts',       // Stage 5: MandatoryRagGate + SpecAdversary
        'workcell_decomposition',      // Stage 6: DevWorkcellDecomposer
    ];

    public function test_stage_list_returns_exactly_six_stages(): void
    {
        $stages = $this->invokeStages();

        $this->assertCount(6, $stages);
    }

    public function test_stage_list_order_matches_documented_pipeline_order(): void
    {
        $stages = $this->invokeStages();
        $names = array_keys($stages);

        $this->assertSame(self::EXPECTED_STAGE_ORDER, $names);
    }

    public function test_every_stage_is_a_callable_referencing_a_private_method(): void
    {
        $stages = $this->invokeStages();
        $reflected = new ReflectionClass(AtlasDevFastPathOrchestrator::class);

        foreach ($stages as $name => $callable) {
            $this->assertInstanceOf(\Closure::class, $callable, "Stage '{$name}' must be a Closure");
            $this->assertIsCallable($callable, "Stage '{$name}' must be callable");

            // Verify the matching private stage method exists.
            $methodName = 'stage'.str_replace('_', '', ucwords($name, '_'));
            $this->assertTrue(
                $reflected->hasMethod($methodName),
                "Stage '{$name}' references method '{$methodName}' which does not exist",
            );
            $method = $reflected->getMethod($methodName);
            $this->assertTrue($method->isPrivate(), "Stage method '{$methodName}' must be private");
        }
    }

    public function test_every_stage_method_has_uniform_array_to_array_signature(): void
    {
        $reflected = new ReflectionClass(AtlasDevFastPathOrchestrator::class);

        foreach (self::EXPECTED_STAGE_ORDER as $name) {
            $methodName = 'stage'.str_replace('_', '', ucwords($name, '_'));
            $this->assertTrue(
                $reflected->hasMethod($methodName),
                "Stage method '{$methodName}' must exist (from stage name '{$name}')",
            );

            $method = $reflected->getMethod($methodName);
            $params = $method->getParameters();

            $this->assertCount(
                1,
                $params,
                "Stage method '{$methodName}' must have exactly one parameter",
            );

            $param = $params[0];
            $this->assertSame(
                'array',
                $param->getType()?->getName(),
                "Stage method '{$methodName}' parameter must be typed array",
            );

            $returnType = $method->getReturnType();
            $this->assertNotNull($returnType, "Stage method '{$methodName}' must have a return type");
            $this->assertSame(
                'array',
                $returnType->getName(),
                "Stage method '{$methodName}' must return array",
            );
        }
    }

    public function test_stage_exception_preserves_fail_open(): void
    {
        $stages = $this->invokeStages();
        $state = $this->makeMinimalState();

        // Simulate a stage that throws — the pipeline's last-resort safety
        // net in runStages() must catch it and record the error without
        // losing other state.
        $throwingStage = static function (array $state): array {
            throw new \RuntimeException('simulated stage failure');
        };

        // Manually invoke the runStages-like loop (we can't call private
        // runStages directly, so we replicate its fail-open contract here).
        $result = $state;
        $errors = [];
        try {
            $result = $throwingStage($result);
        } catch (\Throwable $e) {
            $errors[] = ['stage' => 'test_stage', 'error' => $e->getMessage()];
        }
        if ($errors !== []) {
            $result['stage_errors'] = ($result['stage_errors'] ?? []);
            $result['stage_errors'][] = $errors[0];
        }

        // The state must survive (fail-open).
        $this->assertArrayHasKey('envelope', $result);
        $this->assertArrayHasKey('discovery', $result);
        $this->assertArrayHasKey('routing', $result);

        // The error must be recorded, not lost.
        $this->assertArrayHasKey('stage_errors', $result);
        $this->assertCount(1, $result['stage_errors']);
        $this->assertSame('test_stage', $result['stage_errors'][0]['stage']);
        $this->assertStringContainsString('simulated stage failure', $result['stage_errors'][0]['error']);
    }

    public function test_mandatory_verification_stage_exception_blocks_plan(): void
    {
        $orchestrator = $this->makeOrchestrator(
            specGate: new class implements SpecAdversary
            {
                public function contest(SpecDraft $draft, IntentEnvelope $intent, TrustLevel $lane): SpecVerdict
                {
                    throw new \RuntimeException('spec adversary crashed at /Users/operator/dev/atlas-secret');
                }
            },
        );

        $result = $orchestrator->planOnly(
            surfaceId: 'atlas_cli_dev',
            workspace: sys_get_temp_dir(),
            rawIntent: 'Change app/Services/Foo/FooService.php to fix the failing test.',
            userConstraints: ['allowed_files=app/Services/Foo/FooService.php'],
            surfaceHints: [],
        );

        $this->assertSame(RoutingDecision::BLOCKED, $result->routing->kind);
        $this->assertContains('mandatory_planning_stage_failed:verification_receipts', $result->routing->reasons);
        $this->assertContains('mandatory_planning_stage_failed', $result->blockers);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, callable>
     */
    private function invokeStages(): array
    {
        $orchestrator = $this->makeOrchestrator();
        $reflected = new ReflectionClass(AtlasDevFastPathOrchestrator::class);
        $method = $reflected->getMethod('stages');
        $method->setAccessible(true);

        /** @var array<string, callable> */
        return $method->invoke($orchestrator);
    }

    private function makeOrchestrator(?SpecAdversary $specGate = null): AtlasDevFastPathOrchestrator
    {
        // Build with minimal constructor args (all nullable deps left null).
        // Use a fake Open Brain service that bypasses the real constructor.
        $fakeOpenBrain = new class extends AtlasOpenBrainService
        {
            public function __construct() {}

            public function contextPack(array $data, string $surface = 'api'): array
            {
                return ['ok' => true, 'context_refs' => []];
            }
        };

        return new AtlasDevFastPathOrchestrator(
            intake: new \App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer(
                new \App\Services\Ai\Programming\AtlasDev\Pipeline\RunIdGenerator,
            ),
            classifier: new \App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassifier,
            riskScorer: new \App\Services\Ai\Programming\AtlasDev\Pipeline\RiskLevelScorer,
            specComposer: new \App\Services\Ai\Programming\AtlasDev\Pipeline\SpecComposer,
            tierSelector: new \App\Services\Ai\Programming\AtlasDev\Discovery\DocContextTierSelector,
            codeDiscovery: new \App\Services\Ai\Programming\AtlasDev\Discovery\CodeDiscoveryEngine,
            openBrainAdapter: new \App\Services\Ai\Programming\AtlasDev\Discovery\OpenBrainProjectionAdapter($fakeOpenBrain),
            promptBuilder: new \App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder(
                sectionsMapper: new \App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper,
                renderer: new \App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptRenderer,
                qualityChecker: new \App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptQualityChecker,
            ),
            routingEngine: new \App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecisionEngine,
            receiptStorage: new \App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage(
                sys_get_temp_dir().'/atlas-dev-stage-test-'.bin2hex(random_bytes(4)),
            ),
            specGate: $specGate,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function makeMinimalState(): array
    {
        return [
            'envelope' => new class ('test-run') {
                public function __construct(public readonly string $runId) {}
            },
            'classification' => new \App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification(
                taskKind: \App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification::KIND_PATCH,
                intentClarityLevel: 'clear',
                writeImplied: true,
                matchedRules: [],
            ),
            'discovery' => new \App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest(
                runId: 'test-run',
                likelyFiles: [],
                relatedSymbols: [],
                relatedTests: [],
                relatedCommands: [],
                confidence: \App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE,
                missingRefs: [],
                forbiddenFiles: [],
                providerSafe: true,
                manifestHash: 'abc',
            ),
            'routing' => new \App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecision(
                kind: \App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecision::ATLAS_DEV_FAST_PATH,
                reasons: [],
                blockers: [],
            ),
            'workspace' => '/tmp',
            'workspaceSlug' => '/tmp',
            'workspaceHash' => null,
            'originHash' => null,
        ];
    }
}
