<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowRunbookV1Part05Service;
use Tests\TestCase;

/**
 * Pins the documented Atlas Dev efficient programming flow runbook rules
 * (Parte 5 · §9.2 PRs Sugeridos: classifier precedence, R0..R5 risk heuristic,
 * max_files_changed per R-level, plan-only routing scenarios, and the public
 * CLI contract).
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-05.md
 */
class AtlasDevEfficientProgrammingFlowRunbookV1Part05Test extends TestCase
{
    private function service(): AtlasDevEfficientProgrammingFlowRunbookV1Part05Service
    {
        return new AtlasDevEfficientProgrammingFlowRunbookV1Part05Service();
    }

    /**
     * §9.2 PR 2.1 classifier precedence — one assertion per documented task_kind,
     * including the rule that frontend ONLY fires with a frontend surface
     * (otherwise it falls through to the documented default `patch`), and that
     * risky outranks repair when both signals are present.
     */
    public function test_classifier_maps_each_documented_task_kind(): void
    {
        $s = $this->service();

        $this->assertSame($s::KIND_QUESTION, $s->classifyTaskKind('o que faz o orchestrator?')['task_kind']);
        $this->assertSame($s::KIND_REPAIR, $s->classifyTaskKind('corrija o teste falhando')['task_kind']);
        $this->assertSame($s::KIND_REVIEW, $s->classifyTaskKind('revise este diff por favor')['task_kind']);
        $this->assertSame($s::KIND_PATCH, $s->classifyTaskKind('adicione um campo ao DTO')['task_kind']);

        // frontend tokens WITHOUT a frontend surface -> documented default patch.
        $this->assertSame($s::KIND_PATCH, $s->classifyTaskKind('ajuste o componente da tela', 'api_backend')['task_kind']);
        // frontend tokens WITH a frontend surface -> frontend.
        $this->assertSame($s::KIND_FRONTEND, $s->classifyTaskKind('ajuste o componente da tela', 'atlas_desktop_ai')['task_kind']);

        // risky outranks repair: "corrija o bug de auth" -> risky, not repair.
        $risky = $s->classifyTaskKind('corrija o bug de auth do login');
        $this->assertSame($s::KIND_RISKY, $risky['task_kind']);
        $this->assertFalse($risky['write_implied'], 'risky must not imply a blind write');
    }

    /**
     * §9.2 PR 2.1 risk scorer — one assertion per documented R-level:
     * R5 risky+multiagent, R4 risky, R4 when >5 files, R3 multi-file patch,
     * R2 1-2 file patch, R1 typo/single-file, R0 question.
     */
    public function test_risk_scorer_covers_every_documented_r_level(): void
    {
        $s = $this->service();

        $this->assertSame($s::R5, $s->scoreRiskLevel($s::KIND_RISKY, 'auth replay audit multiagent', 9, 4)['risk_level']);
        $this->assertSame($s::R4, $s->scoreRiskLevel($s::KIND_RISKY, 'mexer no billing', 2, 1)['risk_level']);
        // R4 purely from breadth: a patch touching > 5 files.
        $this->assertSame($s::R4, $s->scoreRiskLevel($s::KIND_PATCH, 'refator amplo', 7, 2)['risk_level']);
        $this->assertSame($s::R3, $s->scoreRiskLevel($s::KIND_REPAIR, 'corrija o fluxo', 3, 2)['risk_level']);
        $this->assertSame($s::R2, $s->scoreRiskLevel($s::KIND_PATCH, 'pequeno ajuste', 2, 1)['risk_level']);
        $this->assertSame($s::R1, $s->scoreRiskLevel($s::KIND_PATCH, 'fix typo na doc', 1, 1)['risk_level']);
        $this->assertSame($s::R0, $s->scoreRiskLevel($s::KIND_QUESTION, 'o que e isso')['risk_level']);
    }

