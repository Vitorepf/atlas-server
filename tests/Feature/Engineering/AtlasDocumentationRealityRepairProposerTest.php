<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationTruthService;
use App\Services\Engineering\AtlasDocumentationRealityRepairProposerService;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * L1-P2 (first increment) — the reconciliation repair PROPOSER. ledger() is mocked
 * for determinism; the proposer must REUSE the ledger's drift verdict and emit only
 * conservative, doc-side, never-auto-applied proposals. No RefreshDatabase — this
 * decider reads nothing from the database.
 */
final class AtlasDocumentationRealityRepairProposerTest extends TestCase
{
    public function test_over_claim_row_yields_conservative_doc_side_proposal_with_both_options(): void
    {
        $this->stubLedger($this->overClaimLedger());

        $payload = app(AtlasDocumentationRealityRepairProposerService::class)->proposeAll();

        $this->assertSame(AtlasDocumentationRealityRepairProposerService::SCHEMA, $payload['schema_version']);
        $this->assertSame(1, data_get($payload, 'summary.drift_count'));
        $this->assertSame(1, data_get($payload, 'summary.proposal_count'));

        $proposal = $payload['proposals'][0];
        $this->assertSame('docs/engineering-knowledge-base/atlas-over-claim-doc.md', $proposal['owner_doc']);
        $this->assertSame('verified', $proposal['claimed_state']);
        $this->assertSame('spec', $proposal['computed_state']);
        $this->assertNotEmpty($proposal['unmet_evidence']);

        // BOTH repair options present, in order.
        $kinds = array_column($proposal['repair_options'], 'kind');
        $this->assertSame(['downgrade_state', 'supply_evidence'], $kinds);

        // downgrade points at the computed state; supply names the missing refs.
        $downgrade = $proposal['repair_options'][0];
        $this->assertStringContainsString('spec', (string) $downgrade['detail']);
        $supply = $proposal['repair_options'][1];
        $this->assertNotEmpty($supply['missing']);
        $this->assertContains(['kind' => 'test', 'ref' => 'AtlasNonexistentRepairTest'], $supply['missing_refs']);

        // With concrete resolvable missing evidence, recommend supply_evidence.
        $this->assertSame('supply_evidence', $proposal['recommended']);

        // Safety: conservative, doc-side, never deletes.
        $this->assertTrue($proposal['safety']['read_only']);
        $this->assertFalse($proposal['safety']['auto_apply']);
        $this->assertFalse($proposal['safety']['proposes_code_change']);
        $this->assertFalse($proposal['safety']['proposes_deletion']);
    }

    public function test_clean_ledger_with_no_drift_yields_zero_proposals(): void
    {
        $this->stubLedger($this->cleanLedger());

        $payload = app(AtlasDocumentationRealityRepairProposerService::class)->proposeAll();

        $this->assertSame(0, data_get($payload, 'summary.drift_count'));
        $this->assertSame(0, data_get($payload, 'summary.proposal_count'));
        $this->assertSame([], $payload['proposals']);
    }

    public function test_no_proposal_or_option_ever_mutates_code_or_deletes(): void
    {
        $this->stubLedger($this->overClaimLedger());

        $payload = app(AtlasDocumentationRealityRepairProposerService::class)->proposeAll();

        // Only doc-side kinds are allowed; a code-mutating / deletion kind is a bug.
        $forbiddenKinds = ['delete_code', 'delete_file', 'modify_code', 'edit_code', 'remove_symbol', 'rewrite_code', 'apply', 'delete', 'mutate'];

        foreach ($payload['proposals'] as $proposal) {
            $this->assertFalse($proposal['safety']['proposes_deletion']);
            $this->assertFalse($proposal['safety']['proposes_code_change']);
            $this->assertFalse($proposal['safety']['auto_apply']);

            foreach ($proposal['repair_options'] as $option) {
                $this->assertNotContains(
                    $option['kind'],
                    $forbiddenKinds,
                    "repair option kind '{$option['kind']}' must never mutate code or delete.",
                );
                // Every option is doc-side and reversible.
                $this->assertFalse((bool) ($option['touches_code'] ?? false));
                $this->assertFalse((bool) ($option['deletes'] ?? false));
                $this->assertSame('owner_doc_frontmatter', $option['target']);
                $this->assertTrue((bool) ($option['reversible'] ?? false));
            }
        }
    }

    public function test_envelope_claim_policy_never_auto_applies(): void
    {
        $this->stubLedger($this->overClaimLedger());

        $payload = app(AtlasDocumentationRealityRepairProposerService::class)->proposeAll();

        $this->assertFalse($payload['writes']);
        $this->assertTrue($payload['claim_policy']['read_only']);
        $this->assertFalse($payload['claim_policy']['auto_applies']);
        $this->assertFalse($payload['claim_policy']['proposes_code_mutation']);
        $this->assertFalse($payload['claim_policy']['proposes_deletion']);
        $this->assertFalse($payload['claim_policy']['applies_repair']);
        $this->assertFalse($payload['claim_policy']['executes_commands']);
        $this->assertIsString($payload['proposal_hash']);
    }

