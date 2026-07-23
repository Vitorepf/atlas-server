<?php

declare(strict_types=1);

namespace Tests\Feature\Foundry\Frontier;

use App\Services\Ai\Foundry\Frontier\Armor\FrontierEvidenceBoundGate;
use App\Services\Ai\Foundry\FoundryEvidenceVerifierService;
use Tests\TestCase;

final class FrontierEvidenceBoundGateTest extends TestCase
{
    private function gate(): FrontierEvidenceBoundGate
    {
        return app(FrontierEvidenceBoundGate::class);
    }

    /**
     * A confirmable cycle_id anchor: present in the dossier AND resolvable via
     * the input.cycles seam the verifier reads.
     *
     * @return array<string,mixed>
     */
    private function cycleAnchor(string $anchorId, string $cycleId): array
    {
        return [
            'anchor_id' => $anchorId,
            'anchor_type' => 'cycle_id',
            'anchor_source' => 'area_focus_cycle',
            'source_path' => 'storage/atlas/cycles',
            'anchor_claim' => $cycleId,
            'resolved' => true,
            'integrity_status' => 'ok',
            'anchor_hash' => 'sha256:'.str_repeat('a', 8),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $anchors
     * @param  list<array<string,mixed>>  $cycleReceipts
     * @return array<string,mixed>
     */
    private function dossier(array $anchors, array $cycleReceipts, string $dossierHash = 'sha256:dossier_real'): array
    {
        return [
            'schema_version' => 'atlas.foundry.dossier.v1',
            'status' => 'ready',
            'area_id' => 'agentic_engineering_os',
            'generated_at' => '2026-05-29T00:00:00+00:00',
            'source_summary' => [],
            'anchor_count' => count($anchors),
            'anchors' => $anchors,
            'cycle_receipts' => $cycleReceipts,
            'evidence_packs' => [],
            'plan_completion' => [],
            'blockers' => [],
            'owner_reuse_matrix' => [],
            'claim_policy' => [],
            'dossier_hash' => $dossierHash,
        ];
    }

    /**
     * 13-key canonical proposal projection citing the given anchor ids.
     *
     * @param  list<string>  $citedAnchorIds
     * @return array<string,mixed>
     */
    private function proposal(string $proposalId, array $citedAnchorIds): array
    {
        return [
            'proposal_id' => $proposalId,
            'horizon' => 'near',
            'title' => 'Bound a real signal',
            'thesis' => 'Compose the existing owner flow.',
            'evidence_refs' => array_map(
                static fn (string $id): array => [
                    'anchor_id' => $id,
                    'anchor_hash' => 'sha256:ref',
                    'anchor_type' => 'cycle_id',
                ],
                $citedAnchorIds,
            ),
            'why_it_multiplies' => 'reuse',
            'success_metric' => ['operator' => '>=', 'baseline' => 0, 'threshold' => 1],
            'rollback' => 'revert proposal',
            'risk_level' => 'low',
            'dependencies' => [],
            'proposed_packets' => [],
            'provider_tier_required' => 'premium',
            'anti_pattern_self_check' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function provenance(string $generatorInputHash): array
    {
        return [
            'generator_label' => 'fixture:deterministic',
            'generator_input_hash' => $generatorInputHash,
            'proposal_hash' => str_repeat('f', 64),
        ];
    }

    // ---------- PASS: all cited anchors confirmed ----------

    public function test_passes_when_every_cited_anchor_is_confirmed(): void
    {
        $anchor = $this->cycleAnchor('anchor:cyc1', 'cycle-001');
        $dossier = $this->dossier([$anchor], [['cycle_id' => 'cycle-001']]);
        $proposal = $this->proposal('prop-1', ['anchor:cyc1']);

        $verdict = $this->gate()->evaluate($proposal, $dossier, $this->provenance('sha256:dossier_real'));

        $this->assertSame(FrontierEvidenceBoundGate::STATUS_PASS, $verdict['status']);
        $this->assertSame('all_cited_anchors_confirmed', $verdict['reason']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertSame('I1', $verdict['stage']);
        $this->assertNotSame('', $verdict['verdict_hash']);
    }

    // ---------- DROP: cited_anchor_refuted ----------

    public function test_drops_with_cited_anchor_refuted_when_verifier_refutes(): void
    {
        // Anchor present in the dossier, but NO matching cycle => verifier refutes.
        $anchor = $this->cycleAnchor('anchor:cyc1', 'cycle-missing');
        $dossier = $this->dossier([$anchor], [['cycle_id' => 'other-cycle']]);
        $proposal = $this->proposal('prop-2', ['anchor:cyc1']);

        $verdict = $this->gate()->evaluate($proposal, $dossier, $this->provenance('sha256:dossier_real'));

        $this->assertSame(FrontierEvidenceBoundGate::STATUS_DROP, $verdict['status']);
        $this->assertSame(FrontierEvidenceBoundGate::REASON_CITED_ANCHOR_REFUTED, $verdict['reason']);
        $this->assertSame([FrontierEvidenceBoundGate::REASON_CITED_ANCHOR_REFUTED], $verdict['blockers']);
        $this->assertStringContainsString('anchor:cyc1', $verdict['detail']);
    }

    // ---------- DROP: cited_anchor_not_in_dossier ----------

    public function test_drops_with_cited_anchor_not_in_dossier_for_unbound_citation(): void
    {
        $anchor = $this->cycleAnchor('anchor:cyc1', 'cycle-001');
        $dossier = $this->dossier([$anchor], [['cycle_id' => 'cycle-001']]);
        // Cites an anchor the dossier never carried.
        $proposal = $this->proposal('prop-3', ['anchor:phantom']);

        $verdict = $this->gate()->evaluate($proposal, $dossier, $this->provenance('sha256:dossier_real'));

        $this->assertSame(FrontierEvidenceBoundGate::STATUS_DROP, $verdict['status']);
        $this->assertSame(FrontierEvidenceBoundGate::REASON_CITED_ANCHOR_NOT_IN_DOSSIER, $verdict['reason']);
        $this->assertSame([FrontierEvidenceBoundGate::REASON_CITED_ANCHOR_NOT_IN_DOSSIER], $verdict['blockers']);
        $this->assertStringContainsString('anchor:phantom', $verdict['detail']);
    }

    // ---------- DROP: dossier_hash_tamper (entire proposal, before anchor scan) ----------

    public function test_drops_with_dossier_hash_tamper_when_generator_input_hash_diverges(): void
    {
        $anchor = $this->cycleAnchor('anchor:cyc1', 'cycle-001');
        $dossier = $this->dossier([$anchor], [['cycle_id' => 'cycle-001']]);
        $proposal = $this->proposal('prop-4', ['anchor:cyc1']);

        // Provenance generator_input_hash was computed over a DIFFERENT (forged) dossier.
        $verdict = $this->gate()->evaluate($proposal, $dossier, $this->provenance('sha256:forged_dossier'));

        $this->assertSame(FrontierEvidenceBoundGate::STATUS_DROP, $verdict['status']);
        $this->assertSame(FrontierEvidenceBoundGate::REASON_DOSSIER_HASH_TAMPER, $verdict['reason']);
        $this->assertSame([FrontierEvidenceBoundGate::REASON_DOSSIER_HASH_TAMPER], $verdict['blockers']);
    }

    public function test_tamper_guard_reads_hash_from_sibling_provenance_not_proposal(): void
    {
        // The proposal object carries ONLY its 13 canon keys; generator_input_hash
        // lives in the sibling provenance map. Empty provenance => tamper drop.
        $anchor = $this->cycleAnchor('anchor:cyc1', 'cycle-001');
        $dossier = $this->dossier([$anchor], [['cycle_id' => 'cycle-001']]);
        $proposal = $this->proposal('prop-5', ['anchor:cyc1']);

        $this->assertArrayNotHasKey('generator_input_hash', $proposal);

        $verdict = $this->gate()->evaluate($proposal, $dossier, []);

        $this->assertSame(FrontierEvidenceBoundGate::REASON_DOSSIER_HASH_TAMPER, $verdict['reason']);
    }

    // ---------- ZERO canonical / code write + deterministic ----------

    public function test_is_read_only_and_deterministic(): void
    {
        $anchor = $this->cycleAnchor('anchor:cyc1', 'cycle-001');
        $dossier = $this->dossier([$anchor], [['cycle_id' => 'cycle-001']]);
        $proposal = $this->proposal('prop-6', ['anchor:cyc1']);

        $v1 = $this->gate()->evaluate($proposal, $dossier, $this->provenance('sha256:dossier_real'));
        $v2 = $this->gate()->evaluate($proposal, $dossier, $this->provenance('sha256:dossier_real'));

        $this->assertSame($v1['verdict_hash'], $v2['verdict_hash']);
        $this->assertTrue($v1['claim_policy']['read_only']);
        $this->assertFalse($v1['claim_policy']['mutates_repo']);
        $this->assertFalse($v1['claim_policy']['canonical_doc_write_allowed']);
        $this->assertFalse($v1['claim_policy']['autoapproval_allowed']);
        $this->assertFalse($v1['claim_policy']['provider_invoked']);
        $this->assertFalse($v1['claim_policy']['executed']);
    }

    public function test_drop_verdict_mirrors_judge_tuple_shape(): void
    {
        $anchor = $this->cycleAnchor('anchor:cyc1', 'cycle-001');
        $dossier = $this->dossier([$anchor], [['cycle_id' => 'cycle-001']]);
        $proposal = $this->proposal('prop-7', ['anchor:phantom']);

        $verdict = $this->gate()->evaluate($proposal, $dossier, $this->provenance('sha256:dossier_real'));

        foreach (['status', 'reason', 'detail', 'blockers'] as $key) {
            $this->assertArrayHasKey($key, $verdict);
        }
        $this->assertIsArray($verdict['blockers']);
    }

    public function test_verifier_refusal_constant_alignment(): void
    {
        // Guard: the refute branch keys off the verifier's own constant.
        $this->assertSame('refuted', FoundryEvidenceVerifierService::VERDICT_REFUTED);
    }
}
