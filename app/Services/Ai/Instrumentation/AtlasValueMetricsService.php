<?php

declare(strict_types=1);

namespace App\Services\Ai\Instrumentation;

/**
 * B4 (fechamento ACOS) — real, deterministic instruments over a session transcript, so
 * the Obra #17 gates stop being immeasurable. Zero fabrication: every number is COUNTED
 * from the transcript JSONL the harness already writes (the same shape the session-capture
 * hook parses).
 *
 *  - TPE (turns_to_first_edit): the 1-based assistant-turn index at which the FIRST
 *    file-mutating tool-use (Edit/Write/MultiEdit/NotebookEdit) appears. This is the raw
 *    "turns até a 1ª edição" signal. The stronger "…correta" (that edit actually landed /
 *    passed a gate) needs a transcript×receipt join not attempted here — LABELLED, not
 *    faked (see turns_to_first_edit vs a would-be turns_to_first_correct_edit).
 *  - clarifying_questions: count of AskUserQuestion tool-uses — the raw "perguntas ao
 *    operador" signal. The "evitável" subset (the answer was already in the registry)
 *    needs a transcript×registry join not attempted here — LABELLED, not faked.
 *
 * Pure and shape-tolerant: accepts the Claude Code line shape
 * ({type:"assistant", message:{content:[{type:"tool_use", name, input}]}}) and the flat
 * {tool_name, tool_input} shape.
 */
final class AtlasValueMetricsService
{
    /** @var list<string> */
    private const MUTATING = ['Edit', 'Write', 'MultiEdit', 'NotebookEdit'];

    private const QUESTION_TOOL = 'AskUserQuestion';

    /**
     * @param  iterable<mixed>  $lines  decoded JSONL transcript lines (one per turn/event)
     * @return array{turns_to_first_edit: int|null, assistant_turns: int, edit_turns: int, clarifying_questions: int}
     */
    public function metricsForTranscript(iterable $lines): array
    {
        $assistantTurns = 0;
        $editTurns = 0;
        $turnsToFirstEdit = null;
        $questions = 0;

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            // A "turn" is an assistant message (rich shape) or a flat tool line; user /
            // system / tool-result lines are not turns and never carry a tool_use here.
            if (($line['type'] ?? null) !== 'assistant' && ! isset($line['tool_name'])) {
                continue;
            }

            $assistantTurns++;

            $hasEdit = false;
            foreach ($this->toolNames($line) as $name) {
                if (in_array($name, self::MUTATING, true)) {
                    $hasEdit = true;
                }
                if ($name === self::QUESTION_TOOL) {
                    $questions++;
                }
            }

            if ($hasEdit) {
                $editTurns++;
                $turnsToFirstEdit ??= $assistantTurns;
            }
        }

        return [
            'turns_to_first_edit' => $turnsToFirstEdit,
            'assistant_turns' => $assistantTurns,
            'edit_turns' => $editTurns,
            'clarifying_questions' => $questions,
        ];
    }

    /**
     * Aggregate per-session metrics into a "valor semana" rollup. Median TPE (robust to
     * one-off outliers) over the sessions that DID edit; total + per-session clarifying
     * questions. Only real, non-fabricated aggregates.
     *
     * @param  list<array{turns_to_first_edit: int|null, assistant_turns: int, edit_turns: int, clarifying_questions: int}>  $perSession
     * @return array{sessions: int, sessions_with_edit: int, median_turns_to_first_edit: int|null, total_clarifying_questions: int, avg_clarifying_questions: float}
     */
    public function rollup(array $perSession): array
    {
        $tpes = [];
        $questionsTotal = 0;
        $withEdit = 0;
        foreach ($perSession as $m) {
            $questionsTotal += (int) ($m['clarifying_questions'] ?? 0);
            $tpe = $m['turns_to_first_edit'] ?? null;
            if (is_int($tpe)) {
                $tpes[] = $tpe;
                $withEdit++;
            }
        }
        $sessions = count($perSession);

        return [
            'sessions' => $sessions,
            'sessions_with_edit' => $withEdit,
            'median_turns_to_first_edit' => $this->median($tpes),
            'total_clarifying_questions' => $questionsTotal,
            'avg_clarifying_questions' => $sessions > 0 ? round($questionsTotal / $sessions, 2) : 0.0,
        ];
    }

    /**
     * @param  array<string,mixed>  $line
     * @return list<string>
     */
    private function toolNames(array $line): array
    {
        $names = [];

        $flat = $line['tool_name'] ?? null;
        if (is_string($flat) && $flat !== '') {
            $names[] = $flat;
        }

        $content = $line['message']['content'] ?? ($line['content'] ?? null);
        if (is_array($content)) {
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'tool_use') {
                    $name = $block['name'] ?? '';
                    if (is_string($name) && $name !== '') {
                        $names[] = $name;
                    }
                }
            }
        }

        return $names;
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$mid]
            : (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }
}
