<?php

namespace App\Services\Ai\Memory\LocalAgentIngestion;

/**
 * Heuristic classifier that maps a (filename, redacted_content) pair to a
 * canonical source class. Intentionally simple: regex-and-substring signals
 * over the REDACTED text (never the raw text). A future classifier can
 * replace this with a learned model — its contract is the return value.
 */
final class LocalAgentSourceClassifier
{
    /**
     * @return array{source_class:string,signals:array<int,string>,quality_score:int}
     */
    public function classify(string $relativePath, string $redactedContent): array
    {
        $name = strtolower($relativePath);
        $body = $redactedContent;
        $bodyLower = strtolower($body);
        $signals = [];

        // Sensitive secret class — caller already redacted, but tag class so
        // promotion stays blocked even if the source text still mentions
        // secret-like tokens.
        if (str_contains($body, ':REDACTED:')) {
            $signals[] = 'redacted_marker_present';

            return [
                'source_class' => LocalAgentMemoryIngestionCanon::CLASS_SENSITIVE_SECRET,
                'signals' => $signals,
                'quality_score' => 0,
            ];
        }

        if (str_contains($name, 'plan') || str_starts_with(trim($body), '# Plan') || str_contains($bodyLower, "\nplan:\n")) {
            $signals[] = 'plan_filename_or_heading';

            return $this->result(LocalAgentMemoryIngestionCanon::CLASS_IMPLEMENTATION_PLAN, $body, $signals);
        }

        if (str_contains($name, 'goal') || str_contains($name, 'prompt')) {
            $signals[] = 'goal_or_prompt_filename';

            return $this->result(LocalAgentMemoryIngestionCanon::CLASS_GOAL_PROMPT, $body, $signals);
        }

        if (preg_match('/(?:Traceback|Exception|fatal error|stack trace|\bError:\s)/i', $body) === 1) {
            $signals[] = 'error_trace_marker';

            return $this->result(LocalAgentMemoryIngestionCanon::CLASS_ERROR_TRACE, $body, $signals);
        }

        if (preg_match('/^(?:\+\+\+|---)\s/m', $body) === 1 && preg_match('/(?:fix|patch|repair)/i', $body) === 1) {
            $signals[] = 'diff_with_fix_keyword';

            return $this->result(LocalAgentMemoryIngestionCanon::CLASS_SUCCESSFUL_FIX, $body, $signals);
        }

        if (str_contains($name, 'recipe') || preg_match('/^\$\s\S/m', $body) === 1) {
            $signals[] = 'recipe_filename_or_shell_prompt';

            return $this->result(LocalAgentMemoryIngestionCanon::CLASS_TOOL_RECIPE, $body, $signals);
        }

        if (str_contains($name, 'preference') || str_contains($name, 'style')) {
            $signals[] = 'preference_filename';

            return $this->result(LocalAgentMemoryIngestionCanon::CLASS_OPERATOR_PREFERENCE, $body, $signals);
        }

        $signals[] = 'no_canonical_signal';

        return $this->result(LocalAgentMemoryIngestionCanon::CLASS_UNTRUSTED_OUTPUT, $body, $signals);
    }

    /**
     * @param  array<int,string>  $signals
     * @return array{source_class:string,signals:array<int,string>,quality_score:int}
     */
    private function result(string $class, string $body, array $signals): array
    {
        return [
            'source_class' => $class,
            'signals' => $signals,
            'quality_score' => $this->scoreQuality($body),
        ];
    }

    /**
     * Crude quality heuristic. Range 0-100. Cheap on purpose — the downstream
     * promotion review is what actually decides usefulness.
     */
    private function scoreQuality(string $body): int
    {
        $len = mb_strlen($body);
        if ($len < 32) {
            return 5;
        }

        $score = 30;
        if ($len > 200) {
            $score += 20;
        }
        if ($len > 1000) {
            $score += 10;
        }
        if (preg_match('/(?:implement|fix|refactor|add|create|update|migrate|test|verify)/i', $body) === 1) {
            $score += 15;
        }
        if (preg_match('/\b(?:should|must|never|always|prefer)\b/i', $body) === 1) {
            $score += 10;
        }
        if (preg_match('/```|^[#-]\s/m', $body) === 1) {
            $score += 10;
        }

        return min(100, $score);
    }
}
