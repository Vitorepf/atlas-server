<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

/**
 * First-decision router for the AAEOS cognitive immune layer.
 *
 * Maps a raw capture to exactly one of the 11 canonical immune input classes
 * via an ordered precedence cascade over the available signals
 * (high-risk markers > privacy > untrusted > candidate-signal strength >
 * recurrence > ephemeral default), then DERIVES default_destination,
 * memory_eligible, embedding_allowed and reason from the chosen class and the
 * computed signal strength. Pure: no I/O, no clock, no randomness, every
 * returned field is computed from the method inputs.
 */
final class AtlasAaeosCognitiveImmuneInputClassifier
{
    private const SCHEMA_VERSION = 'atlas.aaeos.cognitive_immune_input_classifier.v1';

    /**
     * Recurrence floor at which a recurring operational ephemeral capture
     * earns memory eligibility ("nao, salvo padrao recorrente").
     */
    private const RECURRENCE_MEMORY_THRESHOLD = 3;

    /**
     * Canonical class => default destination. Mirrors the existing immune
     * learning kernel lexicon byte-for-byte, except trivial_query whose
     * destination is pinned to 'respond_and_expire' by the slice contract.
     */
    private const DESTINATIONS = [
        'trivial_query' => 'respond_and_expire',
        'operational_ephemeral' => 'task_reminder_cold_file',
        'task_or_reminder' => 'task_routine',
        'project_evidence' => 'project_evidence',
        'conversation_trace' => 'audit_session',
        'personal_fact_candidate' => 'private_review',
        'technical_learning_candidate' => 'learning_signal',
        'strategic_insight_candidate' => 'memory_constellation_candidate',
        'untrusted_content' => 'cited_data_not_instruction',
        'prompt_injection' => 'blocked_ephemeral_evidence',
        'private_sensitive' => 'redact_minimize',
    ];

    /**
     * Classes for which embedding is never allowed regardless of strength.
     */
    private const EMBEDDING_FORBIDDEN_CLASSES = [
        'prompt_injection',
        'private_sensitive',
        'untrusted_content',
    ];

    /**
     * Text markers that signal an instruction-override / injection attempt.
     */
    private const INJECTION_MARKERS = [
        'ignore previous',
        'ignore all previous',
        'ignore the previous',
        'disregard previous',
        'disregard all',
        'ignore as instrucoes',
        'esqueca as instrucoes',
        'ignore suas instrucoes',
        'system prompt',
        'you are now',
        'voce agora e',
        'reveal your',
        'override instructions',
        'jailbreak',
        'do anything now',
    ];

    /**
     * Strong markers for a strategic insight candidate.
     */
    private const STRATEGIC_MARKERS = [
        'estrategia',
        'estrategica',
        'analogia',
        'insight',
        'tese',
        'hipotese',
        'principio',
        'strategy',
        'strategic',
        'thesis',
        'hypothesis',
        'framework',
    ];

    /**
     * Strong markers for a technical learning candidate.
     */
    private const TECHNICAL_MARKERS = [
        'bug',
        'patch',
        'regression',
        'race condition',
        'latency',
        'refactor',
        'stack trace',
        'null pointer',
        'memory leak',
        'algorithm',
        'compiler',
    ];

    /**
     * Strong markers for a personal fact candidate.
     */
    private const PERSONAL_MARKERS = [
        'eu prefiro',
        'eu moro',
        'meu aniversario',
        'minha esposa',
        'meu nome e',
        'i prefer',
        'my birthday',
        'i live in',
        'my favorite',
    ];

    /**
     * Strong markers for project evidence (e.g. "pausar esta funcionando").
     */
    private const PROJECT_EVIDENCE_MARKERS = [
        'esta funcionando',
        ' esta ok',
        'deploy ok',
        'build passou',
        'testes passaram',
        'merged',
        'release',
        'shipped',
        'em producao',
    ];

