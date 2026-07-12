<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Pure classifier. Lets AOBG distinguish actual operating instructions from quoted memory, stale
 * operator frustration, examples, summaries, and noise — so imperative text embedded inside a
 * memory excerpt is never treated as a live instruction by a downstream worker, while real current
 * task instructions remain visible and actionable.
 *
 * Input shape: {text:string, source:string, age_seconds?:int}
 *   source ∈ {current_turn, task_contract, memory, excerpt, example, summary, unknown}
 *
 * Pure — no I/O, no provider calls.
 */
final class AtlasOpenBrainContextInjectionBoundaryClassifier
{
    public const SCHEMA = 'atlas.ai.open_brain_context_injection_boundary_classifier.v1';

    public const CLASS_LIVE_INSTRUCTION = 'live_instruction';

    public const CLASS_STABLE_PREFERENCE = 'stable_preference';

    public const CLASS_QUOTED_MEMORY = 'quoted_memory';

    public const CLASS_STALE_CONTEXT = 'stale_context';

    public const CLASS_EXAMPLE_TEXT = 'example_text';

    public const CLASS_NOISE = 'noise';

    /** @var list<string> */
    private const CURRENT_SOURCES = ['current_turn', 'task_contract'];

    /** @var list<string> */
    private const MEMORY_SOURCES = ['memory', 'excerpt', 'quoted_memory'];

    /** @var list<string> */
    private const EXAMPLE_SOURCES = ['example', 'summary'];

    private const STALE_THRESHOLD_SECONDS = 3600;

    /**
     * @param  array<string,mixed>  $segment
     * @return array{schema:string, classification:string, confidence:float, reason:string, allow_as_worker_directive:bool}
     */
    public function classify(array $segment): array
    {
        $text = trim((string) ($segment['text'] ?? ''));
        $source = trim((string) ($segment['source'] ?? 'unknown'));

        if ($text === '') {
            return $this->result(self::CLASS_NOISE, 0.5, 'empty_text', false);
        }

        $hasImperative = preg_match('/\b(do|run|execute|delete|rm|stop|never|always|must|ignore|override)\b/i', $text) === 1;
        $hasHostileOrFrustration = preg_match('/\b(que merda|porra|stupid|idiot|fix this now|burn|nuke)\b/i', $text) === 1;

        if (in_array($source, self::MEMORY_SOURCES, true)) {
            if ($hasHostileOrFrustration && ! $hasImperative) {
                return $this->result(self::CLASS_NOISE, 0.7, 'hostile_text_inside_memory_source_is_noise', false);
            }
            if ($hasImperative || $hasHostileOrFrustration) {
                return $this->result(self::CLASS_QUOTED_MEMORY, 0.85, 'imperative_text_inside_memory_source_is_quoted_not_live', false);
            }

            return $this->result(self::CLASS_QUOTED_MEMORY, 0.75, 'memory_source_default_quoted', false);
        }

        if (in_array($source, self::EXAMPLE_SOURCES, true)) {
            return $this->result(self::CLASS_EXAMPLE_TEXT, 0.8, 'example_or_summary_source', false);
        }

        if (in_array($source, self::CURRENT_SOURCES, true)) {
            $age = isset($segment['age_seconds']) ? max(0, (int) $segment['age_seconds']) : 0;
            if ($age > self::STALE_THRESHOLD_SECONDS) {
                return $this->result(self::CLASS_STALE_CONTEXT, 0.7, 'current_turn_source_but_age_exceeds_threshold', false);
            }

            return $this->result(self::CLASS_LIVE_INSTRUCTION, 0.95, 'source=current_turn_or_task_contract', true);
        }

        if (preg_match('/\b(always|never|default to|prefer)\b/i', $text) === 1 && ! $hasHostileOrFrustration) {
            return $this->result(self::CLASS_STABLE_PREFERENCE, 0.6, 'declarative_preference_pattern_without_current_source', true);
        }

        return $this->result(self::CLASS_NOISE, 0.4, 'unknown_source_no_recognized_pattern', false);
    }

    private function result(string $classification, float $confidence, string $reason, bool $allow): array
    {
        return [
            'schema' => self::SCHEMA,
            'classification' => $classification,
            'confidence' => $confidence,
            'reason' => $reason,
            'allow_as_worker_directive' => $allow,
        ];
    }
}
