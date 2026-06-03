<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Engineering\AtlasDocumentationRealitySystemService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * FAIL-ON-STUB flip proof for the six Batch-A promotions in ADRS
 * (AtlasDocumentationRealitySystemService). Each of these six evaluators was a PARTIAL proxy
 * (owner-presence / same-owner-histogram / intra-registry duplicate of the hand-picked 11
 * sources) and is now an EXECUTES evaluator that computes its FULL declared verb from the live
 * cross-source authority audit (EngineeringDocumentationAuthorityAuditService::report) and
 * DERIVES its status from that corpus-wide result.
 *
 * The promotion is REAL only if mutating the real input flips the verdict — a constant cannot
 * flip. Each test plants the block's not_ready condition into an isolated temp docs root and
 * asserts the status flips. Reverting any evaluator to its old proxy (which never reads the
 * corpus) leaves the planted mutation invisible and FAILS the matching test — that divergence
 * is the anti-stub / anti-tautology proof.
 *
 * Harness: an isolated temp docs root holding only the doc(s) under test. The audit scans every
 * .md in the root recursively, so two clean, non-colliding canonical docs make the audit 'ready'
 * (the baseline); the planted collision/gap makes it fire. sqlite :memory:, extends
 * Tests\TestCase, NO RefreshDatabase. No database tables are touched (the authority audit reads
 * only the filesystem corpus).
 */
