<?php

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\ConversationContextInput;
use Tests\TestCase;

class ConversationContextInputTest extends TestCase
{
    public function test_normalizes_conversation_context_turn_limits_with_canonical_caps(): void
    {
        $input = new ConversationContextInput;

        config([
            'atlas.ai.context_recent_turn_limit' => 99,
            'atlas.ai.context_payload_turn_limit' => -10,
        ]);

        $this->assertSame(ConversationContextInput::MAX_RECENT_TURN_LIMIT, $input->recentTurnLimit());
        $this->assertSame(ConversationContextInput::MIN_PAYLOAD_TURN_LIMIT, $input->payloadTurnLimit());
        $this->assertSame(ConversationContextInput::MIN_RECENT_TURN_LIMIT, $input->recentTurnLimit(-50));
        $this->assertSame(ConversationContextInput::MAX_PAYLOAD_TURN_LIMIT, $input->payloadTurnLimit(999));
        $this->assertSame(ConversationContextInput::DEFAULT_RECENT_TURN_LIMIT, $input->recentTurnLimit('bad'));
        $this->assertSame(ConversationContextInput::DEFAULT_PAYLOAD_TURN_LIMIT, $input->payloadTurnLimit('bad'));
    }
}
