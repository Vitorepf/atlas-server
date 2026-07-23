<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationTruthService;
use App\Services\Engineering\AtlasDocumentationRealityCodeContractProposerService;
use App\Services\Semantic\FrontmatterParser;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * L1-P2 (third increment) — doc-AHEAD-of-code CODE-CONTRACT proposer. Completes the
 * P2 reconciliation triangle (over-claim + under-claim + this). The maturity ledger
 * is mocked for determinism: a runtime-claiming doc that DECLARED evidence_refs the
 * index cannot resolve must yield a CONTRACT (signature description / test outline)
 * to build the missing code — never runnable code, never a file, never an auto-apply.
 *
 * No RefreshDatabase. The index-health guard reads the symbols table directly; in the
 * sqlite test DB that table is absent, so resolution is trusted (the non-degraded
 * path), exactly like the over-claim proposer. The blind-index degrade case creates a
 * present-but-empty table on purpose and drops it after.
 */
final class AtlasDocumentationRealityCodeContractProposalsTest extends TestCase
{
    public function test_partial_doc_with_unresolved_test_ref_yields_a_test_outline_contract(): void
    {
        // Case (1): a spec/partial doc whose declared TEST ref does not resolve must
        // emit a contract item kind=test carrying a given/when/then OUTLINE (not code),
        // is_code=false, must_be_implemented_by_human=true.
        $this->stubLedger($this->ledgerWith([$this->partialDocWithUnresolvedTestRef()]));

        $payload = $this->service()->proposeAll();

        $this->assertSame(AtlasDocumentationRealityCodeContractProposerService::SCHEMA, $payload['schema_version']);
        $this->assertFalse($payload['degraded']);
        $this->assertSame('doc_ahead_of_code', $payload['direction']);
        $this->assertSame(1, data_get($payload, 'summary.docs_with_gaps'));

        $proposal = $payload['proposals'][0];
        $this->assertSame('docs/engineering-knowledge-base/atlas-ahead-doc.md', $proposal['owner_doc']);
        $this->assertSame('doc_ahead_of_code', $proposal['direction']);
        $this->assertSame(
            AtlasDocumentationRealityCodeContractProposerService::STATUS,
            $proposal['status'],
        );
        $this->assertSame('proposed_requires_human_implementation', $proposal['status']);

        // Find the test contract item.
        $testItems = array_values(array_filter(
            (array) $proposal['contract_items'],
            static fn (array $i): bool => ($i['kind'] ?? '') === 'test',
        ));
        $this->assertCount(1, $testItems, 'exactly one test contract item for the one unresolved test ref');
        $item = $testItems[0];

        $this->assertSame('AtlasAheadCapabilityTest', $item['ref']);
        $this->assertFalse($item['is_code']);
        $this->assertTrue($item['must_be_implemented_by_human']);
        $this->assertTrue($item['must_fail_before_implemented']);
        $this->assertNotEmpty($item['contract']);

        // The verifying test is an OUTLINE (given/when/then), never runnable test code.
        $outline = $item['test_outline'];
        $this->assertIsArray($outline);
        $this->assertArrayHasKey('given_when_then', $outline);
        $this->assertArrayHasKey('given', $outline['given_when_then']);
        $this->assertArrayHasKey('when', $outline['given_when_then']);
        $this->assertArrayHasKey('then', $outline['given_when_then']);
        $this->assertNotEmpty($outline['steps']);
        $this->assertTrue($outline['must_fail_before_implemented']);

        // recommended_order: symbol -> command/route -> test -> receipt.
        $this->assertSame(['symbol', 'command', 'route', 'test', 'receipt'], $proposal['recommended_order']);
        $this->assertSame(['symbol', 'command', 'route', 'test', 'receipt'], $payload['recommended_order']);
    }

