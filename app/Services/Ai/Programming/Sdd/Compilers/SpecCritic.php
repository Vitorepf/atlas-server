<?php

namespace App\Services\Ai\Programming\Sdd\Compilers;

use App\Services\Ai\Support\AiStringListNormalizer;

/**
 * Standalone Spec Critic. Reviews a compiled spec for ambiguity, missing
 * fields, vague language and untestable acceptance criteria.
 *
 * Mirrors the surface used by ProgrammingSpecCompiler::critique() but lives
 * as its own service so AtlasSddPipeline can call it without coupling to
 * the compiler.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/spec-compiler-and-critic.md
 */
class SpecCritic
{
    /** @var list<string> */
    private const VAGUE_WORDS = ['various', 'maybe', 'something', 'stuff', 'qualquer', 'talvez', 'algo', 'meio que'];

    /** @var list<string> */
    private const REQUIRED_LIST_FIELDS = [
        'likely_files', 'risks', 'tests', 'evidence_required', 'completion_criteria',
    ];

    /** @var list<string> */
    private const REQUIRED_TEXT_FIELDS = [
        'objective', 'context', 'expected_behavior', 'rollback',
    ];

    /**
     * @param  array<string,mixed>  $compiledOrSpec
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function review(array $compiledOrSpec, array $context = []): array
    {
        $spec = (array) ($compiledOrSpec['spec'] ?? $compiledOrSpec);
        $issues = [];

        foreach (self::REQUIRED_TEXT_FIELDS as $field) {
            $value = $spec[$field] ?? null;
            if (! is_string($value) || strlen(trim($value)) < 16) {
                $issues[] = ['field' => $field, 'severity' => 'high', 'reason' => 'too_short_or_missing'];
            }
        }
        foreach (self::REQUIRED_LIST_FIELDS as $field) {
            $value = $spec[$field] ?? [];
            if (! is_array($value) || $value === []) {
                $issues[] = ['field' => $field, 'severity' => 'high', 'reason' => 'empty_list'];
            }
        }

        $objective = strtolower((string) ($spec['objective'] ?? ''));
        foreach (self::VAGUE_WORDS as $word) {
            if (str_contains($objective, $word)) {
                $issues[] = ['field' => 'objective', 'severity' => 'medium', 'reason' => "vague_word:{$word}"];
            }
        }

        if (! $this->hasMeasurableCriterion($spec['completion_criteria'] ?? [])) {
            $issues[] = ['field' => 'completion_criteria', 'severity' => 'medium', 'reason' => 'no_measurable_criterion'];
        }

        $questions = $this->generateClarificationQuestions($issues, $spec);

        $blocking = array_values(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['severity'] ?? 'low') === 'high',
        ));

        return [
            'schema_version' => 'atlas.sdd_spec_critic.v1',
            'status' => $issues === [] ? 'clean' : ($blocking !== [] ? 'rejected' : 'warnings_only'),
            'issues' => $issues,
            'blocking_issues' => $blocking,
            'has_blocking_questions' => $blocking !== [],
            'clarification_questions' => $questions,
            'context_digest' => isset($context['digest']) ? (string) $context['digest'] : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $review
     */
    public function hasBlockingQuestions(array $review): bool
    {
        return (bool) ($review['has_blocking_questions'] ?? false);
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @param  array<string,mixed>  $spec
     * @return list<string>
     */
    private function generateClarificationQuestions(array $issues, array $spec): array
    {
        $questions = [];
        foreach ($issues as $issue) {
            $field = (string) ($issue['field'] ?? '');
            $reason = (string) ($issue['reason'] ?? '');
            $questions[] = match ($reason) {
                'too_short_or_missing' => "Please clarify the `{$field}`: a single full sentence is the minimum.",
                'empty_list' => "Please populate `{$field}`: even a single explicit entry is enough to remove ambiguity.",
                'no_measurable_criterion' => 'Add at least one objectively verifiable completion criterion (a test name, a CLI gate, a metric threshold).',
                default => str_starts_with($reason, 'vague_word:')
                    ? "Replace the vague term in `{$field}` (\"".substr($reason, strlen('vague_word:'))."\") with a concrete commitment."
                    : "Clarify `{$field}` ({$reason}).",
            };
        }

        return AiStringListNormalizer::uniqueStrings($questions);
    }

    /**
     * @param  mixed  $criteria
     */
    private function hasMeasurableCriterion($criteria): bool
    {
        if (! is_array($criteria)) {
            return false;
        }
        $needles = ['test', 'phpunit', 'gate', 'green', 'docs-health', 'index-code', 'sync', 'pass', 'count', 'commands'];
        foreach ($criteria as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            $lower = strtolower($entry);
            foreach ($needles as $needle) {
                if (str_contains($lower, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }
}
