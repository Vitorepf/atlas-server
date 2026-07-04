<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Specifies exactly what provider-safe facts a brain cycle may propose for memory.
 *
 * A proposal is a candidate memory entry produced by the cycle. This contract:
 *   1. Validates type — only ALLOWED_TYPES are accepted.
 *   2. Validates required fields — type, fact, source, evidence_ref, scope, lesson,
 *      decision_effect must be present and non-empty; provider_safe must be true.
 *   3. Rejects oversized facts — prevents memory spam (max MAX_FACT_LENGTH chars).
 *   4. Redacts forbidden content — raw prompts, provider names, API tokens.
 *   5. Normalises — trims whitespace, lowercases type.
 *   6. Tags evidence strength — defaults to EVIDENCE_INFERRED when missing or unknown.
 *   7. Deduplicates — a lower-evidence duplicate of an already-accepted entry is rejected.
 *
 * Rejection reasons:
 *   unknown_type                 — type not in ALLOWED_TYPES
 *   missing_required_fields      — type, fact, source, evidence_ref, scope, lesson, or
 *                                  decision_effect is blank
 *   not_provider_safe            — provider_safe != true (unsafe to send to external providers)
 *   fact_too_long                — fact exceeds MAX_FACT_LENGTH
 *   raw_prompt_detected          — fact contains prompt-like markers
 *   provider_details_detected    — fact mentions model/API identifiers
 *   noninstructional_content_detected — fact carries operator frustration, emotional venting,
 *                                  or a raw chat fragment instead of a cleaned operational rule
 *   speculative_claim            — evidence_strength = 'speculative' (not an allowed tier)
 *   duplicate_low_value          — same (type, normalized_fact) already accepted at ≥ evidence
 *   vague_unactionable           — fact names no capability, pattern family, failure mode, or next action
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainMemoryWritebackContract
{
    public const SCHEMA = 'atlas.external_brain.memory_writeback_contract.v1';

    public const TYPE_DELIVERED_LEVERAGE    = 'delivered_leverage';
    public const TYPE_FAILED_PATTERN        = 'failed_pattern';
    public const TYPE_TASK_FAMILY_YIELD     = 'task_family_yield';
    public const TYPE_FORBIDDEN_PROXY_SMELL = 'forbidden_proxy_smell';
    public const TYPE_NEXT_CYCLE_HINT       = 'next_cycle_hint';

    public const ALLOWED_TYPES = [
        self::TYPE_DELIVERED_LEVERAGE,
        self::TYPE_FAILED_PATTERN,
        self::TYPE_TASK_FAMILY_YIELD,
        self::TYPE_FORBIDDEN_PROXY_SMELL,
        self::TYPE_NEXT_CYCLE_HINT,
    ];

    public const EVIDENCE_PROVEN   = 'proven';
    public const EVIDENCE_OBSERVED = 'observed';
    public const EVIDENCE_INFERRED = 'inferred';

    private const EVIDENCE_ORDER = [
        self::EVIDENCE_INFERRED  => 0,
        self::EVIDENCE_OBSERVED  => 1,
        self::EVIDENCE_PROVEN    => 2,
    ];

    private const MAX_FACT_LENGTH = 500;

    /** Markers that indicate raw prompt content or provider identifiers. */
    private const PROMPT_MARKERS = ['<system>', '</system>', '<user>', '</user>', '<assistant>', '</assistant>', 'system_prompt', 'anthropic_api_key', 'openai_api_key'];
    private const PROVIDER_MARKERS = ['claude-opus', 'claude-sonnet', 'claude-haiku', 'gpt-4', 'gpt-3.5', 'text-davinci', 'gemini-pro', 'sk-ant-', 'sk-proj-'];

    /**
     * Markers that indicate operator frustration, emotional venting, or a raw chat fragment
     * rather than a cleaned operational rule — these are never canonical memory even when
     * provider_safe=true and otherwise well-formed.
     */
    private const NONINSTRUCTIONAL_MARKERS = [
        'que merda', 'porra', 'caralho', 'merda', 'wtf', 'damn it', 'this is so frustrating',
        'i am so frustrated', 'você não tá entendendo', 'voce nao ta entendendo',
        'você não entendeu', 'voce nao entendeu', "i can't believe", 'ugh', 'argh',
    ];

    /**
     * Validate, normalise, redact, deduplicate and tag a list of memory proposals.
     *
     * @param  list<array<string,mixed>>  $proposals
     * @return array{
     *     schema:          string,
     *     accepted:        list<array<string,mixed>>,
     *     rejected:        list<array{source:string,reason:string,detail?:string}>,
     *     stats:           array<string,int>,
     * }
     */
    public function validate(array $proposals): array
    {
        $accepted       = [];
        $rejected       = [];
        $seenFactKeys   = [];

        foreach ($proposals as $proposal) {
            $result = $this->evaluate($proposal, $seenFactKeys);

            if ($result['accepted']) {
                $normalized   = $result['normalized'];
                $accepted[]   = $normalized;
                $factKey      = $this->factKey($normalized);
                $seenFactKeys[$factKey] = self::EVIDENCE_ORDER[$normalized['evidence_strength']];
            } else {
                $rejected[] = $result['rejection'];
            }
        }

        return [
            'schema'   => self::SCHEMA,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'stats'    => [
                'proposals_in'    => count($proposals),
                'accepted_count'  => count($accepted),
                'rejected_count'  => count($rejected),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @param  array<string,int>    $seen   factKey → evidence order
     * @return array{accepted:bool, normalized?:array<string,mixed>, rejection?:array<string,string>}
     */
    private function evaluate(array $proposal, array &$seen): array
    {
        $source         = trim((string) ($proposal['source'] ?? ''));
        $type           = strtolower(trim((string) ($proposal['type'] ?? '')));
        $fact           = trim((string) ($proposal['fact'] ?? ''));
        $evidenceRef    = trim((string) ($proposal['evidence_ref'] ?? ''));
        $scope          = trim((string) ($proposal['scope'] ?? ''));
        $lesson         = trim((string) ($proposal['lesson'] ?? ''));
        $decisionEffect = trim((string) ($proposal['decision_effect'] ?? ''));
        $providerSafe   = $proposal['provider_safe'] ?? false;

        // Required fields
        if ($source === '' || $type === '' || $fact === ''
            || $evidenceRef === '' || $scope === '' || $lesson === '' || $decisionEffect === '') {
            return $this->reject($source, 'missing_required_fields',
                'type, fact, source, evidence_ref, scope, lesson, and decision_effect are required');
        }

        // Provider-safe gate — must be explicitly true.
        if ($providerSafe !== true) {
            return $this->reject($source, 'not_provider_safe',
                'provider_safe must be true before writeback');
        }

        // Known type
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            return $this->reject($source, 'unknown_type', "'{$type}' is not in ALLOWED_TYPES");
        }

        // Length guard
        if (mb_strlen($fact) > self::MAX_FACT_LENGTH) {
            return $this->reject($source, 'fact_too_long', 'max '.self::MAX_FACT_LENGTH.' chars');
        }

        // Prompt content detection
        $factLower = strtolower($fact);
        foreach (self::PROMPT_MARKERS as $marker) {
            if (str_contains($factLower, $marker)) {
                return $this->reject($source, 'raw_prompt_detected', "marker: {$marker}");
            }
        }

        // Provider identifier detection
        foreach (self::PROVIDER_MARKERS as $marker) {
            if (str_contains($factLower, $marker)) {
                return $this->reject($source, 'provider_details_detected', "marker: {$marker}");
            }
        }

        // Non-instructional content: operator frustration, emotional venting, raw chat
        // fragments — never canonical memory, even when provider_safe=true.
        foreach (self::NONINSTRUCTIONAL_MARKERS as $marker) {
            if (str_contains($factLower, $marker)) {
                return $this->reject($source, 'noninstructional_content_detected', "marker: {$marker}");
            }
        }

        // Actionability floor — reject vague facts that name no specifics.
        if ($this->isVague($fact)) {
            return $this->reject($source, 'vague_unactionable', 'fact names no capability, pattern family, failure mode, or next action');
        }

        // Evidence strength normalisation
        $rawStrength = strtolower(trim((string) ($proposal['evidence_strength'] ?? '')));
        if ($rawStrength === 'speculative') {
            return $this->reject($source, 'speculative_claim', "'speculative' is not an allowed evidence tier");
        }
        $strength = in_array($rawStrength, array_keys(self::EVIDENCE_ORDER), true)
            ? $rawStrength
            : self::EVIDENCE_INFERRED;

        // Deduplication — reject if an accepted entry already covers this (type, fact) at ≥ evidence
        $normalized = [
            'type'             => $type,
            'fact'             => $fact,
            'source'           => $source,
            'evidence_ref'     => $evidenceRef,
            'scope'            => $scope,
            'lesson'           => $lesson,
            'decision_effect'  => $decisionEffect,
            'provider_safe'    => true,
            'evidence_strength' => $strength,
        ];
        $factKey = $this->factKey($normalized);

        if (isset($seen[$factKey]) && $seen[$factKey] >= (self::EVIDENCE_ORDER[$strength])) {
            return $this->reject($source, 'duplicate_low_value', "already accepted with equal or stronger evidence");
        }

        return ['accepted' => true, 'normalized' => $normalized];
    }

    /** @return array{accepted:false,rejection:array<string,string>} */
    private function reject(string $source, string $reason, string $detail = ''): array
    {
        $entry = ['source' => $source, 'reason' => $reason];
        if ($detail !== '') {
            $entry['detail'] = $detail;
        }

        return ['accepted' => false, 'rejection' => $entry];
    }

    /**
     * True when the fact carries no actionability signal:
     *   - CamelCase identifier (capability/class name)
     *   - snake_case term (pattern family / failure mode)
     *   - hyphenated compound term
     *   - digit (numeric specificity)
     *   - domain action verb
     */
    private function isVague(string $fact): bool
    {
        return ! (
            str_contains($fact, '_')                   // snake_case (pattern family, failure mode)
            || str_contains($fact, '-')                // hyphenated compound
            || (bool) preg_match('/\d/', $fact)        // numeric specifics
            || (bool) preg_match('/[a-z][A-Z]/', $fact) // CamelCase identifier
            || (bool) preg_match(
                '/\b(?:prioritis|implement|fix|refactor|detect|ship|confirm|found|fail|avoid|deploy|skip)\w*/i',
                $fact,
            )
        );
    }

    /** @param array<string,mixed> $normalized */
    private function factKey(array $normalized): string
    {
        return $normalized['type'].'||'.strtolower(trim((string) $normalized['fact']));
    }

    /**
     * Provider-safe summary of validation results: exposes accepted_memory, secret_rejection,
     * duplicate_rejection, vague_lesson_rejection, and missing_evidence_rejection counts.
     *
     * @param  list<array<string,mixed>>  $proposals
     * @return array{accepted_memory:list<array<string,mixed>>, secret_rejection:int, duplicate_rejection:int, vague_lesson_rejection:int, missing_evidence_rejection:int, future_actionability:bool}
     */
    public function providerSafeSummary(array $proposals): array
    {
        $validation = $this->validate($proposals);
        $secretRejection = 0;
        $duplicateRejection = 0;
        $vagueLessonRejection = 0;
        $missingEvidenceRejection = 0;

        foreach ($validation['rejected'] as $rejection) {
            $reason = (string) ($rejection['reason'] ?? '');
            switch ($reason) {
                case 'not_provider_safe':
                case 'raw_prompt_detected':
                case 'provider_details_detected':
                    $secretRejection++;
                    break;
                case 'duplicate_low_value':
                    $duplicateRejection++;
                    break;
                case 'vague_unactionable':
                    $vagueLessonRejection++;
                    break;
                case 'missing_required_fields':
                case 'speculative_claim':
                    $missingEvidenceRejection++;
                    break;
            }
        }

        // Future actionability: at least one accepted memory with proven or observed evidence
        $futureActionability = false;
        foreach ($validation['accepted'] as $memory) {
            $strength = (string) ($memory['evidence_strength'] ?? '');
            if ($strength === self::EVIDENCE_PROVEN || $strength === self::EVIDENCE_OBSERVED) {
                $futureActionability = true;
                break;
            }
        }

        return [
            'accepted_memory' => $validation['accepted'],
            'secret_rejection' => $secretRejection,
            'duplicate_rejection' => $duplicateRejection,
            'vague_lesson_rejection' => $vagueLessonRejection,
            'missing_evidence_rejection' => $missingEvidenceRejection,
            'future_actionability' => $futureActionability,
        ];
    }
}