    public function test_symbol_command_receipt_unresolved_refs_each_get_a_code_free_contract_item(): void
    {
        // A doc that declared a symbol + command + receipt, NONE resolving, yields one
        // contract item per ref: signature DESCRIPTIONS and a produced-by-real-run
        // receipt note — and the by_kind summary counts each.
        $this->stubLedger($this->ledgerWith([$this->partialDocWithMixedUnresolvedRefs()]));

        $payload = $this->service()->proposeAll();

        $this->assertSame(1, data_get($payload, 'summary.docs_with_gaps'));
        $this->assertSame(3, data_get($payload, 'summary.total_unresolved_refs'));
        $this->assertSame(1, data_get($payload, 'summary.by_kind.symbol'));
        $this->assertSame(1, data_get($payload, 'summary.by_kind.command'));
        $this->assertSame(1, data_get($payload, 'summary.by_kind.receipt'));
        $this->assertSame(0, data_get($payload, 'summary.by_kind.test'));

        $items = collect((array) $payload['proposals'][0]['contract_items'])
            ->keyBy(static fn (array $i): string => (string) $i['kind']);

        // symbol -> a proposed signature DESCRIPTION (not code).
        $symbol = $items['symbol'];
        $this->assertFalse($symbol['is_code']);
        $this->assertTrue($symbol['must_be_implemented_by_human']);
        $this->assertArrayHasKey('proposed_signature', $symbol);
        $this->assertStringContainsString('AtlasAheadService', (string) $symbol['proposed_signature']);

        // command -> a proposed artisan signature DESCRIPTION (not code).
        $command = $items['command'];
        $this->assertFalse($command['is_code']);
        $this->assertArrayHasKey('proposed_signature', $command);

        // receipt -> a note that a genuine receipt must be PRODUCED by a real run.
        $receipt = $items['receipt'];
        $this->assertFalse($receipt['is_code']);
        $this->assertTrue($receipt['must_be_produced_by_real_run']);
        $this->assertTrue($receipt['never_hand_written']);
        $this->assertStringContainsStringIgnoringCase('produced by a real run', (string) $receipt['contract']);
    }

    public function test_doc_whose_declared_refs_all_resolve_yields_no_proposal(): void
    {
        // Case (2): a doc whose every declared ref RESOLVES has no doc-ahead-of-code
        // gap — no false gap, no proposal.
        $this->stubLedger($this->ledgerWith([$this->partialDocWithAllRefsResolved()]));

        $payload = $this->service()->proposeAll();

        $this->assertFalse($payload['degraded']);
        $this->assertSame([], $payload['proposals']);
        $this->assertSame(0, data_get($payload, 'summary.docs_with_gaps'));
        $this->assertSame(0, data_get($payload, 'summary.total_unresolved_refs'));
    }

    public function test_non_runtime_self_labeled_doc_is_skipped_even_with_unresolved_refs(): void
    {
        // Case (3): a doc that labeled ITSELF non-runtime (status template /
        // source_material / future) is SKIPPED even though it declares unresolved refs
        // — it is describing a FUTURE, not over-claiming a present gap. RESPECT the
        // self-label, exactly as the multi-estate service respects a data-class.
        foreach (['template', 'source_material', 'future', 'source'] as $status) {
            $this->stubLedger($this->ledgerWith([$this->partialDocWithUnresolvedTestRef(status: $status)]));

            $payload = $this->service()->proposeAll();

            $this->assertSame([], $payload['proposals'], "status '{$status}' must be skipped (non-runtime self-label)");
            $this->assertSame(0, data_get($payload, 'summary.docs_with_gaps'), "status '{$status}' must not count as a gap");
        }
    }

