<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Pipeline\DelegationSuggestion;
use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RiskLevelScorer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecision;
use App\Services\Ai\Programming\AtlasDev\Pipeline\SpecialistFlowDecision;
use App\Services\Ai\Programming\AtlasDev\Pipeline\SpecialistFlowRouter;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextBudget;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use PHPUnit\Framework\TestCase;

final class SpecialistFlowRouterTest extends TestCase
{
    public function test_simple_patch_at_low_risk_uses_code_fast_path(): void
    {
        $decision = $this->decide(
            intent: 'corrija typo em app/Foo.php',
            taskKind: TaskClassification::KIND_PATCH,
            writeImplied: true,
            risk: RiskLevelScorer::R1,
            routingKind: RoutingDecision::ATLAS_DEV_FAST_PATH,
        );
        $this->assertSame(SpecialistFlowDecision::FLOW_CODE, $decision->specialistFlow);
        $this->assertSame(SpecialistFlowDecision::PATH_FAST, $decision->path);
        $this->assertSame('atlas_dev', $decision->atlasAiFlowId);
        $this->assertFalse($decision->escalateToForge);
        $this->assertFalse($decision->ambiguous);
    }

    public function test_repair_routes_to_debug(): void
    {
        $decision = $this->decide(
            intent: 'corrija o teste falhando em tests/Unit/FooTest.php',
            taskKind: TaskClassification::KIND_REPAIR,
            writeImplied: true,
            risk: RiskLevelScorer::R2,
            routingKind: RoutingDecision::ATLAS_DEV_FAST_PATH,
        );
        $this->assertSame(SpecialistFlowDecision::FLOW_DEBUG, $decision->specialistFlow);
        $this->assertSame(SpecialistFlowDecision::PATH_FAST, $decision->path);
        $this->assertSame('atlas_debug', $decision->atlasAiFlowId);
    }

    public function test_review_routes_to_review_fast(): void
    {
        $decision = $this->decide(
            intent: 'revisar este diff de billing',
            taskKind: TaskClassification::KIND_REVIEW,
            writeImplied: false,
            risk: RiskLevelScorer::R1,
            routingKind: RoutingDecision::READ_ONLY_ANSWER,
        );
        $this->assertSame(SpecialistFlowDecision::FLOW_REVIEW, $decision->specialistFlow);
        $this->assertSame(SpecialistFlowDecision::PATH_FAST, $decision->path);
        $this->assertSame('atlas_review', $decision->atlasAiFlowId);
    }

    public function test_question_with_workspace_resolved_uses_explain(): void
    {
        $decision = $this->decide(
            intent: 'quantos dias de grace period BillingPolicy usa?',
            taskKind: TaskClassification::KIND_QUESTION,
            writeImplied: false,
            risk: RiskLevelScorer::R0,
            routingKind: RoutingDecision::READ_ONLY_ANSWER,
            workspaceResolved: true,
        );
        $this->assertSame(SpecialistFlowDecision::FLOW_EXPLAIN, $decision->specialistFlow);
        $this->assertSame(SpecialistFlowDecision::PATH_FAST, $decision->path);
    }

    public function test_question_without_workspace_uses_research_deep(): void
    {
        $decision = $this->decide(
            intent: 'qual o estado da arte sobre context caching de provider em 2026?',
            taskKind: TaskClassification::KIND_QUESTION,
            writeImplied: false,
            risk: RiskLevelScorer::R0,
            routingKind: RoutingDecision::READ_ONLY_ANSWER,
            workspaceResolved: false,
        );
        $this->assertSame(SpecialistFlowDecision::FLOW_RESEARCH, $decision->specialistFlow);
        $this->assertSame(SpecialistFlowDecision::PATH_DEEP, $decision->path);
        $this->assertSame('atlas_research', $decision->atlasAiFlowId);
    }

    public function test_test_keyword_overrides_to_test_flow(): void
    {
        $decision = $this->decide(
            intent: 'escreva testes phpunit para AtlasFoo',
            taskKind: TaskClassification::KIND_PATCH,
            writeImplied: true,
            risk: RiskLevelScorer::R1,
            routingKind: RoutingDecision::ATLAS_DEV_FAST_PATH,
        );
        $this->assertSame(SpecialistFlowDecision::FLOW_TEST, $decision->specialistFlow);
        $this->assertSame(SpecialistFlowDecision::PATH_FAST, $decision->path);
        $this->assertSame('atlas_dev', $decision->atlasAiFlowId);
    }

