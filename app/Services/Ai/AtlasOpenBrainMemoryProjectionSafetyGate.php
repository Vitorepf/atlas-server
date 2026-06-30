<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Pure, read-only gate — rejects or fail-closes provider-projection / context-pack memory ENTRIES before
 * they can reach a muscle worker as provider context. Catches raw hostile/insulting operator phrasing,
 * raw prompt-injection fragments, imperative quoted commands, and missing provenance metadata — the
 * classes of content that must NEVER leak into a downstream provider prompt verbatim.
 *
 * INPUT (memory record):
 *   { summary?:string, excerpt?:string, title?:string, source?:string, freshness?:string,
 *     recorded_at?:string, safe_text?:string, classification?:string|array }
 *
 * SANITIZED REPLACEMENT PATH: when both `safe_text` and `classification` are present, the gate trusts
 * the sanitized text instead of the raw excerpt/summary — it accepts (with warnings if the raw fields
 * also carried a violation) rather than leaking the raw content.
 *
 * VIOLATION CODES:
 *   raw_hostile_language     — raw text matches an insult/hostility pattern
 *   raw_prompt_leakage       — raw text matches a prompt-injection / system-prompt leak pattern
 *   imperative_quoted_text   — raw text contains a quoted imperative command
 *   missing_source_metadata  — no `source` field
 *   missing_freshness_metadata — no `freshness`/`recorded_at` field
 *
 * Pure: no I/O, no DB, no file, no network, no provider call — fact classification only.
 */
final class AtlasOpenBrainMemoryProjectionSafetyGate
{
    public const SCHEMA = 'atlas.open_brain.memory_projection_safety_gate.v1';

    private const HOSTILE_PATTERN = '/\b(idiota|burro|burra|estúpid[oa]|imbecil|merda|porra|caralho|stupid|idiot|damn it|fuck(ing)?|moron)\b/iu';

    private const PROMPT_LEAKAGE_PATTERN = '/(ignore (all |the )?previous instructions|system prompt|you are (now )?chatgpt|<\|im_start\|>|###\s*instruction|disregard (all )?prior (instructions|context))/iu';

    private const IMPERATIVE_QUOTED_PATTERN = '/["\']\s*(do|don\'t|stop|never|always|delete|remove|ignore|run|execute)\b[^"\']{0,200}["\']/iu';

    /**
     * @param  array<string,mixed>  $record
     * @return array{schema:string, accepted:bool, violations:list<string>, warnings:list<string>}
     */
    public function evaluate(array $record): array
    {
        $hasSafeText = trim((string) ($record['safe_text'] ?? '')) !== '';
        $hasClassification = ! empty($record['classification']);
        $sanitized = $hasSafeText && $hasClassification;

        $rawText = implode("\n", array_filter([
            (string) ($record['summary'] ?? ''),
            (string) ($record['excerpt'] ?? ''),
            (string) ($record['title'] ?? ''),
        ], static fn (string $s): bool => $s !== ''));

        $rawViolations = $this->detectTextViolations($rawText);

        $hasSource = trim((string) ($record['source'] ?? '')) !== '';
        $hasFreshness = trim((string) ($record['freshness'] ?? '')) !== '' || trim((string) ($record['recorded_at'] ?? '')) !== '';

        $metadataViolations = [];
        if (! $hasSource) {
            $metadataViolations[] = 'missing_source_metadata';
        }
        if (! $hasFreshness) {
            $metadataViolations[] = 'missing_freshness_metadata';
        }

        if ($sanitized) {
            // Sanitized path: raw content violations become WARNINGS (superseded by safe_text), but
            // missing provenance metadata is still a hard violation — sanitization does not invent provenance.
            $warnings = [];
            if ($rawViolations !== []) {
                $warnings[] = 'raw_excerpt_present_but_superseded_by_safe_text';
            }

            return [
                'schema' => self::SCHEMA,
                'accepted' => $metadataViolations === [],
                'violations' => $metadataViolations,
                'warnings' => $warnings,
            ];
        }

        $violations = array_values(array_merge($rawViolations, $metadataViolations));

        return [
            'schema' => self::SCHEMA,
            'accepted' => $violations === [],
            'violations' => $violations,
            'warnings' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function detectTextViolations(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $violations = [];
        if (preg_match(self::HOSTILE_PATTERN, $text) === 1) {
            $violations[] = 'raw_hostile_language';
        }
        if (preg_match(self::PROMPT_LEAKAGE_PATTERN, $text) === 1) {
            $violations[] = 'raw_prompt_leakage';
        }
        if (preg_match(self::IMPERATIVE_QUOTED_PATTERN, $text) === 1) {
            $violations[] = 'imperative_quoted_text';
        }

        return $violations;
    }
}