    public function test_non_runtime_self_label_via_graph_kind_or_type_is_skipped(): void
    {
        // A doc can self-label non-runtime through graph_kind or type (not only status)
        // while still carrying status: active. Respect the self-label wherever declared,
        // so a doc-ahead-of-code contract is never proposed for a self-declared FUTURE.
        $cases = [
            'graph_kind' => $this->partialDocWithUnresolvedTestRef(status: 'active', graphKind: 'template'),
            'type' => $this->partialDocWithUnresolvedTestRef(status: 'active', type: 'source_material'),
        ];

        foreach ($cases as $field => $row) {
            $this->stubLedger($this->ledgerWith([$row]));

            $payload = $this->service()->proposeAll();

            $this->assertSame([], $payload['proposals'], "non-runtime via {$field} must be skipped");
            $this->assertSame(0, data_get($payload, 'summary.docs_with_gaps'), "non-runtime via {$field} must not count as a gap");
        }
    }

    public function test_verified_doc_is_never_targeted(): void
    {
        // Case (4): a verified doc has NO gap (every tier already resolves). Even if a
        // stray declared ref were unresolved, a verified claim is out of the target
        // population — never targeted.
        $this->stubLedger($this->ledgerWith([$this->verifiedDocWithStrayUnresolvedRef()]));

        $payload = $this->service()->proposeAll();

        $this->assertSame([], $payload['proposals']);
        $this->assertSame(0, data_get($payload, 'summary.docs_with_gaps'));
    }

    public function test_no_emitted_item_has_is_code_true_or_any_file_path_or_code_field(): void
    {
        // Case (5): across a doc with every ref kind unresolved, NO emitted item may
        // carry is_code=true or any populated file/path/code field; and the envelope
        // claim_policy must declare generates_code=false, writes=false, auto_applies=false.
        $this->stubLedger($this->ledgerWith([$this->partialDocWithEveryKindUnresolved()]));

        $payload = $this->service()->proposeAll();

        // Envelope-level safety.
        $this->assertFalse($payload['writes']);
        $this->assertTrue($payload['claim_policy']['read_only']);
        $this->assertFalse($payload['claim_policy']['generates_code']);
        $this->assertFalse($payload['claim_policy']['writes']);
        $this->assertFalse($payload['claim_policy']['auto_applies']);
        $this->assertTrue($payload['claim_policy']['proposes_contract_only']);
        $this->assertTrue($payload['claim_policy']['only_doc_declared_refs']);
        $this->assertIsString($payload['contract_hash']);

        $forbiddenKeys = [
            'code', 'source_code', 'generated_code', 'runnable_code',
            'file', 'file_path', 'path_to_write', 'write_path', 'target_path',
            'create_file', 'created_file', 'generated_file', 'file_written',
            'apply', 'auto_apply', 'install', 'execute', 'exec', 'command_to_run',
        ];

        $items = (array) $payload['proposals'][0]['contract_items'];
        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            // Every item is explicitly is_code=false + human-implemented.
            $this->assertFalse($item['is_code'], "item kind {$item['kind']} must be is_code=false");
            $this->assertTrue($item['must_be_implemented_by_human']);

            // No forbidden field appears anywhere in the serialized item.
            $serialized = json_encode($item, JSON_THROW_ON_ERROR);
            foreach ($forbiddenKeys as $key) {
                $this->assertStringNotContainsString(
                    "\"{$key}\":",
                    (string) $serialized,
                    "contract item must never carry a '{$key}' field that generates/writes code.",
                );
            }
        }

