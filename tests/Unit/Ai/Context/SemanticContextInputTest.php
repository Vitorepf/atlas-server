<?php

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\SemanticContextInput;
use Tests\TestCase;

class SemanticContextInputTest extends TestCase
{
    public function test_normalizes_semantic_context_limits_with_canonical_caps(): void
    {
        $input = new SemanticContextInput;

        config([
            'atlas.ai.context_note_limit' => 999,
            'atlas.ai.context_excerpt_chars' => 99999,
        ]);

        $this->assertSame(SemanticContextInput::MAX_CONTEXT_NOTE_LIMIT, $input->contextNoteLimit());
        $this->assertSame(SemanticContextInput::MAX_CONTEXT_EXCERPT_CHARS, $input->contextExcerptChars());
        $this->assertSame(0, $input->contextNoteLimit(-10));
        $this->assertSame(SemanticContextInput::MIN_CONTEXT_EXCERPT_CHARS, $input->contextExcerptChars(-10));
        $this->assertSame(SemanticContextInput::DEFAULT_CONTEXT_NOTE_LIMIT, $input->contextNoteLimit('bad'));
    }
}
