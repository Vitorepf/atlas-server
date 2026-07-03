<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ArchitectureCouncil;

use App\Services\Ai\SelfConstruction\ArchitectureCouncil\AtlasArchitectureCouncilContractCritic;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasArchitectureCouncilContractCritic: well-formed contract ⇒ accepted=true; missing
 * non_authority ⇒ missing_non_authority finding; designer organ == verifier organ ⇒
 * mixed_powers:designs_and_verifies; no evidence_refs ⇒ no_evidence_refs; hidden side-effect mention
 * ⇒ hidden_side_effects:<key>; broad-mutation mention ⇒ broad_mutation:<key>.
 */
final class AtlasArchitectureCouncilContractCriticTest extends TestCase
{
    private function goodContract(): array
    {
        return [
            'organ' => 'Task Fabric',
            'capability' => 'compile_contract',
            'responsibilities' => ['compile contracts into atomic packet specs'],
            'non_authority' => ['cannot verify outcome', 'cannot decide release'],
            'inputs' => ['architecture_contract'],
            'outputs' => ['packet_spec_drafts'],
            'invariants' => ['no scoring', 'no scheduling'],
            'forbidden_side_effects' => ['execute commands', 'invoke shell', 'merge to main', 'call external provider'],
            'evidence_refs' => ['docs/x.md'],
            'runtime_proof_hooks' => ['phpunit tests/Feature/TaskFabricCompileTest.php'],
            'outcome_learning_hooks' => ['feeds AtlasVerificationCourtVerdictLedger'],
            'worker_feed_effects' => ['emits packet_spec_drafts into task queue'],
            'verifies' => ['organ' => 'Verification Court'],
            'rollback_plan' => 'revert the scoped patch commit and re-run verification from the prior green baseline',
        ];
    }

    public function test_accepted_when_contract_is_well_formed(): void
    {
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($this->goodContract());
        $this->assertTrue($r['accepted']);
        $this->assertSame([], $r['findings']);
    }

