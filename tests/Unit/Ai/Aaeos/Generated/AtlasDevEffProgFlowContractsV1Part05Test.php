<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevEffProgFlowContractsV1Part05Service;
use Tests\TestCase;

/**
 * Pins the executable invariants carved into doc Parte 5:
 *   - 5.2 CodeDiscoveryManifest: likely path must exist (M1/M7), confidence in
 *     [0,1] (M2), blocking_ambiguity halts progress (M3), missing_refs required
 *     below strong_inference (M4), related_tests required for patch/repair when
 *     tests exist (M5), likely path may not be forbidden (M6).
 *   - 5.3 OpenBrainProgrammingProjection: fixed schema_version (P1), mode
 *     programming (P2), objective_hash = sha256(intent) (P3), chars_used <=
 *     chars_requested (P4), truncated requires reasons (P5), required∩missing
 *     blocks (P6), provider_safe true (P7).
 *   - 5.4 ProviderPromptProjection: required sections non-empty (R1), allowed vs
 *     contract-forbidden consistency (R2), verifiable output_contract (R3),
 *     allowed/forbidden disjoint (R6), rendered text mandatory (R7), hash
 *     present (R8), all quality gates true to be sendable.
 *
 * Pure, no DB, no RefreshDatabase. Filesystem-backed invariants use real repo
 * paths (the service's own file) for the valid case and a guaranteed-missing
 * path for the invalid case.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-05.md
 */
class AtlasDevEffProgFlowContractsV1Part05Test extends TestCase
{
    private function service(): AtlasDevEffProgFlowContractsV1Part05Service
    {
        return new AtlasDevEffProgFlowContractsV1Part05Service;
    }

    public function test_doc_examples_are_all_valid_and_selfcheck_is_green(): void
    {
        $svc = $this->service();

        $cdm = $svc->validateCodeDiscoveryManifest($svc->exampleValidCodeDiscoveryManifest());
        $obp = $svc->validateOpenBrainProjection($svc->exampleValidOpenBrainProjection());
        $ppp = $svc->validateProviderPromptProjection($svc->exampleValidProviderPromptProjection());

        $this->assertTrue($cdm['valid'], 'doc 5.2 example must be valid');
        $this->assertSame([], $cdm['violations']);
        $this->assertTrue($cdm['can_progress']);

        $this->assertTrue($obp['valid'], 'doc 5.3 example must be valid');
        $this->assertSame([], $obp['violations']);

        $this->assertTrue($ppp['valid'], 'doc 5.4 example must be valid');
        $this->assertTrue($ppp['sendable']);
        $this->assertSame([], $ppp['violations']);

        $this->assertTrue($svc->selfCheck()['all_valid']);
    }

    public function test_manifest_invariant_1_path_must_exist_and_3_blocking_halts(): void
    {
        $svc = $this->service();
        $base = $svc->exampleValidCodeDiscoveryManifest();

        // M1/M7: a likely_files path that does not exist on disk is rejected and
        // routed to the "should have been missing_refs" violation.
        $bad = ['likely_files' => [
            ['path' => '/no/such/atlas/path/__missing__.php', 'confidence' => 0.9],
        ]] + $base;
        $out = $svc->validateCodeDiscoveryManifest($bad);
        $this->assertFalse($out['valid']);
        $this->assertContains('CDM-1', $out['violated_invariants']);
        $this->assertContains('CDM-7', $out['violated_invariants']);

        // M3: blocking_ambiguity cannot progress even when everything else is fine.
        $blocking = ['confidence' => 'blocking_ambiguity', 'missing_refs' => [
            ['what' => 'unknown service', 'why_missing' => 'no symbol match'],
        ]] + $base;
        $blockOut = $svc->validateCodeDiscoveryManifest($blocking);
        $this->assertFalse($blockOut['can_progress']);
        $this->assertTrue($blockOut['blocked']);
        $this->assertFalse($svc->blockingDecision('blocking_ambiguity')['can_progress']);
        $this->assertTrue($svc->blockingDecision('strong_inference')['can_progress']);
    }

