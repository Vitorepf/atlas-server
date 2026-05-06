<?php

namespace Tests\Unit\Ai\Memory;

use App\Services\Ai\Memory\MemoryQueryInput;
use Tests\TestCase;

class MemoryQueryInputTest extends TestCase
{
    public function test_normalizes_memory_query_limits_with_canonical_caps(): void
    {
        $input = new MemoryQueryInput;

        $this->assertSame(MemoryQueryInput::DEFAULT_REGISTRY_LIMIT, $input->registryLimit(null));
        $this->assertSame(MemoryQueryInput::DEFAULT_RELEVANT_LIMIT, $input->relevantLimit('bad'));
        $this->assertSame(MemoryQueryInput::DEFAULT_VERBATIM_LIMIT, $input->verbatimLimit(null));
        $this->assertSame(MemoryQueryInput::DEFAULT_VERBATIM_CONTEXT_LIMIT, $input->verbatimContextLimit(null));
        $this->assertSame(MemoryQueryInput::DEFAULT_GOVERNANCE_SCAN_LIMIT, $input->governanceScanLimit(null));
        $this->assertSame(MemoryQueryInput::DEFAULT_RELATION_LIMIT, $input->relationLimit(null));
        $this->assertSame(MemoryQueryInput::DEFAULT_PROMOTION_LIMIT, $input->promotionLimit(null));
        $this->assertSame(MemoryQueryInput::DEFAULT_REVIEW_QUEUE_LIMIT, $input->reviewQueueLimit(null));
        $this->assertSame(MemoryQueryInput::DEFAULT_QUALITY_HISTORY_DAYS, $input->qualityHistoryDays(null));
        $this->assertSame(1, $input->registryLimit(-10));
        $this->assertSame(MemoryQueryInput::MAX_MEMORY_LIMIT, $input->registryLimit(9999));
        $this->assertSame(MemoryQueryInput::MAX_MEMORY_LIMIT, $input->promotionLimit(9999));
        $this->assertSame(MemoryQueryInput::MAX_SCAN_LIMIT, $input->governanceScanLimit(9999));
        $this->assertSame(MemoryQueryInput::MAX_QUALITY_HISTORY_DAYS, $input->qualityHistoryDays(9999));
    }
}
