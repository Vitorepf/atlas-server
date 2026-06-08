<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compression;

use App\Services\Ai\Compression\Compressors\SearchCompressor;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test for {@see SearchCompressor} (AP-813). No DB / container / I/O.
 *
 * Proves the quality contract: detection is conservative, redundant bulk shrinks,
 * and — the crux — every error / anomaly / structurally-unique hit survives verbatim
 * (the lossless-signal property). Small/non-matching input passes through unchanged,
 * and the transform is deterministic.
 */
final class SearchCompressorTest extends TestCase
{
    private function compressor(): SearchCompressor
    {
        return new SearchCompressor;
    }

    /** Options mirroring what CompressionPipeline feeds compressors. */
    private function options(int $maxKeep = 40): array
    {
        return ['keep_head' => 8, 'keep_tail' => 4, 'max_keep' => $maxKeep, 'min_block_chars' => 0];
    }

    public function test_content_type_is_search(): void
    {
        $this->assertSame('search', $this->compressor()->contentType());
    }

    public function test_detect_true_on_grep_shaped_block(): void
    {
        $block = implode("\n", [
            'src/Foo.php:10:    public function handle(): void',
            'src/Foo.php:42:        return $this->run();',
            'src/Bar.php:7:    use SomeTrait;',
        ]);

        $this->assertTrue($this->compressor()->detect($block));
    }

    public function test_detect_true_on_ripgrep_with_colons_in_path_and_content(): void
    {
        // Windows-style drive colon in path + colons in content must still parse.
        $block = implode("\n", [
            'C:/proj/app.php:10:url = http://localhost:8080/health',
            'C:/proj/app.php:11:url = http://localhost:8081/ready',
            'C:/proj/app.php:12:url = http://localhost:8082/live',
        ]);

        $this->assertTrue($this->compressor()->detect($block));
    }

    public function test_detect_false_on_prose(): void
    {
        $block = "This is a normal paragraph of text.\nIt has several lines.\nBut none look like grep output.";

        $this->assertFalse($this->compressor()->detect($block));
    }

    public function test_detect_false_on_too_few_lines(): void
    {
        $block = "only/one.php:1:hit\nother/two.php:2:hit";

        $this->assertFalse($this->compressor()->detect($block));
    }

    public function test_detect_false_when_minority_match(): void
    {
        // 2 matches out of 5 lines → not a strict majority.
        $block = implode("\n", [
            'a/file.php:1:match here',
            'just some prose line',
            'another prose line',
            'yet more prose',
            'b/file.php:2:second match',
        ]);

        $this->assertFalse($this->compressor()->detect($block));
    }

    public function test_compress_shrinks_large_redundant_block(): void
    {
        $block = $this->buildRedundantBlock();
        $res = $this->compressor()->compress($block, $this->options());

        $this->assertTrue($res->changed, 'redundant block should compress');
        $this->assertLessThan($res->originalChars, $res->compressedChars, 'output must be strictly smaller');
        $this->assertSame('search', $res->contentType);

        // Honest item-based meta for the marker.
        $this->assertArrayHasKey('items_total', $res->meta);
        $this->assertArrayHasKey('items_kept', $res->meta);
        $this->assertArrayHasKey('files', $res->meta);
        $this->assertGreaterThan($res->meta['items_kept'], $res->meta['items_total']);

        // The omission note is left behind, honestly counting what was folded.
        $this->assertMatchesRegularExpression('/\[\.\.\. \d+ more matches in .+ \.\.\.\]/', $res->output);
    }

    /**
     * THE LOSSLESS-SIGNAL PROPERTY: every error / anomaly / structurally-unique hit
     * from the input still appears verbatim in the compressed output.
     */
    public function test_keeps_every_anomaly_and_unique_hit_verbatim(): void
    {
        $lines = [];

        // A big, homogeneous, provably-redundant bulk (one symbol on many sites).
        for ($i = 1; $i <= 60; $i++) {
            $lines[] = "src/Service.php:{$i}:        \$this->logger->info('processing item');";
        }

        // Load-bearing signal buried in the middle — MUST all survive verbatim.
        $anomalies = [
            'src/Service.php:61:        throw new RuntimeException("payment gateway timeout");',
            'src/Service.php:62:        // FIXME: race condition on the shared cursor',
            'src/Worker.php:5:        $this->logger->error("failed to deserialize job");',
            'src/Worker.php:6:        if ($status === 403) { /* forbidden */ }',
        ];
        // Insert anomalies into the middle of the bulk so they are not first/last.
        array_splice($lines, 30, 0, $anomalies);

        // A structurally-unique hit (no near-duplicate sibling) in another file.
        $unique = 'src/Unique.php:99:        return hash_hmac("sha512", $payload, $secretKeyMaterial);';
        $lines[] = $unique;

        // More homogeneous bulk after, to force middle-dropping.
        for ($i = 100; $i <= 150; $i++) {
            $lines[] = "src/Service.php:{$i}:        \$this->logger->info('processing item');";
        }

        $block = implode("\n", $lines);
        $compressor = $this->compressor();

        $this->assertTrue($compressor->detect($block));
        $res = $compressor->compress($block, $this->options());
        $this->assertTrue($res->changed, 'should have dropped redundant bulk');

        foreach ($anomalies as $anomaly) {
            $this->assertStringContainsString(
                $anomaly,
                $res->output,
                'every anomaly hit must survive verbatim: '.$anomaly
            );
        }
        $this->assertStringContainsString($unique, $res->output, 'structurally-unique hit must survive verbatim');

        // Every distinct file must remain represented (none silently dropped).
        $this->assertStringContainsString('src/Service.php:', $res->output);
        $this->assertStringContainsString('src/Worker.php:', $res->output);
        $this->assertStringContainsString('src/Unique.php:', $res->output);

        // And it actually shrank.
        $this->assertLessThan($res->originalChars, $res->compressedChars);
    }

