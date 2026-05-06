<?php

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\RetrievalRankInput;
use Tests\TestCase;

class RetrievalRankInputTest extends TestCase
{
    public function test_normalizes_retrieval_rank_limits_with_canonical_caps(): void
    {
        $input = new RetrievalRankInput;

        $this->assertSame(RetrievalRankInput::DEFAULT_SESSION_TOP_N, $input->sessionTopN(null));
        $this->assertSame(RetrievalRankInput::DEFAULT_SESSION_TOP_N, $input->promptSessionTopN('bad'));
        $this->assertSame(1, $input->sessionTopN(-10));
        $this->assertSame(RetrievalRankInput::MAX_SESSION_TOP_N, $input->sessionTopN(999));
        $this->assertSame(RetrievalRankInput::MAX_PROMPT_SESSION_TOP_N, $input->promptSessionTopN(999));
    }
}
