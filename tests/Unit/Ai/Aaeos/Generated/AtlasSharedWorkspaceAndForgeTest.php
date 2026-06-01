<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSharedWorkspaceAndForgeService;
use Tests\TestCase;

/**
 * Pins the documented Obras Shared Workspace contract: canonical naming, the
 * governed-work link gate, the 10-point minimum workspace contract, the
 * token-and-context law and the artifact bus schema.
 *
 * @see docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
 */
class AtlasSharedWorkspaceAndForgeTest extends TestCase
{
    private function service(): AtlasSharedWorkspaceAndForgeService
    {
        return new AtlasSharedWorkspaceAndForgeService();
    }

    /**
     * Canonical Name guard: the exact canonical name passes; a forbidden
     * competing metaphor ("provider office") fails with the documented reason.
     */
    public function test_canonical_name_passes_and_forbidden_metaphor_fails(): void
    {
        $ok = $this->service()->validateCanonicalName('Obras Shared Workspace');
        $this->assertSame('pass', $ok['status']);
        $this->assertTrue($ok['is_canonical']);

        $bad = $this->service()->validateCanonicalName('provider office');
        $this->assertSame('fail', $bad['status']);
        $this->assertTrue($bad['is_forbidden']);
        $this->assertContains(
            'forbidden_competing_name: "provider office" is a metaphor, not architecture; use "Obras Shared Workspace".',
            $bad['blocking_reasons'],
        );
    }

    /**
     * Non-Negotiable Rule: "Forge Workspace" is only allowed when explicitly
     * declared a specialization of Obras Shared Workspace; otherwise it fails.
     */
    public function test_forge_workspace_requires_specialization_declaration(): void
    {
        $undeclared = $this->service()->validateCanonicalName('Forge Workspace', false);
        $this->assertSame('fail', $undeclared['status']);
        $this->assertContains(
            'specialization_not_declared: "Forge Workspace" must explicitly state it is a specialization of "Obras Shared Workspace".',
            $undeclared['blocking_reasons'],
        );

        $declared = $this->service()->validateCanonicalName('Forge Workspace', true);
        $this->assertSame('pass', $declared['status']);
    }

    /**
     * Governed-work link gate: a pane with all four links is governed production
     * work; dropping the evidence link makes it ungoverned and surfaces the
     * missing link.
     */
    public function test_pane_is_governed_only_with_all_four_links(): void
    {
        $all = array_fill_keys(
            array_keys(AtlasSharedWorkspaceAndForgeService::GOVERNED_WORK_LINKS),
            true,
        );
        $governed = $this->service()->classifyGovernedWork($all);
        $this->assertSame('pass', $governed['status']);
        $this->assertTrue($governed['is_governed_work']);

        $all['evidence'] = false;
        $ungoverned = $this->service()->classifyGovernedWork($all);
        $this->assertSame('fail', $ungoverned['status']);
        $this->assertFalse($ungoverned['is_governed_work']);
        $this->assertSame(['evidence'], $ungoverned['missing_links']);
    }

    /**
     * Minimum Workspace Contract: there are exactly 10 documented points; all
     * present => ready; one missing => not ready and flagged as ad hoc chaining.
     */
    public function test_minimum_workspace_contract_has_ten_points_and_blocks_when_incomplete(): void
    {
        $this->assertCount(10, AtlasSharedWorkspaceAndForgeService::MINIMUM_WORKSPACE_CONTRACT);

        $full = array_fill_keys(
            array_keys(AtlasSharedWorkspaceAndForgeService::MINIMUM_WORKSPACE_CONTRACT),
            true,
        );
        $ready = $this->service()->auditMinimumWorkspaceContract($full);
        $this->assertSame('pass', $ready['status']);
        $this->assertTrue($ready['ready_for_multi_provider']);
        $this->assertSame(10, $ready['present_count']);

        $full['integration_queue'] = false;
        $partial = $this->service()->auditMinimumWorkspaceContract($full);
        $this->assertSame('fail', $partial['status']);
        $this->assertFalse($partial['ready_for_multi_provider']);
        $this->assertSame(['integration_queue'], $partial['missing']);
        $this->assertContains(
            'ad_hoc_chaining: without the full minimum contract, multi-provider programming is only ad hoc chaining.',
            $partial['blocking_reasons'],
        );
    }