    public function test_refactor_keyword_overrides_to_refactor_flow(): void
    {
        $decision = $this->decide(
            intent: 'refatorar AtlasFoo extraindo metodo bar()',
            taskKind: TaskClassification::KIND_PATCH,
            writeImplied: true,
            risk: RiskLevelScorer::R2,
            routingKind: RoutingDecision::ATLAS_DEV_FAST_PATH,
        );
        $this->assertSame(SpecialistFlowDecision::FLOW_REFACTOR, $decision->specialistFlow);
        $this->assertSame(SpecialistFlowDecision::PATH_FAST, $decision->path);
    }

    public function test_high_risk_r4_routes_to_forge_escalation(): void
    {
        $decision = $this->decide(
            intent: 'mexer no fluxo de billing com migracao',
            taskKind: TaskClassification::KIND_RISKY,
            writeImplied: true,
            risk: RiskLevelScorer::R4,
            routingKind: RoutingDecision::FORGE_PROMOTION_PREVIEW,
        );
        $this->assertSame(SpecialistFlowDecision::FLOW_FORGE_ESCALATION, $decision->specialistFlow);
        $this->assertSame(SpecialistFlowDecision::PATH_ESCALATE, $decision->path);
        $this->assertTrue($decision->escalateToForge);
        $this->assertSame('atlas_forge', $decision->atlasAiFlowId);
    }

    public function test_blocking_ambiguity_with_write_intent_asks_clarification(): void
    {
        $decision = $this->decide(
            intent: 'faz aquela coisa que a gente tinha falado',
            taskKind: TaskClassification::KIND_PATCH,
            writeImplied: true,
            risk: RiskLevelScorer::R1,
            routingKind: RoutingDecision::BLOCKED,
            clarity: IntakeNormalizer::CLARITY_BLOCKING,
        );
        $this->assertSame(SpecialistFlowDecision::FLOW_PLAN, $decision->specialistFlow);
        $this->assertSame(SpecialistFlowDecision::PATH_ASK_CLARIFICATION, $decision->path);
        $this->assertTrue($decision->ambiguous);
    }

    public function test_low_clarity_write_intent_plans_then_asks(): void
    {
        $decision = $this->decide(
            intent: 'ajusta o login um pouco',
            taskKind: TaskClassification::KIND_PATCH,
            writeImplied: true,
            risk: RiskLevelScorer::R2,
            routingKind: RoutingDecision::ATLAS_DEV_FAST_PATH,
            clarity: IntakeNormalizer::CLARITY_LOW,
        );
        $this->assertSame(SpecialistFlowDecision::FLOW_PLAN, $decision->specialistFlow);
        $this->assertSame(SpecialistFlowDecision::PATH_ASK_CLARIFICATION, $decision->path);
        $this->assertTrue($decision->ambiguous);
    }

    public function test_patch_r3_uses_deep_path(): void
    {
        $decision = $this->decide(
            intent: 'corrija bug em tres servicos relacionados',
            taskKind: TaskClassification::KIND_PATCH,
            writeImplied: true,
            risk: RiskLevelScorer::R3,
            routingKind: RoutingDecision::ATLAS_DEV_FAST_PATH,
        );
        $this->assertSame(SpecialistFlowDecision::FLOW_CODE, $decision->specialistFlow);
        $this->assertSame(SpecialistFlowDecision::PATH_DEEP, $decision->path);
    }

    public function test_delegation_mirrors_suggested_flow(): void
    {
        $decision = $this->decide(
            intent: 'pesquise fontes confiaveis sobre x',
            taskKind: TaskClassification::KIND_QUESTION,
            writeImplied: false,
            risk: RiskLevelScorer::R0,
            routingKind: RoutingDecision::DELEGATE_TO_OTHER_FLOW,
            delegation: new DelegationSuggestion(
                suggestedFlow: 'atlas_research',
                reason: 'research_intent_detected',
            ),
        );
        $this->assertSame(SpecialistFlowDecision::FLOW_RESEARCH, $decision->specialistFlow);
        $this->assertSame(SpecialistFlowDecision::PATH_FAST, $decision->path);
        $this->assertSame('atlas_research', $decision->atlasAiFlowId);
    }

