<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AcosMax;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Services\Ai\Memory\AtlasMemoryConflictResolutionService;
use App\Services\Ai\Memory\MemoryConsolidationScanner;
use App\Services\Ai\Memory\MemoryPairwiseCosineScorer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class Maxh03ConsolidationScannerTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    private string $ledgerRoot;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-12T02:00:00+00:00');
        $this->createAtlasMemoryEntryTable();
        $this->createRelationTable();

        // ASI-05: phpunit MUST NOT touch the live ledger. Point the scanner at
        // a per-test tmp dir; the config was designed to be env-swappable
        // exactly for this reason.
        $this->ledgerRoot = sys_get_temp_dir().'/atlas-maxh03-'.Str::random(8);
        @mkdir($this->ledgerRoot, 0755, true);
        config(['atlas.memory_consolidation.ledger_root' => $this->ledgerRoot]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Schema::dropIfExists('atlas_memory_entry_relations');
        $this->dropAtlasMemoryEntryTable();
        $this->deleteTree($this->ledgerRoot);

        parent::tearDown();
    }

    public function test_scanner_emits_verdict_distribution_when_pair_set_is_qualified_and_writes_zero_relation_rows(): void
    {
        $entries = $this->seedPairedCorpus(pairs: 8);

        $scores = $this->stubScoresForPairs($entries, threshold: 0.9);
        $scanner = new MemoryConsolidationScanner(new StubMemoryPairwiseCosineScorer($scores));

        $report = $scanner->scan(MemoryConsolidationScanner::MODE_OBSERVE);

        $this->assertSame('qualified', $report['status']);
        $this->assertSame(MemoryConsolidationScanner::SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame(16, $report['active_count']);
        $this->assertGreaterThanOrEqual(12, $report['pairs_evaluated'], 'ELEV-06: non-trivial set requires >= 12 pairs.');
        $this->assertSame(0, $report['relations_written'], 'Observe mode must never persist a relation row.');
        $this->assertGreaterThanOrEqual(2, $report['non_degenerate_verb_count'], 'Distribution must span >= 2 non-degenerate verbs.');

        $counts = (array) $report['verdict_distribution'];
        // Within-pair verdicts: same title => key match. Even i => scope
        // mismatch => `scoped`. Odd i => same scope same polarity => `compatible`.
        $this->assertGreaterThanOrEqual(4, $counts[AtlasMemoryConflictResolutionService::VERDICT_SCOPED]);
        $this->assertGreaterThanOrEqual(4, $counts[AtlasMemoryConflictResolutionService::VERDICT_COMPATIBLE]);

        // Byte-check the LIVE relation table: nothing was ever inserted.
        $this->assertSame(0, AtlasMemoryEntryRelation::query()->count());

        // Ledger under sys_get_temp_dir(), never the live ASI-05 dir.
        $ledgerPath = (string) $report['ledger_path'];
        $this->assertNotSame('', $ledgerPath);
        $this->assertFileExists($ledgerPath);
        $this->assertStringStartsWith($this->ledgerRoot, $ledgerPath);
    }

    public function test_scanner_below_min_pairs_is_reported_as_insufficient_signal_never_qualified_at_one_pair(): void
    {
        $entries = $this->seedPairedCorpus(pairs: 2);
        $scores = $this->stubScoresForPairs($entries, threshold: 0.9);
        $scanner = new MemoryConsolidationScanner(new StubMemoryPairwiseCosineScorer($scores));

        $report = $scanner->scan(MemoryConsolidationScanner::MODE_OBSERVE);

        $this->assertLessThan(12, $report['pairs_evaluated']);
        $this->assertSame(
            'insufficient_signal',
            $report['status'],
            'ELEV-06: a two-pair scan MUST NOT report qualified — the freeze pins min_pairs = 12.',
        );
        $this->assertSame(0, $report['relations_written']);
    }

    public function test_scanner_short_circuits_when_scorer_is_unavailable_and_never_fabricates_similarity(): void
    {
        $this->seedPairedCorpus(pairs: 8);
        $scanner = new MemoryConsolidationScanner(new UnavailableStubMemoryPairwiseCosineScorer);

        $report = $scanner->scan(MemoryConsolidationScanner::MODE_OBSERVE);

        $this->assertSame('unavailable', $report['similarity_source']);
        $this->assertSame(0, $report['pairs_evaluated']);
        $this->assertSame('insufficient_signal', $report['status']);
        $this->assertSame(0, $report['relations_written']);
    }

    public function test_scanner_verdict_is_deterministic_and_pair_hash_is_symmetric(): void
    {
        $entries = $this->seedPairedCorpus(pairs: 8);
        $scores = $this->stubScoresForPairs($entries, threshold: 0.9);
        $scanner = new MemoryConsolidationScanner(new StubMemoryPairwiseCosineScorer($scores));

        $first = $scanner->scan(MemoryConsolidationScanner::MODE_OBSERVE);
        $second = $scanner->scan(MemoryConsolidationScanner::MODE_OBSERVE);

        $this->assertSame(
            array_column($first['proposals'], 'pair_hash'),
            array_column($second['proposals'], 'pair_hash'),
            'Same corpus + same stub scorer => same proposal hashes (determinism).',
        );

        // pair_hash is derived from the LEXICOGRAPHICALLY sorted ids, so it
        // is symmetric under source/target swap and always matches the
        // canonical hash the scanner would compute.
        foreach ($first['proposals'] as $proposal) {
            $ordered = [$proposal['source_id'], $proposal['target_id']];
            sort($ordered);
            $canonical = hash('sha256', $ordered[0].'|'.$ordered[1]);
            $this->assertSame($canonical, $proposal['pair_hash']);
        }
    }

    public function test_scanner_command_emits_json_and_never_touches_live_relation_table(): void
    {
        $entries = $this->seedPairedCorpus(pairs: 8);
        $scores = $this->stubScoresForPairs($entries, threshold: 0.9);

        // Bind the stub globally so the artisan command picks it up.
        $this->app->instance(MemoryPairwiseCosineScorer::class, new StubMemoryPairwiseCosineScorer($scores));

        $exit = Artisan::call('atlas:memory:consolidation-scan', [
            '--observe' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('observe', data_get($payload, 'memory_consolidation_scan.mode'));
        $this->assertSame('qualified', data_get($payload, 'memory_consolidation_scan.status'));
        $this->assertSame(0, data_get($payload, 'memory_consolidation_scan.relations_written'));
        $this->assertSame(0, AtlasMemoryEntryRelation::query()->count());
    }

    public function test_enforce_applies_reversible_non_high_risk_supersedence(): void
    {
        $older = $this->memory('runtime timeout policy', [
            'memory_type' => 'technical_context',
            'recorded_at' => CarbonImmutable::now()->subDay()->toIso8601String(),
            'metadata' => ['polarity' => 'affirm'],
        ]);
        $newer = $this->memory('runtime timeout policy', [
            'memory_type' => 'technical_context',
            'recorded_at' => CarbonImmutable::now()->toIso8601String(),
            'metadata' => ['polarity' => 'negate'],
        ]);
        $scanner = new MemoryConsolidationScanner(new StubMemoryPairwiseCosineScorer([
            (string) $older->summary => [(string) $newer->id => 0.91],
            (string) $newer->summary => [(string) $older->id => 0.91],
        ]));

        $report = $scanner->scan(MemoryConsolidationScanner::MODE_ENFORCE);

        $this->assertSame(1, $report['relations_written']);
        $this->assertSame(1, data_get($report, 'enforce.applied'));
        $this->assertSame(0, data_get($report, 'enforce.review_bucket'));
        $older->refresh();
        $this->assertSame((string) $newer->id, (string) $older->superseded_by_id);
        $this->assertNotNull($older->valid_until);

        $handle = (string) data_get($report, 'enforce.applications.0.reverse_handle');
        $this->assertNotSame('', $handle);
        $reverted = $scanner->reverseApplication($handle);
        $this->assertTrue($reverted['ok']);
        $older->refresh();
        $this->assertNull($older->superseded_by_id);
        $this->assertNull($older->valid_until);
    }

    public function test_enforce_holds_high_risk_supersedence_for_digest_review(): void
    {
        $older = $this->memory('architecture contract', [
            'memory_type' => 'decision',
            'recorded_at' => CarbonImmutable::now()->subDay()->toIso8601String(),
            'metadata' => ['polarity' => 'affirm'],
        ]);
        $newer = $this->memory('architecture contract', [
            'memory_type' => 'decision',
            'recorded_at' => CarbonImmutable::now()->toIso8601String(),
            'metadata' => ['polarity' => 'negate'],
        ]);
        $scanner = new MemoryConsolidationScanner(new StubMemoryPairwiseCosineScorer([
            (string) $older->summary => [(string) $newer->id => 0.91],
            (string) $newer->summary => [(string) $older->id => 0.91],
        ]));

        $report = $scanner->scan(MemoryConsolidationScanner::MODE_ENFORCE);

        $this->assertSame(0, $report['relations_written']);
        $this->assertSame(0, data_get($report, 'enforce.applied'));
        $this->assertSame(1, data_get($report, 'enforce.review_bucket'));
        $older->refresh();
        $this->assertNull($older->superseded_by_id);
    }

    /**
     * @return list<AtlasMemoryEntry>
     */
    private function seedPairedCorpus(int $pairs): array
    {
        $entries = [];
        // Each "logical pair" shares the same title so the verb classifier
        // picks a non-not_conflict verdict. Different pairs carry different
        // titles so the distribution has more than one non-degenerate verb.
        for ($i = 0; $i < $pairs; $i++) {
            $baseTitle = 'canonical-fact-'.$i;
            $entries[] = $this->memory($baseTitle, [
                'scope_type' => 'global',
                'recorded_at' => CarbonImmutable::now()->subDays($i)->toIso8601String(),
            ]);
            $entries[] = $this->memory($baseTitle, [
                'scope_type' => $i % 2 === 0 ? 'project' : 'global',
                'recorded_at' => CarbonImmutable::now()->subDays($i)->subHour()->toIso8601String(),
            ]);
        }

        return $entries;
    }

    /**
     * @param  list<AtlasMemoryEntry>  $entries
     * @return array<string,array<string,float>> query text => target id => cosine
     */
    private function stubScoresForPairs(array $entries, float $threshold): array
    {
        // Every entry has a unique summary of the form
        // `consolidation fixture <title>`. The stub keys by that summary so it
        // can return the row for the CURRENT source no matter the pair order.
        $scores = [];
        foreach ($entries as $source) {
            $query = (string) $source->getAttribute('summary');
            $row = [];
            foreach ($entries as $target) {
                if ($target->id === $source->id) {
                    continue;
                }
                $row[(string) $target->id] = $threshold;
            }
            $scores[$query] = $row;
        }

        return $scores;
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function memory(string $title, array $overrides = []): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => $title,
            'body' => 'consolidation fixture '.$title,
            'summary' => 'consolidation fixture '.$title,
            'confidence' => 0.9,
            'source_type' => 'test',
            'status' => 'active',
            'metadata' => [],
            'recorded_at' => CarbonImmutable::now(),
        ], $overrides));
    }

    private function createRelationTable(): void
    {
        Schema::dropIfExists('atlas_memory_entry_relations');
        Schema::create('atlas_memory_entry_relations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_memory_entry_id')->index();
            $table->uuid('target_memory_entry_id')->index();
            $table->string('relation_type', 40)->index();
            $table->string('status', 24)->default('open')->index();
            $table->float('confidence')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->default('{}');
            $table->string('marked_by_actor', 32)->nullable()->index();
            $table->string('marked_by_model', 128)->nullable();
            $table->string('judgment_status', 24)->default('pending')->index();
            $table->json('evidence_refs')->nullable();
            $table->string('verdict_schema_version', 64)->default('atlas.memory.relation_verdict.v1');
            $table->timestamps();
        });
    }

    private function deleteTree(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }
        $items = scandir($root) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $root.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }
        @rmdir($root);
    }
}

