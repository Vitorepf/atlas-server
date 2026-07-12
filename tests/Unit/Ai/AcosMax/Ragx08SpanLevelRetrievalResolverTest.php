<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\Context\AtlasCanonicalContextRef;
use App\Services\Ai\Context\SpanLevelRetrievalResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Ragx08SpanLevelRetrievalResolverTest extends TestCase
{
    #[Test]
    public function resolves_claim_to_exact_content_versioned_span_ref(): void
    {
        $memory = [
            'type' => 'decision',
            'title' => 'Scoped commits policy',
            'summary' => 'Provider-safe summary',
            'body' => 'Unrelated intro. The policy must enforce scoped commits only on local main. Final note.',
            'source_type' => 'memory_entry',
            'content_hash' => 'content-v1',
        ];
        $sourceText = "Scoped commits policy\nProvider-safe summary\nUnrelated intro. The policy must enforce scoped commits only on local main. Final note.";
        $expectedExcerpt = 'The policy must enforce scoped commits only on local main.';
        $start = strpos($sourceText, $expectedExcerpt);
        $this->assertIsInt($start);
        $end = $start + strlen($expectedExcerpt);
        $contentVersion = hash('sha256', $sourceText);
        $parentRef = AtlasCanonicalContextRef::fromMemoryItem($memory);

        $result = (new SpanLevelRetrievalResolver)->resolve([
            'memory' => [$memory],
            'retrieval_agenda' => [
                'claims' => [
                    ['claim' => 'The policy must enforce scoped commits only'],
                ],
            ],
        ]);

        $this->assertTrue($result['present']);
        $this->assertSame('atlas.aobg.span_level_retrieval.v1', $result['schema_version']);
        $this->assertSame('resolved', $result['claims'][0]['status']);
        $this->assertSame($parentRef, $result['claims'][0]['spans'][0]['parent_ref']);
        $this->assertSame($contentVersion, $result['claims'][0]['spans'][0]['content_version']);
        $this->assertSame($start, $result['claims'][0]['spans'][0]['start']);
        $this->assertSame($end, $result['claims'][0]['spans'][0]['end']);
        $this->assertSame($expectedExcerpt, $result['claims'][0]['spans'][0]['span_excerpt']);
        $this->assertSame(
            AtlasCanonicalContextRef::spanRef($parentRef, $contentVersion, $start, $end),
            $result['claims'][0]['spans'][0]['span_ref'],
        );
        $this->assertSame('memory', $result['claims'][0]['spans'][0]['source_type']);
        $this->assertFalse($result['source']['llm_used']);
        $this->assertFalse($result['source']['record_usage']);
    }

    #[Test]
    public function unmatched_claim_is_unresolved_without_fabricating_spans(): void
    {
        $result = (new SpanLevelRetrievalResolver)->resolve([
            'memory' => [
                [
                    'type' => 'decision',
                    'title' => 'Unrelated note',
                    'summary' => 'Wheat harvest forecast',
                    'body' => 'Seasonal agriculture data only.',
                    'source_type' => 'memory_entry',
                    'content_hash' => 'content-v2',
                ],
            ],
            'retrieval_agenda' => [
                'claims' => [
                    ['claim' => 'The policy must enforce scoped commits only'],
                ],
            ],
        ]);

        $this->assertTrue($result['present']);
        $this->assertSame('unresolved', $result['claims'][0]['status']);
        $this->assertSame([], $result['claims'][0]['spans']);
        $this->assertFalse($result['source']['fabricates_spans']);
    }
}
