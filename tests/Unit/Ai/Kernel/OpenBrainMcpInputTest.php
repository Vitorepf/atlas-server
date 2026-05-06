<?php

namespace Tests\Unit\Ai\Kernel;

use App\Services\Ai\Kernel\Mcp\OpenBrainMcpInput;
use Tests\TestCase;

class OpenBrainMcpInputTest extends TestCase
{
    public function test_limits_normalize_mcp_tool_windows_with_canonical_caps(): void
    {
        $input = new OpenBrainMcpInput;

        $this->assertSame(OpenBrainMcpInput::DEFAULT_CODE_LIMIT, $input->codeLimit(null));
        $this->assertSame(OpenBrainMcpInput::MAX_CODE_LIMIT, $input->codeLimit(999));
        $this->assertSame(OpenBrainMcpInput::DEFAULT_DOCS_LIMIT, $input->docsLimit('bad'));
        $this->assertSame(OpenBrainMcpInput::MAX_RECENT_CHANGES_LIMIT, $input->recentChangesLimit(999));
        $this->assertSame(OpenBrainMcpInput::MAX_DECISION_LIMIT, $input->decisionLimit(999));
        $this->assertSame(OpenBrainMcpInput::MAX_SYMBOLS_LIMIT, $input->symbolsLimit(999));
        $this->assertSame(OpenBrainMcpInput::MAX_CONTEXT_MEMORY_LIMIT, $input->contextMemoryLimit(999));
        $this->assertSame(OpenBrainMcpInput::MAX_CONTEXT_CODE_LIMIT, $input->contextCodeLimit(999));
        $this->assertSame(OpenBrainMcpInput::MAX_CONTEXT_DOCS_LIMIT, $input->contextDocsLimit(999));
    }
}
