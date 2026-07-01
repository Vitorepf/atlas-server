<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskArtifactSchemaContractRegistry;
use PHPUnit\Framework\TestCase;

final class AtlasTaskArtifactSchemaContractRegistryTest extends TestCase
{
    private AtlasTaskArtifactSchemaContractRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new AtlasTaskArtifactSchemaContractRegistry();
    }

    public function test_all_nine_kinds_are_registered(): void
    {
        $kinds = $this->registry->kinds();

        $this->assertContains(AtlasTaskArtifactSchemaContractRegistry::KIND_TASK_PACKET, $kinds);
        $this->assertContains(AtlasTaskArtifactSchemaContractRegistry::KIND_FRONTIER_CANDIDATE, $kinds);
        $this->assertContains(AtlasTaskArtifactSchemaContractRegistry::KIND_BLOCKED_RESPEC_DRAFT, $kinds);
        $this->assertContains(AtlasTaskArtifactSchemaContractRegistry::KIND_CORTEX_FACT, $kinds);
        $this->assertContains(AtlasTaskArtifactSchemaContractRegistry::KIND_RESPEC, $kinds);
        $this->assertContains(AtlasTaskArtifactSchemaContractRegistry::KIND_REPLACEMENT, $kinds);
        $this->assertContains(AtlasTaskArtifactSchemaContractRegistry::KIND_CANCELLATION, $kinds);
        $this->assertContains(AtlasTaskArtifactSchemaContractRegistry::KIND_OPERATOR_ONLY, $kinds);
        $this->assertContains(AtlasTaskArtifactSchemaContractRegistry::KIND_EVIDENCE_REPAIR, $kinds);
        $this->assertCount(9, $kinds);
    }

    public function test_unknown_kind_returns_null_contract(): void
    {
        $this->assertNull($this->registry->contract('nonexistent_kind'));
    }

    public function test_unknown_kind_validate_returns_invalid(): void
    {
        $result = $this->registry->validate('nonexistent_kind', ['foo' => 'bar']);

        $this->assertFalse($result['valid']);
        $this->assertSame([], $result['missing_required']);
        $this->assertSame([], $result['found_proxy_fields']);
    }

    // --- task_packet ---

    public function test_task_packet_contract_exposes_required_fields(): void
    {
        $contract = $this->registry->contract(AtlasTaskArtifactSchemaContractRegistry::KIND_TASK_PACKET);

        $this->assertNotNull($contract);
        foreach (['task_packet_id', 'objective', 'allowed_files', 'acceptance_criteria', 'required_evidence'] as $field) {
            $this->assertContains($field, $contract['required_fields'], "task_packet must require '{$field}'");
        }
    }

    public function test_task_packet_contract_forbids_score_rank_grade(): void
    {
        $contract = $this->registry->contract(AtlasTaskArtifactSchemaContractRegistry::KIND_TASK_PACKET);

        $this->assertNotNull($contract);
        foreach (['score', 'rank', 'grade'] as $field) {
            $this->assertContains($field, $contract['forbidden_proxy_fields'],
                "task_packet must forbid pétreo scoring field '{$field}'");
        }
    }

    public function test_valid_task_packet_passes_validation(): void
    {
        $result = $this->registry->validate(AtlasTaskArtifactSchemaContractRegistry::KIND_TASK_PACKET, [
            'task_packet_id'      => 'pkt-1',
            'objective'           => 'Implement AtlasFoo to wire the brain.',
            'allowed_files'       => ['app/Services/Ai/AtlasFoo.php'],
            'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test --filter=AtlasFooTest'],
            'required_evidence'   => ['tests_or_gates_result'],
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['missing_required']);
        $this->assertSame([], $result['found_proxy_fields']);
    }

    public function test_task_packet_missing_fields_are_reported(): void
    {
        $result = $this->registry->validate(AtlasTaskArtifactSchemaContractRegistry::KIND_TASK_PACKET, [
            'task_packet_id' => 'pkt-incomplete',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('objective', $result['missing_required']);
        $this->assertContains('allowed_files', $result['missing_required']);
        $this->assertContains('acceptance_criteria', $result['missing_required']);
        $this->assertContains('required_evidence', $result['missing_required']);
    }

    public function test_task_packet_with_proxy_score_field_is_rejected(): void
    {
        $result = $this->registry->validate(AtlasTaskArtifactSchemaContractRegistry::KIND_TASK_PACKET, [
            'task_packet_id'      => 'pkt-scored',
            'objective'           => 'Implement AtlasFoo.',
            'allowed_files'       => ['app/Foo.php'],
            'acceptance_criteria' => ['artisan test'],
            'required_evidence'   => ['tests_or_gates_result'],
            'score'               => 9.5, // forbidden
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('score', $result['found_proxy_fields']);
    }

    // --- frontier_candidate ---

    public function test_frontier_candidate_contract_exposes_required_fields(): void
    {
        $contract = $this->registry->contract(AtlasTaskArtifactSchemaContractRegistry::KIND_FRONTIER_CANDIDATE);

        $this->assertNotNull($contract);
        foreach (['scope_id', 'evidence_refs', 'atlas_native_owner', 'requires_operator', 'proven_leverage_tier'] as $field) {
            $this->assertContains($field, $contract['required_fields'],
                "frontier_candidate must require '{$field}'");
        }
    }

    public function test_frontier_candidate_forbids_proxy_scoring_fields(): void
    {
        $contract = $this->registry->contract(AtlasTaskArtifactSchemaContractRegistry::KIND_FRONTIER_CANDIDATE);

        $this->assertNotNull($contract);
        foreach (['score', 'rank', 'grade', 'confidence', 'priority_score'] as $field) {
            $this->assertContains($field, $contract['forbidden_proxy_fields'],
                "frontier_candidate must forbid '{$field}'");
        }
    }

    public function test_valid_frontier_candidate_passes_validation(): void
    {
        $result = $this->registry->validate(AtlasTaskArtifactSchemaContractRegistry::KIND_FRONTIER_CANDIDATE, [
            'scope_id'                   => 'scope-brain-next',
            'evidence_refs'              => ['doc:docs/loop-canonical-definition.md'],
            'atlas_native_owner'         => true,
            'requires_operator'          => false,
            'requires_human'             => false,
            'requires_external_provider' => false,
            'proven_leverage_tier'       => 3,
            'autonomy_readiness_tier'    => 2,
            'risk'                       => 1,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['missing_required']);
        $this->assertSame([], $result['found_proxy_fields']);
    }

    // --- blocked_respec_draft ---

    public function test_blocked_respec_draft_contract_exposes_required_fields(): void
    {
        $contract = $this->registry->contract(AtlasTaskArtifactSchemaContractRegistry::KIND_BLOCKED_RESPEC_DRAFT);

        $this->assertNotNull($contract);
        foreach (['original_packet_id', 'block_reason', 'proposed_objective', 'proposed_allowed_files'] as $field) {
            $this->assertContains($field, $contract['required_fields'],
                "blocked_respec_draft must require '{$field}'");
        }
    }

    public function test_blocked_respec_draft_forbids_score_fields(): void
    {
        $contract = $this->registry->contract(AtlasTaskArtifactSchemaContractRegistry::KIND_BLOCKED_RESPEC_DRAFT);

        $this->assertNotNull($contract);
        foreach (['score', 'rank', 'grade'] as $field) {
            $this->assertContains($field, $contract['forbidden_proxy_fields']);
        }
    }

    public function test_blocked_respec_draft_missing_fields_reported(): void
    {
        $result = $this->registry->validate(AtlasTaskArtifactSchemaContractRegistry::KIND_BLOCKED_RESPEC_DRAFT, [
            'original_packet_id' => 'pkt-broken',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('block_reason', $result['missing_required']);
        $this->assertContains('proposed_objective', $result['missing_required']);
        $this->assertContains('proposed_allowed_files', $result['missing_required']);
    }

    // --- cortex_fact ---

    public function test_cortex_fact_contract_exposes_required_fields(): void
    {
        $contract = $this->registry->contract(AtlasTaskArtifactSchemaContractRegistry::KIND_CORTEX_FACT);

        $this->assertNotNull($contract);
        foreach (['snapshot_id', 'inventory', 'orphans', 'clone_clusters', 'forbidden', 'doc_stated_gaps'] as $field) {
            $this->assertContains($field, $contract['required_fields'],
                "cortex_fact must require '{$field}' (mirrors AtlasCortexUniversalFactsSchema)");
        }
    }

    public function test_cortex_fact_forbids_proxy_scoring_fields(): void
    {
        $contract = $this->registry->contract(AtlasTaskArtifactSchemaContractRegistry::KIND_CORTEX_FACT);

        $this->assertNotNull($contract);
        foreach (['score', 'rank', 'grade', 'quality_score', 'confidence'] as $field) {
            $this->assertContains($field, $contract['forbidden_proxy_fields'],
                "cortex_fact must forbid pétreo field '{$field}'");
        }
    }

    public function test_valid_cortex_fact_passes_validation(): void
    {
        $result = $this->registry->validate(AtlasTaskArtifactSchemaContractRegistry::KIND_CORTEX_FACT, [
            'snapshot_id'    => 'snap-2026-06-30',
            'inventory'      => [],
            'orphans'        => [],
            'clone_clusters' => [],
            'forbidden'      => [],
            'doc_stated_gaps'=> [],
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['missing_required']);
        $this->assertSame([], $result['found_proxy_fields']);
    }

    public function test_cortex_fact_with_rank_field_is_rejected(): void
    {
        $result = $this->registry->validate(AtlasTaskArtifactSchemaContractRegistry::KIND_CORTEX_FACT, [
            'snapshot_id'    => 'snap-ranked',
            'inventory'      => [],
            'orphans'        => [],
            'clone_clusters' => [],
            'forbidden'      => [],
            'doc_stated_gaps'=> [],
            'rank'           => 1, // forbidden
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('rank', $result['found_proxy_fields']);
    }

    // --- respec ---

    public function test_respec_contract_exposes_required_fields(): void
    {
        $contract = $this->registry->contract(AtlasTaskArtifactSchemaContractRegistry::KIND_RESPEC);

        $this->assertNotNull($contract);
        foreach (['task_packet_id', 'root_cause', 'new_objective', 'new_allowed_files', 'new_acceptance_criteria'] as $field) {
            $this->assertContains($field, $contract['required_fields'], "respec must require '{$field}'");
        }
    }

    public function test_respec_forbids_generic_proxy_fields(): void
    {
        $contract = $this->registry->contract(AtlasTaskArtifactSchemaContractRegistry::KIND_RESPEC);

        foreach (['vague_summary', 'looks_good', 'manual_review_only'] as $field) {
            $this->assertContains($field, $contract['forbidden_proxy_fields'], "respec must forbid '{$field}'");
        }
    }

    public function test_valid_respec_artifact_passes_validation(): void
    {
        $result = $this->registry->validate(AtlasTaskArtifactSchemaContractRegistry::KIND_RESPEC, [
            'task_packet_id' => 'pkt-blocked',
            'root_cause' => 'contradictory_acceptance',
            'new_objective' => 'Resolve the contradictory acceptance by splitting the AC.',
            'new_allowed_files' => ['app/Foo.php'],
            'new_acceptance_criteria' => ['/opt/homebrew/bin/php artisan test --filter=FooTest'],
        ]);

        $this->assertTrue($result['valid']);
    }

    public function test_respec_artifact_with_vague_summary_is_rejected(): void
    {
        $result = $this->registry->validate(AtlasTaskArtifactSchemaContractRegistry::KIND_RESPEC, [
            'task_packet_id' => 'pkt-blocked',
            'root_cause' => 'contradictory_acceptance',
            'new_objective' => 'Fix it.',
            'new_allowed_files' => ['app/Foo.php'],
            'new_acceptance_criteria' => ['artisan test'],
            'vague_summary' => 'looks fine now',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('vague_summary', $result['found_proxy_fields']);
    }

    // --- replacement ---

    public function test_replacement_contract_exposes_required_fields(): void
    {
        $contract = $this->registry->contract(AtlasTaskArtifactSchemaContractRegistry::KIND_REPLACEMENT);

        $this->assertNotNull($contract);
        foreach (['original_task_packet_id', 'replacement_task_packet_id', 'replacement_reason', 'behavior_equivalence_proof'] as $field) {
            $this->assertContains($field, $contract['required_fields'], "replacement must require '{$field}'");
        }
    }

    public function test_valid_replacement_artifact_passes_validation(): void
    {
        $result = $this->registry->validate(AtlasTaskArtifactSchemaContractRegistry::KIND_REPLACEMENT, [
            'original_task_packet_id' => 'pkt-old',
            'replacement_task_packet_id' => 'pkt-new',
            'replacement_reason' => 'original packet targets a deleted file',
            'behavior_equivalence_proof' => 'before/after diff shows identical output',
        ]);

        $this->assertTrue($result['valid']);
    }

    public function test_replacement_artifact_with_looks_good_is_rejected(): void
    {
        $result = $this->registry->validate(AtlasTaskArtifactSchemaContractRegistry::KIND_REPLACEMENT, [
            'original_task_packet_id' => 'pkt-old',
            'replacement_task_packet_id' => 'pkt-new',
            'replacement_reason' => 'stale',
            'behavior_equivalence_proof' => 'trust me',
            'looks_good' => true,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('looks_good', $result['found_proxy_fields']);
    }

    // --- cancellation ---

    public function test_cancellation_contract_exposes_required_fields(): void
    {
        $contract = $this->registry->contract(AtlasTaskArtifactSchemaContractRegistry::KIND_CANCELLATION);

        $this->assertNotNull($contract);
        foreach (['task_packet_id', 'cancellation_reason', 'evidence_of_duplicate_or_obsolete'] as $field) {
            $this->assertContains($field, $contract['required_fields'], "cancellation must require '{$field}'");
        }
    }

    public function test_valid_cancellation_artifact_passes_validation(): void
    {
        $result = $this->registry->validate(AtlasTaskArtifactSchemaContractRegistry::KIND_CANCELLATION, [
            'task_packet_id' => 'pkt-dup',
            'cancellation_reason' => 'duplicate_of_completed_task',
            'evidence_of_duplicate_or_obsolete' => 'commit abc123 already implements this',
        ]);

        $this->assertTrue($result['valid']);
    }

    public function test_cancellation_artifact_missing_evidence_is_reported(): void
    {
        $result = $this->registry->validate(AtlasTaskArtifactSchemaContractRegistry::KIND_CANCELLATION, [
            'task_packet_id' => 'pkt-dup',
            'cancellation_reason' => 'duplicate',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('evidence_of_duplicate_or_obsolete', $result['missing_required']);
    }

    // --- operator_only ---

    public function test_operator_only_kind_contract_exposes_required_fields(): void
    {
        $contract = $this->registry->contract(AtlasTaskArtifactSchemaContractRegistry::KIND_OPERATOR_ONLY);

        $this->assertNotNull($contract);
        foreach (['task_packet_id', 'operator_gate_reason', 'required_operator_action'] as $field) {
            $this->assertContains($field, $contract['required_fields'], "operator_only must require '{$field}'");
        }
    }

    public function test_valid_operator_only_artifact_passes_validation(): void
    {
        $result = $this->registry->validate(AtlasTaskArtifactSchemaContractRegistry::KIND_OPERATOR_ONLY, [
            'task_packet_id' => 'pkt-human-gate',
            'operator_gate_reason' => 'requires rotating a leaked credential',
            'required_operator_action' => 'rotate the API key in the provider dashboard',
        ]);

        $this->assertTrue($result['valid']);
    }

    public function test_operator_only_artifact_with_manual_review_only_is_rejected(): void
    {
        $result = $this->registry->validate(AtlasTaskArtifactSchemaContractRegistry::KIND_OPERATOR_ONLY, [
            'task_packet_id' => 'pkt-human-gate',
            'operator_gate_reason' => 'unclear',
            'required_operator_action' => 'manual_review_only',
            'manual_review_only' => true,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('manual_review_only', $result['found_proxy_fields']);
    }

    // --- evidence_repair ---

    public function test_evidence_repair_contract_exposes_required_fields(): void
    {
        $contract = $this->registry->contract(AtlasTaskArtifactSchemaContractRegistry::KIND_EVIDENCE_REPAIR);

        $this->assertNotNull($contract);
        foreach (['task_packet_id', 'missing_evidence_type', 'repair_command', 'proof_of_repair'] as $field) {
            $this->assertContains($field, $contract['required_fields'], "evidence_repair must require '{$field}'");
        }
    }

    public function test_valid_evidence_repair_artifact_passes_validation(): void
    {
        $result = $this->registry->validate(AtlasTaskArtifactSchemaContractRegistry::KIND_EVIDENCE_REPAIR, [
            'task_packet_id' => 'pkt-no-evidence',
            'missing_evidence_type' => 'tests_or_gates_result',
            'repair_command' => '/opt/homebrew/bin/php artisan test --filter=FooTest',
            'proof_of_repair' => 'gate now exits 0',
        ]);

        $this->assertTrue($result['valid']);
    }

    public function test_evidence_repair_artifact_missing_proof_is_reported(): void
    {
        $result = $this->registry->validate(AtlasTaskArtifactSchemaContractRegistry::KIND_EVIDENCE_REPAIR, [
            'task_packet_id' => 'pkt-no-evidence',
            'missing_evidence_type' => 'tests_or_gates_result',
            'repair_command' => 'artisan test',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('proof_of_repair', $result['missing_required']);
    }

    // --- determinism ---

    public function test_contract_output_is_deterministic(): void
    {
        foreach ($this->registry->kinds() as $kind) {
            $a = $this->registry->contract($kind);
            $b = $this->registry->contract($kind);
            $this->assertSame($a, $b, "contract for '{$kind}' must be deterministic");
        }
    }

    public function test_all_contracts_have_non_empty_validation_hints(): void
    {
        foreach ($this->registry->kinds() as $kind) {
            $contract = $this->registry->contract($kind);
            $this->assertNotNull($contract);
            $this->assertNotEmpty($contract['validation_hints'],
                "contract for '{$kind}' must include at least one validation hint");
        }
    }
}
