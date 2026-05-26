<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Gate\MandatoryRagGate;
use App\Services\Ai\Programming\AtlasDev\Gate\MandatoryRagGateResult;
use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecision;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextBudget;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\ContextRetrievalPlan;
use Illuminate\Config\Repository as ConfigRepository;
use PHPUnit\Framework\TestCase;

final class MandatoryRagGateTest extends TestCase
{
    public function test_trivial_question_at_low_risk_passes_without_strict_context(): void
    {
        $gate = new MandatoryRagGate;
        $envelope = $this->envelope([], 'atlas_cli_dev');
        $classification = $this->classification(TaskClassification::KIND_QUESTION, writeImplied: false);
        $sdd = $this->sdd(taskKind: 'question', riskLevel: 'R0', hash: 'sdd-hash-1');
        $plan = $this->plan(tiers: [], requiredSources: [], missingSources: [], hash: '');
        $routing = new RoutingDecision(
            kind: RoutingDecision::READ_ONLY_ANSWER,
            reasons: ['task_kind=question_routes_read_only'],
            blockers: [],
        );

        $result = $gate->evaluate($envelope, $classification, $sdd, $plan, $routing);

        $this->assertSame(MandatoryRagGateResult::STATUS_PASSED, $result->status);
        $this->assertSame(MandatoryRagGateResult::CLASS_TRIVIAL, $result->taskClass);
        $this->assertSame(MandatoryRagGateResult::REASON_TRIVIAL_TASK_NO_GATE, $result->reason);
        $this->assertSame([], $result->blockers);
    }

    public function test_non_trivial_write_with_full_context_passes(): void
    {
        $gate = new MandatoryRagGate;
        $envelope = $this->envelope([], 'atlas_cli_dev');
        $classification = $this->classification(TaskClassification::KIND_PATCH, writeImplied: true);
        $sdd = $this->sdd(taskKind: 'patch', riskLevel: 'R2', hash: 'sdd-hash-2');
        $plan = $this->plan(
            tiers: ['core', 'sdd'],
            requiredSources: ['doc://x'],
            missingSources: [],
            hash: 'plan-hash-1',
        );
        $routing = new RoutingDecision(
            kind: RoutingDecision::ATLAS_DEV_FAST_PATH,
            reasons: ['write_kind=patch_risk=R2_routes_fast_path'],
            blockers: [],
        );

        $result = $gate->evaluate($envelope, $classification, $sdd, $plan, $routing);

        $this->assertSame(MandatoryRagGateResult::STATUS_PASSED, $result->status);
        $this->assertSame(MandatoryRagGateResult::CLASS_NON_TRIVIAL, $result->taskClass);
        $this->assertSame(MandatoryRagGateResult::REASON_SUFFICIENT_CONTEXT, $result->reason);
    }

    public function test_non_trivial_without_plan_hash_is_blocked(): void
    {
        $gate = new MandatoryRagGate;
        $envelope = $this->envelope([], 'atlas_cli_dev');
        $classification = $this->classification(TaskClassification::KIND_PATCH, writeImplied: true);
        $sdd = $this->sdd(taskKind: 'patch', riskLevel: 'R2', hash: 'sdd-hash-3');
        $plan = $this->plan(
            tiers: ['core', 'sdd'],
            requiredSources: ['doc://x'],
            missingSources: [],
            hash: '',
        );
        $routing = new RoutingDecision(
            kind: RoutingDecision::ATLAS_DEV_FAST_PATH,
            reasons: [],
            blockers: [],
        );

        $result = $gate->evaluate($envelope, $classification, $sdd, $plan, $routing);

        $this->assertTrue($result->isBlocked());
        $this->assertSame(MandatoryRagGateResult::REASON_NO_CONTEXT_PACK_HASH, $result->reason);
        $this->assertContains(
            MandatoryRagGateResult::BLOCKER_MANDATORY_RAG_NO_CONTEXT_PACK_HASH,
            $result->blockers,
        );
    }

