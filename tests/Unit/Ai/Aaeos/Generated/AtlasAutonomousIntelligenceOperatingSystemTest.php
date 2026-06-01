<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAutonomousIntelligenceOperatingSystemService;
use Tests\TestCase;

/**
 * Pins the documented Atlas Autonomous Intelligence OS decision rules.
 *
 * @see docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
 */
class AtlasAutonomousIntelligenceOperatingSystemTest extends TestCase
{
    private function service(): AtlasAutonomousIntelligenceOperatingSystemService
    {
        return new AtlasAutonomousIntelligenceOperatingSystemService();
    }

    /**
     * "Fluxo" step 2: a trivial prompt answers directly (no mission record,
     * no DoD), while a multi-step / research / tool-using prompt becomes a
     * governed mission that requires source plan, tool plan, safety gate,
     * evidence and certification.
     */
    public function test_mission_classification_governs_only_from_mission_tier_up(): void
    {
        $svc = $this->service();

        $trivial = $svc->classifyMission([]);
        $this->assertSame('trivial', $trivial['execution_tier']);
        $this->assertFalse($trivial['is_governed_mission']);
        $this->assertFalse($trivial['creates_mission_record']);
        $this->assertFalse($trivial['requires_evidence_pack']);

        $mission = $svc->classifyMission([
            'requires_research' => true,
            'multi_step' => true,
        ]);
        $this->assertSame('mission', $mission['execution_tier']);
        $this->assertTrue($mission['is_governed_mission']);
        $this->assertTrue($mission['requires_definition_of_done']);
        $this->assertTrue($mission['requires_source_plan']);
        $this->assertTrue($mission['requires_tool_plan']);
        $this->assertTrue($mission['requires_safety_gate']);
        $this->assertTrue($mission['requires_certification']);
        $this->assertFalse($mission['promote_to_forge']);
    }

    /**
     * The strongest tier (obra) is reached by multi-cycle or multi-domain work
     * and is the only tier promoted to Forge. A single bounded action is a task,
     * not a mission.
     */
    public function test_obra_and_task_tiers_resolve_correctly(): void
    {
        $svc = $this->service();

        $obra = $svc->classifyMission(['multi_domain' => true]);
        $this->assertSame('obra', $obra['execution_tier']);
        $this->assertTrue($obra['is_governed_mission']);
        $this->assertTrue($obra['promote_to_forge']);

        $cycles = $svc->classifyMission(['multi_cycle' => true]);
        $this->assertSame('obra', $cycles['execution_tier']);

        $task = $svc->classifyMission(['single_action' => true]);
        $this->assertSame('task', $task['execution_tier']);
        $this->assertFalse($task['is_governed_mission']);
    }

    /**
     * Tool Policy: an unnecessary tool is skipped; a trusted ready tool is used;
     * a needed tool with no ready option and an UNVETTED repo (no license proof,
     * no reputation) must NOT be cloned — it falls back to building own.
     * ("Nao clonar repo sem avaliar licenca, atividade, seguranca e escopo.")
     */
    public function test_tool_policy_skip_use_and_unsafe_clone_falls_back_to_build(): void
    {
        $svc = $this->service();

        $skip = $svc->decideTool(['tool_is_necessary' => false]);
        $this->assertSame(
            AtlasAutonomousIntelligenceOperatingSystemService::TOOL_SKIP,
            $skip['verdict'],
        );

        $use = $svc->decideTool([
            'tool_is_necessary' => true,
            'trusted_ready_tool_exists' => true,
        ]);
        $this->assertSame(
            AtlasAutonomousIntelligenceOperatingSystemService::TOOL_USE_EXISTING,
            $use['verdict'],
        );

        // Needed, no ready tool, repo not vetted -> build own, with the exact
        // blockers that disqualified the clone surfaced.
        $build = $svc->decideTool([
            'tool_is_necessary' => true,
            'trusted_ready_tool_exists' => false,
        ]);
        $this->assertSame(
            AtlasAutonomousIntelligenceOperatingSystemService::TOOL_BUILD_OWN,
            $build['verdict'],
        );
        $this->assertContains('license_disallows_use', $build['clone_blockers']);
        $this->assertContains('repo_lacks_activity_or_reputation', $build['clone_blockers']);
    }

    /**
     * A repo that passes every documented gate (license ok, activity/reputation,
     * no supply-chain risk, acceptable cost) is cloned; flipping supply-chain
     * risk on alone forces a fallback to building own.
     */
    public function test_tool_policy_clones_only_a_fully_vetted_repo(): void
    {
        $svc = $this->service();

        $cleanRepo = [
            'tool_is_necessary' => true,
            'trusted_ready_tool_exists' => false,
            'license_allows_use' => true,
            'repo_has_activity_and_reputation' => true,
            'supply_chain_risk' => false,
            'clone_cost_risk_acceptable' => true,
            'how_to_validate_it_worked' => true,
            'how_to_record_learning_for_evolution' => true,
        ];

        $clone = $svc->decideTool($cleanRepo);
        $this->assertSame(
            AtlasAutonomousIntelligenceOperatingSystemService::TOOL_CLONE_REPO,
            $clone['verdict'],
        );
        $this->assertSame([], $clone['clone_blockers']);
        $this->assertTrue($clone['policy_compliant']);

        // One unsafe signal (supply chain) is enough to refuse the clone.
        $unsafe = $svc->decideTool(array_merge($cleanRepo, ['supply_chain_risk' => true]));
        $this->assertSame(
            AtlasAutonomousIntelligenceOperatingSystemService::TOOL_BUILD_OWN,
            $unsafe['verdict'],
        );
        $this->assertContains('supply_chain_risk', $unsafe['clone_blockers']);

        // A running tool (non-skip) that cannot say how it will be validated is
        // not policy compliant.
        $noValidation = $svc->decideTool(array_merge($cleanRepo, ['how_to_validate_it_worked' => false]));
        $this->assertFalse($noValidation['policy_compliant']);
    }