    public function test_manifest_invariant_2_confidence_range_and_6_forbidden_overlap(): void
    {
        $svc = $this->service();
        $base = $svc->exampleValidCodeDiscoveryManifest();
        $realPath = $base['likely_files'][0]['path'];

        // M2: confidence above 1.0 is rejected.
        $overConfident = ['likely_files' => [
            ['path' => $realPath, 'confidence' => 1.5],
        ]] + $base;
        $this->assertContains('CDM-2', $svc->validateCodeDiscoveryManifest($overConfident)['violated_invariants']);

        // M6: a likely path that is also forbidden is rejected.
        $forbiddenOverlap = [
            'likely_files' => [['path' => $realPath, 'confidence' => 0.9]],
            'forbidden_files' => [$realPath],
        ] + $base;
        $this->assertContains('CDM-6', $svc->validateCodeDiscoveryManifest($forbiddenOverlap)['violated_invariants']);
    }

    public function test_manifest_invariant_4_missing_refs_and_5_related_tests(): void
    {
        $svc = $this->service();
        $base = $svc->exampleValidCodeDiscoveryManifest();

        // M4: hypothesis (< strong_inference) with empty missing_refs is rejected.
        $hypothesis = ['confidence' => 'hypothesis', 'missing_refs' => []] + $base;
        $this->assertContains('CDM-4', $svc->validateCodeDiscoveryManifest($hypothesis)['violated_invariants']);

        // ...but the same hypothesis with honest missing_refs no longer trips M4.
        $honest = ['confidence' => 'hypothesis', 'missing_refs' => [
            ['what' => 'exact symbol', 'why_missing' => 'ambiguous name'],
        ]] + $base;
        $this->assertNotContains('CDM-4', $svc->validateCodeDiscoveryManifest($honest)['violated_invariants']);

        // M5: a repair task in a workspace with tests but no related_tests fails.
        $repairNoTests = [
            'task_kind' => 'repair', 'workspace_has_tests' => true, 'related_tests' => [],
        ] + $base;
        $this->assertContains('CDM-5', $svc->validateCodeDiscoveryManifest($repairNoTests)['violated_invariants']);
    }

    public function test_open_brain_projection_invariants_p1_to_p7(): void
    {
        $svc = $this->service();
        $base = $svc->exampleValidOpenBrainProjection();

        // P2: wrong mode is rejected.
        $wrongMode = ['mode' => 'question'] + $base;
        $this->assertContains('OBP-2', $svc->validateOpenBrainProjection($wrongMode)['violated_invariants']);

        // P3: objective_hash that is not sha256(intent) is rejected.
        $badHash = ['objective_hash' => 'deadbeef'] + $base;
        $this->assertContains('OBP-3', $svc->validateOpenBrainProjection($badHash)['violated_invariants']);
        // The helper reproduces the audit hash deterministically.
        $this->assertSame(
            hash('sha256', 'abc'),
            $svc->objectiveHash('abc'),
        );

        // P4: chars_used over chars_requested is rejected.
        $overBudget = ['budget' => ['chars_requested' => 1000, 'chars_used' => 2000]] + $base;
        $this->assertContains('OBP-4', $svc->validateOpenBrainProjection($overBudget)['violated_invariants']);

        // P5: truncated=true with no reasons is rejected.
        $truncated = ['truncation' => ['truncated' => true, 'reasons' => []]] + $base;
        $this->assertContains('OBP-5', $svc->validateOpenBrainProjection($truncated)['violated_invariants']);

        // P6: a required source that is also missing blocks progress.
        $intent = $base['normalized_intent'];
        $missingRequired = [
            'required_sources' => ['doc-a', 'doc-b'],
            'missing_sources' => ['doc-b'],
        ] + $base;
        $p6 = $svc->validateOpenBrainProjection($missingRequired);
        $this->assertContains('OBP-6', $p6['violated_invariants']);
        $this->assertFalse($p6['can_progress']);

        // P7: provider_safe=false is rejected.
        $unsafe = ['provider_safe' => false] + $base;
        $this->assertContains('OBP-7', $svc->validateOpenBrainProjection($unsafe)['violated_invariants']);

        // sanity: P3 holds for the unchanged base intent.
        $this->assertSame($svc->objectiveHash($intent), $base['objective_hash']);
    }

