<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCatalogRoadmapService;
use Tests\TestCase;

/**
 * Pins the documented Atlas Tool Runtime Catalog Roadmap:
 *   - priority tier / family classification with per-tier invariants;
 *   - the seven-part Promotion Rule (with the conditional "safe recipe when executable"
 *     requirement and the "tests with fake binary OR fixture output" rule);
 *   - the "optional/local/open-source first" backlog decision.
 *
 * @see docs/engineering-knowledge-base/tool-runtime/catalog-roadmap.md
 */
class AtlasCatalogRoadmapTest extends TestCase
{
    private function service(): AtlasCatalogRoadmapService
    {
        return new AtlasCatalogRoadmapService();
    }

    /**
     * A fully-green executable tool that has met every Promotion-Rule requirement.
     *
     * @return array<string,mixed>
     */
    private function greenExecutableCandidate(): array
    {
        return [
            'tool' => 'osv-scanner',
            'executable' => true,
            'registry_definition' => true,
            'doctor_detection_state' => true,
            'safe_recipe' => true,
            'normalizer' => true,
            'authority_group_role' => true,
            'gate_behavior' => true,
            'has_fixture_output' => true,
        ];
    }

    /** Tools map to their documented tier + family, with normalization across spellings. */
    public function test_tools_classify_into_their_documented_tier_and_family(): void
    {
        $service = $this->service();

        // P0 Security And Supply Chain (normalized: "OSV-Scanner" == "osv_scanner").
        $osv = $service->classifyTool('OSV-Scanner');
        $this->assertTrue($osv['recognized']);
        $this->assertSame('P0', $osv['tier']);
        $this->assertSame('security_supply_chain', $osv['family']);

        // P0 Code Semantics.
        $astGrep = $service->classifyTool('ast-grep');
        $this->assertSame('P0', $astGrep['tier']);
        $this->assertSame('code_semantics', $astGrep['family']);

        // P1 API/Testing/Frontend/Architecture.
        $deptrac = $service->classifyTool('Deptrac');
        $this->assertSame('P1', $deptrac['tier']);
        $this->assertSame('api_testing_frontend_architecture', $deptrac['family']);

        // P2 External Agents.
        $aider = $service->classifyTool('Aider');
        $this->assertSame('P2', $aider['tier']);
        $this->assertSame('external_agents', $aider['family']);

        // Unknown tool: not in the catalog -> no tier/family.
        $unknown = $service->classifyTool('totally-made-up-tool');
        $this->assertFalse($unknown['recognized']);
        $this->assertNull($unknown['tier']);
        $this->assertNull($unknown['family']);
    }

    /**
     * Per-tier invariants: Code Semantics reads are cheap but writes need
     * sandbox/approval+evidence; External Agents must run behind Atlas and need
     * operator approval; a plain P0/P1 tool carries neither special flag.
     */
    public function test_per_tier_governance_invariants_are_surfaced(): void
    {
        $service = $this->service();

        $semantics = $service->classifyTool('serena');
        $this->assertTrue($semantics['reads_are_cheap']);
        $this->assertTrue($semantics['writes_require_sandbox_approval_evidence']);
        $this->assertFalse($semantics['must_run_behind_atlas']);

        $agent = $service->classifyTool('openhands');
        $this->assertTrue($agent['must_run_behind_atlas']);
        $this->assertTrue($agent['requires_operator_approval']);
        $this->assertFalse($agent['reads_are_cheap']);

        $quality = $service->classifyTool('phpstan');
        $this->assertFalse($quality['reads_are_cheap']);
        $this->assertFalse($quality['must_run_behind_atlas']);
        $this->assertFalse($quality['requires_operator_approval']);
    }

    /** All seven Promotion-Rule requirements satisfied => operational, governed capability. */
    public function test_all_requirements_met_promotes_to_operational(): void
    {
        $result = $this->service()->evaluatePromotion($this->greenExecutableCandidate());

        $this->assertSame('operational', $result['verdict']);
        $this->assertTrue($result['operational']);
        $this->assertSame([], $result['missing_requirements']);
        $this->assertTrue($result['enters_as_governed_capability']);
        // The full executable requirement set is the seven documented requirements.
        $this->assertSame(
            [
                'registry_definition',
                'doctor_detection_state',
                'safe_recipe',
                'normalizer_or_generic_fallback',
                'authority_group_role',
                'gate_behavior',
                'tests',
            ],
            $result['required_requirements'],
        );
    }