    /**
     * Safety / "Riscos" gate: a clean context passes; a login + external-cost
     * context requires confirmation and may not execute until approved; passing
     * explicit human confirmation then allows it.
     */
    public function test_safety_gate_requires_confirmation_for_login_and_cost(): void
    {
        $svc = $this->service();

        $clean = $svc->evaluateSafetyGate([]);
        $this->assertSame('passed', $clean['safety_status']);
        $this->assertTrue($clean['may_execute']);

        $gated = $svc->evaluateSafetyGate([
            'login_or_credential' => true,
            'external_cost' => true,
        ]);
        $this->assertSame('requires_confirmation', $gated['safety_status']);
        $this->assertFalse($gated['may_execute']);
        $this->assertTrue($gated['requires_human_confirmation']);
        $this->assertContains('login_or_credential', $gated['triggered_conditions']);

        $approved = $svc->evaluateSafetyGate([
            'login_or_credential' => true,
            'external_cost' => true,
            'human_confirmation' => true,
        ]);
        $this->assertSame('allowed_with_confirmation', $approved['safety_status']);
        $this->assertTrue($approved['may_execute']);
    }

    /**
     * Destructive / exfil commands are a HARD block: they can never be waved
     * through by a confirmation flag.
     */
    public function test_destructive_command_is_hard_blocked_even_with_confirmation(): void
    {
        $svc = $this->service();

        $blocked = $svc->evaluateSafetyGate([
            'data_delete_exfil_or_mutate_command' => true,
            'human_confirmation' => true,
        ]);
        $this->assertSame('blocked', $blocked['safety_status']);
        $this->assertFalse($blocked['may_execute']);
        $this->assertContains('data_delete_exfil_or_mutate_command', $blocked['hard_block_conditions']);

        $destructive = $svc->evaluateSafetyGate([
            'destructive_execution' => true,
            'human_confirmation' => true,
        ]);
        $this->assertSame('blocked', $destructive['safety_status']);
        $this->assertFalse($destructive['may_execute']);
    }

    /**
     * Definition of Done: a mission is certified only with a validated result
     * AND an evidence pack AND a selected domain; missing the evidence pack
     * leaves it incomplete; an open blocker yields an explicit `blocked` result
     * (a real blocker is a valid result, never hidden as complete).
     */
    public function test_certification_requires_evidence_and_surfaces_blockers(): void
    {
        $svc = $this->service();

        $certified = $svc->certifyMission([
            'domain_selected' => true,
            'result_validated' => true,
            'evidence_pack_present' => true,
            'open_blockers' => [],
        ]);
        $this->assertSame('certified', $certified['certification_status']);
        $this->assertTrue($certified['certified']);
        $this->assertSame([], $certified['missing_gates']);

        // No evidence pack => cannot be certified complete.
        $noEvidence = $svc->certifyMission([
            'domain_selected' => true,
            'result_validated' => true,
            'evidence_pack_present' => false,
            'open_blockers' => [],
        ]);
        $this->assertSame('incomplete', $noEvidence['certification_status']);
        $this->assertFalse($noEvidence['certified']);
        $this->assertContains('evidence_pack_present', $noEvidence['missing_gates']);

        // A real open blocker is surfaced, not swallowed into a fake completion.
        $blocked = $svc->certifyMission([
            'domain_selected' => true,
            'result_validated' => true,
            'evidence_pack_present' => true,
            'open_blockers' => ['external_login_required'],
        ]);
        $this->assertSame('blocked', $blocked['certification_status']);
        $this->assertFalse($blocked['certified']);
        $this->assertTrue($blocked['blocker_is_valid_result']);
        $this->assertSame(1, $blocked['open_blockers']);
    }

    /**
     * "Nao confundir capacidade com prova de superioridade." An external
     * advantage claim without reproducible proof is downgraded to internal
     * capability; with proof it may be asserted.
     */
    public function test_advantage_claim_downgrades_without_external_proof(): void
    {
        $svc = $this->service();

        $unproven = $svc->evaluateAdvantageClaim(['asserts_external_advantage' => true]);
        $this->assertSame('downgrade_to_internal_capability', $unproven['disposition']);
        $this->assertFalse($unproven['may_assert_external_advantage']);

        $proven = $svc->evaluateAdvantageClaim([
            'asserts_external_advantage' => true,
            'external_proof_present' => true,
        ]);
        $this->assertSame('external_advantage_proven', $proven['disposition']);
        $this->assertTrue($proven['may_assert_external_advantage']);

        $capabilityOnly = $svc->evaluateAdvantageClaim([]);
        $this->assertSame('internal_capability_only', $capabilityOnly['disposition']);
        $this->assertFalse($capabilityOnly['may_assert_external_advantage']);
    }
}
