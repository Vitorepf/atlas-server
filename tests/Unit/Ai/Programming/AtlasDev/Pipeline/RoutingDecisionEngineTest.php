<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Pipeline\DelegationSuggestion;
use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RiskLevelScorer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecision;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecisionEngine;
use App\Services\Ai\Programming\AtlasDev\Pipeline\SpecComposer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CodeCandidate;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use PHPUnit\Framework\TestCase;

final class RoutingDecisionEngineTest extends TestCase
{
    public function test_question_routes_to_read_only_answer(): void
    {
        $decision = $this->decide(
            classification: $this->classification(TaskClassification::KIND_QUESTION, false),
            risk: RiskLevelScorer::R0,
            confidence: CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE,
        );
        $this->assertSame(RoutingDecision::READ_ONLY_ANSWER, $decision->kind);
    }

    public function test_r4_routes_to_forge_preview_even_for_write_kind(): void
    {
        $decision = $this->decide(
            classification: $this->classification(TaskClassification::KIND_PATCH, true),
            risk: RiskLevelScorer::R4,
            confidence: CodeDiscoveryManifest::CONFIDENCE_CONFIRMED_FACT,
        );
        $this->assertSame(RoutingDecision::FORGE_PROMOTION_PREVIEW, $decision->kind);
    }

    public function test_r2_repair_with_strong_inference_routes_to_fast_path(): void
    {
        $decision = $this->decide(
            classification: $this->classification(TaskClassification::KIND_REPAIR, true),
            risk: RiskLevelScorer::R2,
            confidence: CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE,
        );
        $this->assertSame(RoutingDecision::ATLAS_DEV_FAST_PATH, $decision->kind);
        $this->assertSame([], $decision->blockers);
    }

    public function test_blocking_intent_clarity_blocks_routing(): void
    {
        $decision = $this->decide(
            classification: $this->classification(TaskClassification::KIND_PATCH, true),
            risk: RiskLevelScorer::R2,
            confidence: CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE,
            intentClarity: IntakeNormalizer::CLARITY_BLOCKING,
        );
        $this->assertSame(RoutingDecision::BLOCKED, $decision->kind);
        $this->assertContains('intent_clarity_blocking', $decision->blockers);
    }

    public function test_blocking_discovery_blocks_routing(): void
    {
        $decision = $this->decide(
            classification: $this->classification(TaskClassification::KIND_PATCH, true),
            risk: RiskLevelScorer::R2,
            confidence: CodeDiscoveryManifest::CONFIDENCE_BLOCKING_AMBIGUITY,
        );
        $this->assertSame(RoutingDecision::BLOCKED, $decision->kind);
        $this->assertContains('discovery_blocking_ambiguity', $decision->blockers);
    }

    public function test_low_clarity_write_falls_back_to_read_only(): void
    {
        $decision = $this->decide(
            classification: $this->classification(TaskClassification::KIND_PATCH, true),
            risk: RiskLevelScorer::R2,
            confidence: CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE,
            intentClarity: IntakeNormalizer::CLARITY_LOW,
        );
        $this->assertSame(RoutingDecision::READ_ONLY_ANSWER, $decision->kind);
    }

    public function test_hypothesis_discovery_on_write_falls_back_to_read_only(): void
    {
        $decision = $this->decide(
            classification: $this->classification(TaskClassification::KIND_PATCH, true),
            risk: RiskLevelScorer::R2,
            confidence: CodeDiscoveryManifest::CONFIDENCE_HYPOTHESIS,
        );
        $this->assertSame(RoutingDecision::READ_ONLY_ANSWER, $decision->kind);
    }

    public function test_conceptual_intent_without_workspace_delegates_to_research(): void
    {
        $decision = $this->decide(
            classification: $this->classification(TaskClassification::KIND_QUESTION, false),
            risk: RiskLevelScorer::R0,
            confidence: CodeDiscoveryManifest::CONFIDENCE_HYPOTHESIS,
            intent: 'explique como funciona OAuth 2.0',
            workspaceResolved: false,
        );

        $this->assertSame(RoutingDecision::DELEGATE_TO_OTHER_FLOW, $decision->kind);
        $this->assertNotNull($decision->delegation);
        $this->assertSame(DelegationSuggestion::FLOW_RESEARCH, $decision->suggestedFlow());
        $this->assertContains('delegate_to_atlas_research_because_conceptual_question_without_workspace_target', $decision->reasons);
    }

    public function test_chat_intent_delegates_to_conversation(): void
    {
        $decision = $this->decide(
            classification: $this->classification(TaskClassification::KIND_QUESTION, false),
            risk: RiskLevelScorer::R0,
            confidence: CodeDiscoveryManifest::CONFIDENCE_HYPOTHESIS,
            intent: 'vamos conversar sobre o futuro do produto',
            workspaceResolved: false,
        );

        $this->assertSame(RoutingDecision::DELEGATE_TO_OTHER_FLOW, $decision->kind);
        $this->assertSame(DelegationSuggestion::FLOW_CONVERSATION, $decision->suggestedFlow());
    }

