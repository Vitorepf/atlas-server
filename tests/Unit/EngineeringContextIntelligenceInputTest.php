<?php

namespace Tests\Unit;

use App\Services\Engineering\EngineeringContextIntelligenceInput;
use Tests\TestCase;

class EngineeringContextIntelligenceInputTest extends TestCase
{
    public function test_normalizes_engineering_context_intelligence_limits(): void
    {
        $input = new EngineeringContextIntelligenceInput;

        $this->assertSame(EngineeringContextIntelligenceInput::DEFAULT_KNOWLEDGE_LIMIT, $input->knowledgeLimit(null));
        $this->assertSame(EngineeringContextIntelligenceInput::MAX_KNOWLEDGE_LIMIT, $input->knowledgeLimit(999));
        $this->assertSame(EngineeringContextIntelligenceInput::MAX_CODE_LIMIT, $input->codeLimit(999));
        $this->assertSame(EngineeringContextIntelligenceInput::MAX_EVIDENCE_HISTORY_LIMIT, $input->evidenceHistoryLimit(999));
        $this->assertSame(1, $input->knowledgeLimit(-10));
        $this->assertSame(EngineeringContextIntelligenceInput::DEFAULT_CODE_LIMIT, $input->codeLimit('bad'));
    }
}