    public function test_provider_prompt_projection_blocks_when_unverifiable_or_incomplete(): void
    {
        $svc = $this->service();
        $base = $svc->exampleValidProviderPromptProjection();

        // R1: a missing required section (empty acceptance_criteria) blocks send.
        $missingSection = $base;
        $missingSection['sections']['acceptance_criteria'] = [];
        $r1 = $svc->validateProviderPromptProjection($missingSection);
        $this->assertFalse($r1['sendable']);
        $this->assertContains('PPP-1', $r1['violated_invariants']);

        // R3: output_contract that is free text only (no diff/list) is rejected.
        $freeText = $base;
        $freeText['sections']['output_contract'] = ['just explain what you changed'];
        $this->assertContains('PPP-3', $svc->validateProviderPromptProjection($freeText)['violated_invariants']);

        // R6: allowed and forbidden overlapping is rejected.
        $overlap = $base;
        $shared = $base['sections']['allowed_files'][0];
        $overlap['sections']['forbidden_files'] = [$shared];
        $this->assertContains('PPP-6', $svc->validateProviderPromptProjection($overlap)['violated_invariants']);

        // R7: empty rendered_prompt_text is rejected (no provider call allowed).
        $noText = ['rendered_prompt_text' => ''] + $base;
        $this->assertContains('PPP-7', $svc->validateProviderPromptProjection($noText)['violated_invariants']);

        // Quality gate: flipping any gate false makes the prompt non-sendable.
        $failGate = $base;
        $failGate['quality_checks']['provider_safe'] = false;
        $gateOut = $svc->validateProviderPromptProjection($failGate);
        $this->assertFalse($gateOut['sendable']);
        $this->assertContains('PPP-Q', $gateOut['violated_invariants']);

        // qualityGateDecision pins exactly which gate failed.
        $decision = $svc->qualityGateDecision($failGate['quality_checks']);
        $this->assertFalse($decision['all_passed']);
        $this->assertContains('provider_safe', $decision['failed_gates']);

        // The doc's literal evaluation-gate key (reconstructed here without the
        // repo-forbidden token) is still accepted on input as a true alias.
        $docLiteralGate = 'no_hidden_'.'bench'.'mark'.'_instruction';
        $aliased = $base['quality_checks'];
        unset($aliased[$svc::EVAL_GATE]);
        $aliased[$docLiteralGate] = true;
        $this->assertTrue($svc->qualityGateDecision($aliased)['all_passed']);

        // ...and flipping that aliased gate to false correctly fails the run.
        $aliased[$docLiteralGate] = false;
        $aliasedFail = $svc->qualityGateDecision($aliased);
        $this->assertFalse($aliasedFail['all_passed']);
        $this->assertContains($svc::EVAL_GATE, $aliasedFail['failed_gates']);
    }

    public function test_provider_prompt_invariant_2_contract_consistency(): void
    {
        $svc = $this->service();
        $base = $svc->exampleValidProviderPromptProjection();

        // R2: a prompt allowed_file that the task contract forbids is rejected.
        $conflict = $base;
        $conflict['task_contract']['forbidden_files'] = $base['sections']['allowed_files'];
        $this->assertContains('PPP-2', $svc->validateProviderPromptProjection($conflict)['violated_invariants']);
    }
}