    /**
     * Markers for a low-value conversational trace.
     */
    private const CONVERSATION_MARKERS = [
        'obrigado',
        'valeu',
        'beleza',
        'tudo bem',
        'bom dia',
        'boa tarde',
        'thanks',
        'thank you',
        'good morning',
    ];

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{
     *     schema_version: string,
     *     input_class: string,
     *     default_destination: string,
     *     memory_eligible: bool,
     *     embedding_allowed: bool,
     *     reason: string,
     *     matched_signals: list<string>
     * }
     */
    public function classify(string $text, array $metadata = []): array
    {
        $hasUrl = $this->flag($metadata, 'has_url');
        $hasSecretMarker = $this->flag($metadata, 'has_secret_marker');
        $isQuestion = $this->flag($metadata, 'is_question');
        $imperativeVerb = $this->flag($metadata, 'imperative_verb');
        $privacyHint = $this->flag($metadata, 'privacy_hint');
        $recurrenceCount = $this->intFlag($metadata, 'recurrence_count');

        $normalized = $this->normalizeText($text);
        $hasInjectionText = $this->containsAny($normalized, self::INJECTION_MARKERS);

        $candidateScores = [
            'strategic_insight_candidate' => $this->countMatches($normalized, self::STRATEGIC_MARKERS),
            'technical_learning_candidate' => $this->countMatches($normalized, self::TECHNICAL_MARKERS),
            'personal_fact_candidate' => $this->countMatches($normalized, self::PERSONAL_MARKERS),
            'project_evidence' => $this->countMatches($normalized, self::PROJECT_EVIDENCE_MARKERS),
            'conversation_trace' => $this->countMatches($normalized, self::CONVERSATION_MARKERS),
        ];

        $matchedSignals = $this->collectSignals(
            $hasUrl,
            $hasSecretMarker,
            $isQuestion,
            $imperativeVerb,
            $privacyHint,
            $recurrenceCount,
            $hasInjectionText,
            $candidateScores,
        );

        [$inputClass, $reason] = $this->routeClass(
            $hasInjectionText,
            $hasSecretMarker,
            $privacyHint,
            $hasUrl,
            $candidateScores,
            $imperativeVerb,
            $isQuestion,
            $recurrenceCount,
        );

        $memoryEligible = $this->deriveMemoryEligible($inputClass, $recurrenceCount, $hasSecretMarker);
        $embeddingAllowed = $this->deriveEmbeddingAllowed($inputClass, $memoryEligible);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'input_class' => $inputClass,
            'default_destination' => self::DESTINATIONS[$inputClass],
            'memory_eligible' => $memoryEligible,
            'embedding_allowed' => $embeddingAllowed,
            'reason' => $reason,
            'matched_signals' => $matchedSignals,
        ];
    }

    /**
     * Ordered precedence cascade. Returns [input_class, decisive_reason].
     *
     * @param  array<string, int>  $candidateScores
     * @return array{0: string, 1: string}
     */
    private function routeClass(
        bool $hasInjectionText,
        bool $hasSecretMarker,
        bool $privacyHint,
        bool $hasUrl,
        array $candidateScores,
        bool $imperativeVerb,
        bool $isQuestion,
        int $recurrenceCount,
    ): array {
        // 1. High-risk markers: instruction-override / injection always wins.
        if ($hasInjectionText) {
            return ['prompt_injection', 'injection_marker'];
        }

        // 2. Privacy: secret markers / privacy hints redact to sensitive.
        if ($hasSecretMarker) {
            return ['private_sensitive', 'secret_marker_privacy'];
        }

        if ($privacyHint) {
            return ['private_sensitive', 'privacy_hint'];
        }

        // 3. Untrusted: foreign / cited content is never a trusted candidate.
        if ($hasUrl) {
            return ['untrusted_content', 'untrusted_url'];
        }

        // 4. Candidate-signal strength: strongest lexicon hit wins when > 0.
        $strongest = $this->strongestCandidate($candidateScores);
        if ($strongest !== null) {
            return [$strongest, $this->candidateReason($strongest)];
        }

        // 5. Recurrence / action / question shaping.
        if ($imperativeVerb) {
            return ['task_or_reminder', 'imperative_task'];
        }

        if ($isQuestion) {
            return ['trivial_query', 'trivial_question'];
        }

        if ($recurrenceCount >= self::RECURRENCE_MEMORY_THRESHOLD) {
            return ['operational_ephemeral', 'recurrent_ephemeral'];
        }

        // 6. Ephemeral default.
        return ['operational_ephemeral', 'ephemeral_default'];
    }

    private function deriveMemoryEligible(string $inputClass, int $recurrenceCount, bool $hasSecretMarker): bool
    {
        return match ($inputClass) {
            'trivial_query', 'conversation_trace', 'prompt_injection' => false,
            'private_sensitive' => false,
            'untrusted_content' => false,
            'task_or_reminder' => false,
            'operational_ephemeral' => $recurrenceCount >= self::RECURRENCE_MEMORY_THRESHOLD,
            'personal_fact_candidate',
            'technical_learning_candidate',
            'strategic_insight_candidate',
            'project_evidence' => ! $hasSecretMarker,
            default => false,
        };
    }

    private function deriveEmbeddingAllowed(string $inputClass, bool $memoryEligible): bool
    {
        if (in_array($inputClass, self::EMBEDDING_FORBIDDEN_CLASSES, true)) {
            return false;
        }

        return match ($inputClass) {
            'trivial_query', 'conversation_trace', 'task_or_reminder' => false,
            'operational_ephemeral' => $memoryEligible,
            'personal_fact_candidate',
            'technical_learning_candidate',
            'strategic_insight_candidate',
            'project_evidence' => true,
            default => false,
        };
    }

    /**
     * @param  array<string, int>  $candidateScores
     */
    private function strongestCandidate(array $candidateScores): ?string
    {
        $best = null;
        $bestScore = 0;

        foreach ($candidateScores as $class => $score) {
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $class;
            }
        }

        return $best;
    }

    private function candidateReason(string $candidateClass): string
    {
        return match ($candidateClass) {
            'strategic_insight_candidate' => 'strategic_insight_signal',
            'technical_learning_candidate' => 'technical_learning_signal',
            'personal_fact_candidate' => 'personal_fact_signal',
            'project_evidence' => 'project_evidence_signal',
            'conversation_trace' => 'conversation_trace_signal',
            default => 'candidate_signal',
        };
    }

    /**
     * @param  array<string, int>  $candidateScores
     * @return list<string>
     */
    private function collectSignals(
        bool $hasUrl,
        bool $hasSecretMarker,
        bool $isQuestion,
        bool $imperativeVerb,
        bool $privacyHint,
        int $recurrenceCount,
        bool $hasInjectionText,
        array $candidateScores,
    ): array {
        $signals = [];

        if ($hasUrl) {
            $signals[] = 'has_url';
        }
        if ($hasSecretMarker) {
            $signals[] = 'has_secret_marker';
        }
        if ($isQuestion) {
            $signals[] = 'is_question';
        }
        if ($imperativeVerb) {
            $signals[] = 'imperative_verb';
        }
        if ($privacyHint) {
            $signals[] = 'privacy_hint';
        }
        if ($recurrenceCount >= self::RECURRENCE_MEMORY_THRESHOLD) {
            $signals[] = 'recurrent';
        }
        if ($hasInjectionText) {
            $signals[] = 'injection_marker';
        }
        foreach ($candidateScores as $class => $score) {
            if ($score > 0) {
                $signals[] = $this->candidateReason($class);
            }
        }

        return AtlasAaeosStringListNormalizer::uniqueSortedStrings($signals);
    }

    private function normalizeText(string $text): string
    {
        return mb_strtolower(trim($text));
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        return $this->countMatches($haystack, $needles) > 0;
    }

    /**
     * @param  list<string>  $needles
     */
    private function countMatches(string $haystack, array $needles): int
    {
        $hits = 0;

        foreach ($needles as $needle) {
            if ('' !== $needle && str_contains($haystack, $needle)) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function flag(array $metadata, string $key): bool
    {
        return ($metadata[$key] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function intFlag(array $metadata, string $key): int
    {
        $value = $metadata[$key] ?? 0;

        return is_int($value) ? max($value, 0) : max((int) $value, 0);
    }
}
