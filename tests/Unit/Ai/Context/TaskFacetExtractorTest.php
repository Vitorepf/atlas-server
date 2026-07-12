<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\TaskFacetExtractor;
use Tests\TestCase;

class TaskFacetExtractorTest extends TestCase
{
    public function test_extracts_typed_facets_from_symbol_and_path_and_command(): void
    {
        $extractor = new TaskFacetExtractor;

        $result = $extractor->extract(
            'Investigate App\\Services\\Ai\\AtlasOpenBrainContextPackService::packFor and app/Services/Ai/Context/TaskFacetExtractor.php running atlas:context:pack'
        );

        $types = array_column($result['facets'], 'type');
        $values = array_column($result['facets'], 'value');

        $this->assertContains(TaskFacetExtractor::FACET_SYMBOL, $types);
        $this->assertContains(TaskFacetExtractor::FACET_PATH, $types);
        $this->assertContains(TaskFacetExtractor::FACET_COMMAND, $types);

        $this->assertContains('App\\Services\\Ai\\AtlasOpenBrainContextPackService::packFor', $values);
        $this->assertContains('atlas:context:pack', $values);
        $this->assertContains('app/Services/Ai/Context/TaskFacetExtractor.php', $values);
    }

    public function test_extracts_quoted_phrase_and_marks_essential(): void
    {
        $extractor = new TaskFacetExtractor;

        $result = $extractor->extract('Track "context sufficiency" regression and fix it.');
        $phraseFacets = array_values(array_filter(
            $result['facets'],
            static fn (array $f): bool => $f['type'] === TaskFacetExtractor::FACET_PHRASE,
        ));

        $this->assertNotEmpty($phraseFacets);
        $this->assertSame('context sufficiency', $phraseFacets[0]['value']);
        $this->assertTrue($phraseFacets[0]['essential']);
    }

    public function test_dedupes_repeated_values_case_insensitively(): void
    {
        $extractor = new TaskFacetExtractor;

        $result = $extractor->extract('AtlasOpenBrainContextPackService AtlasOpenBrainContextPackService atlasopenbraincontextpackservice');

        $symbols = array_values(array_filter(
            $result['facets'],
            static fn (array $f): bool => $f['type'] === TaskFacetExtractor::FACET_SYMBOL,
        ));

        $this->assertCount(1, $symbols, 'Symbol facet should be deduped case-insensitively.');
    }

    public function test_empty_task_returns_empty_result(): void
    {
        $extractor = new TaskFacetExtractor;

        $result = $extractor->extract('   ');

        $this->assertSame([], $result['facets']);
        $this->assertSame([], $result['counts']);
        $this->assertSame('', $result['raw_task']);
    }

    public function test_class_scope_method_symbol_is_captured_as_essential(): void
    {
        $extractor = new TaskFacetExtractor;

        $result = $extractor->extract('Look at MemoryRecall::execute in the pipeline');
        $symbols = array_values(array_filter(
            $result['facets'],
            static fn (array $f): bool => $f['type'] === TaskFacetExtractor::FACET_SYMBOL,
        ));

        $this->assertNotEmpty($symbols);
        $values = array_column($symbols, 'value');
        $this->assertContains('MemoryRecall::execute', $values);
    }
}
