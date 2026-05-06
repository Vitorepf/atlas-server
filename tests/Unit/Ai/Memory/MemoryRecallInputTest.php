<?php

namespace Tests\Unit\Ai\Memory;

use App\Services\Ai\Memory\MemoryRecallInput;
use Tests\TestCase;

class MemoryRecallInputTest extends TestCase
{
    public function test_normalizes_hybrid_memory_recall_limits_with_canonical_caps(): void
    {
        $input = new MemoryRecallInput;

        config([
            'atlas.ai.memory_recall_limit' => 999,
            'atlas.ai.memory_recall_budget_chars' => 99999,
            'atlas.ai.memory_recall_item_chars' => -10,
            'atlas.ai.memory_registry_excerpt_chars' => 99999,
        ]);

        $this->assertSame(MemoryRecallInput::MAX_RECALL_LIMIT, $input->recallLimit());
        $this->assertSame(MemoryRecallInput::MAX_BUDGET_CHARS, $input->budgetChars());
        $this->assertSame(MemoryRecallInput::MIN_ITEM_CHARS, $input->itemChars());
        $this->assertSame(MemoryRecallInput::MAX_REGISTRY_EXCERPT_CHARS, $input->registryExcerptChars());
        $this->assertSame(1, $input->recallLimit(-10));
        $this->assertSame(MemoryRecallInput::MIN_REGISTRY_EXCERPT_CHARS, $input->registryExcerptChars(-10));
        $this->assertSame(MemoryRecallInput::MAX_REGISTRY_CANDIDATE_LIMIT, $input->registryCandidateLimit(9999, 50));
        $this->assertSame(MemoryRecallInput::MAX_VERBATIM_CANDIDATE_LIMIT, $input->verbatimCandidateLimit(9999, 50));
        $this->assertSame(MemoryRecallInput::MAX_SEMANTIC_CANDIDATE_LIMIT, $input->semanticCandidateLimit(9999, 50));
        $this->assertSame(12, $input->registryCandidateLimit(null, 2));
        $this->assertSame(4, $input->verbatimCandidateLimit(null, 2));
        $this->assertSame(5, $input->semanticCandidateLimit(null, 2));
    }
}
