<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Engineering\AtlasDocumentationRealityBidirectionalReconciliationService;
use App\Services\Engineering\AtlasDocumentationRealityRepairProposerService;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * L1-P2 (second increment) — BIDIRECTIONAL doc<->code reconciliation. Both the
 * maturity ledger AND the over-claim repair proposer are mocked for determinism:
 * the over-claim direction must be DELEGATED verbatim to the existing proposer, and
 * the under-claim direction must be derived from the ledger's own under_claim flag
 * as a DOC-SIDE upgrade that never generates code. No RefreshDatabase — this decider
 * reads nothing from the database (the index-health guard lives in the delegate).
 */
final class AtlasDocumentationRealityBidirectionalReconciliationTest extends TestCase
{
    public function test_over_claim_row_is_delegated_to_the_existing_repair_proposer(): void
    {
        $delegated = $this->repairEnvelope([$this->overClaimRepairProposal()]);
        $this->stubProposer($delegated);
        $this->stubLedger($this->ledgerWith([$this->overClaimRow()]));

        $payload = $this->service()->reconcileAll();

        $this->assertSame(AtlasDocumentationRealityBidirectionalReconciliationService::SCHEMA, $payload['schema_version']);
        $this->assertFalse($payload['degraded']);

        // The over-claim repairs are the proposer's proposals, embedded verbatim.
        $this->assertSame($delegated['proposals'], $payload['over_claim_repairs']);
        $this->assertSame(1, data_get($payload, 'summary.over_claim_count'));
        $this->assertSame(0, data_get($payload, 'summary.under_claim_count'));

        // It is recorded as delegated, and the delegate's hash is carried through.
        $this->assertSame('delegated_to_repair_proposer', data_get($payload, 'directions.over_claim'));
        $this->assertSame($delegated['proposal_hash'], data_get($payload, 'over_claim_source.proposal_hash'));

        // The delegated over-claim proposal still offers only doc-side options.
        $kinds = array_column($payload['over_claim_repairs'][0]['repair_options'], 'kind');
        $this->assertSame(['downgrade_state', 'supply_evidence'], $kinds);
    }

    public function test_under_claim_row_yields_a_doc_side_upgrade_from_spec_to_verified(): void
    {
        // No over-claim from the delegate; one under-claim row from the ledger.
        $this->stubProposer($this->repairEnvelope([]));
        $this->stubLedger($this->ledgerWith([$this->underClaimRow()]));

        $payload = $this->service()->reconcileAll();

        $this->assertSame(0, data_get($payload, 'summary.over_claim_count'));
        $this->assertSame(1, data_get($payload, 'summary.under_claim_count'));
        $this->assertSame(1, data_get($payload, 'summary.total_reconciliations'));

        $upgrade = $payload['under_claim_upgrades'][0];
        $this->assertSame('docs/engineering-knowledge-base/atlas-under-claim-doc.md', $upgrade['owner_doc']);
        $this->assertSame('spec', $upgrade['claimed_state']);
        $this->assertSame('verified', $upgrade['computed_state']);
        $this->assertSame('under_claim_doc_upgrade', $upgrade['direction']);
        $this->assertSame('upgrade_state', $upgrade['recommended']);

        // The single repair option upgrades implementation_state from spec to verified.
        $this->assertCount(1, $upgrade['repair_options']);
        $option = $upgrade['repair_options'][0];
        $this->assertSame('upgrade_state', $option['kind']);
        $this->assertSame('implementation_state', $option['field']);
        $this->assertSame('spec', $option['from']);
        $this->assertSame('verified', $option['to']);
        $this->assertSame('owner_doc_frontmatter', $option['target']);
        $this->assertTrue($option['reversible']);

        // The upgrade is doc-side: it never touches code and never generates code.
        $this->assertFalse($option['touches_code']);
        $this->assertFalse($option['generates_code']);
        $this->assertFalse($upgrade['safety']['generates_code']);
        $this->assertFalse($upgrade['safety']['proposes_code_change']);
        $this->assertFalse($upgrade['safety']['auto_apply']);

        // It names the resolved proof refs that back the higher tier (read-only).
        $this->assertContains(['kind' => 'symbol', 'ref' => 'AtlasUnderClaimSymbol'], $upgrade['proof_refs_resolved']);
    }