    public function test_non_trivial_with_missed_required_sources_is_blocked(): void
    {
        $gate = new MandatoryRagGate;
        $envelope = $this->envelope([], 'atlas_cli_dev');
        $classification = $this->classification(TaskClassification::KIND_PATCH, writeImplied: true);
        $sdd = $this->sdd(taskKind: 'patch', riskLevel: 'R3', hash: 'sdd-hash-4');
        $plan = $this->plan(
            tiers: ['core', 'sdd'],
            requiredSources: ['doc://forge', 'doc://core'],
            missingSources: ['doc://forge'],
            hash: 'plan-hash-2',
        );
        $routing = new RoutingDecision(
            kind: RoutingDecision::ATLAS_DEV_FAST_PATH,
            reasons: [],
            blockers: [],
        );

        $result = $gate->evaluate($envelope, $classification, $sdd, $plan, $routing);

        $this->assertTrue($result->isBlocked());
        $this->assertSame(MandatoryRagGateResult::REASON_MISSED_REQUIRED_SOURCES, $result->reason);
        $this->assertSame(['doc://forge'], $result->missingSources);
        $this->assertContains(
            MandatoryRagGateResult::BLOCKER_MANDATORY_RAG_MISSED_REQUIRED_SOURCES,
            $result->blockers,
        );
    }

    public function test_non_trivial_with_empty_selected_tiers_is_blocked(): void
    {
        $gate = new MandatoryRagGate;
        $envelope = $this->envelope([], 'atlas_cli_dev');
        $classification = $this->classification(TaskClassification::KIND_PATCH, writeImplied: true);
        $sdd = $this->sdd(taskKind: 'patch', riskLevel: 'R2', hash: 'sdd-hash-5');
        $plan = $this->plan(tiers: [], requiredSources: [], missingSources: [], hash: 'plan-hash-3');
        $routing = new RoutingDecision(
            kind: RoutingDecision::ATLAS_DEV_FAST_PATH,
            reasons: [],
            blockers: [],
        );

        $result = $gate->evaluate($envelope, $classification, $sdd, $plan, $routing);

        $this->assertTrue($result->isBlocked());
        $this->assertSame(MandatoryRagGateResult::REASON_EMPTY_SELECTED_TIERS, $result->reason);
    }

    public function test_high_risk_review_without_context_is_blocked_even_if_not_write(): void
    {
        $gate = new MandatoryRagGate;
        $envelope = $this->envelope([], 'atlas_cli_dev');
        $classification = $this->classification(TaskClassification::KIND_REVIEW, writeImplied: false);
        $sdd = $this->sdd(taskKind: 'review', riskLevel: 'R4', hash: 'sdd-hash-6');
        $plan = $this->plan(tiers: [], requiredSources: [], missingSources: [], hash: '');
        $routing = new RoutingDecision(
            kind: RoutingDecision::FORGE_PROMOTION_PREVIEW,
            reasons: [],
            blockers: [],
        );

        $result = $gate->evaluate($envelope, $classification, $sdd, $plan, $routing);

        $this->assertTrue($result->isBlocked());
        $this->assertSame(MandatoryRagGateResult::CLASS_NON_TRIVIAL, $result->taskClass);
    }

    public function test_delegation_short_circuits_gate(): void
    {
        $gate = new MandatoryRagGate;
        $envelope = $this->envelope([], 'atlas_cli_dev');
        $classification = $this->classification(TaskClassification::KIND_PATCH, writeImplied: true);
        $sdd = $this->sdd(taskKind: 'patch', riskLevel: 'R3', hash: 'sdd-hash-7');
        $plan = $this->plan(tiers: [], requiredSources: [], missingSources: [], hash: '');
        $routing = new RoutingDecision(
            kind: RoutingDecision::DELEGATE_TO_OTHER_FLOW,
            reasons: ['delegate_to_atlas_research_because_research_intent'],
            blockers: [],
        );

        $result = $gate->evaluate($envelope, $classification, $sdd, $plan, $routing);

        $this->assertTrue($result->isPassed());
        $this->assertSame(MandatoryRagGateResult::REASON_DELEGATION_NO_GATE, $result->reason);
    }

