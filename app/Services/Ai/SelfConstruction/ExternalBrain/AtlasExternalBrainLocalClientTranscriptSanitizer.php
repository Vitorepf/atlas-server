<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Sanitizes a local subscription client's transcript facts (Cursor, Hermes,
 * etc.) into a provider-safe summary before outcome learning consumes it.
 *
 * Auto-redacted (counted in omitted_sensitive_count, does NOT block):
 *   secret-shaped tokens (sk-..., ghp_..., AKIA..., private keys, slack xox*)
 *   account identifiers (email addresses)
 *   UI selector noise in touched_files (CSS-selector-like / XPath-like
 *   strings that are not real file paths — these are dropped entirely).
 *
 * BLOCKING (transcript_safe_for_memory=false):
 *   missing_required_redaction_facts — commands, touched_files AND
 *     evidence_refs are all empty, so nothing could be verified as safe.
 *   raw_provider_trace_detected      — raw_text contains a provider-internal
 *     trace/request-id marker (structural leak risk, not auto-redactable).
 *   hidden_prompt_detected           — raw_text contains a system-prompt /
 *     hidden-instruction marker.
 *
 * raw_text itself is NEVER echoed back in the summary — only the redacted
 * commands/touched_files/evidence_refs and the blocker list are returned.
 *
 * INPUT:
 *   raw_text?:       string
 *   commands?:       list<string>
 *   touched_files?:  list<string>
 *   evidence_refs?:  list<string>
 *   model_hint?:     string
 *
 * Pure: no I/O, no network calls, no side effects.
 */
final class AtlasExternalBrainLocalClientTranscriptSanitizer
{
    public const SCHEMA = 'atlas.external_brain.local_client_transcript_sanitizer.v1';

    private const SECRET_PATTERNS = [
        '/sk-[a-zA-Z0-9]{16,}/',
        '/ghp_[a-zA-Z0-9]{20,}/',
        '/AKIA[0-9A-Z]{12,}/',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/',
        '/xox[baprs]-[a-zA-Z0-9-]{10,}/',
    ];

    private const ACCOUNT_ID_PATTERN = '/[\w.+-]+@[\w-]+\.[\w.-]+/';

    private const PROVIDER_TRACE_MARKERS = [
        'trace_id=', 'request_id=', 'x-request-id', 'anthropic-request-id', 'openai-request-id', 'x-trace-id',
    ];

    private const HIDDEN_PROMPT_MARKERS = [
        'system prompt', '<|system|>', '<system>', 'you are claude', 'you are chatgpt', 'ignore previous instructions',
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function sanitize(array $facts): array
    {
        $rawText = (string) ($facts['raw_text'] ?? '');
        $commands = $this->toStringList($facts['commands'] ?? null);
        $touchedFiles = $this->toStringList($facts['touched_files'] ?? null);
        $evidenceRefs = $this->toStringList($facts['evidence_refs'] ?? null);
        $modelHint = (string) ($facts['model_hint'] ?? '');

        $omitted = 0;

        $redactedCommands = [];
        foreach ($commands as $command) {
            [$clean, $n] = $this->redact($command);
            $redactedCommands[] = $clean;
            $omitted += $n;
        }

        $cleanTouchedFiles = [];
        foreach ($touchedFiles as $file) {
            if ($this->looksLikeUiSelectorNoise($file)) {
                $omitted++;

                continue;
            }
            [$clean, $n] = $this->redact($file);
            $omitted += $n;
            $cleanTouchedFiles[] = $clean;
        }

        $cleanEvidenceRefs = [];
        foreach ($evidenceRefs as $ref) {
            [$clean, $n] = $this->redact($ref);
            $omitted += $n;
            $cleanEvidenceRefs[] = $clean;
        }

        $blockers = [];
        if ($commands === [] && $touchedFiles === [] && $evidenceRefs === []) {
            $blockers[] = 'missing_required_redaction_facts';
        }
        if ($this->matchesAny($rawText, self::PROVIDER_TRACE_MARKERS)) {
            $blockers[] = 'raw_provider_trace_detected';
        }
        if ($this->matchesAny($rawText, self::HIDDEN_PROMPT_MARKERS)) {
            $blockers[] = 'hidden_prompt_detected';
        }

        $transcriptSafeForMemory = $blockers === [];

        return [
            'schema' => self::SCHEMA,
            'summary' => [
                'redacted_commands' => $redactedCommands,
                'touched_files' => $cleanTouchedFiles,
                'evidence_refs' => $cleanEvidenceRefs,
                'model_hints' => $modelHint,
                'blockers' => $blockers,
            ],
            'omitted_sensitive_count' => $omitted,
            'transcript_safe_for_memory' => $transcriptSafeForMemory,
        ];
    }

    /**
     * @return array{0:string,1:int}
     */
    private function redact(string $value): array
    {
        $count = 0;

        foreach (self::SECRET_PATTERNS as $pattern) {
            $value = (string) preg_replace_callback($pattern, static function () use (&$count): string {
                $count++;

                return '[REDACTED_SECRET]';
            }, $value);
        }

        $value = (string) preg_replace_callback(self::ACCOUNT_ID_PATTERN, static function () use (&$count): string {
            $count++;

            return '[REDACTED_ACCOUNT_ID]';
        }, $value);

        return [$value, $count];
    }

    private function looksLikeUiSelectorNoise(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }
        if (str_starts_with($trimmed, '.') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '//')) {
            return true;
        }

        return (bool) preg_match('/\s*[>~+]\s*/', $trimmed) && ! str_contains($trimmed, '/');
    }

    /** @param list<string> $markers */
    private function matchesAny(string $haystack, array $markers): bool
    {
        if ($haystack === '') {
            return false;
        }
        $low = strtolower($haystack);
        foreach ($markers as $marker) {
            if (str_contains($low, strtolower($marker))) {
                return true;
            }
        }

        return false;
    }

    private function toStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn (string $s): bool => $s !== ''));
    }
}
