<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\PackSufficiencyBlockBuilder;
use App\Services\Ai\Context\TaskFacetExtractor;
use Tests\TestCase;

class PackSufficiencyBlockBuilderTest extends TestCase
{
    public function test_essential_symbol_missing_sets_not_enough_context(): void
    {
        $extractor = new TaskFacetExtractor;
        $builder = new PackSufficiencyBlockBuilder;

        $facets = $extractor->extract('Find MissingSymbolXYZ implementation')['facets'];

        $block = $builder->build($facets, [], [], []);

        $this->assertTrue($block['not_enough_context']);
        $this->assertNotEmpty($block['missing_essential']);
        $this->assertContains('expand:symbol:MissingSymbolXYZ', $block['handles']);
    }

    public function test_covered_facet_reports_source_and_no_gap(): void
    {
        $extractor = new TaskFacetExtractor;
        $builder = new PackSufficiencyBlockBuilder;

        $facets = $extractor->extract('Check KnownClass usage')['facets'];

        $block = $builder->build(
            $facets,
            [['symbol' => 'App\\Services\\KnownClass', 'file_path' => 'app/Services/KnownClass.php']],
            [],
            [],
        );

        $this->assertFalse($block['not_enough_context']);
        $this->assertSame([], $block['missing_essential']);
        $this->assertNotEmpty($block['facets']);
        $covered = array_values(array_filter(
            $block['facets'],
            static fn (array $f): bool => $f['value'] === 'KnownClass',
        ));
        $this->assertNotEmpty($covered);
        $this->assertContains('code_graph', $covered[0]['sources']);
    }

    public function test_empty_facets_returns_absent_block(): void
    {
        $block = (new PackSufficiencyBlockBuilder)->build([], [], [], []);

        $this->assertFalse($block['present']);
        $this->assertFalse($block['not_enough_context']);
    }

    public function test_block_never_writes_context_sufficiency_scalar(): void
    {
        $extractor = new TaskFacetExtractor;
        $builder = new PackSufficiencyBlockBuilder;

        $facets = $extractor->extract('SymbolA and SymbolB')['facets'];
        $block = $builder->build($facets, [], [], []);

        $flat = json_encode($block, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($flat);
        $this->assertStringNotContainsString('context_sufficiency', $flat, 'MAXC-02: sufficiency block must never carry the fabricated scalar');
    }
}
