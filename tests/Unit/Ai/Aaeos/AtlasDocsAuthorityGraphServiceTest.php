<?php

namespace Tests\Unit\Ai\Aaeos;

use App\Models\AtlasDocsAuthorityGraph;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDocsAuthorityGraphService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasDocsAuthorityGraphServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The full migration set is Postgres-flavored (CREATE EXTENSION ...), which
        // sqlite :memory: cannot run, so — like the rest of the suite — create just
        // the table under test directly from its own migration (single source).
        Schema::dropIfExists('atlas_docs_authority_graph');
        (require base_path('database/migrations/2026_05_31_210000_create_atlas_docs_authority_graph_table.php'))->up();
    }

    public function test_rows_for_doc_emits_governs_capability_and_id_rows(): void
    {
        $rows = $this->service()->rowsForDoc([
            'id' => 'atlas-foo',
            'implementation_state' => 'partial',
            'governs' => ['foo.bar', 'foo.baz'],
            'capabilities' => ['foo_capability'],
        ], 'docs/engineering-knowledge-base/atlas-foo.md');

        $byBasis = collect($rows)->groupBy('owner_basis');
        $this->assertCount(2, $byBasis['governs_frontmatter']);
        $this->assertCount(1, $byBasis['doc_id']);
        $this->assertCount(1, $byBasis['capability_frontmatter']);

        $governsRow = collect($rows)->firstWhere('needle', 'foo.bar');
        $this->assertSame(100, $governsRow['confidence']);
        $this->assertSame('foo.bar', $governsRow['needle_normalized']);
        $this->assertSame('atlas-foo', $governsRow['owner_doc_id']);
        $this->assertSame('partial', $governsRow['owner_implementation_state']);
    }

    public function test_locate_exact_match_prefers_highest_confidence_basis(): void
    {
        AtlasDocsAuthorityGraph::query()->insert([
            $this->row('memory.recall', 'capability_frontmatter', 80, 'docs/engineering-knowledge-base/atlas-memory.md'),
            $this->row('memory.recall', 'governs_frontmatter', 100, 'docs/engineering-knowledge-base/atlas-memory-core.md'),
        ]);

        $result = $this->service()->locate('memory.recall');

        $this->assertTrue($result['resolved']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-memory-core.md', $result['owner_doc_path']);
        $this->assertSame('governs_frontmatter', $result['owner_basis']);
        $this->assertSame(100, $result['confidence']);
    }

    public function test_locate_uses_keyword_fallback_when_no_exact_match(): void
    {
        AtlasDocsAuthorityGraph::query()->insert([
            $this->row('memory.recall.budget', 'governs_frontmatter', 100, 'docs/engineering-knowledge-base/atlas-memory-core.md'),
        ]);

        $result = $this->service()->locate('recall');

        $this->assertTrue($result['resolved']);
        $this->assertSame('keyword_fallback', $result['owner_basis']);
        $this->assertSame(40, $result['confidence']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-memory-core.md', $result['owner_doc_path']);
    }

    public function test_locate_is_unresolved_when_nothing_relates(): void
    {
        $result = $this->service()->locate('totally-unknown-needle-xyz');

        $this->assertFalse($result['resolved']);
        $this->assertSame('keyword_fallback', $result['owner_basis']);
        $this->assertSame(0, $result['confidence']);
    }

    /**
     * @return array<string,mixed>
     */
    private function row(string $needle, string $basis, int $confidence, string $path): array
    {
        return [
            'needle_kind' => 'capability',
            'needle' => $needle,
            'needle_normalized' => mb_strtolower($needle),
            'owner_doc_path' => $path,
            'owner_doc_id' => null,
            'owner_basis' => $basis,
            'confidence' => $confidence,
            'owner_implementation_state' => null,
        ];
    }

    private function service(): AtlasDocsAuthorityGraphService
    {
        return new AtlasDocsAuthorityGraphService(new CanonicalDocsFrontmatterParser);
    }
}
