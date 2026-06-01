<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowContractsV1Part04Service;
use Tests\TestCase;

/**
 * Pins the executable invariants carved into doc Parte 4:
 *   - 4.4 LightTaskContract: watched-file disjointness (I4), the 6-file fast
 *     path cap that forces R4 (I5), provider_lock.fallback=false (I7), the
 *     four baseline blocked_actions (I8), write-tool/permission coupling (I9),
 *     autonomy=auto only in dev/staging (I10), restricted privacy forbids raw
 *     excerpts (I11), R3+ requires sandbox (I12), auto_best_available out of
 *     scope (I13).
 *   - 5.1 ContextRetrievalPlan: core unless question (J2), code_intelligence
 *     when workspace resolved (J3), forge implies R4 (J4), empty
 *     required_sources only for read-only question (J5), reserved budget
 *     cannot exceed max_chars (J6).
 *
 * Pure, no DB, no RefreshDatabase.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-04.md
 */
class AtlasDevEfficientProgrammingFlowContractsV1Part04Test extends TestCase
{
    private function service(): AtlasDevEfficientProgrammingFlowContractsV1Part04Service
    {
        return new AtlasDevEfficientProgrammingFlowContractsV1Part04Service;
    }

    public function test_doc_example_light_task_contract_and_plan_are_valid(): void
    {
        $svc = $this->service();

        // The doc's own "Exemplo Valido" rows must pass clean.
        $ltc = $svc->validateLightTaskContract($svc->exampleValidLightTaskContract());
        $crp = $svc->validateContextRetrievalPlan($svc->exampleValidContextRetrievalPlan());

        $this->assertTrue($ltc['valid'], 'doc 4.4 example must be valid');
        $this->assertSame([], $ltc['violations']);
        $this->assertSame('atlas.dev.light_task_contract.v1', $ltc['contract']);

        $this->assertTrue($crp['valid'], 'doc 5.1 example must be valid');
        $this->assertSame([], $crp['violations']);
        $this->assertSame('atlas.dev.context_retrieval_plan.v1', $crp['contract']);

        // selfCheck() (the command's payload) is green.
        $this->assertTrue($svc->selfCheck()['all_valid']);
    }

    public function test_invariant_5_six_file_cap_forces_r4(): void
    {
        $svc = $this->service();

        // "Exemplos Invalidos" row: max_files_changed:12 + risk_level:R2 -> I5.
        $base = $svc->exampleValidLightTaskContract();
        $bad = ['max_files_changed' => 12, 'risk_level' => 'R2'] + $base;
        $out = $svc->validateLightTaskContract($bad);

        $this->assertFalse($out['valid']);
        $this->assertContains('LTC-5', $out['violated_invariants']);

        // Exactly 6 is still fast-path; 7 needs R4.
        $this->assertTrue($svc->fastPathFileDecision(6)['fast_path_allowed']);
        $this->assertFalse($svc->fastPathFileDecision(6)['requires_r4']);
        $this->assertTrue($svc->fastPathFileDecision(7)['requires_r4']);

        // Same 12 files but escalated to R4 is now legal (I5 satisfied).
        $r4 = ['max_files_changed' => 12, 'risk_level' => 'R4', 'policy_profile' => [
            'autonomy_level' => 'auto_with_confirmation',
            'privacy_class' => 'internal',
            'sandbox_required' => true, // R4 is >= R3 so sandbox is mandatory (I12)
            'decision_mode' => 'manual_override',
            'sends_raw_excerpts' => false,
        ]] + $base;
        $this->assertNotContains('LTC-5', $svc->validateLightTaskContract($r4)['violated_invariants']);
    }

    public function test_invariant_7_and_8_provider_lock_and_blocked_actions(): void
    {
        $svc = $this->service();
        $base = $svc->exampleValidLightTaskContract();

        // I7: fallback_allowed=true is rejected.
        $fallback = ['provider_lock' => ['fallback_allowed' => true]] + $base;
        $this->assertContains('LTC-7', $svc->validateLightTaskContract($fallback)['violated_invariants']);

        // I8: dropping secret_access from blocked_actions is rejected.
        $missing = ['blocked_actions' => ['production_write', 'migration_apply', 'broad_refactor']] + $base;
        $out = $svc->validateLightTaskContract($missing);
        $this->assertFalse($out['valid']);
        $this->assertContains('LTC-8', $out['violated_invariants']);
    }

    public function test_invariant_9_write_tool_requires_permission(): void
    {
        $svc = $this->service();
        $base = $svc->exampleValidLightTaskContract();

        // allowed_tools has write but granted_permissions does not -> I9.
        $bad = [
            'allowed_tools' => ['read', 'write'],
            'granted_permissions' => ['read'],
        ] + $base;

        $this->assertContains('LTC-9', $svc->validateLightTaskContract($bad)['violated_invariants']);
    }

