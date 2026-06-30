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

    public function test_all_four_kinds_are_registered(): void
    {
        $kinds = $this->registry->kinds();

        $this->assertContains(AtlasTaskArtifactSchemaContractRegistry::KIND_TASK_PACKET, $kinds);
        $this->assertContains(AtlasTaskArtifactSchemaContractRegistry::KIND_FRONTIER_CANDIDATE, $kinds);
        $this->assertContains(AtlasTaskArtifactSchemaContractRegistry::KIND_BLOCKED_RESPEC_DRAFT, $kinds);
        $this->assertContains(AtlasTaskArtifactSchemaContractRegistry::KIND_CORTEX_FACT, $kinds);
        $this->assertCount(4, $kinds);
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