        // And the proposal-level safety block mirrors it.
        $safety = $payload['proposals'][0]['safety'];
        $this->assertFalse($safety['generates_code']);
        $this->assertFalse($safety['writes']);
        $this->assertFalse($safety['auto_applies']);
        $this->assertTrue($safety['only_doc_declared_refs']);
    }

    public function test_only_doc_declared_refs_are_proposed_never_invented(): void
    {
        // The contract items must key EXACTLY to the refs the doc declared-and-unresolved
        // — never an invented ref. The doc declared symbol(unresolved) + command(resolved):
        // only the unresolved symbol becomes a contract item; nothing is invented.
        $this->stubLedger($this->ledgerWith([$this->partialDocWithOneResolvedOneUnresolved()]));

        $payload = $this->service()->proposeAll();

        $items = (array) $payload['proposals'][0]['contract_items'];
        $this->assertCount(1, $items, 'only the single declared-but-unresolved ref becomes a contract item');
        $this->assertSame('symbol', $items[0]['kind']);
        $this->assertSame('AtlasAheadOnlyMissingSymbol', $items[0]['ref']);

        $declaredRefs = ['AtlasAheadOnlyMissingSymbol', 'atlas:ahead-present-command'];
        $this->assertContains($items[0]['ref'], $declaredRefs, 'the proposed ref must be one the doc actually declared');
    }

    public function test_present_but_empty_symbol_index_degrades_and_withholds_contracts(): void
    {
        // Case (6): a present-but-EMPTY code-intelligence index makes EVERY declared
        // ref look unresolved — the proposer must withhold rather than hand out a
        // corpus-wide "build all this code" plan.
        Schema::create('atlas_engineering_code_symbols', function ($table): void {
            $table->id();
            $table->string('symbol_name')->nullable();
            $table->string('symbol_type')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('archived_at')->nullable();
        });

        try {
            // The ledger WOULD yield a gap, proving the degrade withholds it.
            $this->stubLedger($this->ledgerWith([$this->partialDocWithUnresolvedTestRef()]));

            $payload = $this->service()->proposeAll();

            $this->assertTrue($payload['degraded']);
            $this->assertSame(
                'code_intelligence_index_empty_or_absent_contracts_withheld',
                $payload['degraded_reason'],
            );
            $this->assertSame([], $payload['proposals']);
            $this->assertSame(0, data_get($payload, 'summary.docs_with_gaps'));
            $this->assertSame(0, data_get($payload, 'summary.total_unresolved_refs'));
            // Still a fully-formed, read-only envelope on the withheld path.
            $this->assertFalse($payload['writes']);
            $this->assertFalse($payload['claim_policy']['generates_code']);
            $this->assertIsString($payload['contract_hash']);
        } finally {
            Schema::dropIfExists('atlas_engineering_code_symbols');
        }
    }

    public function test_propose_for_doc_passes_the_capability_filter_to_the_ledger(): void
    {
        $this->mock(AtlasImplementationTruthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ledger')
                ->once()
                ->with('atlas-ahead-doc')
                ->andReturn($this->ledgerWith([$this->partialDocWithUnresolvedTestRef()]));
        });

        $payload = $this->service()->proposeForDoc('atlas-ahead-doc');

        $this->assertSame('atlas-ahead-doc', $payload['capability_filter']);
        $this->assertSame(1, data_get($payload, 'summary.docs_with_gaps'));
    }

    public function test_structural_guard_makes_an_unsafe_is_code_item_unemittable(): void
    {
        // Mirror the P3 antibody proposer's in-code invariant: an item that tried to
        // carry is_code=true (or a populated code/file/path field) must be structurally
        // UNEMITTABLE — the guard throws rather than letting an unsafe item out. We
        // force a subclass to build an unsafe item and assert the guard refuses it.
        // Stub the ledger BEFORE constructing the subclass so it captures the mocked
        // truth service (and the absent-table index-health check is trusted, not queried).
        $this->stubLedger($this->ledgerWith([$this->partialDocWithUnresolvedTestRef()]));

        $service = new class(app(AtlasImplementationTruthService::class), app(FrontmatterParser::class)) extends AtlasDocumentationRealityCodeContractProposerService
        {
            protected function contractItemFor(array $ref): array
            {
                // Deliberately unsafe: is_code=true AND a populated runnable-code field.
                return [
                    'kind' => $ref['kind'],
                    'ref' => $ref['ref'],
                    'is_code' => true,
                    'must_be_implemented_by_human' => true,
                    'code' => '<?php echo "generated"; ?>',
                ];
            }
        };

        $this->expectException(LogicException::class);
        $service->proposeAll();
    }

    public function test_structural_guard_rejects_a_populated_forbidden_field_even_when_is_code_false(): void
    {
        // Even with is_code=false, a populated file/path/code field means the item
        // crossed from DESCRIPTION into writing/generation — the guard must throw.
        $this->stubLedger($this->ledgerWith([$this->partialDocWithUnresolvedTestRef()]));

        $service = new class(app(AtlasImplementationTruthService::class), app(FrontmatterParser::class)) extends AtlasDocumentationRealityCodeContractProposerService
        {
            protected function contractItemFor(array $ref): array
            {
                return [
                    'kind' => $ref['kind'],
                    'ref' => $ref['ref'],
                    'is_code' => false,
                    'must_be_implemented_by_human' => true,
                    // is_code=false, but it names a file to write — still forbidden.
                    'file_path' => 'app/Services/Engineering/SomeGeneratedThing.php',
                ];
            }
        };

        $this->expectException(LogicException::class);
        $service->proposeAll();
    }

    private function service(): AtlasDocumentationRealityCodeContractProposerService
    {
        return app(AtlasDocumentationRealityCodeContractProposerService::class);
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
     * Wrap rows into a ledger envelope with a derived summary (the real ledger shape).
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function ledgerWith(array $rows): array
    {
        return [
            'schema_version' => AtlasImplementationTruthService::LEDGER_SCHEMA,
            'summary' => [
                'evaluated' => count($rows),
                'drift_count' => 0,
                'by_computed_state' => ['spec' => 0, 'partial' => 0, 'verified' => 0],
                'test_resolution' => 'existence_only',
            ],
            'capabilities' => $rows,
        ];
    }

    /**
     * A partial (runtime-claiming) doc that declared a TEST ref the index could not
     * resolve. status is carried in-row for deterministic self-label gating; default
     * 'active' is runtime-claiming.
     *
     * @return array<string,mixed>
     */
    private function partialDocWithUnresolvedTestRef(string $status = 'active', string $graphKind = '', string $type = ''): array
    {
        $row = [
            'capability_id' => 'atlas-ahead-doc',
            'owner_doc' => 'docs/engineering-knowledge-base/atlas-ahead-doc.md',
            'status' => $status,
            'claimed_state' => 'partial',
            'computed_state' => 'partial',
            'drift' => false,
            'under_claim' => false,
            'evidence' => [
                ['kind' => 'symbol', 'ref' => 'AtlasAheadService', 'resolved' => true, 'matched' => 'App\\AtlasAheadService'],
                ['kind' => 'command', 'ref' => 'atlas:ahead', 'resolved' => true, 'matched' => 'atlas:ahead'],
                // The doc NAMED a test that does not exist yet.
                ['kind' => 'test', 'ref' => 'AtlasAheadCapabilityTest', 'resolved' => false, 'matched' => null],
            ],
        ];

        if ($graphKind !== '') {
            $row['graph_kind'] = $graphKind;
        }
        if ($type !== '') {
            $row['type'] = $type;
        }

        return $row;
    }

    /**
     * A partial doc that declared a symbol + command + receipt, NONE resolving.
     *
     * @return array<string,mixed>
     */
    private function partialDocWithMixedUnresolvedRefs(): array
    {
        return [
            'capability_id' => 'atlas-ahead-mixed-doc',
            'owner_doc' => 'docs/engineering-knowledge-base/atlas-ahead-mixed-doc.md',
            'status' => 'active',
            'claimed_state' => 'partial',
            'computed_state' => 'spec',
            'drift' => false,
            'under_claim' => false,
            'evidence' => [
                ['kind' => 'symbol', 'ref' => 'AtlasAheadService', 'resolved' => false, 'matched' => null],
                ['kind' => 'command', 'ref' => 'atlas:ahead-mixed', 'resolved' => false, 'matched' => null],
                ['kind' => 'receipt', 'ref' => 'storage/receipts/atlas-ahead-mixed.json', 'resolved' => false, 'matched' => null],
            ],
        ];
    }

    /**
     * A partial doc whose every declared ref RESOLVES — no gap.
     *
     * @return array<string,mixed>
     */
    private function partialDocWithAllRefsResolved(): array
    {
        return [
            'capability_id' => 'atlas-ahead-resolved-doc',
            'owner_doc' => 'docs/engineering-knowledge-base/atlas-ahead-resolved-doc.md',
            'status' => 'active',
            'claimed_state' => 'partial',
            'computed_state' => 'partial',
            'drift' => false,
            'under_claim' => false,
            'evidence' => [
                ['kind' => 'symbol', 'ref' => 'AtlasAheadResolvedService', 'resolved' => true, 'matched' => 'App\\AtlasAheadResolvedService'],
                ['kind' => 'command', 'ref' => 'atlas:ahead-resolved', 'resolved' => true, 'matched' => 'atlas:ahead-resolved'],
            ],
        ];
    }

    /**
     * A VERIFIED doc with a stray unresolved ref. Verified is out of the target
     * population (no gap) — must never be targeted.
     *
     * @return array<string,mixed>
     */
    private function verifiedDocWithStrayUnresolvedRef(): array
    {
        return [
            'capability_id' => 'atlas-ahead-verified-doc',
            'owner_doc' => 'docs/engineering-knowledge-base/atlas-ahead-verified-doc.md',
            'status' => 'active',
            'claimed_state' => 'verified',
            'computed_state' => 'verified',
            'drift' => false,
            'under_claim' => false,
            'evidence' => [
                ['kind' => 'symbol', 'ref' => 'AtlasAheadVerifiedSymbol', 'resolved' => false, 'matched' => null],
            ],
        ];
    }

    /**
     * A partial doc that declared EVERY ref kind, all unresolved — to exercise every
     * contract-item branch for the forbidden-field assertions.
     *
     * @return array<string,mixed>
     */
    private function partialDocWithEveryKindUnresolved(): array
    {
        return [
            'capability_id' => 'atlas-ahead-everykind-doc',
            'owner_doc' => 'docs/engineering-knowledge-base/atlas-ahead-everykind-doc.md',
            'status' => 'building',
            'claimed_state' => 'partial',
            'computed_state' => 'spec',
            'drift' => false,
            'under_claim' => false,
            'evidence' => [
                ['kind' => 'symbol', 'ref' => 'AtlasAheadEveryService', 'resolved' => false, 'matched' => null],
                ['kind' => 'command', 'ref' => 'atlas:ahead-every', 'resolved' => false, 'matched' => null],
                ['kind' => 'route', 'ref' => 'api/atlas/ahead-every', 'resolved' => false, 'matched' => null],
                ['kind' => 'test', 'ref' => 'AtlasAheadEveryTest', 'resolved' => false, 'matched' => null],
                ['kind' => 'receipt', 'ref' => 'storage/receipts/atlas-ahead-every.json', 'resolved' => false, 'matched' => null],
            ],
        ];
    }

    /**
     * A partial doc with one RESOLVED ref and one UNRESOLVED ref — only the unresolved
     * one becomes a contract item; nothing is invented.
     *
     * @return array<string,mixed>
     */
    private function partialDocWithOneResolvedOneUnresolved(): array
    {
        return [
            'capability_id' => 'atlas-ahead-one-doc',
            'owner_doc' => 'docs/engineering-knowledge-base/atlas-ahead-one-doc.md',
            'status' => 'active',
            'claimed_state' => 'partial',
            'computed_state' => 'spec',
            'drift' => false,
            'under_claim' => false,
            'evidence' => [
                ['kind' => 'symbol', 'ref' => 'AtlasAheadOnlyMissingSymbol', 'resolved' => false, 'matched' => null],
                ['kind' => 'command', 'ref' => 'atlas:ahead-present-command', 'resolved' => true, 'matched' => 'atlas:ahead-present-command'],
            ],
        ];
    }
}