    /**
     * Token and Context Law: each role gets its curated slice (not the whole raw
     * context), and broadcasting the whole large context to more than one role
     * fails the law.
     */
    public function test_token_law_slices_per_role_and_forbids_broadcast(): void
    {
        $codex = $this->service()->sliceContextForRole('codex_implementer');
        $this->assertSame('pass', $codex['status']);
        $this->assertSame(
            ['allowed_files', 'task', 'tests', 'gates', 'local_commands', 'evidence_contract'],
            $codex['context_slice'],
        );

        $oneRole = $this->service()->auditTokenLaw([
            'gemini_scout' => true,
            'codex_implementer' => false,
        ]);
        $this->assertSame('pass', $oneRole['status']);

        $broadcast = $this->service()->auditTokenLaw([
            'gemini_scout' => true,
            'claude_planner_reviewer' => true,
            'codex_implementer' => true,
        ]);
        $this->assertSame('fail', $broadcast['status']);
        $this->assertSame(
            ['gemini_scout', 'claude_planner_reviewer', 'codex_implementer'],
            $broadcast['whole_context_roles'],
        );
    }

    /**
     * Artifact Bus: a complete + schema-validated + evidence-met artifact is
     * trusted Obra state; the same artifact without schema validation is NOT
     * trusted (streaming partials are not trusted state).
     */
    public function test_artifact_is_trusted_only_when_complete_and_validated(): void
    {
        $artifact = [
            'id' => 'art_1',
            'kind' => 'implementation_diff',
            'source' => 'codex',
            'timestamp' => '2026-06-01T00:00:00Z',
            'owning_provider_session' => 'codex:session-7',
            'input_hash' => 'sha256:a',
            'output_hash' => 'sha256:b',
            'status' => 'validated',
        ];

        $trusted = $this->service()->validateArtifact($artifact, true, true);
        $this->assertSame('pass', $trusted['status']);
        $this->assertTrue($trusted['is_trusted_state']);
        $this->assertSame([], $trusted['missing_fields']);

        $notValidated = $this->service()->validateArtifact($artifact, false, true);
        $this->assertSame('fail', $notValidated['status']);
        $this->assertFalse($notValidated['is_trusted_state']);
        $this->assertContains(
            'artifact_not_trusted: not updated as trusted Obra state until it validates against its schema.',
            $notValidated['blocking_reasons'],
        );

        // Missing the owning provider/session field => listed as missing.
        $orphan = $artifact;
        unset($orphan['owning_provider_session']);
        $orphanReport = $this->service()->validateArtifact($orphan, true, true);
        $this->assertSame('fail', $orphanReport['status']);
        $this->assertContains('owning_provider_session', $orphanReport['missing_fields']);
    }

    /** The orchestrator folds a conformant bundle into one green evidence document. */
    public function test_whole_contract_audit_passes_on_conformant_bundle(): void
    {
        $bundle = [
            'name' => AtlasSharedWorkspaceAndForgeService::CANONICAL_NAME,
            'governed_links' => array_fill_keys(
                array_keys(AtlasSharedWorkspaceAndForgeService::GOVERNED_WORK_LINKS),
                true,
            ),
            'minimum_contract' => array_fill_keys(
                array_keys(AtlasSharedWorkspaceAndForgeService::MINIMUM_WORKSPACE_CONTRACT),
                true,
            ),
            'whole_context_roles' => ['gemini_scout' => true],
            'artifact' => [
                'id' => 'art_1',
                'kind' => 'spec',
                'source' => 'context_compiler',
                'timestamp' => '2026-06-01T00:00:00Z',
                'owning_provider_session' => 'claude:s1',
                'input_hash' => 'sha256:a',
                'output_hash' => 'sha256:b',
                'status' => 'validated',
            ],
            'artifact_schema_validated' => true,
            'artifact_evidence_policy_met' => true,
        ];

        $report = $this->service()->audit($bundle);
        $this->assertSame('pass', $report['status']);
        $this->assertSame([], $report['blocking_reasons']);
        $this->assertSame(
            ['canonical_name', 'governed_work', 'minimum_workspace_contract', 'token_law', 'artifact_bus'],
            array_keys($report['sections']),
        );
    }
}
