<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Engineering\AtlasCodeRealityUsageIntelligenceService;
use App\Services\Engineering\AtlasDocumentationRealitySystemService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Cross-request cache contract for AtlasDocumentationRealitySystemService::report().
 *
 * report() is a pure read-only scan of the canonical docs corpus (~9-10s) that the synchronous
 * create pipeline reaches on EVERY interaction (session-bootstrap gate + feature-placement gate).
 * It is cached cross-request keyed by a stat-only corpus signature (root@sha256(relpath:mtime:size)*)
 * so repeat requests on an unchanged corpus drop the full cost to a cache read, while a real doc
 * add/edit/delete moves the key and recomputes — never a stale result.
 *
 * These tests prove BOTH halves of that contract WITHOUT depending on wall-clock timing:
 *   (1) an unchanged corpus is SERVED FROM THE CACHE STORE across DIFFERENT service instances
 *       (a fresh instance returns the byte-identical array, incl. the frozen-at generated_at, even
 *       after test time advances — a recompute would capture the new now());
 *   (2) a genuinely changed corpus RECOMPUTES (the new generated_at is captured), and clearing the
 *       cache store also forces a recompute (proving the reuse came from the cross-request cache,
 *       not merely a per-instance memo).
 *
 * Harness: an isolated temp docs root holding only the docs under test, so the corpus signature is
 * fully controlled. sqlite :memory:, extends Tests\TestCase, NO RefreshDatabase. The report's
 * filesystem corpus scan is what is exercised; no database tables are required for the cache proof.
 */
final class AtlasDocumentationRealityReportCacheTest extends TestCase
{
    private ?string $docsRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->docsRoot = base_path('storage/framework/testing/adrs-report-cache-'.uniqid());
        File::ensureDirectoryExists($this->docsRoot);
        // Default the TTL on so the cross-request cache is active for these tests regardless of the
        // ambient env (the suite default is array cache, reset between tests).
        config(['atlas.engineering.documentation_reality.report_cache_seconds' => 300]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->docsRoot !== null && File::isDirectory($this->docsRoot)) {
            File::deleteDirectory($this->docsRoot);
        }

