<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphMissingLanguageProposer;
use PHPUnit\Framework\TestCase;

class CodeGraphMissingLanguageProposerTest extends TestCase
{
    public function test_uncovered_extensions_are_proposed_ranked_by_count(): void
    {
        $counts = [
            'php' => 9000,   // covered -> omitted
            'py' => 120,     // covered -> omitted
            'rs' => 800,     // uncovered, most files
            'go' => 300,     // uncovered, middle
            'rb' => 50,      // uncovered, fewest
        ];

        $result = (new CodeGraphMissingLanguageProposer)->propose($counts, ['php', 'py']);

        $this->assertSame(CodeGraphMissingLanguageProposer::SCHEMA, $result['schema_version']);

        $extensions = array_column($result['proposals'], 'extension');
        $this->assertSame(['rs', 'go', 'rb'], $extensions, 'ranked by file_count desc');

        // Every proposal is an add_extractor with a 1-based priority following rank.
        foreach ($result['proposals'] as $i => $proposal) {
            $this->assertSame(CodeGraphMissingLanguageProposer::PROPOSAL_ADD_EXTRACTOR, $proposal['proposal']);
            $this->assertSame($i + 1, $proposal['priority']);
        }

        $top = $result['proposals'][0];
        $this->assertSame('rs', $top['extension']);
        $this->assertSame(800, $top['file_count']);
        $this->assertSame(1, $top['priority']);
    }

    public function test_covered_extensions_are_omitted(): void
    {
        $counts = ['php' => 5000, 'py' => 2000, 'ts' => 999];

        $result = (new CodeGraphMissingLanguageProposer)->propose($counts, ['php', 'py', 'ts']);

        $this->assertSame([], $result['proposals'], 'all extensions covered => nothing to propose');
        $this->assertSame(3, $result['stats']['covered']);
        $this->assertSame(0, $result['stats']['uncovered']);
    }

    public function test_count_ties_break_by_extension_name_ascending(): void
    {
        $counts = ['zig' => 100, 'awk' => 100, 'lua' => 100];

        $result = (new CodeGraphMissingLanguageProposer)->propose($counts, []);

        $this->assertSame(['awk', 'lua', 'zig'], array_column($result['proposals'], 'extension'));
        $this->assertSame([1, 2, 3], array_column($result['proposals'], 'priority'));
    }

    public function test_invalid_and_nonpositive_counts_are_skipped(): void
    {
        $counts = [
            'rs' => 40,
            'go' => 0,        // non-positive -> skipped
            'rb' => -5,       // negative -> skipped
            'lua' => 'nope',  // non-numeric -> skipped
            'py' => '12',     // numeric-string -> counted (12)
        ];

        $result = (new CodeGraphMissingLanguageProposer)->propose($counts, []);

        $extensions = array_column($result['proposals'], 'extension');
        $this->assertSame(['rs', 'py'], $extensions, 'only valid positive counts ranked');
        $this->assertSame(12, $result['proposals'][1]['file_count']);
        $this->assertSame(3, $result['stats']['skipped_invalid']);
    }

    public function test_extension_normalization_merges_duplicates_and_strips_dots(): void
    {
        // "*.rs", ".RS" and "rs" all normalize to "rs" and sum their counts.
        $counts = ['*.rs' => 30, '.RS' => 10, 'rs' => 5, '.PHP' => 9000];

        $result = (new CodeGraphMissingLanguageProposer)->propose($counts, ['php']);

        $this->assertCount(1, $result['proposals'], 'covered .PHP omitted, rs variants merged');
        $this->assertSame('rs', $result['proposals'][0]['extension']);
        $this->assertSame(45, $result['proposals'][0]['file_count']);
    }

    public function test_propose_is_deterministic_across_input_orderings(): void
    {
        $a = ['rs' => 800, 'go' => 300, 'rb' => 50, 'lua' => 50, 'php' => 9000];
        $b = ['php' => 9000, 'lua' => 50, 'rb' => 50, 'go' => 300, 'rs' => 800];

        $proposer = new CodeGraphMissingLanguageProposer;
        $resultA = $proposer->propose($a, ['php']);
        $resultB = $proposer->propose($b, ['php']);

        $this->assertSame($resultA, $resultB, 'output independent of input key ordering');
        $this->assertSame(['rs', 'go', 'lua', 'rb'], array_column($resultA['proposals'], 'extension'));
    }

    public function test_empty_inputs_yield_empty_proposals(): void
    {
        $result = (new CodeGraphMissingLanguageProposer)->propose([], []);

        $this->assertSame([], $result['proposals']);
        $this->assertSame(0, $result['stats']['extensions_seen']);
        $this->assertSame(0, $result['stats']['uncovered']);
    }
}
