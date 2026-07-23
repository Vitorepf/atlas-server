<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Models\AtlasEngineeringCodeSymbol;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationTruthService;
use App\Services\Engineering\AtlasDocumentationRealitySystemService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * C3 keystone — the FAIL-ON-STUB proof that the Drift & Duplication Guard (ADRS block
 * #11) is a REAL doc-vs-code drift detector that FIRES on real divergence, not the old
 * tautological duplicate-id/path-only check.
 *
 * The old body checked ONLY duplicate source ids and paths. But ids are the
 * CANONICAL_DOCS array keys (unique by construction) and paths are literal constants —
 * so a duplicate could NEVER appear and the guard could NEVER fire. driftDuplicationEvaluation()
 * now composes TWO live signals:
 *   1. OVER-CLAIM DRIFT — the AAEOS capability truth ledger (a doc claiming a higher
 *      implementation_state than the code index proves).
 *   2. CLAIMED-BUT-ABSENT FACT DRIFT — a canonical source doc declaring an evidence_ref
 *      (symbol/command/route) that does NOT resolve in the code index.
 *
 * KEY anti-stub / anti-tautology (test_planted_drift_is_invisible_to_the_old_id_path_check):
 * the planted drifts in cases (1)/(2) carry NO duplicate ids and NO duplicate paths, so
 * the OLD logic would have returned 'ready' on them. The NEW logic returns 'drift_detected'.
 * If driftDuplicationEvaluation is reverted to the id/path-only check, the planted drifts go
 * invisible and these assertions FAIL — which is the point.
 *
 * Determinism: the over-claim direction MOCKS the AAEOS truth ledger; the claimed-fact
 * direction drives the REAL resolver against a seeded (tiny) index and a temp canonical
 * doc. sqlite :memory:, NO RefreshDatabase — only the symbols table is built, via the real
 * migration's up(), and dropped in tearDown.
 */
final class AtlasDocumentationRealityDriftGuardTest extends TestCase
{
    private string $docsRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runMigration('2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php');
        $this->assertTrue(Schema::hasTable('atlas_engineering_code_symbols'));

        // A single unrelated active symbol makes the index HEALTHY (present + non-empty) so
        // codeIndexHealthy() passes and the guard renders a real verdict rather than degrade.
        // It deliberately does NOT match any planted ghost ref, so claimed-fact drift fires.
        $this->seedSymbol('class', 'App\\Services\\Engineering\\AtlasDocumentationRealitySystemService');

        // An isolated temp docs root so the test never mutates real repo docs. Only the
        // canonical doc(s) we write exist here; every other CANONICAL_DOCS path is absent
        // (skipped by the guard as a missing source, not a claimed-fact drift).
        $this->docsRoot = base_path('storage/framework/testing/drift-guard-'.uniqid());
        File::ensureDirectoryExists($this->docsRoot);
    }

    protected function tearDown(): void
    {
        if (isset($this->docsRoot) && File::isDirectory($this->docsRoot)) {
            File::deleteDirectory($this->docsRoot);
        }

        Schema::dropIfExists('atlas_engineering_doc_links');
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::dropIfExists('atlas_engineering_code_modules');

        parent::tearDown();
    }

    /**
     * CASE 1 — PLANT an OVER-CLAIM drift (claimed=verified, computed=spec) via the AAEOS
     * ledger. The guard status must be != 'ready' and the drift row must name that doc.
     */
    public function test_planted_over_claim_drift_flips_guard_and_names_the_doc(): void
    {
        $this->stubLedger($this->ledgerWith([$this->overClaimRow()]));

        $guard = $this->guard();

        $this->assertNotSame('ready', $guard['status']);
        $this->assertSame('drift_detected', $guard['status']);
        $this->assertGreaterThanOrEqual(1, $guard['drift_count']);
        $this->assertGreaterThanOrEqual(1, $guard['over_claim_drift_count']);

        $overClaim = collect($guard['drifts'])->firstWhere('kind', 'over_claim');
        $this->assertIsArray($overClaim, 'an over_claim drift row must be present');
        $this->assertSame('docs/engineering-knowledge-base/atlas-over-claim-doc.md', $overClaim['owner_doc']);
        $this->assertSame('atlas-over-claim-doc', $overClaim['capability_id']);
        $this->assertSame('verified', $overClaim['claimed']);
        $this->assertSame('spec', $overClaim['computed']);

        // The planted over-claim carries NO duplicate id/path — the OLD logic would have
        // returned 'ready'. Proven explicitly so a revert to id/path-only fails this test.
        $this->assertSame([], $guard['duplicate_source_ids']);
        $this->assertSame([], $guard['duplicate_source_paths']);

        // System-level honesty: a real drift blocks readiness with a named drift blocker.
        $payload = $this->report();
        $this->assertSame('blocked', $payload['status']);
        $driftBlocker = collect($payload['blockers'])->firstWhere('reason', 'documentation_reality_drift_detected');
        $this->assertIsArray($driftBlocker);
        $this->assertGreaterThanOrEqual(1, $driftBlocker['drift_count']);
    }

    /**
     * CASE 2 — PLANT a NONEXISTENT-FACT drift: a canonical source doc declares a symbol AND
     * a command that do NOT resolve in the index. Caught as claimed-but-absent drift rows by
     * the REAL resolver (the ledger is clean here, so this is the ONLY signal firing).
     */
    public function test_planted_claimed_but_absent_fact_drift_is_caught(): void
    {
        // Ledger reports zero drift => signal 1 is clean; only the claimed-fact signal fires.
        $this->stubLedger($this->ledgerWith([]));

        // Write the canonical mother doc into the temp root, declaring two ghost refs.
        $this->writeCanonicalDoc(
            'atlas-documentation-reality-system.md',
            implementationState: 'partial',
            evidenceRefs: [
                'symbol: AtlasTotallyNonexistentGhostSymbolXYZ',
                'command: atlas:ghost:does-not-exist',
            ],
        );

        $guard = $this->guard();

        $this->assertSame('drift_detected', $guard['status']);
        $this->assertGreaterThanOrEqual(2, $guard['claimed_fact_drift_count']);
        $this->assertSame(0, $guard['over_claim_drift_count']);

        $factDrifts = collect($guard['drifts'])->where('kind', 'claimed_fact_absent');
        $symbolDrift = $factDrifts->firstWhere('ref', 'AtlasTotallyNonexistentGhostSymbolXYZ');
        $this->assertIsArray($symbolDrift, 'the unresolved symbol ref must surface as a drift row');
        $this->assertSame('symbol', $symbolDrift['ref_kind']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-documentation-reality-system.md', $symbolDrift['owner_doc']);

        $commandDrift = $factDrifts->firstWhere('ref', 'atlas:ghost:does-not-exist');
        $this->assertIsArray($commandDrift, 'the unresolved command ref must surface as a drift row');
        $this->assertSame('command', $commandDrift['ref_kind']);

        // Again: no duplicate id/path — invisible to the old check, visible now.
        $this->assertSame([], $guard['duplicate_source_ids']);
        $this->assertSame([], $guard['duplicate_source_paths']);
    }

    /**
     * CASE 3 — ZERO drift: a clean ledger AND a source doc whose declared refs all resolve.
     * The guard status is 'ready' with an empty drift list.
     */
    public function test_zero_drift_yields_ready_with_empty_drift_list(): void
    {
        $this->stubLedger($this->ledgerWith([]));

        // The doc declares a symbol that DOES resolve (we seeded this exact class), plus a
        // test ref (tests are not part of the claimed-fact check) — so nothing drifts.
        $this->writeCanonicalDoc(
            'atlas-documentation-reality-system.md',
            implementationState: 'partial',
            evidenceRefs: [
                'symbol: AtlasDocumentationRealitySystemService',
                'test: AtlasDocumentationRealityDriftGuardTest',
            ],
        );

        $guard = $this->guard();

        $this->assertSame('ready', $guard['status']);
        $this->assertFalse($guard['degraded']);
        $this->assertSame(0, $guard['drift_count']);
        $this->assertSame([], $guard['drifts']);
        $this->assertSame(0, $guard['over_claim_drift_count']);
        $this->assertSame(0, $guard['claimed_fact_drift_count']);
        $this->assertFalse($guard['writes']);
    }

    /**
     * THE ANTI-STUB / ANTI-TAUTOLOGY — both planted drifts (over-claim AND claimed-fact)
     * carry NO duplicate ids and NO duplicate paths. Therefore the OLD id/path-only logic
     * (status = duplicates ? 'review' : 'ready') would have returned 'ready' for BOTH. The
     * NEW logic returns 'drift_detected'. This locks the regression shut: reverting
     * driftDuplicationEvaluation to the duplicate-only check makes the planted drifts
     * invisible and this test FAILS.
     */
    public function test_planted_drift_is_invisible_to_the_old_id_path_check(): void
    {
        // (a) over-claim plant — emulate the OLD verdict from the SAME guard payload.
        $this->stubLedger($this->ledgerWith([$this->overClaimRow()]));
        $overClaimGuard = $this->guard();
        $this->assertSame('drift_detected', $overClaimGuard['status']);
        $this->assertSame('ready', $this->oldIdPathVerdict($overClaimGuard), 'old logic would have passed the over-claim plant');

        // (b) claimed-fact plant — re-bind a CLEAN ledger (no refreshApplication: that would
        // discard the :memory: index). A temp doc declaring a ghost symbol is the only signal.
        $this->stubLedger($this->ledgerWith([]));
        $this->writeCanonicalDoc(
            'atlas-documentation-reality-system.md',
            implementationState: 'partial',
            evidenceRefs: ['symbol: AtlasTotallyNonexistentGhostSymbolXYZ'],
        );
        $factGuard = $this->guard();
        $this->assertSame('drift_detected', $factGuard['status']);
        $this->assertSame('ready', $this->oldIdPathVerdict($factGuard), 'old logic would have passed the claimed-fact plant');

        // The new verdict diverges from the old one on BOTH plants — the tautology is broken.
        $this->assertNotSame($this->oldIdPathVerdict($factGuard), $factGuard['status']);
    }

    /**
     * Degrade-safe: with the symbols table DROPPED (no index to compare against), the guard
     * reports 'degraded' — NEVER a false 'ready' — even though the ledger would yield drift.
     */
    public function test_absent_index_degrades_instead_of_false_ready(): void
    {
        // A ledger that, if trusted, WOULD report an over-claim drift — proving it is withheld.
        $this->stubLedger($this->ledgerWith([$this->overClaimRow()]));

        Schema::dropIfExists('atlas_engineering_code_symbols');
        $this->assertFalse(Schema::hasTable('atlas_engineering_code_symbols'));

        $guard = $this->guard();

        $this->assertSame('degraded', $guard['status']);
        $this->assertTrue($guard['degraded']);
        $this->assertSame('code_intelligence_index_empty_or_absent_drift_verdict_withheld', $guard['degraded_reason']);
        $this->assertSame(0, $guard['drift_count']);
        $this->assertSame([], $guard['drifts']);
        $this->assertNotSame('ready', $guard['status']);
    }

    /**
     * Degrade-safe (blind index): the table is PRESENT but EMPTY. Every ref would look
     * unresolved and every claim over-claimed, so the verdict is withheld as 'degraded'.
     */
    public function test_empty_index_degrades_instead_of_corpus_wide_drift(): void
    {
        $this->stubLedger($this->ledgerWith([$this->overClaimRow()]));

        // Empty the index (present but zero active rows) — the dangerous blind state.
        AtlasEngineeringCodeSymbol::query()->delete();

        $guard = $this->guard();

        $this->assertSame('degraded', $guard['status']);
        $this->assertTrue($guard['degraded']);
        $this->assertSame(0, $guard['drift_count']);
    }

    // ---- helpers -------------------------------------------------------------

    /**
     * The OLD (tautological) verdict, recomputed from the SAME guard payload's duplicate
     * arrays: status = duplicates ? 'review' : 'ready'. This is exactly what the reverted
     * id/path-only body would have returned. It NEVER sees the drift signals.
     *
     * @param  array<string,mixed>  $guard
     */
    private function oldIdPathVerdict(array $guard): string
    {
        $hasDuplicates = ($guard['duplicate_source_ids'] ?? []) !== []
            || ($guard['duplicate_source_paths'] ?? []) !== [];

        return $hasDuplicates ? 'review' : 'ready';
    }

    /**
     * The drift_duplication_guard evaluation from a report rooted at the temp docs dir.
     *
     * @return array<string,mixed>
     */
    private function guard(): array
    {
        return $this->report()['evaluations']['drift_duplication_guard'];
    }

    /**
     * @return array<string,mixed>
     */
    private function report(): array
    {
        return app(AtlasDocumentationRealitySystemService::class)->report($this->docsRoot);
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
     * Wrap rows into a ledger envelope with a derived summary (same shape ledger() emits).
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function ledgerWith(array $rows): array
    {
        $drift = count(array_filter($rows, static fn (array $r): bool => ($r['drift'] ?? false) === true));

        return [
            'schema_version' => AtlasImplementationTruthService::LEDGER_SCHEMA,
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
     * Write a canonical module doc (frontmatter only is enough for the source registry +
     * the claimed-fact signal) at its canonical relative path under the temp docs root.
     *
     * @param  array<int,string>  $evidenceRefs  list of "kind: ref" lines
     */
    private function writeCanonicalDoc(string $filename, string $implementationState, array $evidenceRefs): void
    {
        $lines = [
            '---',
            'doc_schema: atlas_canonical_module_doc.v1',
            'owner: atlas-documentation-governance',
            'status: active',
            'implementation_state: '.$implementationState,
            'evidence_refs:',
        ];
        foreach ($evidenceRefs as $ref) {
            $lines[] = '  - '.$ref;
        }
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '# '.$filename;
        $lines[] = '';

        File::put($this->docsRoot.DIRECTORY_SEPARATOR.$filename, implode("\n", $lines)."\n");
    }

    private function seedSymbol(string $type, string $name): void
    {
        AtlasEngineeringCodeSymbol::query()->create([
            'symbol_type' => $type,
            'symbol_name' => $name,
            'file_path' => 'tests/seed/'.md5($name).'.php',
            'language' => 'php',
            'status' => 'active',
            'docs_status' => 'documented',
            'source_hash' => 'seed-'.md5($type.$name),
        ]);
    }

    private function runMigration(string $file): void
    {
        $migration = require base_path('database/migrations/'.$file);
        $migration->up();
    }
}