    public function test_clean_row_yields_no_proposal_in_either_direction(): void
    {
        $this->stubProposer($this->repairEnvelope([]));
        $this->stubLedger($this->ledgerWith([$this->cleanRow()]));

        $payload = $this->service()->reconcileAll();

        $this->assertSame([], $payload['over_claim_repairs']);
        $this->assertSame([], $payload['under_claim_upgrades']);
        $this->assertSame(0, data_get($payload, 'summary.over_claim_count'));
        $this->assertSame(0, data_get($payload, 'summary.under_claim_count'));
        $this->assertSame(0, data_get($payload, 'summary.total_reconciliations'));
    }

    public function test_no_proposal_or_option_anywhere_generates_code_or_mutates(): void
    {
        // Both directions populated at once: an over-claim repair AND an under-claim upgrade.
        $this->stubProposer($this->repairEnvelope([$this->overClaimRepairProposal()]));
        $this->stubLedger($this->ledgerWith([$this->overClaimRow(), $this->underClaimRow()]));

        $payload = $this->service()->reconcileAll();

        $forbiddenKinds = ['delete_code', 'delete_file', 'modify_code', 'edit_code', 'remove_symbol', 'rewrite_code', 'generate_code', 'regenerate_code', 'scaffold_code', 'write_code', 'apply', 'delete', 'mutate'];

        $allProposals = array_merge(
            (array) $payload['over_claim_repairs'],
            (array) $payload['under_claim_upgrades'],
        );
        $this->assertNotEmpty($allProposals);

        foreach ($allProposals as $proposal) {
            $this->assertFalse((bool) data_get($proposal, 'safety.proposes_code_change'));
            $this->assertFalse((bool) data_get($proposal, 'safety.auto_apply'));
            // generates_code is absent on the over-claim safety block; when present it must be false.
            $this->assertNotTrue(data_get($proposal, 'safety.generates_code'));

            foreach ((array) ($proposal['repair_options'] ?? []) as $option) {
                $this->assertNotContains(
                    $option['kind'],
                    $forbiddenKinds,
                    "repair option kind '{$option['kind']}' must never generate/mutate code or delete.",
                );
                $this->assertFalse((bool) ($option['touches_code'] ?? false));
                // generates_code must never be true on any option in either direction.
                $this->assertNotTrue($option['generates_code'] ?? false);
            }
        }
    }

    public function test_claim_policy_never_generates_code_and_never_auto_applies(): void
    {
        $this->stubProposer($this->repairEnvelope([]));
        $this->stubLedger($this->ledgerWith([$this->underClaimRow()]));

        $payload = $this->service()->reconcileAll();

        $this->assertFalse($payload['writes']);
        $this->assertTrue($payload['claim_policy']['read_only']);
        $this->assertFalse($payload['claim_policy']['auto_applies']);
        $this->assertFalse($payload['claim_policy']['generates_code']);
        $this->assertFalse($payload['claim_policy']['proposes_code_mutation']);
        $this->assertTrue($payload['claim_policy']['both_directions_doc_side_only']);
        $this->assertIsString($payload['reconcile_hash']);
    }

