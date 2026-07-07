<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\Instrumentation\AtlasValueMetricsService;
use Tests\TestCase;

/**
 * B4 (fechamento ACOS) — the value instruments are COUNTED from the transcript, never
 * fabricated: TPE = the assistant-turn of the first file-mutating tool-use;
 * clarifying_questions = the count of AskUserQuestion tool-uses.
 */
final class AtlasValueMetricsServiceTest extends TestCase
{
    public function test_computes_tpe_and_clarifying_questions_from_a_transcript(): void
    {
        $svc = new AtlasValueMetricsService;

        $lines = [
            ['type' => 'user', 'message' => ['content' => 'do X']],                                                    // not a turn
            ['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'thinking']]]],           // turn 1, no edit
            ['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'Read', 'input' => []]]]], // turn 2, no edit
            ['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'AskUserQuestion', 'input' => []]]]], // turn 3, a question
            ['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'Edit', 'input' => ['file_path' => 'a.php']]]]], // turn 4, FIRST edit
            ['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'Write', 'input' => ['file_path' => 'b.php']]]]], // turn 5, edit
        ];

        $m = $svc->metricsForTranscript($lines);

        self::assertSame(4, $m['turns_to_first_edit']);
        self::assertSame(5, $m['assistant_turns']);
        self::assertSame(2, $m['edit_turns']);
        self::assertSame(1, $m['clarifying_questions']);
    }

    public function test_flat_tool_shape_and_no_edit_session(): void
    {
        $svc = new AtlasValueMetricsService;

        // Flat {tool_name} shape, and a session that never edits → TPE null, but questions still counted.
        $m = $svc->metricsForTranscript([
            ['tool_name' => 'AskUserQuestion', 'tool_input' => []],
            ['tool_name' => 'Read', 'tool_input' => []],
        ]);

        self::assertNull($m['turns_to_first_edit'], 'a session with no edit has no TPE (honest null, not 0)');
        self::assertSame(0, $m['edit_turns']);
        self::assertSame(1, $m['clarifying_questions']);
    }

    public function test_rollup_medians_tpe_over_editing_sessions_and_totals_questions(): void
    {
        $svc = new AtlasValueMetricsService;

        $roll = $svc->rollup([
            ['turns_to_first_edit' => 4, 'assistant_turns' => 5, 'edit_turns' => 2, 'clarifying_questions' => 1],
            ['turns_to_first_edit' => 2, 'assistant_turns' => 3, 'edit_turns' => 1, 'clarifying_questions' => 0],
            ['turns_to_first_edit' => null, 'assistant_turns' => 2, 'edit_turns' => 0, 'clarifying_questions' => 3], // no-edit session excluded from TPE
        ]);

        self::assertSame(3, $roll['sessions']);
        self::assertSame(2, $roll['sessions_with_edit']);
        self::assertSame(3, $roll['median_turns_to_first_edit'], 'median of [2,4] = 3');
        self::assertSame(4, $roll['total_clarifying_questions']);
    }
}