    public function test_log_only_debug_intent_delegates_to_debug(): void
    {
        $decision = $this->decide(
            classification: $this->classification(TaskClassification::KIND_QUESTION, false),
            risk: RiskLevelScorer::R0,
            confidence: CodeDiscoveryManifest::CONFIDENCE_HYPOTHESIS,
            intent: 'analise os logs deste incidente em produção',
            workspaceResolved: false,
        );

        $this->assertSame(RoutingDecision::DELEGATE_TO_OTHER_FLOW, $decision->kind);
        $this->assertSame(DelegationSuggestion::FLOW_DEBUG, $decision->suggestedFlow());
    }

    public function test_repo_bound_question_stays_in_atlas_dev_read_only(): void
    {
        $decision = $this->decide(
            classification: $this->classification(TaskClassification::KIND_QUESTION, false),
            risk: RiskLevelScorer::R0,
            confidence: CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE,
            intent: 'explique como funciona o app/Services/Auth/OAuthService.php neste repo',
            workspaceResolved: true,
        );

        $this->assertSame(RoutingDecision::READ_ONLY_ANSWER, $decision->kind);
        $this->assertNull($decision->delegation);
    }

    public function test_patch_with_workspace_stays_in_fast_path(): void
    {
        $decision = $this->decide(
            classification: $this->classification(TaskClassification::KIND_REPAIR, true),
            risk: RiskLevelScorer::R2,
            confidence: CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE,
            intent: 'corrigir o teste em tests/Unit/FooTest.php',
            workspaceResolved: true,
        );

        $this->assertSame(RoutingDecision::ATLAS_DEV_FAST_PATH, $decision->kind);
        $this->assertNull($decision->delegation);
    }

    public function test_obra_marker_delegates_to_forge(): void
    {
        $decision = $this->decide(
            classification: $this->classification(TaskClassification::KIND_PATCH, true),
            risk: RiskLevelScorer::R2,
            confidence: CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE,
            intent: 'reescrever o sistema inteiro de autenticação em varias semanas',
            workspaceResolved: true,
        );

        $this->assertSame(RoutingDecision::DELEGATE_TO_OTHER_FLOW, $decision->kind);
        $this->assertSame(DelegationSuggestion::FLOW_FORGE, $decision->suggestedFlow());
    }

    private function decide(
        TaskClassification $classification,
        string $risk,
        string $confidence,
        string $intentClarity = IntakeNormalizer::CLARITY_HIGH,
        string $intent = 'corrigir o teste falhando em tests/Unit/FooTest.php',
        bool $workspaceResolved = true,
    ): RoutingDecision {
        $envelope = $this->envelope($intentClarity, $intent, $workspaceResolved);
        $compact = (new SpecComposer)->composeCompactSdd($envelope, $classification, $risk);
        $discovery = $this->discovery($confidence);

        return (new RoutingDecisionEngine)->decide($envelope, $classification, $compact, $discovery);
    }

    private function classification(string $kind, bool $writeImplied): TaskClassification
    {
        return new TaskClassification(
            taskKind: $kind,
            intentClarityLevel: IntakeNormalizer::CLARITY_HIGH,
            matchedRules: [],
            writeImplied: $writeImplied,
        );
    }

    private function envelope(
        string $clarity,
        string $intent = 'corrigir o teste falhando em tests/Unit/FooTest.php',
        bool $workspaceResolved = true,
    ): OperationEnvelope {
        return new OperationEnvelope(
            runId: 'dev-test',
            surfaceId: 'atlas_cli_dev',
            surfaceContext: new SurfaceContext(productSurface: 'atlas_cli_dev'),
            workspace: $workspaceResolved ? '/ws' : '',
            workspaceHash: hash('sha256', '/ws'),
            gitState: new GitState(headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0),
            rawIntent: $intent,
            normalizedIntent: $intent,
            userConstraints: [],
            intentClarityLevel: $clarity,
            dirtyWorktreePolicy: IntakeNormalizer::DIRTY_POLICY_PRESERVE,
            preflight: new Preflight(
                workspaceResolved: $workspaceResolved,
                permissionMode: IntakeNormalizer::PERMISSION_WRITE_ALLOWED,
                writeAllowed: $workspaceResolved,
                operatorExplicit: false,
            ),
            envelopeHash: 'deadbeef',
        );
    }

    private function discovery(string $confidence): CodeDiscoveryManifest
    {
        $likely = $confidence === CodeDiscoveryManifest::CONFIDENCE_BLOCKING_AMBIGUITY
            ? []
            : [new CodeCandidate(path: '/ws/app/Foo.php', reason: 'fixture', confidence: 0.9, symbols: [])];

        return new CodeDiscoveryManifest(
            runId: 'dev-test',
            likelyFiles: $likely,
            relatedSymbols: [],
            relatedTests: [],
            relatedCommands: [],
            confidence: $confidence,
            missingRefs: [],
            forbiddenFiles: [],
            providerSafe: true,
            manifestHash: '',
        );
    }
}