    public function test_decision_hash_is_deterministic(): void
    {
        $args = [
            'intent' => 'corrija typo em app/Foo.php',
            'taskKind' => TaskClassification::KIND_PATCH,
            'writeImplied' => true,
            'risk' => RiskLevelScorer::R1,
            'routingKind' => RoutingDecision::ATLAS_DEV_FAST_PATH,
        ];
        $a = $this->decide(...$args);
        $b = $this->decide(...$args);
        $this->assertSame($a->decisionHash, $b->decisionHash);
        $this->assertSame(64, strlen($a->decisionHash));
    }

    public function test_canonical_serialization_contains_required_fields(): void
    {
        $decision = $this->decide(
            intent: 'corrija typo em app/Foo.php',
            taskKind: TaskClassification::KIND_PATCH,
            writeImplied: true,
            risk: RiskLevelScorer::R1,
            routingKind: RoutingDecision::ATLAS_DEV_FAST_PATH,
        );
        $payload = $decision->toCanonicalArray();
        $this->assertSame(SpecialistFlowDecision::SCHEMA_VERSION, $payload['schema_version']);
        foreach (['ambiguous', 'atlas_ai_flow_id', 'escalate_to_forge', 'matched_signals', 'path', 'reasons', 'risk_level', 'specialist_flow', 'task_kind', 'decision_hash'] as $field) {
            $this->assertArrayHasKey($field, $payload);
        }
    }

    private function decide(
        string $intent,
        string $taskKind,
        bool $writeImplied,
        string $risk,
        string $routingKind,
        bool $workspaceResolved = true,
        string $clarity = IntakeNormalizer::CLARITY_MEDIUM,
        ?DelegationSuggestion $delegation = null,
    ): SpecialistFlowDecision {
        $envelope = $this->envelope($intent, $workspaceResolved, $clarity);
        $classification = new TaskClassification(
            taskKind: $taskKind,
            intentClarityLevel: $clarity,
            matchedRules: [],
            writeImplied: $writeImplied,
        );
        $sdd = $this->sdd($taskKind, $risk);
        $discovery = $this->discovery();
        $routing = new RoutingDecision(
            kind: $routingKind,
            reasons: [],
            blockers: [],
            delegation: $delegation,
        );

        return (new SpecialistFlowRouter)->decide($envelope, $classification, $sdd, $discovery, $routing);
    }

    private function envelope(string $intent, bool $workspaceResolved, string $clarity): OperationEnvelope
    {
        return new OperationEnvelope(
            runId: 'dev-test',
            surfaceId: 'atlas_cli_dev',
            surfaceContext: new SurfaceContext(productSurface: 'atlas_cli_dev'),
            workspace: '/ws',
            workspaceHash: hash('sha256', '/ws'),
            gitState: new GitState(headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0),
            rawIntent: $intent,
            normalizedIntent: trim($intent),
            userConstraints: [],
            intentClarityLevel: $clarity,
            dirtyWorktreePolicy: IntakeNormalizer::DIRTY_POLICY_PRESERVE,
            preflight: new Preflight(
                workspaceResolved: $workspaceResolved,
                permissionMode: IntakeNormalizer::PERMISSION_WRITE_ALLOWED,
                writeAllowed: true,
                operatorExplicit: false,
            ),
            envelopeHash: 'deadbeef',
        );
    }

    private function sdd(string $taskKind, string $risk): CompactSdd
    {
        return new CompactSdd(
            runId: 'dev-test',
            envelopeHash: 'deadbeef',
            intentRaw: 'intent',
            intentNormalized: 'intent',
            taskKind: $taskKind,
            riskLevel: $risk,
            scopeMode: 'plan_only',
            mode: 'plan_only',
            contextBudget: new ContextBudget(
                maxChars: 4000,
                maxDocs: 6,
                maxCandidateFiles: 8,
                maxPlanSteps: 6,
                maxProviderCalls: 1,
                maxRepairAttempts: 0,
            ),
            docTiersRequired: [],
            verificationProfile: 'fast_path',
            contextDigest: null,
            escalationTriggers: [],
            miniSpecHash: null,
            taskContractHash: null,
            compactSddHash: 'sdd-hash',
        );
    }

    private function discovery(): CodeDiscoveryManifest
    {
        return new CodeDiscoveryManifest(
            runId: 'dev-test',
            likelyFiles: [],
            relatedSymbols: [],
            relatedTests: [],
            relatedCommands: [],
            confidence: CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE,
            missingRefs: [],
            forbiddenFiles: [],
            providerSafe: true,
            manifestHash: 'discovery-hash',
        );
    }
}
