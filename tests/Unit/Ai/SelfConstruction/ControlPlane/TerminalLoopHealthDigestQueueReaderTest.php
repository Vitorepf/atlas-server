<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\ControlPlane\TerminalLoopHealthDigestQueueReader;
use Tests\TestCase;

class TerminalLoopHealthDigestQueueReaderTest extends TestCase
{
    public function test_record_queue_tags_normalizes(): void
    {
        $result = TerminalLoopHealthDigestQueueReader::recordQueueTags(['queue_tags' => ['a', 'b', 'c']]);
        self::assertSame(['a', 'b', 'c'], $result);
    }

    public function test_record_queue_tags_missing(): void
    {
        self::assertSame([], TerminalLoopHealthDigestQueueReader::recordQueueTags([]));
    }

    public function test_record_queue_tags_casts_non_strings(): void
    {
        $result = TerminalLoopHealthDigestQueueReader::recordQueueTags(['queue_tags' => [42, 'x', null]]);
        self::assertSame(['42', 'x', ''], $result);
    }

    public function test_record_queue_tags_returns_list(): void
    {
        $result = TerminalLoopHealthDigestQueueReader::recordQueueTags(['queue_tags' => ['a' => 'a', 'b' => 'b']]);
        self::assertSame(['a', 'b'], $result);
    }

    public function test_classification_count_no_tags(): void
    {
        $items = [
            ['classification' => 'servable'],
            ['classification' => 'blocked'],
            ['classification' => 'servable'],
        ];

        self::assertSame(2, TerminalLoopHealthDigestQueueReader::classificationCount($items, 'servable', []));
        self::assertSame(1, TerminalLoopHealthDigestQueueReader::classificationCount($items, 'blocked', []));
    }

    public function test_classification_count_with_matching_tags(): void
    {
        $items = [
            ['classification' => 'servable', 'queue_tags' => ['a', 'b']],
            ['classification' => 'servable', 'queue_tags' => ['a']],
            ['classification' => 'servable', 'queue_tags' => ['c']],
        ];

        self::assertSame(1, TerminalLoopHealthDigestQueueReader::classificationCount($items, 'servable', ['a', 'b']));
    }

    public function test_classification_count_skips_mismatched_classification(): void
    {
        $items = [
            ['classification' => 'other', 'queue_tags' => ['a']],
            ['classification' => 'servable', 'queue_tags' => ['a']],
        ];

        self::assertSame(1, TerminalLoopHealthDigestQueueReader::classificationCount($items, 'servable', ['a']));
    }

    public function test_classification_count_empty(): void
    {
        self::assertSame(0, TerminalLoopHealthDigestQueueReader::classificationCount([], 'servable', []));
        self::assertSame(0, TerminalLoopHealthDigestQueueReader::classificationCount([], 'servable', ['a']));
    }

    public function test_classification_count_with_qualifying_tags_match_subset(): void
    {
        // When requested tags are a subset of record tags, count it
        $items = [
            ['classification' => 'servable', 'queue_tags' => ['a', 'b', 'c']],
        ];

        self::assertSame(1, TerminalLoopHealthDigestQueueReader::classificationCount($items, 'servable', ['a', 'b']));
    }
}