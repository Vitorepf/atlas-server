<?php

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\ContextPackMemoryInput;
use Tests\TestCase;

class ContextPackMemoryInputTest extends TestCase
{
    public function test_normalizes_context_pack_memory_limits_with_canonical_caps(): void
    {
        $input = new ContextPackMemoryInput;

        config([
            'atlas.ai.memory_registry_limit' => 999,
            'atlas.ai.verbatim_recall_limit' => 999,
            'atlas.ai.verbatim_recall_budget_chars' => 99999,
            'atlas.ai.verbatim_recall_item_chars' => -10,
            'atlas.ai.memory_registry_excerpt_chars' => 99999,
        ]);

        $this->assertSame(ContextPackMemoryInput::MAX_MEMORY_REGISTRY_LIMIT, $input->memoryRegistryLimit());
        $this->assertSame(ContextPackMemoryInput::MAX_VERBATIM_RECALL_LIMIT, $input->verbatimRecallLimit());
        $this->assertSame(ContextPackMemoryInput::MAX_VERBATIM_RECALL_BUDGET_CHARS, $input->verbatimRecallBudgetChars());
        $this->assertSame(ContextPackMemoryInput::MIN_VERBATIM_RECALL_ITEM_CHARS, $input->verbatimRecallItemChars());
        $this->assertSame(ContextPackMemoryInput::MAX_MEMORY_REGISTRY_EXCERPT_CHARS, $input->memoryRegistryExcerptChars());
        $this->assertSame(0, $input->memoryRegistryLimit(-10));
        $this->assertSame(0, $input->verbatimRecallLimit(-10));
        $this->assertSame(ContextPackMemoryInput::DEFAULT_MEMORY_REGISTRY_LIMIT, $input->memoryRegistryLimit('bad'));
    }
}