final class AtlasDocumentationRealityAuthorityVerbFlipTest extends TestCase
{
    private ?string $docsRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->docsRoot = base_path('storage/framework/testing/adrs-authority-verb-'.uniqid());
        File::ensureDirectoryExists($this->docsRoot);
    }

    protected function tearDown(): void
    {
        if ($this->docsRoot !== null && File::isDirectory($this->docsRoot)) {
            File::deleteDirectory($this->docsRoot);
        }

        parent::tearDown();
    }

    // ----------------------------------------------------------------------------------------------
    // authority_kernel (blocks #1 + #2): ready/review -> BLOCKED on a planted authority-slot clash.
    // ----------------------------------------------------------------------------------------------
    public function test_authority_kernel_flips_to_blocked_on_planted_duplicate_graph_id(): void
    {
        // Baseline: two clean, non-colliding canonical docs -> audit ready -> kernel adjudicates a
        // unique winner with no contested slot -> NOT blocked (ready, since the isolated corpus is
        // clean and carries owner+evidence).
        $this->writeCanonicalDoc('alpha.md', [
            'id' => 'alpha-doc',
            'graph_id' => 'alpha-graph',
            'technical_runtime' => 'AlphaRuntimeService',
            'runtime_acronym' => 'ALP',
            'product_name' => 'Alpha Runtime',
            'capabilities' => ['alpha_capability'],
        ]);
        $this->writeCanonicalDoc('beta.md', [
            'id' => 'beta-doc',
            'graph_id' => 'beta-graph',
            'technical_runtime' => 'BetaRuntimeService',
            'runtime_acronym' => 'BET',
            'product_name' => 'Beta Runtime',
            'capabilities' => ['beta_capability'],
        ]);

        $baseline = $this->kernel();
        $this->assertSame('executes', $baseline['execution']);
        $this->assertNotSame('blocked', $baseline['status']);
        $this->assertSame(0, $baseline['conflict_count']);

        // PLANT a blocker-grade authority collision: a third canonical doc that claims the SAME
        // graph_id as alpha. Two docs now compete for one authority slot.
        $this->writeCanonicalDoc('alpha-impostor.md', [
            'id' => 'alpha-impostor-doc',
            'graph_id' => 'alpha-graph', // <-- identity collision with alpha.md
            'technical_runtime' => 'AlphaImpostorRuntimeService',
            'runtime_acronym' => 'AIM',
            'product_name' => 'Alpha Impostor Runtime',
            'capabilities' => ['impostor_capability'],
        ]);

        $planted = $this->kernel();

        // FLIP: a real unadjudicable collision -> blocked, with the conflict adjudicated and named.
        $this->assertNotSame($baseline['status'], $planted['status']);
        $this->assertSame('blocked', $planted['status']);
        $this->assertGreaterThanOrEqual(1, $planted['conflict_count']);
        $this->assertSame('low', $planted['confidence']);
        $conflictPaths = collect($planted['adjudicated_conflicts'])
            ->flatMap(static fn (array $c): array => array_merge([$c['winner_path']], $c['loser_paths']))
            ->all();
        $this->assertContains('alpha.md', array_map(static fn (string $p): string => basename($p), $conflictPaths));
        $this->assertContains('alpha-impostor.md', array_map(static fn (string $p): string => basename($p), $conflictPaths));
    }

    // ----------------------------------------------------------------------------------------------
    // knowledge_governance: ready/review -> BLOCKED when the audit finds a real authority collision.
    // ----------------------------------------------------------------------------------------------
    public function test_knowledge_governance_flips_to_blocked_on_planted_corpus_collision(): void
    {
        $this->writeCanonicalDoc('alpha.md', [
            'id' => 'alpha-doc',
            'graph_id' => 'alpha-graph',
            'technical_runtime' => 'AlphaRuntimeService',
            'runtime_acronym' => 'ALP',
            'product_name' => 'Alpha Runtime',
            'capabilities' => ['alpha_capability'],
        ]);
        $this->writeCanonicalDoc('beta.md', [
            'id' => 'beta-doc',
            'graph_id' => 'beta-graph',
            'technical_runtime' => 'BetaRuntimeService',
            'runtime_acronym' => 'BET',
            'product_name' => 'Beta Runtime',
            'capabilities' => ['beta_capability'],
        ]);

        $baseline = $this->evaluation('knowledge_governance_system');
        $this->assertSame('executes', $baseline['execution']);
        $this->assertNotSame('blocked', $baseline['status']);
        $this->assertSame('clean', $baseline['conflict_matrix_status']);

        // PLANT a duplicate technical_runtime collision across two canonical docs.
        $this->writeCanonicalDoc('beta-impostor.md', [
            'id' => 'beta-impostor-doc',
            'graph_id' => 'beta-impostor-graph',
            'technical_runtime' => 'BetaRuntimeService', // <-- runtime collision with beta.md
            'runtime_acronym' => 'BIM',
            'product_name' => 'Beta Impostor Runtime',
            'capabilities' => ['impostor_capability'],
        ]);

        $planted = $this->evaluation('knowledge_governance_system');

        // FLIP: the conflict matrix now reports a real authority collision -> blocked.
        $this->assertNotSame($baseline['status'], $planted['status']);
        $this->assertSame('blocked', $planted['status']);
        $this->assertSame('authority_collision', $planted['conflict_matrix_status']);
        $this->assertGreaterThanOrEqual(1, $planted['blocker_count']);
    }

    // ----------------------------------------------------------------------------------------------
    // contradiction_resolver: ready -> REVIEW when two docs claim the same technical_runtime.
    // The old intra-registry id/path check stays blind (anti-tautology divergence).
    // ----------------------------------------------------------------------------------------------
    public function test_contradiction_resolver_flips_to_review_on_planted_runtime_contradiction(): void
    {
        $this->writeCanonicalDoc('alpha.md', [
            'id' => 'alpha-doc',
            'graph_id' => 'alpha-graph',
            'technical_runtime' => 'AlphaRuntimeService',
            'runtime_acronym' => 'ALP',
            'product_name' => 'Alpha Runtime',
            'capabilities' => ['alpha_capability'],
        ]);

        $baseline = $this->evaluation('contradiction_resolver');
        $this->assertSame('executes', $baseline['execution']);
        $this->assertSame('ready', $baseline['status']);
        $this->assertSame(0, $baseline['contradiction_count']);
        // The demoted registry signal sees no intra-registry duplicate (unique ids by construction).
        $this->assertFalse($baseline['registry_duplicate_ids']);

        // PLANT: a second canonical doc sharing alpha's technical_runtime — a real contradiction the
        // old duplicate-id/path proxy could never see (distinct ids, distinct paths).
        $this->writeCanonicalDoc('alpha-clone.md', [
            'id' => 'alpha-clone-doc',
            'graph_id' => 'alpha-clone-graph',
            'technical_runtime' => 'AlphaRuntimeService', // <-- runtime contradiction
            'runtime_acronym' => 'ALC',
            'product_name' => 'Alpha Clone Runtime',
            'capabilities' => ['clone_capability'],
        ]);

        $planted = $this->evaluation('contradiction_resolver');

        // FLIP: a populated contradiction queue -> review, naming both docs.
        $this->assertNotSame($baseline['status'], $planted['status']);
        $this->assertSame('review', $planted['status']);
        $this->assertGreaterThanOrEqual(1, $planted['contradiction_count']);
        // ANTI-TAUTOLOGY: the demoted intra-registry check is STILL blind to this real contradiction.
        $this->assertFalse($planted['registry_duplicate_ids']);
        $this->assertSame([], $planted['registry_duplicate_paths']);
        $packetPaths = collect($planted['contradiction_packet'])
            ->flatMap(static fn (array $row): array => $row['conflicting_paths'])
            ->map(static fn (string $p): string => basename($p))
            ->all();
        $this->assertContains('alpha.md', $packetPaths);
        $this->assertContains('alpha-clone.md', $packetPaths);
    }

    // ----------------------------------------------------------------------------------------------
    // vocabulary_alignment_guard: ready -> REVIEW when two docs collide on product_name+runtime_acronym.
    // ----------------------------------------------------------------------------------------------
    public function test_vocabulary_alignment_flips_to_review_on_planted_name_collision(): void
    {
        $this->writeCanonicalDoc('alpha.md', [
            'id' => 'alpha-doc',
            'graph_id' => 'alpha-graph',
            'technical_runtime' => 'AlphaRuntimeService',
            'runtime_acronym' => 'ALP',
            'product_name' => 'Alpha Runtime',
            'capabilities' => ['alpha_capability'],
        ]);

        $baseline = $this->evaluation('vocabulary_alignment_guard');
        $this->assertSame('executes', $baseline['execution']);
        $this->assertSame('ready', $baseline['status']);
        $this->assertSame(0, $baseline['name_conflict_count']);

        // PLANT: a second canonical doc with the SAME product_name + runtime_acronym — a conflicting
        // name. (Its technical_runtime differs, so this is purely a naming collision, not a runtime
        // duplicate — proving the guard reacts to the vocabulary signal specifically.)
        $this->writeCanonicalDoc('alpha-rename.md', [
            'id' => 'alpha-rename-doc',
            'graph_id' => 'alpha-rename-graph',
            'technical_runtime' => 'AlphaRenamedRuntimeService',
            'runtime_acronym' => 'ALP', // <-- same acronym ...
            'product_name' => 'Alpha Runtime', // <-- ... + same product_name = name collision
            'capabilities' => ['rename_capability'],
        ]);

        $planted = $this->evaluation('vocabulary_alignment_guard');

        // FLIP: a populated glossary diff -> review, naming both docs.
        $this->assertNotSame($baseline['status'], $planted['status']);
        $this->assertSame('review', $planted['status']);
        $this->assertGreaterThanOrEqual(1, $planted['name_conflict_count']);
        $diffPaths = collect($planted['glossary_diff'])
            ->flatMap(static fn (array $row): array => $row['paths'])
            ->map(static fn (string $p): string => basename($p))
            ->all();
        $this->assertContains('alpha.md', $diffPaths);
        $this->assertContains('alpha-rename.md', $diffPaths);
    }

    // ----------------------------------------------------------------------------------------------
    // orphaned_decision_finder: ready -> REVIEW when a canonical doc omits owner + evidence.
    // ----------------------------------------------------------------------------------------------
    public function test_orphaned_decision_finder_flips_to_review_on_planted_owner_evidence_gap(): void
    {
        // Baseline: one complete canonical doc (owner + repo_paths + evidence + summary present).
        $this->writeCanonicalDoc('complete.md', [
            'id' => 'complete-doc',
            'graph_id' => 'complete-graph',
            'technical_runtime' => 'CompleteRuntimeService',
            'runtime_acronym' => 'CMP',
            'product_name' => 'Complete Runtime',
            'capabilities' => ['complete_capability'],
        ]);

        $baseline = $this->evaluation('orphaned_decision_finder');
        $this->assertSame('executes', $baseline['execution']);
        $this->assertSame('ready', $baseline['status']);
        $this->assertSame(0, $baseline['orphan_count']);

        // PLANT: a canonical doc (doc_schema set, status active) that OMITS owner and evidence — a
        // genuine orphaned decision the corpus-wide owner_gaps detects.
        $this->writeCanonicalDoc('orphan.md', [
            'id' => 'orphan-doc',
            'graph_id' => 'orphan-graph',
            'technical_runtime' => 'OrphanRuntimeService',
            'runtime_acronym' => 'ORP',
            'product_name' => 'Orphan Runtime',
            'capabilities' => ['orphan_capability'],
            'owner' => '',   // <-- missing owner
            'evidence' => [], // <-- missing evidence
        ]);

        $planted = $this->evaluation('orphaned_decision_finder');

        // FLIP: a populated orphan queue -> review, naming the path with its missing legs.
        $this->assertNotSame($baseline['status'], $planted['status']);
        $this->assertSame('review', $planted['status']);
        $this->assertGreaterThanOrEqual(1, $planted['orphan_count']);
        $orphan = collect($planted['orphan_queue'])
            ->first(static fn (array $row): bool => basename((string) $row['path']) === 'orphan.md');
        $this->assertNotNull($orphan);
        $this->assertContains('owner', $orphan['missing']);
        $this->assertContains('evidence', $orphan['missing']);

        // Removing the orphan returns the verdict to ready (derivation, not a one-way constant).
        File::delete($this->docsRoot.'/orphan.md');
        $this->assertSame('ready', $this->evaluation('orphaned_decision_finder')['status']);
    }

    // ----------------------------------------------------------------------------------------------
    // semantic_deduplication_engine: ready -> REVIEW when two non-family docs declare the same capability.
    // The same-owner histogram alone does not react (anti-proxy divergence).
    // ----------------------------------------------------------------------------------------------
    public function test_semantic_deduplication_flips_to_review_on_planted_capability_overlap(): void
    {
        $this->writeCanonicalDoc('alpha.md', [
            'id' => 'alpha-doc',
            'graph_id' => 'alpha-graph',
            'graph_parent' => 'alpha-parent',
            'owner' => 'owner-a',
            'technical_runtime' => 'AlphaRuntimeService',
            'runtime_acronym' => 'ALP',
            'product_name' => 'Alpha Runtime',
            'capabilities' => ['alpha_capability'],
        ]);

        $baseline = $this->evaluation('semantic_deduplication_engine');
        $this->assertSame('executes', $baseline['execution']);
        $this->assertSame('ready', $baseline['status']);
        $this->assertSame(0, $baseline['dedup_candidate_count']);

        // PLANT: a second canonical doc in a DIFFERENT owner + graph family that declares the SAME
        // capability — a real shared-responsibility overlap (cross-owner, so the audit flags it). The
        // distinct graph_parent keeps it out of a declared graph family.
        $this->writeCanonicalDoc('beta.md', [
            'id' => 'beta-doc',
            'graph_id' => 'beta-graph',
            'graph_parent' => 'beta-parent',
            'owner' => 'owner-b',
            'technical_runtime' => 'BetaRuntimeService',
            'runtime_acronym' => 'BET',
            'product_name' => 'Beta Runtime',
            'capabilities' => ['alpha_capability'], // <-- same capability as alpha.md
        ]);

        $planted = $this->evaluation('semantic_deduplication_engine');

        // FLIP: a populated merge/supersede plan -> review, naming both docs.
        $this->assertNotSame($baseline['status'], $planted['status']);
        $this->assertSame('review', $planted['status']);
        $this->assertGreaterThanOrEqual(1, $planted['dedup_candidate_count']);
        $candidate = collect($planted['dedup_candidates'])
            ->first(static fn (array $row): bool => $row['capability'] === 'alpha_capability');
        $this->assertNotNull($candidate);
        $candidatePaths = array_map(static fn (string $p): string => basename($p), $candidate['paths']);
        $this->assertContains('alpha.md', $candidatePaths);
        $this->assertContains('beta.md', $candidatePaths);
    }

    private function kernel(): array
    {
        return $this->evaluation('authority_kernel');
    }

    /**
     * @return array<string,mixed>
     */
    private function evaluation(string $key): array
    {
        $payload = app(AtlasDocumentationRealitySystemService::class)->report($this->docsRoot);

        return $payload['evaluations'][$key];
    }

    /**
     * Write a valid canonical module doc into the isolated temp root. Mirrors the frontmatter shape
     * the authority audit parses (doc_schema + status make it canonical; the duplicate/overlap/gap
     * fields are what the promoted verbs read).
     *
     * @param  array<string,mixed>  $overrides
     */
    private function writeCanonicalDoc(string $filename, array $overrides): void
    {
        $frontmatter = array_replace([
            'id' => 'doc-id',
            'doc_schema' => 'atlas_canonical_module_doc.v1',
            'title' => 'Doc '.pathinfo($filename, PATHINFO_FILENAME),
            'status' => 'active',
            'category' => 'test',
            'summary' => 'Test summary for '.$filename,
            'capabilities' => ['test_capability'],
            'graph_id' => 'doc-id',
            'graph_kind' => 'module',
            'graph_parent' => 'parent',
            'owner' => 'test-owner',
            'repo_paths' => ['docs/engineering-knowledge-base/test.md'],
            'evidence' => ['test-evidence'],
            'product_name' => 'Test Product',
            'runtime_acronym' => 'TP',
            'technical_runtime' => 'TestRuntimeService',
        ], $overrides);

        $yaml = "---\n";
        foreach ($frontmatter as $key => $value) {
            if (is_array($value)) {
                if ($value === []) {
                    $yaml .= $key.": []\n";

                    continue;
                }
                $yaml .= $key.":\n";
                foreach ($value as $item) {
                    $yaml .= '  - '.$item."\n";
                }
            } else {
                $yaml .= $key.': '.$value."\n";
            }
        }
        $yaml .= "---\n\n# {$frontmatter['title']}\n\n## Resumo\n\nTest.\n";

        File::put($this->docsRoot.'/'.$filename, $yaml);
    }
}
