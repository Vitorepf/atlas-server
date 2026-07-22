<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\UnifiedReceipts\AtlasLoopUnifiedReceiptChain;
use App\Services\Ai\AutonomousEvolution\UnifiedReceipts\AtlasLoopUnifiedReceiptExportManifest;
use App\Services\Ai\AutonomousEvolution\UnifiedReceipts\AtlasLoopUnifiedReceiptExporter;
use App\Services\Ai\AutonomousEvolution\UnifiedReceipts\AtlasLoopUnifiedReceiptVerifier;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Proves the offline-audit exporter: byte-identical determinism on identical inputs; manifest head_hash +
 * total_nodes match the source chain; the exporter NEVER recomputes hashes (a post-record tamper to
 * source_facts is carried verbatim, surfacing the tamper rather than hiding it); sinceHash window slices
 * correctly; overwrite refusal without force=true; the source proves no hash_* call against source_facts.
 */
final class AtlasLoopUnifiedReceiptExporterTest extends TestCase
{
    private string $chainFile;

    private string $exportPath;

    /** @var list<string> hashes of nodes in order */
    private array $nodeHashes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(6));
        $this->chainFile = sys_get_temp_dir().'/atlas_unified_export_chain_'.$tag.'.jsonl';
        $this->exportPath = sys_get_temp_dir().'/atlas_unified_export_out_'.$tag.'.jsonl';

        $chain = new AtlasLoopUnifiedReceiptChain($this->chainFile);
        for ($i = 1; $i <= 10; $i++) {
            $node = $chain->append([
                'source_ledger' => 'ledger_'.$i,
                'receipt_id' => 'r_'.$i,
                'facts' => ['n' => $i, 'tag' => 'fact_'.$i],
            ]);
            $this->nodeHashes[] = $node->node_hash;
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->chainFile, $this->exportPath, $this->exportPath.'_b'] as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
        parent::tearDown();
    }

    private function exporter(int $clock = 1_700_000_000_000_000): AtlasLoopUnifiedReceiptExporter
    {
        return new AtlasLoopUnifiedReceiptExporter(
            new AtlasLoopUnifiedReceiptChain($this->chainFile),
            static fn (): int => $clock,
        );
    }

    public function test_full_export_is_byte_identical_for_identical_inputs(): void
    {
        $this->exporter()->full($this->exportPath);
        $first = (string) hash_file('sha256', $this->exportPath);

        // Second export to a fresh path with the SAME injected clock — must hash identically.
        $other = $this->exportPath.'_b';
        $this->exporter()->full($other);
        $second = (string) hash_file('sha256', $other);

        $this->assertSame($first, $second, 'identical inputs ⇒ byte-identical export file');
    }

    public function test_manifest_head_hash_and_total_nodes_match_the_chain(): void
    {
        $manifest = $this->exporter()->full($this->exportPath);
        $chain = new AtlasLoopUnifiedReceiptChain($this->chainFile);

        $this->assertSame($chain->headHash(), $manifest->headHash);
        $this->assertSame(10, $manifest->totalNodes);

        // First line of the export IS the manifest, by construction.
        $first = (array) json_decode((string) file($this->exportPath)[0], true);
        $this->assertSame(AtlasLoopUnifiedReceiptExportManifest::VERSION, $first[AtlasLoopUnifiedReceiptExportManifest::MAGIC]);
        $this->assertSame($chain->headHash(), $first['head_hash']);
        $this->assertSame(10, $first['total_nodes']);
    }

    public function test_exporter_copies_stored_node_hash_verbatim_does_not_re_hash_source_facts(): void
    {
        // Tamper the source_facts_json of line 5 in the CHAIN file (post-record). The exporter must copy the
        // STORED node_hash verbatim — i.e. the exporter does NOT recompute. The exported node_hash should
        // therefore remain the original (pre-tamper) value; the source_facts in the export reflect the tamper.
        $lines = file($this->chainFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $line5 = (array) json_decode((string) $lines[4], true);
        $originalNodeHash = (string) $line5['node_hash'];
        $line5['source_facts_json'] = str_replace('"fact_5"', '"TAMPERED_FACT_5"', (string) $line5['source_facts_json']);
        // IMPORTANT: leave node_hash UNCHANGED on disk (the tamper of source_facts is what we want to expose).
        $lines[4] = (string) json_encode($line5, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->chainFile, implode("\n", $lines)."\n");

        $this->exporter()->full($this->exportPath);
        $exportLines = file($this->exportPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        // Line 0 = manifest, lines 1..10 = nodes 1..10 ⇒ line 5 (seq=5) is index 5.
        $exportedNode5 = (array) json_decode((string) $exportLines[5], true);
        $this->assertSame(5, $exportedNode5['seq']);
        $this->assertSame($originalNodeHash, $exportedNode5['node_hash'], 'node_hash is copied verbatim from chain — exporter must not re-hash');
        $this->assertStringContainsString('TAMPERED_FACT_5', (string) $exportedNode5['source_facts_json'], 'tampered source_facts_json is surfaced, not hidden');
    }

    public function test_since_hash_window_emits_only_nodes_after_the_given_head(): void
    {
        $headOfNode3 = $this->nodeHashes[2]; // seq 3 head
        $manifest = $this->exporter()->sinceHash($this->exportPath, $headOfNode3);

        $this->assertSame(7, $manifest->totalNodes, 'sinceHash(seq=3) ⇒ seqs 4..10');
        $this->assertSame('since_hash', $manifest->range['mode']);
        $this->assertSame($headOfNode3, $manifest->range['since_hash']);

        $lines = file($this->exportPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        // Manifest at line 0; nodes at 1..7
        $first = (array) json_decode((string) $lines[1], true);
        $last = (array) json_decode((string) $lines[7], true);
        $this->assertSame(4, $first['seq']);
        $this->assertSame(10, $last['seq']);
    }

    public function test_range_window_slices_inclusive(): void
    {
        $manifest = $this->exporter()->range($this->exportPath, 3, 7);
        $this->assertSame(5, $manifest->totalNodes, 'range(3,7) inclusive ⇒ 5 nodes');
        $this->assertSame(['mode' => 'range', 'from' => 3, 'to' => 7, 'since_hash' => null], $manifest->range);
    }

    public function test_overwrite_refused_unless_force_true(): void
    {
        $this->exporter()->full($this->exportPath);

        try {
            $this->exporter()->full($this->exportPath); // no force ⇒ refusal
            $this->fail('expected RuntimeException on overwrite without force');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refusing to overwrite', $e->getMessage());
        }

        // force=true succeeds.
        $this->exporter()->full($this->exportPath, force: true);
        $this->assertFileExists($this->exportPath);
    }

    public function test_exporter_source_does_not_hash_source_facts(): void
    {
        $reflection = new ReflectionClass(AtlasLoopUnifiedReceiptExporter::class);
        $source = (string) file_get_contents($reflection->getFileName());

        // Pétreo: the exporter must not call hash_*() on source_facts (or anything else). The only place
        // canonical JSON is computed is the canonicalJson helper; no hash_sha256/hash_hmac/hash_init calls.
        foreach (['hash_init', 'hash_update', 'hash_final', 'hash_hmac'] as $banned) {
            $this->assertStringNotContainsString($banned, $source, "exporter must not use $banned");
        }
        // hash() may appear only in copied field names like "head_hash"; verify no hash('sha256', ...) calls.
        $this->assertDoesNotMatchRegularExpression("/\\bhash\\s*\\(/", $source, 'exporter must not call hash() — it copies stored values verbatim');
    }

    public function test_exported_node_keys_match_chain_shape_for_round_trip(): void
    {
        $this->exporter()->full($this->exportPath);
        $lines = file($this->exportPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $node = (array) json_decode((string) $lines[1], true);

        $this->assertArrayHasKey('source_facts_json', $node, 'must emit source_facts_json, not source_facts');
        $this->assertArrayHasKey('recorded_at', $node, 'must emit recorded_at, not recorded_at_us');
        $this->assertArrayNotHasKey('source_facts', $node);
        $this->assertArrayNotHasKey('recorded_at_us', $node);
    }

    public function test_export_passes_back_through_chain_verifier_when_reconstituted(): void
    {
        // Round-trip: write the exported NODE lines (without the manifest) into a fresh chain file and run the
        // verifier. The verifier rebuilds payload_hash from source_facts_json+source_ledger+source_receipt_id
        // — which means the exporter MUST preserve those fields verbatim. Honest export ⇒ verifier ok.
        $this->exporter()->full($this->exportPath);
        $exportLines = file($this->exportPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        // Drop the manifest line (index 0); the rest are node lines.
        $nodes = array_slice($exportLines, 1);

        $reconstitutedPath = $this->exportPath.'_chain';
        file_put_contents($reconstitutedPath, implode("\n", $nodes)."\n");

        try {
            $report = (new AtlasLoopUnifiedReceiptVerifier($reconstitutedPath))->verify();
            $this->assertTrue($report->ok, 'round-trip integrity: exported file reconstitutes into a verifiable chain');
        } finally {
            @unlink($reconstitutedPath);
        }
    }
}