/**
 * Stub pgvector-free adapter: returns the pre-computed cosine map exactly as
 * declared by the test, no fabrication. Score rows are keyed by the query
 * text the scanner passes in, so the map is source-scoped without leaking
 * across pairs.
 *
 * @internal
 */
final class StubMemoryPairwiseCosineScorer implements MemoryPairwiseCosineScorer
{
    /** @param array<string,array<string,float>> $scoresByQuery query text => target id => cosine */
    public function __construct(private readonly array $scoresByQuery) {}

    /**
     * @param  array<int,string>  $ids
     * @return array<string,float>
     */
    public function scoreEntries(string $query, array $ids): array
    {
        $row = $this->scoresByQuery[$query] ?? [];

        return array_intersect_key($row, array_flip($ids));
    }

    public function available(): bool
    {
        return true;
    }
}

/**
 * Stub that reports "unavailable" — proves the scanner never fabricates
 * cosine scores when pgvector is absent.
 *
 * @internal
 */
final class UnavailableStubMemoryPairwiseCosineScorer implements MemoryPairwiseCosineScorer
{
    /**
     * @param  array<int,string>  $ids
     * @return array<string,float>
     */
    public function scoreEntries(string $query, array $ids): array
    {
        return [];
    }

    public function available(): bool
    {
        return false;
    }
}
