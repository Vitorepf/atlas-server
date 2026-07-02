<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RiskLevelScorer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\SpecComposer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;
use Tests\TestCase;

/**
 * Stub ADML with a fixed learned route (mirrors StubAdmlForProviderManager
 * in AiProviderManagerTest — the same brain, consumed from the Dev side).
 */
final class StubAdmlForSpecComposer extends AtlasDecideMetaLearningService
{
    /** @param array<string,mixed>|null $route */
    public function __construct(private readonly ?array $route = null) {}

    public function activeRouteFor(string $taskCategory, string $role, ?string $framework = null): ?array
    {
        return $this->route;
    }
}

/**
 * Model routing organ: with NO explicit operator provider choice, the
 * SpecComposer follows the Atlas Decide learned route (ADML) — and every
 * fail-open edge keeps today's config default.
 */
final class SpecComposerDecideRoutingTest extends TestCase
{
    /** @param array<string,mixed>|null $route */
    private function bindConsultation(?array $route): void
    {
        $u = uniqid('', true);
        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting(sys_get_temp_dir()."/atlas_sc_kernel_{$u}.jsonl");
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting(sys_get_temp_dir()."/atlas_sc_admission_{$u}.jsonl");

        $svc = new AtlasDecideGatewayConsultationService(new StubAdmlForSpecComposer($route), $kernel, $admission);
        $svc->setLogPathForTesting(sys_get_temp_dir()."/atlas_sc_consult_{$u}.jsonl");

        $this->app->instance(AtlasDecideGatewayConsultationService::class, $svc);
    }

    private function composeContract(?string $providerChoice = null): LightTaskContract
    {
        $composer = new SpecComposer;
        $envelope = $this->envelope('corrija o teste falhando em tests/Unit/FooTest.php', $providerChoice);
        $classification = new TaskClassification(
            taskKind: TaskClassification::KIND_REPAIR,
            intentClarityLevel: IntakeNormalizer::CLARITY_HIGH,
            matchedRules: ['repair:corrija'],
            writeImplied: true,
        );
        $compact = $composer->composeCompactSdd($envelope, $classification, RiskLevelScorer::R2);
        $discovery = new CodeDiscoveryManifest(
            runId: $envelope->runId,
            likelyFiles: [],
            relatedSymbols: [],
            relatedTests: [],
            relatedCommands: [],
            confidence: CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE,
            missingRefs: [],
            forbiddenFiles: [],
            providerSafe: true,
            manifestHash: 'h',
        );
        $miniSpec = $composer->composeMiniSpec($envelope, $compact, $discovery, $this->emptyProjection($envelope->runId));

        return $composer->composeTaskContract($envelope, $compact, $miniSpec);
    }

    public function test_learned_route_is_followed_when_operator_gave_no_provider_choice(): void
    {
        $this->bindConsultation([
            'provider' => 'minimax_m27_cli',
            'model' => 'MiniMax-M3',
            'mode' => 'active',
        ]);

        $contract = $this->composeContract();

        $this->assertSame('minimax_m27_cli', $contract->providerLock->provider);
        $this->assertSame('MiniMax-M3', $contract->providerLock->modelFamily);
    }

    public function test_explicit_operator_choice_wins_over_learned_route(): void
    {
        $this->bindConsultation([
            'provider' => 'minimax_m27_cli',
            'model' => 'MiniMax-M3',
            'mode' => 'active',
        ]);

        $contract = $this->composeContract(providerChoice: 'claude');

        $this->assertSame('claude_cli', $contract->providerLock->provider);
    }

    public function test_no_learned_route_falls_back_to_config_default(): void
    {
        $this->bindConsultation(null);

        $contract = $this->composeContract();

        $this->assertSame(
            (string) config('atlas_dev.provider.default_provider', 'claude_cli'),
            $contract->providerLock->provider,
        );
    }

    public function test_learned_route_to_provider_without_dev_runtime_driver_is_ignored(): void
    {
        $this->bindConsultation([
            'provider' => 'gemini_cli',
            'model' => 'selected-by-decide',
            'mode' => 'active',
        ]);

        $contract = $this->composeContract();

        $this->assertSame(
            (string) config('atlas_dev.provider.default_provider', 'claude_cli'),
            $contract->providerLock->provider,
        );
    }

    public function test_kill_switch_disables_consultation(): void
    {
        config()->set('atlas_dev.provider.consult_decide', false);
        $this->bindConsultation([
            'provider' => 'minimax_m27_cli',
            'model' => 'MiniMax-M3',
            'mode' => 'active',
        ]);

        $contract = $this->composeContract();

        $this->assertSame(
            (string) config('atlas_dev.provider.default_provider', 'claude_cli'),
            $contract->providerLock->provider,
        );
    }

    private function envelope(string $intent, ?string $providerChoice = null): OperationEnvelope
    {
        return new OperationEnvelope(
            runId: 'dev-decide-test',
            surfaceId: 'atlas_cli_dev',
            surfaceContext: new SurfaceContext(productSurface: 'atlas_cli_dev', providerChoice: $providerChoice),
            workspace: '/ws',
            workspaceHash: hash('sha256', '/ws'),
            gitState: new GitState(headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0),
            rawIntent: $intent,
            normalizedIntent: trim($intent),
            userConstraints: [],
            intentClarityLevel: IntakeNormalizer::CLARITY_HIGH,
            dirtyWorktreePolicy: IntakeNormalizer::DIRTY_POLICY_PRESERVE,
            preflight: new Preflight(
                workspaceResolved: true,
                permissionMode: IntakeNormalizer::PERMISSION_WRITE_ALLOWED,
                writeAllowed: true,
                operatorExplicit: false,
            ),
            envelopeHash: 'deadbeef',
        );
    }

    private function emptyProjection(string $runId): OpenBrainProgrammingProjection
    {
        return new OpenBrainProgrammingProjection(
            runId: $runId,
            mode: OpenBrainProgrammingProjection::MODE,
            objectiveHash: hash('sha256', 'objective'),
            memoryRefs: [],
            knowledgeRefs: [],
            codeRefs: [],
            missingSources: [],
            truncation: ['truncated' => false, 'reasons' => []],
            providerSafe: true,
            projectionHash: '',
        );
    }
}