    public function test_bypass_requires_config_flag_and_two_constraints_and_audits_trail(): void
    {
        $config = new ConfigRepository([
            'atlas_dev' => [
                'mandatory_rag_gate' => [
                    'bypass_enabled' => true,
                    'allowed_surfaces' => [],
                ],
            ],
        ]);
        $gate = new MandatoryRagGate($config);
        $envelope = $this->envelope(
            constraints: [
                'mandatory_rag_gate:bypass',
                'mandatory_rag_gate:bypass_reason=greenfield_workspace_no_prior_context',
            ],
            surface: 'atlas_cli_dev',
        );
        $classification = $this->classification(TaskClassification::KIND_PATCH, writeImplied: true);
        $sdd = $this->sdd(taskKind: 'patch', riskLevel: 'R3', hash: 'sdd-hash-8');
        $plan = $this->plan(tiers: [], requiredSources: [], missingSources: [], hash: '');
        $routing = new RoutingDecision(
            kind: RoutingDecision::ATLAS_DEV_FAST_PATH,
            reasons: [],
            blockers: [],
        );

        $result = $gate->evaluate($envelope, $classification, $sdd, $plan, $routing);

        $this->assertTrue($result->isBypassed());
        $this->assertSame(MandatoryRagGateResult::REASON_BYPASS_APPLIED, $result->reason);
        $this->assertSame([], $result->blockers);
        $this->assertArrayHasKey('bypass_audit', $result->receipt);
        $this->assertSame('greenfield_workspace_no_prior_context', $result->receipt['bypass_audit']['reason']);
        $this->assertSame('atlas_cli_dev', $result->receipt['bypass_audit']['surface']);
    }

    public function test_bypass_denied_when_config_flag_disabled(): void
    {
        $config = new ConfigRepository([
            'atlas_dev' => [
                'mandatory_rag_gate' => [
                    'bypass_enabled' => false,
                ],
            ],
        ]);
        $gate = new MandatoryRagGate($config);
        $envelope = $this->envelope(
            constraints: [
                'mandatory_rag_gate:bypass',
                'mandatory_rag_gate:bypass_reason=should_not_work',
            ],
            surface: 'atlas_cli_dev',
        );
        $classification = $this->classification(TaskClassification::KIND_PATCH, writeImplied: true);
        $sdd = $this->sdd(taskKind: 'patch', riskLevel: 'R3', hash: 'sdd-hash-9');
        $plan = $this->plan(tiers: [], requiredSources: [], missingSources: [], hash: '');
        $routing = new RoutingDecision(
            kind: RoutingDecision::ATLAS_DEV_FAST_PATH,
            reasons: [],
            blockers: [],
        );

        $result = $gate->evaluate($envelope, $classification, $sdd, $plan, $routing);

        $this->assertFalse($result->isBypassed());
        $this->assertTrue($result->isBlocked());
    }

    public function test_bypass_denied_when_reason_constraint_missing(): void
    {
        $config = new ConfigRepository([
            'atlas_dev' => [
                'mandatory_rag_gate' => [
                    'bypass_enabled' => true,
                ],
            ],
        ]);
        $gate = new MandatoryRagGate($config);
        $envelope = $this->envelope(
            constraints: ['mandatory_rag_gate:bypass'],
            surface: 'atlas_cli_dev',
        );
        $classification = $this->classification(TaskClassification::KIND_PATCH, writeImplied: true);
        $sdd = $this->sdd(taskKind: 'patch', riskLevel: 'R3', hash: 'sdd-hash-10');
        $plan = $this->plan(tiers: [], requiredSources: [], missingSources: [], hash: '');
        $routing = new RoutingDecision(
            kind: RoutingDecision::ATLAS_DEV_FAST_PATH,
            reasons: [],
            blockers: [],
        );

        $result = $gate->evaluate($envelope, $classification, $sdd, $plan, $routing);

        $this->assertFalse($result->isBypassed());
        $this->assertTrue($result->isBlocked());
    }