    public function test_every_distinct_file_stays_represented(): void
    {
        $lines = [];
        // Five files, each with many near-identical hits.
        foreach (['alpha', 'beta', 'gamma', 'delta', 'epsilon'] as $name) {
            for ($i = 1; $i <= 20; $i++) {
                $lines[] = "src/{$name}.php:{$i}:        use App\\Shared\\CommonTrait;";
            }
        }
        $block = implode("\n", $lines);

        $res = $this->compressor()->compress($block, $this->options(12));
        $this->assertTrue($res->changed);

        foreach (['alpha', 'beta', 'gamma', 'delta', 'epsilon'] as $name) {
            $this->assertStringContainsString("src/{$name}.php:", $res->output, "file {$name} must stay represented");
        }
        $this->assertSame(5, $res->meta['files']);
    }

    public function test_small_block_returns_unchanged(): void
    {
        $block = "a/b.php:1:tiny\nc/d.php:2:tiny\ne/f.php:3:tiny";
        $res = $this->compressor()->compress($block, $this->options());

        // Nothing droppable (each file has a single hit) → unchanged, byte-identical.
        $this->assertFalse($res->changed);
        $this->assertSame($block, $res->output);
    }

    public function test_empty_block_returns_unchanged(): void
    {
        $res = $this->compressor()->compress('', $this->options());

        $this->assertFalse($res->changed);
        $this->assertSame('', $res->output);
    }

    public function test_non_matching_block_returns_unchanged(): void
    {
        $block = "just\nsome\nprose\nwith no grep shape at all";
        $res = $this->compressor()->compress($block, $this->options());

        $this->assertFalse($res->changed);
        $this->assertSame($block, $res->output);
    }

    public function test_is_deterministic(): void
    {
        $block = $this->buildRedundantBlock();
        $compressor = $this->compressor();

        $a = $compressor->compress($block, $this->options());
        $b = $compressor->compress($block, $this->options());

        $this->assertSame($a->output, $b->output);
        $this->assertSame($a->changed, $b->changed);
        $this->assertSame($a->meta, $b->meta);
    }

    public function test_preserves_original_file_order(): void
    {
        $lines = [];
        foreach (['zzz', 'aaa', 'mmm'] as $name) {
            for ($i = 1; $i <= 15; $i++) {
                $lines[] = "src/{$name}.php:{$i}:        \$x = doThing();";
            }
        }
        $block = implode("\n", $lines);

        $res = $this->compressor()->compress($block, $this->options(9));
        $this->assertTrue($res->changed);

        // First appearance of each file, in output, must follow input order zzz<aaa<mmm.
        $posZ = strpos($res->output, 'src/zzz.php:');
        $posA = strpos($res->output, 'src/aaa.php:');
        $posM = strpos($res->output, 'src/mmm.php:');

        $this->assertNotFalse($posZ);
        $this->assertNotFalse($posA);
        $this->assertNotFalse($posM);
        $this->assertLessThan($posA, $posZ);
        $this->assertLessThan($posM, $posA);
    }

    public function test_preserves_non_match_separator_lines(): void
    {
        // ripgrep emits `--` group separators; they are not grep hits but are signal.
        $lines = [];
        for ($i = 1; $i <= 30; $i++) {
            $lines[] = "src/Foo.php:{$i}:        \$this->same();";
        }
        $lines[] = '--';
        for ($i = 1; $i <= 30; $i++) {
            $lines[] = "src/Bar.php:{$i}:        \$this->same();";
        }
        $block = implode("\n", $lines);

        $res = $this->compressor()->compress($block, $this->options());
        $this->assertTrue($res->changed);
        $this->assertStringContainsString("--", $res->output, 'non-match separator lines must be preserved verbatim');
    }

    /**
     * A grep dump: one file with one rare hit + a huge homogeneous bulk, plus a few
     * other files. Designed to be clearly compressible.
     */
    private function buildRedundantBlock(): string
    {
        $lines = [];
        for ($i = 1; $i <= 80; $i++) {
            $lines[] = "src/Big.php:{$i}:        \$this->repeat();";
        }
        for ($i = 1; $i <= 30; $i++) {
            $lines[] = "src/Mid.php:{$i}:        \$this->repeat();";
        }
        $lines[] = 'src/Tiny.php:1:        \$only = true;';

        return implode("\n", $lines);
    }
}
