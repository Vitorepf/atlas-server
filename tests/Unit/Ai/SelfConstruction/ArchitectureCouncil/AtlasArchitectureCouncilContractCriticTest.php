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
}