    public function test_present_but_empty_symbol_index_degrades_and_withholds_proposals(): void
    {
        // A present-but-EMPTY code-intelligence index would make EVERY claiming doc
        // look like an over-claim; the proposer must withhold proposals rather than
        // hand out a corpus-wide "downgrade everything" plan that erases real runtime.
        Schema::create('atlas_engineering_code_symbols', function ($table): void {
            $table->id();
            $table->string('symbol_name')->nullable();
            $table->string('symbol_type')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('archived_at')->nullable();
        });

        try {
            // The ledger reports drift, but the blind index must force a degrade.
            $this->stubLedger($this->overClaimLedger());

            $payload = app(AtlasDocumentationRealityRepairProposerService::class)->proposeAll();

            $this->assertTrue($payload['degraded']);
            $this->assertSame(
                'code_intelligence_index_empty_or_absent_proposals_withheld',
                $payload['degraded_reason'],
            );
            $this->assertSame([], $payload['proposals']);
            $this->assertSame(0, data_get($payload, 'summary.proposal_count'));
            $this->assertFalse($payload['claim_policy']['auto_applies']);
        } finally {
            Schema::dropIfExists('atlas_engineering_code_symbols');
        }
    }

    public function test_propose_for_doc_passes_the_capability_filter_to_the_ledger(): void
    {
        $this->mock(AtlasImplementationTruthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ledger')
                ->once()
                ->with('atlas-over-claim-doc')
                ->andReturn($this->overClaimLedger());
        });

        $payload = app(AtlasDocumentationRealityRepairProposerService::class)
            ->proposeForDoc('atlas-over-claim-doc');

        $this->assertSame('atlas-over-claim-doc', $payload['capability_filter']);
        $this->assertSame(1, data_get($payload, 'summary.proposal_count'));
    }

    /**
     * @param  array<string,mixed>  $ledger
     */
    private function stubLedger(array $ledger): void
    {
        $this->mock(AtlasImplementationTruthService::class, function (MockInterface $mock) use ($ledger): void {
            $mock->shouldReceive('ledger')->andReturn($ledger);
        });
    }

    /**
     * A ledger with exactly one over-claim row: claimed verified, computed spec,
     * unmet_evidence non-empty, with a named-but-unresolved test ref.
     *
     * @return array<string,mixed>
     */
    private function overClaimLedger(): array
    {
        return [
            'schema_version' => AtlasImplementationTruthService::LEDGER_SCHEMA,
            'summary' => [
                'evaluated' => 2,
                'drift_count' => 1,
                'by_computed_state' => ['spec' => 1, 'partial' => 0, 'verified' => 1],
                'test_resolution' => 'existence_only',
            ],
            'capabilities' => [
                [
                    'capability_id' => 'atlas-over-claim-doc',
                    'owner_doc' => 'docs/engineering-knowledge-base/atlas-over-claim-doc.md',
                    'claimed_state' => 'verified',
                    'computed_state' => 'spec',
                    'drift' => true,
                    'under_claim' => false,
                    'unmet_evidence' => [
                        'needs >=1 resolved symbol (class/method) for partial',
                        'needs >=1 resolved route or command for partial',
                    ],
                    'evidence' => [
                        ['kind' => 'test', 'ref' => 'AtlasNonexistentRepairTest', 'resolved' => false, 'matched' => null],
                        ['kind' => 'symbol', 'ref' => 'AtlasResolvedSymbol', 'resolved' => true, 'matched' => 'App\\X'],
                    ],
                ],
                [
                    'capability_id' => 'atlas-clean-doc',
                    'owner_doc' => 'docs/engineering-knowledge-base/atlas-clean-doc.md',
                    'claimed_state' => 'verified',
                    'computed_state' => 'verified',
                    'drift' => false,
                    'under_claim' => false,
                    'unmet_evidence' => [],
                    'evidence' => [],
                ],
            ],
        ];
    }

    /**
     * A ledger with no drift at all.
     *
     * @return array<string,mixed>
     */
    private function cleanLedger(): array
    {
        return [
            'schema_version' => AtlasImplementationTruthService::LEDGER_SCHEMA,
            'summary' => [
                'evaluated' => 1,
                'drift_count' => 0,
                'by_computed_state' => ['spec' => 0, 'partial' => 0, 'verified' => 1],
                'test_resolution' => 'existence_only',
            ],
            'capabilities' => [
                [
                    'capability_id' => 'atlas-clean-doc',
                    'owner_doc' => 'docs/engineering-knowledge-base/atlas-clean-doc.md',
                    'claimed_state' => 'verified',
                    'computed_state' => 'verified',
                    'drift' => false,
                    'under_claim' => false,
                    'unmet_evidence' => [],
                    'evidence' => [],
                ],
            ],
        ];
    }
}
