<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\AtlasLoopFactAnchorExtractor;
use Tests\TestCase;

final class AtlasLoopFactAnchorExtractorTest extends TestCase
{
    private string $root = '';

    private string $realFile = 'app/Demo/Foo.php';

    private string $realFilePath = '';

    private int $realFileLines = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-anchor-extractor-'.bin2hex(random_bytes(6));
        @mkdir($this->root.'/app/Demo', 0o755, true);
        $this->realFilePath = $this->root.'/'.$this->realFile;
        $content = "<?php\n\nnamespace App\\Demo;\n\nfinal class Foo\n{\n    public function bar(): int\n    {\n        return 42;\n    }\n}\n";
        file_put_contents($this->realFilePath, $content);
        $this->realFileLines = count(file($this->realFilePath) ?: []);
    }

    protected function tearDown(): void
    {
        @unlink($this->realFilePath);
        @rmdir($this->root.'/app/Demo');
        @rmdir($this->root.'/app');
        @rmdir($this->root);
        parent::tearDown();
    }

    private function extractor(): AtlasLoopFactAnchorExtractor
    {
        return new AtlasLoopFactAnchorExtractor($this->root);
    }

    public function test_file_line_anchor_is_resolved_with_exact_line(): void
    {
        $text = 'See '.$this->realFile.':3 for the namespace.';
        $verdict = $this->extractor()->extract($text);

        $this->assertTrue($verdict->isAnchored());
        $row = $verdict->resolved[0];
        $this->assertSame($this->realFile, $row['file']);
        $this->assertSame(3, $row['line']);
    }

    public function test_file_line_anchor_is_resolved_with_line_out_of_range_flag(): void
    {
        $out = $this->realFileLines + 1000;
        $verdict = $this->extractor()->extract('See '.$this->realFile.':'.$out.' (phantom line)');

        $this->assertTrue($verdict->isAnchored());
        $found = false;
        foreach ($verdict->resolved as $row) {
            if (($row['line'] ?? null) === $out) {
                $this->assertSame($this->realFile, $row['file']);
                $this->assertTrue($row['line_out_of_range'] ?? false);
                $found = true;
            }
        }
        $this->assertTrue($found, 'must surface the file even when the line is out of range');
    }

    public function test_file_line_range_is_resolved(): void
    {
        $verdict = $this->extractor()->extract('See '.$this->realFile.':3-6 for the class body.');
        $row = null;
        foreach ($verdict->resolved as $r) {
            if (($r['kind'] ?? '') === 'file_line_range') {
                $row = $r;
                break;
            }
        }
        $this->assertNotNull($row);
        $this->assertSame(3, $row['line_start']);
        $this->assertSame(6, $row['line_end']);
    }

    public function test_bare_file_path_is_resolved(): void
    {
        $verdict = $this->extractor()->extract('Touched '.$this->realFile.' today.');
        $found = false;
        foreach ($verdict->resolved as $row) {
            if (($row['kind'] ?? '') === 'file_path' && $row['file'] === $this->realFile) {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function test_class_and_method_symbols_are_resolved(): void
    {
        $verdict = $this->extractor()->extract('Run App\\Demo\\Foo::bar() to compute.');
        $symbols = array_column(array_filter($verdict->resolved, static fn (array $r): bool => $r['kind'] === 'php_symbol'), 'symbol');
        $this->assertContains('App\\Demo\\Foo::bar', $symbols);
    }

    public function test_mixed_refs_are_all_extracted_and_duplicates_collapsed(): void
    {
        $text = 'See '.$this->realFile.':3 and again '.$this->realFile.':3 plus App\\Demo\\Foo::bar twice: App\\Demo\\Foo::bar';
        $verdict = $this->extractor()->extract($text);

        // file:3 appears twice but must be collapsed to one resolved entry.
        $fileLineEntries = array_filter($verdict->resolved, static fn (array $r): bool => ($r['kind'] ?? '') === 'file_line' && ($r['line'] ?? 0) === 3);
        $this->assertCount(1, $fileLineEntries);

        $symbolEntries = array_filter($verdict->resolved, static fn (array $r): bool => ($r['kind'] ?? '') === 'php_symbol' && ($r['symbol'] ?? '') === 'App\\Demo\\Foo::bar');
        $this->assertCount(1, $symbolEntries);
    }

    public function test_phantom_file_paths_are_unresolved_not_resolved(): void
    {
        $verdict = $this->extractor()->extract('See app/Phantom/DoesNotExist.php and lib/also-missing.js');

        $this->assertFalse($verdict->isAnchored(), 'phantom refs MUST NOT count as anchored');
        $this->assertGreaterThan(0, $verdict->unresolvedCount());
        $this->assertSame(0, $verdict->resolvedCount());
    }

    public function test_pure_narrative_with_no_refs_returns_empty_anchor_list(): void
    {
        $verdict = $this->extractor()->extract('the daemon kept running and learned to schedule retries.');
        $this->assertFalse($verdict->isAnchored());
        $this->assertSame(0, $verdict->resolvedCount());
        $this->assertSame(0, $verdict->unresolvedCount());
    }

    public function test_multidigit_range_yields_one_range_anchor_no_fabricated_single_line(): void
    {
        $verdict = $this->extractor()->extract('See '.$this->realFile.':10-20 for the body.');

        $rangeAnchors = array_filter($verdict->resolved, static fn (array $r): bool => ($r['kind'] ?? '') === 'file_line_range');
        $lineAnchors = array_filter($verdict->resolved, static fn (array $r): bool => ($r['kind'] ?? '') === 'file_line');

        $this->assertCount(1, $rangeAnchors, 'exactly one range anchor');
        $this->assertCount(0, $lineAnchors, 'no fabricated single-line anchor from backtracking');

        $rangeAnchor = array_values($rangeAnchors)[0];
        $this->assertSame(10, $rangeAnchor['line_start']);
        $this->assertSame(20, $rangeAnchor['line_end']);
    }
}
