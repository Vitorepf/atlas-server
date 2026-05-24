<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Surface;

use App\Services\Ai\AtlasOpenBrainService;
use App\Services\Ai\Programming\AtlasDev\Discovery\CodeDiscoveryEngine;
use App\Services\Ai\Programming\AtlasDev\Discovery\DocContextTierSelector;
use App\Services\Ai\Programming\AtlasDev\Discovery\OpenBrainProjectionAdapter;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RiskLevelScorer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecisionEngine;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RunIdGenerator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\SpecComposer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassifier;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptQualityChecker;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptRenderer;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\Surface\AtlasDesktopAiAdapter;
use App\Services\Ai\Programming\AtlasDev\Surface\DesktopUiHintsBuilder;
use App\Services\Ai\Programming\AtlasDev\Surface\SurfaceResponseFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtlasDesktopAiAdapterTest extends TestCase
{
    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-surface-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-surface-ws-'.bin2hex(random_bytes(4));
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

    public function test_surface_id_is_canonical(): void
    {
        $this->assertSame('atlas_desktop_ai', $this->adapter()->surfaceId());
    }

    public function test_build_envelope_with_desktop_payload_produces_atlas_desktop_ai_envelope(): void
    {
        $envelope = $this->adapter()->buildEnvelope([
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
            'user_constraints' => ['nao tocar vendor/'],
            'thread_id' => 'thread_abc',
            'conversation_id' => 'conv_xyz',
            'composer_mode' => 'programming',
            'composer_task' => 'dev',
            'provider_choice' => 'auto',
            'attachments' => [['kind' => 'note', 'text' => '...']],
        ]);

        $this->assertSame('atlas_desktop_ai', $envelope->surfaceId);
        // IntakeNormalizer resolves to the canonical realpath; on macOS this
        // expands `/var/...` to `/private/var/...`. Compare via realpath.
        $this->assertSame(realpath($this->tmpWorkspace), $envelope->workspace);
        $this->assertSame('programming', $envelope->surfaceContext->composerMode);
        $this->assertSame('thread_abc', $envelope->surfaceContext->threadId);
        $this->assertSame(['nao tocar vendor/'], $envelope->userConstraints);
        $this->assertNotSame('', $envelope->envelopeHash);
        $this->assertTrue($envelope->preflight->workspaceResolved);
    }

    public function test_build_envelope_missing_workspace_falls_back_to_plan_only(): void
    {
        $envelope = $this->adapter()->buildEnvelope([
            'workspace' => '/nonexistent/'.bin2hex(random_bytes(4)),
            'raw_intent' => 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
        ]);

        $this->assertSame('atlas_desktop_ai', $envelope->surfaceId);
        $this->assertFalse($envelope->preflight->workspaceResolved);
        $this->assertFalse($envelope->preflight->writeAllowed);
        $this->assertSame(IntakeNormalizer::PERMISSION_PLAN_ONLY, $envelope->preflight->permissionMode);
    }

    public function test_build_envelope_rejects_empty_raw_intent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->buildEnvelope([
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => '   ',
        ]);
    }

    public function test_build_envelope_rejects_unsupported_surface_id_override(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->buildEnvelope([
            'surface_id' => 'atlas_cli_dev',
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => 'corrija o teste',
        ]);
    }

    public function test_build_envelope_accepts_legacy_atlas_ai_desktop_mac_surface_id(): void
    {
        $envelope = $this->adapter()->buildEnvelope([
            'surface_id' => 'atlas_ai_desktop_mac',
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
        ]);

        $this->assertSame('atlas_desktop_ai', $envelope->surfaceId);
    }

    public function test_build_envelope_forwards_flow_origin_router_and_command_intent_from_payload(): void
    {
        $envelope = $this->adapter()->buildEnvelope([
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
            'flow_origin' => 'atlas_ai_router',
            'command_intent' => 'dev',
        ]);

        $this->assertSame('atlas_dev', $envelope->flowId);
        $this->assertSame('atlas_ai_router', $envelope->flowOrigin);
        $this->assertSame('dev', $envelope->commandIntent);
    }

    public function test_build_envelope_defaults_to_direct_flow_origin_when_payload_lacks_router_hint(): void
    {
        $envelope = $this->adapter()->buildEnvelope([
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
        ]);

        $this->assertSame('atlas_dev', $envelope->flowId);
        $this->assertSame('direct', $envelope->flowOrigin);
        $this->assertNull($envelope->commandIntent);
    }

    public function test_format_plan_only_includes_canonical_artifacts_and_routing(): void
    {
        $result = $this->runPlanOnly('corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php');

        $response = $this->adapter()->formatPlanOnly($result);

        $this->assertSame('plan_only', $response['kind']);
        $this->assertSame($result->envelope->runId, $response['run_id']);
        $this->assertSame('atlas_desktop_ai', $response['surface_id']);
        $this->assertSame($result->routing->kind, $response['routing_decision']);
        $this->assertSame($result->routing->reasons, $response['routing_reasons']);
        $this->assertArrayHasKey('artifacts', $response);
        $this->assertArrayHasKey('operation_envelope', $response['artifacts']);
        $this->assertArrayHasKey('compact_sdd', $response['artifacts']);
        $this->assertArrayHasKey('mini_programming_spec', $response['artifacts']);
        $this->assertArrayHasKey('task_contract', $response['artifacts']);
        $this->assertArrayHasKey('prompt_projection', $response['artifacts']);
        $this->assertArrayHasKey('senior_engineer_loop_audit', $response['artifacts']);
        $this->assertSame('passed', $response['senior_loop']['status']);
    }

    public function test_format_plan_only_ui_hints_contexto_renders_discovery_and_open_brain(): void
    {
        $result = $this->runPlanOnly('corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php');

        $hints = $this->adapter()->formatPlanOnly($result)['ui_hints'];

        $this->assertArrayHasKey('panel_contexto', $hints);
        $contexto = $hints['panel_contexto'];
        $this->assertSame($result->contextPlan->selectedTiers, $contexto['selected_tiers']);
        $this->assertSame($result->contextPlan->budgetChars, $contexto['budget']['max_chars']);
        $this->assertSame($result->discovery->confidence, $contexto['discovery']['confidence']);
        $this->assertCount(count($result->discovery->likelyFiles), $contexto['discovery']['likely_files']);
        $this->assertCount(count($result->projection->memoryRefs), $contexto['memory_refs']);
        $this->assertCount(count($result->projection->codeRefs), $contexto['code_refs']);
    }

    public function test_format_plan_only_ui_hints_plano_renders_mini_spec_and_task_contract(): void
    {
        $result = $this->runPlanOnly('corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php');

        $plano = $this->adapter()->formatPlanOnly($result)['ui_hints']['panel_plano'];

        $this->assertSame($result->miniSpec->goal, $plano['objective']);
        $this->assertSame($result->miniSpec->nonGoals, $plano['non_goals']);
        $this->assertNotSame($result->miniSpec->expectedFiles, $plano['expected_files']);
        foreach ($plano['expected_files'] as $path) {
            $this->assertIsString($path);
            $this->assertDoesNotMatchRegularExpression('@^/@', $path, 'Desktop ui_hints expected_files must not leak absolute paths.');
        }
        $this->assertSame($result->miniSpec->allowedFiles, $plano['allowed_files']);
        $this->assertSame($result->miniSpec->forbiddenFiles, $plano['forbidden_files']);
        $this->assertSame($result->miniSpec->acceptanceCriteria, $plano['acceptance_criteria']);
        $this->assertSame($result->miniSpec->verificationPlan->commands, $plano['validation_commands']);
        $this->assertSame($result->taskContract->escalationOn, $plano['escalation_conditions']);
        $this->assertSame($result->taskContract->maxFilesChanged, $plano['max_files_changed']);
    }

    public function test_ui_hints_inline_indicators_match_artifact_facts(): void
    {
        $result = $this->runPlanOnly('corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php');

        $indicators = $this->adapter()->formatPlanOnly($result)['ui_hints']['inline_indicators'];

        $expectedOpenBrainState = ($result->projection->truncation['truncated'] ?? false) === true
            || $result->projection->missingSources !== []
            ? 'parcial'
            : 'completo';
        $this->assertSame($expectedOpenBrainState, $indicators['open_brain']['state']);
        $this->assertSame(count($result->miniSpec->allowedFiles), $indicators['escopo']['n_arquivos']);
        $this->assertSame($result->taskContract->maxFilesChanged, $indicators['escopo']['max_files_changed']);
        $this->assertSame('pending', $indicators['verificacao']['state']);
        $this->assertSame('pending', $indicators['repair']['state']);
        $this->assertSame($result->taskContract->repairPolicy->maxAttempts, $indicators['repair']['max_attempts']);
    }

    public function test_ui_hints_senior_loop_panel_projects_audit_without_inventing_facts(): void
    {
        $result = $this->runPlanOnly('corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php');

        $panel = $this->adapter()->formatPlanOnly($result)['ui_hints']['panel_senior_loop'];

        $this->assertSame($result->seniorLoopAudit?->status, $panel['state']);
        $this->assertSame($result->seniorLoopAudit?->auditHash, $panel['audit_hash']);
        $this->assertSame($result->seniorLoopAudit?->capabilities, $panel['capabilities']);
        $this->assertSame($result->seniorLoopAudit?->blockers, $panel['blockers']);
        $this->assertSame($result->seniorLoopAudit?->multiStepPlan, $panel['multi_step_plan']);
        $this->assertSame($result->seniorLoopAudit?->learningHandoff, $panel['learning_handoff']);
    }

    public function test_ui_hints_never_invents_routing_decision(): void
    {
        $result = $this->runPlanOnly('explique como o FooService resolve workspace');

        $response = $this->adapter()->formatPlanOnly($result);

        // routing_decision is whatever the core decided; ui_hints does NOT
        // surface a different value, and `execution_placeholders` keeps
        // every gate slot pending until the core executes them.
        $this->assertSame($result->routing->kind, $response['routing_decision']);
        $this->assertSame('pending', $response['ui_hints']['execution_placeholders']['diff']['state']);
        $this->assertSame('pending', $response['ui_hints']['execution_placeholders']['tests']['state']);
        $this->assertSame('pending', $response['ui_hints']['execution_placeholders']['receipt']['state']);
    }

    public function test_adapter_does_not_depend_on_any_provider_class(): void
    {
        $reflection = new \ReflectionClass(AtlasDesktopAiAdapter::class);
        $deps = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType) {
                $deps[] = $type->getName();
            }
        }

        foreach ($deps as $dep) {
            $this->assertStringNotContainsString('Provider', $dep, "adapter must not depend on a Provider class (got {$dep})");
            $this->assertStringNotContainsString('ClaudeCli', $dep, "adapter must not depend on a Claude CLI gateway (got {$dep})");
        }
    }

    private function adapter(): AtlasDesktopAiAdapter
    {
        return new AtlasDesktopAiAdapter(
            intake: new IntakeNormalizer(new RunIdGenerator),
            formatter: new SurfaceResponseFormatter,
            hintsBuilder: new DesktopUiHintsBuilder,
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
            openBrainAdapter: new OpenBrainProjectionAdapter(new SurfaceFakeOpenBrainService),
            promptBuilder: new ProviderPromptBuilder(
                sectionsMapper: new PromptSectionsMapper,
                renderer: new PromptRenderer,
                qualityChecker: new PromptQualityChecker,
            ),
            routingEngine: new RoutingDecisionEngine,
            receiptStorage: new ReceiptStorage($this->tmpStorage),
        );

        return $orchestrator->planOnly(
            surfaceId: 'atlas_desktop_ai',
            workspace: $this->tmpWorkspace,
            rawIntent: $rawIntent,
        );
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
 * Local zero-context Open Brain fake. Mirrors the FakeAtlasOpenBrainService
 * helper used by the E2E feature test so the Surface unit test can exercise
 * the orchestrator without touching the real DB/context pack stack.
 */
final class SurfaceFakeOpenBrainService extends AtlasOpenBrainService
{
    public function __construct()
    {
        // skip parent
    }

    public function contextPack(array $data, string $surface = 'api'): array
    {
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
