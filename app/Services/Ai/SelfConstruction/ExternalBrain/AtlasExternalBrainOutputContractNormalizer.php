<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure normalizer that converts raw model outputs into strict envelopes:
 * task-spec, critique, research-note, or no-proposal — before any downstream
 * gate reads them.
 *
 * Prose-only outputs become critique or no_proposal, never task specs.
 * Malformed task envelopes include repair hints.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainOutputContractNormalizer
{
    public const SCHEMA = 'atlas.external_brain.output_contract_normalizer.v1';

    public const TYPE_TASK_SPEC = 'task_spec';
    public const TYPE_CRITIQUE = 'critique';
    public const TYPE_RESEARCH_NOTE = 'research_note';
    public const TYPE_NO_PROPOSAL = 'no_proposal';

    private const REQUIRED_TASK_FIELDS = ['objective', 'allowed_files', 'acceptance_criteria', 'required_evidence'];

    private const REDACTED_FIELDS = ['raw_prompt', 'provider_trace', 'provider_response', 'api_key', 'secret', 'token', 'password', 'credentials'];

    /**
     * @param  array<string,mixed>  $raw
     * @return array{
     *   schema:string,
     *   type:string,
     *   payload:array<string,mixed>,
     *   repair_hints:list<string>,
     * }
     */
    public function normalize(array $raw): array
    {
        $declaredType = (string) ($raw['type'] ?? '');

        // Explicit type detection
        if ($declaredType === 'task_spec' || isset($raw['objective'], $raw['allowed_files'])) {
            return $this->normalizeTaskSpec($raw);
        }

        if ($declaredType === 'critique' || isset($raw['critique'])) {
            return $this->envelope(self::TYPE_CRITIQUE, [
                'critique' => (string) ($raw['critique'] ?? ''),
                'target' => (string) ($raw['target'] ?? ''),
            ], []);
        }

        if ($declaredType === 'research_note' || isset($raw['research_note'])) {
            return $this->envelope(self::TYPE_RESEARCH_NOTE, [
                'note' => (string) ($raw['research_note'] ?? $raw['note'] ?? ''),
                'topic' => (string) ($raw['topic'] ?? ''),
            ], []);
        }

        // Prose-only output with no recognized structure → no_proposal
        $prose = (string) ($raw['prose'] ?? $raw['text'] ?? $raw['content'] ?? '');
        if ($prose !== '') {
            // Check if it looks like critique (expresses concerns/suggestions)
            if (preg_match('/(should|must|needs?|wrong|broken|issue|suggest|improve)/i', $prose)) {
                return $this->envelope(self::TYPE_CRITIQUE, ['critique' => $prose], []);
            }

            return $this->envelope(self::TYPE_NO_PROPOSAL, ['reason' => 'prose_only_no_structure'], []);
        }

        // Empty
        return $this->envelope(self::TYPE_NO_PROPOSAL, ['reason' => 'empty_output'], []);
    }

    /**
     * @return array{schema:string,type:string,payload:array<string,mixed>,repair_hints:list<string>}
     */
    private function normalizeTaskSpec(array $raw): array
    {
        $repairHints = [];
        $payload = [];

        // task_packet_id
        $payload['task_packet_id'] = (string) ($raw['task_packet_id'] ?? '');

        foreach (self::REQUIRED_TASK_FIELDS as $field) {
            $value = $raw[$field] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && $value === [])) {
                $repairHints[] = "missing_or_empty:{$field}";
            }
            $payload[$field] = $value ?? null;
        }

        // decision_rationale
        $payload['decision_rationale'] = (string) ($raw['decision_rationale'] ?? '');

        // Copy and redact provider-sensitive fields from raw input
        foreach (self::REDACTED_FIELDS as $field) {
            if (array_key_exists($field, $raw)) {
                $payload[$field] = '[REDACTED_PROVIDER_SAFE_REF]';
            }
        }

        // invalid flag
        $payload['invalid'] = $repairHints !== [];

        return $this->envelope(self::TYPE_TASK_SPEC, $payload, $repairHints);
    }

    /**
     * Redact raw prompt text, provider traces, and secret-like fields.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function redactProviderFields(array $payload): array
    {
        foreach (self::REDACTED_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = '[REDACTED_PROVIDER_SAFE_REF]';
            }
        }

        return $payload;
    }

    /**
     * @param  list<string>  $repairHints
     * @return array{schema:string,type:string,payload:array<string,mixed>,repair_hints:list<string>}
     */
    private function envelope(string $type, array $payload, array $repairHints): array
    {
        sort($repairHints, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'type' => $type,
            'payload' => $payload,
            'repair_hints' => $repairHints,
        ];
    }
}