    public function test_blind_index_degrade_from_delegate_withholds_both_directions(): void
    {
        // When the delegated over-claim proposer degrades (blind index), the whole
        // bidirectional packet must degrade: no under-claim upgrades either, since an
        // empty index makes EVERY doc look mis-claimed in both directions.
        $this->stubProposer($this->degradedRepairEnvelope());
        // A ledger that, if read, WOULD yield an under-claim — proving it is withheld.
        $this->stubLedger($this->ledgerWith([$this->underClaimRow()]));

        $payload = $this->service()->reconcileAll();

        $this->assertTrue($payload['degraded']);
        $this->assertSame(
            'code_intelligence_index_empty_or_absent_reconciliation_withheld',
            $payload['degraded_reason'],
        );
        $this->assertSame([], $payload['over_claim_repairs']);
        $this->assertSame([], $payload['under_claim_upgrades']);
        $this->assertSame(0, data_get($payload, 'summary.total_reconciliations'));
        $this->assertFalse($payload['claim_policy']['generates_code']);
    }

    public function test_reconcile_for_doc_passes_the_capability_filter_to_both_sources(): void
    {
        $this->mock(AtlasDocumentationRealityRepairProposerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('proposeForDoc')
                ->once()
                ->with('atlas-under-claim-doc')
                ->andReturn($this->repairEnvelope([]));
        });
        $this->mock(AtlasAaeosImplementationTruthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ledger')
                ->once()
                ->with('atlas-under-claim-doc')
                ->andReturn($this->ledgerWith([$this->underClaimRow()]));
        });

        $payload = $this->service()->reconcileForDoc('atlas-under-claim-doc');

        $this->assertSame('atlas-under-claim-doc', $payload['capability_filter']);
        $this->assertSame(1, data_get($payload, 'summary.under_claim_count'));
    }

    private function service(): AtlasDocumentationRealityBidirectionalReconciliationService
    {
        return app(AtlasDocumentationRealityBidirectionalReconciliationService::class);
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function stubProposer(array $envelope): void
    {
        $this->mock(AtlasDocumentationRealityRepairProposerService::class, function (MockInterface $mock) use ($envelope): void {
            $mock->shouldReceive('proposeAll')->andReturn($envelope);
            $mock->shouldReceive('proposeForDoc')->andReturn($envelope);
        });
    }

    /**
     * @param  array<string,mixed>  $ledger
     */
    private function stubLedger(array $ledger): void
    {
        $this->mock(AtlasAaeosImplementationTruthService::class, function (MockInterface $mock) use ($ledger): void {
            $mock->shouldReceive('ledger')->andReturn($ledger);
        });
    }

    /**
     * A healthy (non-degraded) over-claim proposer envelope wrapping the given proposals.
     *
     * @param  array<int,array<string,mixed>>  $proposals
     * @return array<string,mixed>
     */
    private function repairEnvelope(array $proposals): array
    {
        return [
            'schema_version' => AtlasDocumentationRealityRepairProposerService::SCHEMA,
            'mode' => 'doc_side_reconciliation_repair_proposer',
            'degraded' => false,
            'summary' => ['drift_count' => count($proposals), 'proposal_count' => count($proposals)],
            'proposals' => $proposals,
            'writes' => false,
            'proposal_hash' => 'deadbeefoverclaimhash',
        ];
    }

    /**
     * A degraded over-claim proposer envelope (blind index).
     *
     * @return array<string,mixed>
     */
    private function degradedRepairEnvelope(): array
    {
        return [
            'schema_version' => AtlasDocumentationRealityRepairProposerService::SCHEMA,
            'degraded' => true,
            'degraded_reason' => 'code_intelligence_index_empty_or_absent_proposals_withheld',
            'summary' => ['drift_count' => 0, 'proposal_count' => 0],
            'proposals' => [],
            'writes' => false,
            'proposal_hash' => 'degradedhash',
        ];
    }

    /**
     * The shape the real proposer emits for an over-claim row (doc-side, both options).
     *
     * @return array<string,mixed>
     */
    private function overClaimRepairProposal(): array
    {
        return [
            'owner_doc' => 'docs/engineering-knowledge-base/atlas-over-claim-doc.md',
            'capability_id' => 'atlas-over-claim-doc',
            'claimed_state' => 'verified',
            'computed_state' => 'spec',
            'unmet_evidence' => ['needs >=1 resolved symbol (class/method) for partial'],
            'repair_options' => [
                ['kind' => 'downgrade_state', 'detail' => 'set implementation_state to spec', 'target' => 'owner_doc_frontmatter', 'reversible' => true, 'touches_code' => false, 'deletes' => false],
                ['kind' => 'supply_evidence', 'detail' => 'add evidence_refs that resolve', 'target' => 'owner_doc_frontmatter', 'reversible' => true, 'touches_code' => false, 'deletes' => false],
            ],
            'recommended' => 'supply_evidence',
            'safety' => ['read_only' => true, 'auto_apply' => false, 'proposes_code_change' => false, 'proposes_deletion' => false],
        ];
    }

    /**
     * Wrap rows into a ledger envelope with a derived summary.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function ledgerWith(array $rows): array
    {
        $drift = count(array_filter($rows, static fn (array $r): bool => ($r['drift'] ?? false) === true));

        return [
            'schema_version' => AtlasAaeosImplementationTruthService::LEDGER_SCHEMA,
            'summary' => [
                'evaluated' => count($rows),
                'drift_count' => $drift,
                'by_computed_state' => ['spec' => 0, 'partial' => 0, 'verified' => 0],
                'test_resolution' => 'existence_only',
            ],
            'capabilities' => $rows,
        ];
    }

    /**
     * An over-claim ledger row (claimed verified, computed spec, drift true).
     *
     * @return array<string,mixed>
     */
    private function overClaimRow(): array
    {
        return [
            'capability_id' => 'atlas-over-claim-doc',
            'owner_doc' => 'docs/engineering-knowledge-base/atlas-over-claim-doc.md',
            'claimed_state' => 'verified',
            'computed_state' => 'spec',
            'drift' => true,
            'under_claim' => false,
            'unmet_evidence' => ['needs >=1 resolved symbol (class/method) for partial'],
            'proof_refs_resolved' => [],
            'evidence' => [
                ['kind' => 'symbol', 'ref' => 'AtlasMissingSymbol', 'resolved' => false, 'matched' => null],
            ],
        ];
    }

    /**
     * An under-claim ledger row: the doc claims spec but the code resolves to
     * verified (rank(computed) > rank(claimed)), so under_claim is true and drift
     * is false. Carries resolved proof refs the upgrade can name.
     *
     * @return array<string,mixed>
     */
    private function underClaimRow(): array
    {
        return [
            'capability_id' => 'atlas-under-claim-doc',
            'owner_doc' => 'docs/engineering-knowledge-base/atlas-under-claim-doc.md',
            'claimed_state' => 'spec',
            'computed_state' => 'verified',
            'drift' => false,
            'under_claim' => true,
            'unmet_evidence' => [],
            'proof_refs_resolved' => [
                ['kind' => 'symbol', 'ref' => 'AtlasUnderClaimSymbol', 'resolved' => true, 'matched' => 'App\\AtlasUnderClaimSymbol'],
                ['kind' => 'command', 'ref' => 'atlas:under-claim', 'resolved' => true, 'matched' => 'atlas:under-claim'],
            ],
            'evidence' => [
                ['kind' => 'symbol', 'ref' => 'AtlasUnderClaimSymbol', 'resolved' => true, 'matched' => 'App\\AtlasUnderClaimSymbol'],
                ['kind' => 'command', 'ref' => 'atlas:under-claim', 'resolved' => true, 'matched' => 'atlas:under-claim'],
            ],
        ];
    }

    /**
     * A clean ledger row: no drift, no under-claim.
     *
     * @return array<string,mixed>
     */
    private function cleanRow(): array
    {
        return [
            'capability_id' => 'atlas-clean-doc',
            'owner_doc' => 'docs/engineering-knowledge-base/atlas-clean-doc.md',
            'claimed_state' => 'partial',
            'computed_state' => 'partial',
            'drift' => false,
            'under_claim' => false,
            'unmet_evidence' => [],
            'proof_refs_resolved' => [],
            'evidence' => [],
        ];
    }
}