    /**
     * §9.2 PR 2.2 — composeTaskContract derives max_files_changed by R-level:
     * the doc states R1=1, R2=2, R3=5. The budget gate rejects an overflow.
     */
    public function test_max_files_changed_per_r_level_and_budget_gate(): void
    {
        $s = $this->service();

        $this->assertSame(1, $s->maxFilesChangedForLevel($s::R1));
        $this->assertSame(2, $s->maxFilesChangedForLevel($s::R2));
        $this->assertSame(5, $s->maxFilesChangedForLevel($s::R3));
        // Unknown level fails closed to zero (no writes).
        $this->assertSame(0, $s->maxFilesChangedForLevel('R9'));

        $this->assertTrue($s->fitsTaskContractBudget($s::R2, 2)['within_budget']);
        $overflow = $s->fitsTaskContractBudget($s::R2, 3);
        $this->assertFalse($overflow['within_budget']);
        $this->assertSame('exceeds_max_files_changed', $overflow['reason']);
    }

    /**
     * §9.2 PR 2.3 DoD — "repair R2 com simbolo claro" -> atlas_dev_fast_path,
     * the single documented case where the plan proceeds to one provider call.
     */
    public function test_routing_repair_r2_clear_symbol_is_fast_path(): void
    {
        $s = $this->service();

        $r = $s->decideRouting($s::KIND_REPAIR, $s::R2, 'high', 'confirmed_fact', null, null, true);

        $this->assertSame($s::ROUTE_ATLAS_DEV_FAST_PATH, $r['routing_decision']);
        $this->assertSame(1, $r['provider_calls']);
        $this->assertSame([], $r['blockers']);
    }

    /**
     * §9.2 PR 2.3 DoD — "task R4" -> forge_promotion_preview (never patches),
     * and a blocking clarity/ambiguity -> blocked with a clarification request.
     */
    public function test_routing_r4_forge_preview_and_blocking_blocks(): void
    {
        $s = $this->service();

        $forge = $s->decideRouting($s::KIND_RISKY, $s::R4, 'low', 'other');
        $this->assertSame($s::ROUTE_FORGE_PROMOTION_PREVIEW, $forge['routing_decision']);
        $this->assertSame(0, $forge['provider_calls']);

        $blocked = $s->decideRouting($s::KIND_PATCH, $s::R2, 'blocking', 'blocking_ambiguity');
        $this->assertSame($s::ROUTE_BLOCKED, $blocked['routing_decision']);
        $this->assertContains('clarification_required', $blocked['blockers']);
    }

    /**
     * §9.2 PR 2.3 DoD Gap E — question + discovery.confidence=confirmed_fact +
     * zero ambiguity resolves via the manifest with cost.provider_calls = 0.
     * And command_intent=debug from the Atlas AI Router -> delegate_to_other_flow
     * with the suggested flow.
     */
    public function test_routing_gap_e_no_provider_and_router_delegate(): void
    {
        $s = $this->service();

        $gapE = $s->decideRouting($s::KIND_QUESTION, $s::R0, 'high', 'confirmed_fact');
        $this->assertSame($s::ROUTE_READ_ONLY_ANSWER_NO_PROVIDER, $gapE['routing_decision']);
        $this->assertSame(0, $gapE['provider_calls']);

        $delegate = $s->decideRouting($s::KIND_QUESTION, $s::R0, 'high', 'other', 'debug', 'atlas_ai_router');
        $this->assertSame($s::ROUTE_DELEGATE_TO_OTHER_FLOW, $delegate['routing_decision']);
        $this->assertSame('atlas_debug', $delegate['suggested_flow']);
    }

    /**
     * §9.2 PR 2.4 — the public CLI contract: documented flags, plan-only without
     * --yes (no provider call), and that --efficient --yes consumes the token and
     * invokes the executor. The hidden smoke command is NOT public.
     */
    public function test_cli_contract_flags_and_plan_only_vs_yes(): void
    {
        $s = $this->service();
        $contract = $s->cliContract();

        $this->assertSame('atlas:cli:dev', $contract['command']);
        $this->assertContains('--efficient', $contract['public_flags']);
        $this->assertContains('--yes', $contract['public_flags']);
        $this->assertFalse($contract['hidden_is_public']);

        // --efficient without --yes stops plan-only: no provider call.
        $planOnly = $s->evaluateCliInvocation(['--efficient', '--json']);
        $this->assertFalse($planOnly['calls_provider']);
        $this->assertTrue($planOnly['all_public']);

        // --efficient --yes invokes the executor (one provider call path).
        $confirmed = $s->evaluateCliInvocation(['--efficient', '--yes', '--json']);
        $this->assertTrue($confirmed['calls_provider']);
        $this->assertSame('efficient_with_yes_invokes_executor', $confirmed['reason']);
    }
}