    public function test_invariant_10_11_12_13_policy_profile_rules(): void
    {
        $svc = $this->service();
        $base = $svc->exampleValidLightTaskContract();

        // I10: autonomy=auto in production is rejected.
        $autoProd = ['environment' => 'production', 'policy_profile' => [
            'autonomy_level' => 'auto', 'privacy_class' => 'internal',
            'sandbox_required' => false, 'decision_mode' => 'manual_override',
            'sends_raw_excerpts' => false,
        ]] + $base;
        $this->assertContains('LTC-10', $svc->validateLightTaskContract($autoProd)['violated_invariants']);

        // I10: same autonomy=auto in dev is allowed.
        $autoDev = ['environment' => 'dev', 'policy_profile' => [
            'autonomy_level' => 'auto', 'privacy_class' => 'internal',
            'sandbox_required' => false, 'decision_mode' => 'manual_override',
            'sends_raw_excerpts' => false,
        ]] + $base;
        $this->assertNotContains('LTC-10', $svc->validateLightTaskContract($autoDev)['violated_invariants']);

        // I11: restricted privacy + raw excerpts is rejected.
        $restricted = ['policy_profile' => [
            'autonomy_level' => 'assist', 'privacy_class' => 'restricted',
            'sandbox_required' => false, 'decision_mode' => 'manual_override',
            'sends_raw_excerpts' => true,
        ]] + $base;
        $this->assertContains('LTC-11', $svc->validateLightTaskContract($restricted)['violated_invariants']);

        // I12: R3 task without sandbox is rejected.
        $r3NoSandbox = ['risk_level' => 'R3', 'policy_profile' => [
            'autonomy_level' => 'assist', 'privacy_class' => 'internal',
            'sandbox_required' => false, 'decision_mode' => 'manual_override',
            'sends_raw_excerpts' => false,
        ]] + $base;
        $this->assertContains('LTC-12', $svc->validateLightTaskContract($r3NoSandbox)['violated_invariants']);

        // I13: decision_mode=auto_best_available is out of scope.
        $oob = ['policy_profile' => [
            'autonomy_level' => 'assist', 'privacy_class' => 'internal',
            'sandbox_required' => false, 'decision_mode' => 'auto_best_available',
            'sends_raw_excerpts' => false,
        ]] + $base;
        $this->assertContains('LTC-13', $svc->validateLightTaskContract($oob)['violated_invariants']);
    }

    public function test_invariant_4_watched_files_must_be_disjoint(): void
    {
        $svc = $this->service();
        $base = $svc->exampleValidLightTaskContract();

        // watched overlaps allowed -> I4.
        $bad = ['watched_files' => ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php']] + $base;
        $this->assertContains('LTC-4', $svc->validateLightTaskContract($bad)['violated_invariants']);
    }

    public function test_context_plan_invariants_j2_j3_j4_j5_j6(): void
    {
        $svc = $this->service();
        $base = $svc->exampleValidContextRetrievalPlan();

        // J2: non-question task without core tier is rejected.
        $noCore = ['tiers_selected' => ['code_intelligence', 'sdd']] + $base;
        $this->assertContains('CRP-2', $svc->validateContextRetrievalPlan($noCore)['violated_invariants']);

        // J3: workspace resolved but no code_intelligence tier -> J3.
        $noCodeIntel = ['tiers_selected' => ['core', 'sdd']] + $base;
        $this->assertContains('CRP-3', $svc->validateContextRetrievalPlan($noCodeIntel)['violated_invariants']);

        // J4: forge tier at R2 is rejected; forge at R4 is allowed.
        $forgeLowRisk = ['tiers_selected' => ['core', 'code_intelligence', 'forge'], 'risk_level' => 'R2'] + $base;
        $this->assertContains('CRP-4', $svc->validateContextRetrievalPlan($forgeLowRisk)['violated_invariants']);
        $forgeR4 = ['tiers_selected' => ['core', 'code_intelligence', 'forge'], 'risk_level' => 'R4'] + $base;
        $this->assertNotContains('CRP-4', $svc->validateContextRetrievalPlan($forgeR4)['violated_invariants']);

        // J5: empty required_sources on a non-question task is rejected; on a
        // read-only question it is allowed.
        $emptyRepair = ['required_sources' => []] + $base;
        $this->assertContains('CRP-5', $svc->validateContextRetrievalPlan($emptyRepair)['violated_invariants']);
        $emptyQuestion = [
            'task_kind' => 'question', 'read_only' => true,
            'workspace_resolved' => false, 'required_sources' => [],
            'tiers_selected' => [],
        ] + $base;
        $this->assertNotContains('CRP-5', $svc->validateContextRetrievalPlan($emptyQuestion)['violated_invariants']);

        // J6: reserved budgets summing above max_chars is rejected.
        $overBudget = ['budget' => [
            'max_chars' => 5000, 'reserved_for_core' => 3000, 'reserved_for_code_intelligence' => 4000,
        ]] + $base;
        $this->assertContains('CRP-6', $svc->validateContextRetrievalPlan($overBudget)['violated_invariants']);
    }
}