    /** Any missing requirement holds the tool at "cataloged" and names what's missing. */
    public function test_missing_requirement_keeps_tool_cataloged(): void
    {
        $candidate = $this->greenExecutableCandidate();
        $candidate['gate_behavior'] = false;          // drop a gate
        unset($candidate['doctor_detection_state']);  // missing key also counts as unmet

        $result = $this->service()->evaluatePromotion($candidate);

        $this->assertSame('cataloged', $result['verdict']);
        $this->assertFalse($result['operational']);
        $this->assertEqualsCanonicalizing(
            ['gate_behavior', 'doctor_detection_state'],
            $result['missing_requirements'],
        );
    }

    /**
     * "safe recipe when executable" — a NON-executable tool drops the safe_recipe
     * requirement, so it can be operational without one; an EXECUTABLE tool that lacks
     * a safe recipe is blocked.
     */
    public function test_safe_recipe_requirement_is_conditional_on_executability(): void
    {
        $service = $this->service();

        // Non-executable: safe_recipe is not in the required set.
        $this->assertNotContains('safe_recipe', $service->requiredRequirementsFor(false));

        $nonExec = [
            'tool' => 'idebridges',
            'executable' => false,
            'registry_definition' => true,
            'doctor_detection_state' => true,
            // no safe_recipe supplied — and none required for a non-executable tool
            'normalizer' => true,
            'authority_group_role' => true,
            'gate_behavior' => true,
            'has_fake_binary' => true,
        ];
        $resultNonExec = $service->evaluatePromotion($nonExec);
        $this->assertSame('operational', $resultNonExec['verdict']);
        $this->assertNotContains('safe_recipe', $resultNonExec['missing_requirements']);

        // Executable but no safe recipe -> blocked.
        $exec = $this->greenExecutableCandidate();
        $exec['safe_recipe'] = false;
        $resultExec = $service->evaluatePromotion($exec);
        $this->assertSame('cataloged', $resultExec['verdict']);
        $this->assertContains('safe_recipe', $resultExec['missing_requirements']);
    }

    /**
     * "tests with fake binary OR fixture output" and "normalizer OR explicit generic
     * fallback" — either alternative satisfies its slot.
     */
    public function test_disjunctive_requirements_accept_either_alternative(): void
    {
        $service = $this->service();

        // tests via fake binary only (no fixture, no explicit tests flag).
        $viaFakeBinary = $this->greenExecutableCandidate();
        unset($viaFakeBinary['has_fixture_output']);
        $viaFakeBinary['has_fake_binary'] = true;
        $this->assertSame('operational', $service->evaluatePromotion($viaFakeBinary)['verdict']);

        // normalizer slot via explicit generic fallback only (no normalizer).
        $viaFallback = $this->greenExecutableCandidate();
        unset($viaFallback['normalizer']);
        $viaFallback['generic_fallback'] = true;
        $this->assertSame('operational', $service->evaluatePromotion($viaFallback)['verdict']);

        // Neither tests alternative -> the tests slot is unmet.
        $noTests = $this->greenExecutableCandidate();
        unset($noTests['has_fixture_output']);
        $resultNoTests = $service->evaluatePromotion($noTests);
        $this->assertSame('cataloged', $resultNoTests['verdict']);
        $this->assertContains('tests', $resultNoTests['missing_requirements']);
    }

    /** "optional/local/open-source first" — any such posture is preferred-first intake. */
    public function test_backlog_priority_prefers_optional_local_open_source(): void
    {
        $service = $this->service();

        $this->assertSame('preferred_first', $service->backlogPriority(true, false, false)['intake']);
        $this->assertSame('preferred_first', $service->backlogPriority(false, true, false)['intake']);
        $this->assertSame('preferred_first', $service->backlogPriority(false, false, true)['intake']);

        $deferred = $service->backlogPriority(false, false, false);
        $this->assertSame('deferred', $deferred['intake']);
        $this->assertFalse($deferred['preferred_first']);
    }
}