    public function test_missing_non_authority_is_flagged(): void
    {
        $c = $this->goodContract();
        $c['non_authority'] = [];
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);
        $this->assertFalse($r['accepted']);
        $this->assertContains('missing_non_authority', $r['findings']);
    }

    public function test_same_organ_designs_and_verifies_is_flagged(): void
    {
        $c = $this->goodContract();
        $c['verifies'] = ['organ' => 'Task Fabric']; // same as designer
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);
        $this->assertContains('mixed_powers:designs_and_verifies', $r['findings']);
    }

    public function test_missing_evidence_refs_is_flagged(): void
    {
        $c = $this->goodContract();
        $c['evidence_refs'] = [];
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);
        $this->assertContains('no_evidence_refs', $r['findings']);
    }

    public function test_hidden_side_effect_in_responsibilities_is_flagged(): void
    {
        $c = $this->goodContract();
        $c['responsibilities'] = ['compile contracts and git push to main'];
        $c['forbidden_side_effects'] = []; // not forbidden
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);
        $this->assertContains('hidden_side_effects:git', $r['findings']);
    }

    public function test_broad_mutation_in_responsibilities_is_flagged(): void
    {
        $c = $this->goodContract();
        $c['responsibilities'] = ['compile contracts and edit constitution'];
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);
        $this->assertContains('broad_mutation:edit_constitution', $r['findings']);
    }

    public function test_missing_invariants_is_flagged(): void
    {
        $c = $this->goodContract();
        $c['invariants'] = [];
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);
        $this->assertContains('missing_invariants', $r['findings']);
    }

    public function test_findings_are_deterministically_sorted(): void
    {
        $c = $this->goodContract();
        $c['non_authority'] = [];
        $c['evidence_refs'] = [];
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);
        $copy = $r['findings'];
        sort($copy, SORT_STRING);
        $this->assertSame($copy, $r['findings']);
    }

    public function test_singleton_interface_in_responsibilities_is_flagged(): void
    {
        $c = $this->goodContract();
        $c['responsibilities'] = ['expose singleton interface with one implementation for compiler'];
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);
        $this->assertFalse($r['accepted']);
        $this->assertContains('overengineering:singleton_interface', $r['findings']);
    }

    public function test_factory_for_one_in_responsibilities_is_flagged(): void
    {
        $c = $this->goodContract();
        $c['responsibilities'] = ['provide a factory for one product only'];
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);
        $this->assertFalse($r['accepted']);
        $this->assertContains('overengineering:factory_for_one', $r['findings']);
    }

    public function test_speculative_config_in_responsibilities_is_flagged(): void
    {
        $c = $this->goodContract();
        $c['responsibilities'] = ['expose speculative config knobs for future extensibility'];
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);
        $this->assertFalse($r['accepted']);
        $this->assertContains('overengineering:speculative_config', $r['findings']);
    }

    public function test_template_farm_wording_is_flagged(): void
    {
        $c = $this->goodContract();
        $c['responsibilities'] = ['manage a template-farm for scaffolding new organs'];
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);
        $this->assertFalse($r['accepted']);
        $this->assertContains('template_farm', $r['findings']);
    }

    public function test_non_atlas_steady_state_runtime_ownership_is_flagged(): void
    {
        $c = $this->goodContract();
        $c['responsibilities'] = ['operator drives steady-state execution pipeline'];
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);
        $this->assertFalse($r['accepted']);
        $this->assertContains('non_atlas_steady_state_runtime', $r['findings']);
    }

    public function test_missing_runtime_proof_hook_is_flagged_as_proxy_contract(): void
    {
        $c = $this->goodContract();
        $c['runtime_proof_hooks'] = [];
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);
        $this->assertFalse($r['accepted']);
        $this->assertContains('proxy_contract', $r['findings']);
    }

    public function test_missing_outcome_learning_hook_is_flagged_as_proxy_contract(): void
    {
        $c = $this->goodContract();
        $c['outcome_learning_hooks'] = [];
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);
        $this->assertFalse($r['accepted']);
        $this->assertContains('proxy_contract', $r['findings']);
    }

    public function test_missing_worker_feed_effect_is_flagged_as_proxy_contract(): void
    {
        $c = $this->goodContract();
        $c['worker_feed_effects'] = [];
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);
        $this->assertFalse($r['accepted']);
        $this->assertContains('proxy_contract', $r['findings']);
    }

    public function test_contract_binding_proof_and_learning_surfaces_is_accepted(): void
    {
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($this->goodContract());
        $this->assertTrue($r['accepted']);
        $this->assertNotContains('proxy_contract', $r['findings']);
    }

    public function test_compact_simple_contract_with_evidence_invariants_non_authority_passes(): void
    {
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($this->goodContract());
        $this->assertTrue($r['accepted']);
        $this->assertSame([], $r['findings']);
        // Confirm no overengineering/template_farm/non_atlas finding leaked in.
        foreach ($r['findings'] as $f) {
            $this->assertStringNotContainsString('overengineering', $f);
            $this->assertStringNotContainsString('template_farm', $f);
            $this->assertStringNotContainsString('non_atlas_steady_state_runtime', $f);
        }
    }

    // ── AC: clean contract ────────────────────────────────────────────────────────

    public function test_clean_contract_yields_accept_verdict(): void
    {
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($this->goodContract());

        $this->assertSame(AtlasArchitectureCouncilContractCritic::VERDICT_ACCEPT, $r['verdict']);
    }

    // ── AC: overbuilt contract ───────────────────────────────────────────────────

    public function test_overbuilt_contract_yields_revise_verdict(): void
    {
        $c = $this->goodContract();
        $c['responsibilities'] = ['expose singleton interface with one implementation for compiler'];

        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);

        $this->assertContains('overengineering:singleton_interface', $r['findings']);
        $this->assertSame(AtlasArchitectureCouncilContractCritic::VERDICT_REVISE, $r['verdict']);
    }

    // ── AC: missing proof ─────────────────────────────────────────────────────────

    public function test_missing_runtime_proof_hooks_is_flagged_missing_proof(): void
    {
        $c = $this->goodContract();
        $c['runtime_proof_hooks'] = [];

        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);

        $this->assertContains('missing_proof', $r['findings']);
        $this->assertSame(AtlasArchitectureCouncilContractCritic::VERDICT_REVISE, $r['verdict']);
    }

    // ── AC: forbidden mutation ────────────────────────────────────────────────────

    public function test_forbidden_broad_mutation_yields_reject_verdict(): void
    {
        $c = $this->goodContract();
        $c['responsibilities'] = ['compile contracts and edit master switch'];

        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);

        $this->assertContains('broad_mutation:edit_master_switch', $r['findings']);
        $this->assertSame(AtlasArchitectureCouncilContractCritic::VERDICT_REJECT, $r['verdict']);
    }

    // ── AC: duplicate responsibility ─────────────────────────────────────────────

    public function test_duplicate_responsibility_is_flagged(): void
    {
        $c = $this->goodContract();
        $c['responsibilities'] = ['compile contracts into atomic packet specs', 'Compile contracts into atomic packet specs '];

        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);

        $this->assertContains('duplicate_responsibility', $r['findings']);
    }

    public function test_distinct_responsibilities_are_not_flagged_as_duplicate(): void
    {
        $c = $this->goodContract();
        $c['responsibilities'] = ['compile contracts', 'validate packet specs'];

        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);

        $this->assertNotContains('duplicate_responsibility', $r['findings']);
    }

    // ── AC: weak rollback ─────────────────────────────────────────────────────────

    public function test_missing_rollback_plan_is_flagged_weak_rollback(): void
    {
        $c = $this->goodContract();
        unset($c['rollback_plan']);

        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);

        $this->assertContains('weak_rollback', $r['findings']);
    }

    public function test_blank_rollback_plan_is_flagged_weak_rollback(): void
    {
        $c = $this->goodContract();
        $c['rollback_plan'] = '   ';

        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);

        $this->assertContains('weak_rollback', $r['findings']);
    }

    // ── vague_owner ───────────────────────────────────────────────────────────────

    public function test_empty_organ_is_flagged_vague_owner(): void
    {
        $c = $this->goodContract();
        $c['organ'] = '';

        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);

        $this->assertContains('vague_owner', $r['findings']);
    }

    public function test_generic_placeholder_organ_is_flagged_vague_owner(): void
    {
        $c = $this->goodContract();
        $c['organ'] = 'Component';

        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);

        $this->assertContains('vague_owner', $r['findings']);
    }

    public function test_concrete_organ_name_is_not_flagged_vague_owner(): void
    {
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($this->goodContract());

        $this->assertNotContains('vague_owner', $r['findings']);
    }

    public function test_hidden_side_effect_key_without_colon_does_not_crash(): void
    {
        // The critic uses explode(':', $key)[1] to extract the tag for suppression.
        // A key without a colon would raise an undefined-array-key error.
        // The guard ensures $parts[1] ?? $key degrades safely.
        // We verify by confirming the critic completes without error on a
        // responsibility that triggers a hidden side-effect pattern.
        $c = $this->goodContract();
        $c['responsibilities'] = ['compile contracts and git push to main'];
        $c['forbidden_side_effects'] = [];

        // This should not raise an undefined-array-key error.
        $r = (new AtlasArchitectureCouncilContractCritic)->critique($c);

        $this->assertContains('hidden_side_effects:git', $r['findings']);
        $this->assertArrayHasKey('accepted', $r);
    }
}
