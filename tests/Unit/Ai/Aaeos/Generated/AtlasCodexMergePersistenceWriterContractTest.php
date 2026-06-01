<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePersistenceWriterContractService;
use Tests\TestCase;

/**
 * Pins the documented Codex Merge Post-Execution Action Persistence Writer
 * Contract: boundary, required capabilities, required pre-write checks and
 * forbidden implementation content.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-contract.md
 */
class AtlasCodexMergePersistenceWriterContractTest extends TestCase
{
    private function service(): AtlasCodexMergePersistenceWriterContractService
    {
        return new AtlasCodexMergePersistenceWriterContractService();
    }

    /**
     * Every one of the ten documented pre-write proofs set to true.
     *
     * @return array<string,true>
     */
    private function allProofsMet(): array
    {
        $proofs = [];
        foreach (AtlasCodexMergePersistenceWriterContractService::PRE_WRITE_CHECKS as $check) {
            $proofs[$check] = true;
        }

        return $proofs;
    }

    /**
     * Doc "Boundary": the template keeps all eight keys false. Doc "Required
     * Pre-Write Checks" with no proofs furnished => every check unmet and an
     * implementation may NOT be considered, while the boundary stays intact.
     */
    public function test_empty_input_keeps_boundary_and_blocks_consideration(): void
    {
        $r = $this->service()->contract([]);

        foreach (AtlasCodexMergePersistenceWriterContractService::BOUNDARY_KEYS as $key) {
            $this->assertArrayHasKey($key, $r['pre_write_checks']['boundary']);
            $this->assertFalse($r['pre_write_checks']['boundary'][$key], "boundary $key must be false");
        }
        // Exactly eight boundary keys, no more.
        $this->assertCount(8, AtlasCodexMergePersistenceWriterContractService::BOUNDARY_KEYS);

        $this->assertTrue($r['boundary_held']);
        $this->assertSame([], $r['boundary_violations']);
        $this->assertFalse($r['implementation_may_be_considered']);
        // All ten documented checks are unmet on empty input.
        $this->assertCount(10, $r['pre_write_checks']['unmet_checks']);
        $this->assertFalse($r['pre_write_checks']['writer_may_be_implemented']);
    }

    /**
     * Doc "Required Capabilities": the future writer must implement exactly the
     * seven named behaviours, and the surface itself implements none of them.
     */
    public function test_required_capabilities_are_the_seven_documented(): void
    {
        $cap = $this->service()->requiredCapabilities();

        $this->assertSame([
            'append_only_ledger_write_only_behavior',
            'payload_hash_recomputation',
            'source_hash_match_enforcement',
            'hot_scope_recheck_enforcement',
            'human_confirmation_hash_enforcement',
            'no_merge_authority',
            'no_dispatch_authority',
        ], $cap['required_capabilities']);
        $this->assertSame(7, $cap['count']);
        $this->assertFalse($cap['implements_writer']);
    }

    /**
     * Doc "Required Pre-Write Checks": a single unmet proof keeps the writer
     * un-implementable and surfaces the EXACT documented check, while the others
     * stay met. (Proves the gate is per-check, not all-or-nothing by accident.)
     */
    public function test_single_unmet_pre_write_check_blocks_with_named_reason(): void
    {
        $proofs = $this->allProofsMet();
        // Flip exactly one documented proof: the contract-hash binding is missing.
        $proofs['writer_contract_hash_bound_to_implementation'] = false;

        $checks = $this->service()->preWriteChecks($proofs);

        $this->assertFalse($checks['writer_may_be_implemented']);
        $this->assertSame(['writer_contract_hash_bound_to_implementation'], $checks['unmet_checks']);
        // The other nine remain met.
        $this->assertTrue($checks['checks']['writer_preflight_ready']);
        $this->assertTrue($checks['checks']['merge_authority_absent']);
    }

    /**
     * Doc "writer contract must be hash-bound to the writer preflight before any
     * future implementation can be considered" — dropping either the preflight
     * readiness OR the contract-hash binding must block consideration.
     */
    public function test_preflight_and_hash_binding_are_mandatory_for_consideration(): void
    {
        $svc = $this->service();

        $noPreflight = $this->allProofsMet();
        $noPreflight['writer_preflight_ready'] = false;
        $a = $svc->contract(['pre_write_proofs' => $noPreflight]);
        $this->assertFalse($a['implementation_may_be_considered']);
        $this->assertContains('pre_write_check_unmet:writer_preflight_ready', $a['blocked_reasons']);

        $noHash = $this->allProofsMet();
        $noHash['writer_contract_hash_bound_to_implementation'] = false;
        $b = $svc->contract(['pre_write_proofs' => $noHash]);
        $this->assertFalse($b['implementation_may_be_considered']);
    }

    /**
     * Doc "Forbidden Implementation Content": a candidate that declares any of
     * the eight forbidden items is flagged not-clean and consideration is
     * blocked — even when every pre-write proof is met.
     */
    public function test_forbidden_content_blocks_even_with_all_proofs_met(): void
    {
        $svc = $this->service();

        // All proofs met, but the candidate declares a forbidden behaviour.
        $r = $svc->contract([
            'pre_write_proofs' => $this->allProofsMet(),
            'declared_content' => ['signature_validation', 'merge_execution'],
        ]);

        $this->assertSame(['merge_execution', 'signature_validation'], $r['forbidden_implementation_content']['declared_forbidden']);
        $this->assertFalse($r['forbidden_implementation_content']['clean']);
        $this->assertFalse($r['implementation_may_be_considered']);
        $this->assertContains('forbidden_content_declared:merge_execution', $r['blocked_reasons']);
        $this->assertContains('forbidden_content_declared:signature_validation', $r['blocked_reasons']);
    }

    /**
     * Doc full template: every pre-write proof met AND no forbidden content =>
     * implementation MAY be considered, yet the boundary still holds and the
     * surface implements no writer (consideration is never authorization).
     */
    public function test_all_proofs_and_clean_content_allow_consideration_but_boundary_holds(): void
    {
        $r = $this->service()->contract([
            'pre_write_proofs' => $this->allProofsMet(),
            'declared_content' => [],
        ]);

        $this->assertTrue($r['pre_write_checks']['writer_may_be_implemented']);
        $this->assertSame([], $r['pre_write_checks']['unmet_checks']);
        $this->assertTrue($r['forbidden_implementation_content']['clean']);
        $this->assertTrue($r['implementation_may_be_considered']);
        $this->assertSame([], $r['blocked_reasons']);

        // Boundary is sacred even when consideration is unlocked.
        $this->assertTrue($r['boundary_held']);
        $this->assertSame([], $r['boundary_violations']);
        $this->assertFalse($r['required_capabilities']['implements_writer']);
    }

    /**
     * Guard: assertBoundaryHeld must actually catch a flipped key (proves the
     * check is real, not vacuous).
     */
    public function test_boundary_assertion_catches_a_flipped_key(): void
    {
        $svc = $this->service();
        $tampered = [
            'surface' => 'tampered',
            'boundary' => ['ledger_write_allowed' => true] + $svc->boundary(),
        ];

        $violations = $svc->assertBoundaryHeld([$tampered]);

        $this->assertContains('tampered.ledger_write_allowed', $violations);
    }
}