        parent::tearDown();
    }

    public function test_unchanged_corpus_is_served_from_cross_request_cache_across_instances(): void
    {
        $this->writeCanonicalDoc('alpha.md', ['id' => 'alpha-doc', 'graph_id' => 'alpha-graph']);
        $this->writeCanonicalDoc('beta.md', ['id' => 'beta-doc', 'graph_id' => 'beta-graph']);

        // Freeze time, compute the report once on a fresh instance, then ADVANCE time.
        Carbon::setTestNow(Carbon::parse('2026-06-08T12:00:00Z'));
        $first = $this->freshService()->report($this->docsRoot);
        $this->assertSame('2026-06-08T12:00:00.000000Z', $first['generated_at']);

        Carbon::setTestNow(Carbon::parse('2026-06-08T12:01:00Z'));

        // A DIFFERENT service instance on the UNCHANGED corpus must be served the byte-identical
        // cached array — same certification_hash AND the original frozen-at generated_at. A
        // recompute would have captured 12:01 (the advanced now(), still well inside the 300s TTL),
        // so an identical 12:00 generated_at proves the cross-request cache store (not a per-instance
        // memo, which a fresh instance would not share) served it.
        $second = $this->freshService()->report($this->docsRoot);

        $this->assertSame($first['generated_at'], $second['generated_at']);
        $this->assertSame($first['certification_hash'], $second['certification_hash']);
        $this->assertSame($first, $second);
    }

    public function test_corpus_change_moves_the_key_and_recomputes(): void
    {
        $this->writeCanonicalDoc('alpha.md', ['id' => 'alpha-doc', 'graph_id' => 'alpha-graph']);

        Carbon::setTestNow(Carbon::parse('2026-06-08T12:00:00Z'));
        $first = $this->freshService()->report($this->docsRoot);
        $this->assertSame('2026-06-08T12:00:00.000000Z', $first['generated_at']);

        // Mutate the corpus: a new doc moves the stat-only signature -> a NEW cache key.
        $this->writeCanonicalDoc('gamma.md', ['id' => 'gamma-doc', 'graph_id' => 'gamma-graph']);

        // Advance time so a recompute is observable via a fresh generated_at.
        Carbon::setTestNow(Carbon::parse('2026-06-08T12:01:00Z'));
        $afterChange = $this->freshService()->report($this->docsRoot);

        // The changed corpus is a cache MISS -> recompute -> the new now() is captured, and the
        // source registry / certification reflect the new corpus.
        $this->assertSame('2026-06-08T12:01:00.000000Z', $afterChange['generated_at']);
        $this->assertNotSame($first['generated_at'], $afterChange['generated_at']);

        // And the original signature is still cached: reverting to it (same files) is served the
        // ORIGINAL frozen-at result again — proving the key is the corpus signature, not the clock.
        File::delete($this->docsRoot.'/gamma.md');
        $reverted = $this->freshService()->report($this->docsRoot);
        $this->assertSame($first['generated_at'], $reverted['generated_at']);
        $this->assertSame($first['certification_hash'], $reverted['certification_hash']);
    }

    public function test_clearing_the_cache_store_forces_a_recompute(): void
    {
        $this->writeCanonicalDoc('alpha.md', ['id' => 'alpha-doc', 'graph_id' => 'alpha-graph']);

        Carbon::setTestNow(Carbon::parse('2026-06-08T12:00:00Z'));
        $first = $this->freshService()->report($this->docsRoot);
        $this->assertSame('2026-06-08T12:00:00.000000Z', $first['generated_at']);

        // Clearing the cross-request cache store drops the memoized report; a fresh instance on the
        // SAME corpus must then recompute (capturing the advanced now()) — confirming the reuse in
        // the other tests came from the cache store, not from global/static state.
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-06-08T12:01:00Z'));
        $afterFlush = $this->freshService()->report($this->docsRoot);

        $this->assertSame('2026-06-08T12:01:00.000000Z', $afterFlush['generated_at']);
    }

    public function test_disabled_ttl_recomputes_every_call(): void
    {
        config(['atlas.engineering.documentation_reality.report_cache_seconds' => 0]);
        $this->writeCanonicalDoc('alpha.md', ['id' => 'alpha-doc', 'graph_id' => 'alpha-graph']);

        Carbon::setTestNow(Carbon::parse('2026-06-08T12:00:00Z'));
        $first = $this->freshService()->report($this->docsRoot);

        // With the cache disabled, a fresh instance on the unchanged corpus recomputes (no L2), so
        // the advanced now() is captured. (Within one instance the per-instance memo still holds.)
        Carbon::setTestNow(Carbon::parse('2026-06-08T12:01:00Z'));
        $second = $this->freshService()->report($this->docsRoot);

        $this->assertSame('2026-06-08T12:00:00.000000Z', $first['generated_at']);
        $this->assertSame('2026-06-08T12:01:00.000000Z', $second['generated_at']);
        $this->assertNotSame($first['generated_at'], $second['generated_at']);
    }

    /**
     * A fresh service instance (NOT the container singleton) so each call starts with an empty
     * per-instance memo — isolating the cross-request cache store as the only possible reuse path.
     */
    private function freshService(): AtlasDocumentationRealitySystemService
    {
        return new AtlasDocumentationRealitySystemService(
            app(CanonicalDocsFrontmatterParser::class),
            app(AtlasCodeRealityUsageIntelligenceService::class),
        );
    }

    /**
     * Write a minimal valid canonical module doc into the isolated temp root. Mirrors the shape the
     * report's source/authority scan parses (doc_schema + status make it canonical).
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