    public function test_bypass_denied_when_allowed_surfaces_filter_does_not_match(): void
    {
        $config = new ConfigRepository([
            'atlas_dev' => [
                'mandatory_rag_gate' => [
                    'bypass_enabled' => true,
                    'allowed_surfaces' => ['atlas_app_only'],
                ],
            ],
        ]);
        $gate = new MandatoryRagGate($config);
        $envelope = $this->envelope(
            constraints: [
                'mandatory_rag_gate:bypass',
                'mandatory_rag_gate:bypass_reason=invalid_surface_filter',
            ],
            surface: 'atlas_cli_dev',
        );
        $classification = $this->classification(TaskClassification::KIND_PATCH, writeImplied: true);
        $sdd = $this->sdd(taskKind: 'patch', riskLevel: 'R3', hash: 'sdd-hash-11');
        $plan = $this->plan(tiers: [], requiredSources: [], missingSources: [], hash: '');
        $routing = new RoutingDecision(
            kind: RoutingDecision::ATLAS_DEV_FAST_PATH,
            reasons: [],
            blockers: [],
        );

        $result = $gate->evaluate($envelope, $classification, $sdd, $plan, $routing);

        $this->assertFalse($result->isBypassed());
        $this->assertTrue($result->isBlocked());
    }

    public function test_canonical_serialization_includes_required_fields(): void
    {
        $result = new MandatoryRagGateResult(
            runId: 'run-xyz',
            status: MandatoryRagGateResult::STATUS_BLOCKED,
            reason: MandatoryRagGateResult::REASON_MISSED_REQUIRED_SOURCES,
            taskClass: MandatoryRagGateResult::CLASS_NON_TRIVIAL,
            missingSources: ['doc://x', 'doc://y'],
            remediation: MandatoryRagGateResult::REMEDIATION_ADD_REQUIRED_SOURCES,
            receipt: ['plan_hash' => 'h', 'compact_sdd_hash' => 'g', 'run_id' => 'run-xyz'],
            blockers: [MandatoryRagGateResult::BLOCKER_MANDATORY_RAG_MISSED_REQUIRED_SOURCES],
        );

        $payload = $result->toCanonicalArray();
        $this->assertSame('atlas.dev.mandatory_rag_gate.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('missed_required_sources', $payload['reason']);
        $this->assertSame(['doc://x', 'doc://y'], $payload['missing_sources']);
        $this->assertNotEmpty($payload['result_hash']);
        $this->assertContains(
            MandatoryRagGateResult::BLOCKER_MANDATORY_RAG_MISSED_REQUIRED_SOURCES,
            $payload['blockers'],
        );
    }

    /**
     * @param  list<string>  $constraints
     */
    private function envelope(array $constraints, string $surface): OperationEnvelope
    {
        return new OperationEnvelope(
            runId: 'dev-test',
            surfaceId: $surface,
            surfaceContext: new SurfaceContext(productSurface: $surface),
            workspace: '/ws',
            workspaceHash: hash('sha256', '/ws'),
            gitState: new GitState(headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0),
            rawIntent: 'intent',
            normalizedIntent: 'intent',
            userConstraints: $constraints,
            intentClarityLevel: IntakeNormalizer::CLARITY_MEDIUM,
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

    private function classification(string $kind, bool $writeImplied): TaskClassification
    {
        return new TaskClassification(
            taskKind: $kind,
            intentClarityLevel: IntakeNormalizer::CLARITY_MEDIUM,
            matchedRules: [],
            writeImplied: $writeImplied,
        );
    }

    private function sdd(string $taskKind, string $riskLevel, string $hash): CompactSdd
    {
        return new CompactSdd(
            runId: 'dev-test',
            envelopeHash: 'deadbeef',
            intentRaw: 'intent',
            intentNormalized: 'intent',
            taskKind: $taskKind,
            riskLevel: $riskLevel,
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
            compactSddHash: $hash,
        );
    }

    /**
     * @param  list<string>  $tiers
     * @param  list<string>  $requiredSources
     * @param  list<string>  $missingSources
     */
    private function plan(array $tiers, array $requiredSources, array $missingSources, string $hash): ContextRetrievalPlan
    {
        return new ContextRetrievalPlan(
            runId: 'dev-test',
            selectedTiers: $tiers,
            budgetChars: 4000,
            requiredSources: $requiredSources,
            optionalSources: [],
            missingSources: $missingSources,
            truncationPolicy: [],
            providerSafe: true,
            planHash: $hash,
        );
    }
}
