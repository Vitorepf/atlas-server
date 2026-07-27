<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasStringListNormalizer;

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
final class AtlasCognitiveImmuneInputClassifier
{
    public const FIELD_INPUT_CLASS = 'input_class';
    public const FIELD_MATCHED_SIGNALS = 'matched_signals';
    public const SCHEMA_VERSION = 'atlas.aaeos.cognitive_immune_input_classifier.v1';

    /**
     * Recurrence floor at which a recurring operational ephemeral capture
     * earns memory eligibility ("nao, salvo padrao recorrente").
     */
    public const RECURRENCE_MEMORY_THRESHOLD = 3;

    public const CLASS_TRIVIAL_QUERY = 'trivial_query';
    public const CLASS_OPERATIONAL_EPHEMERAL = 'operational_ephemeral';
    public const CLASS_TASK_OR_REMINDER = 'task_or_reminder';
    public const CLASS_PROJECT_EVIDENCE = 'project_evidence';
    public const CLASS_CONVERSATION_TRACE = 'conversation_trace';
    public const CLASS_PERSONAL_FACT_CANDIDATE = 'personal_fact_candidate';
    public const CLASS_TECHNICAL_LEARNING_CANDIDATE = 'technical_learning_candidate';
    public const CLASS_STRATEGIC_INSIGHT_CANDIDATE = 'strategic_insight_candidate';
    public const CLASS_UNTRUSTED_CONTENT = 'untrusted_content';
    public const CLASS_PROMPT_INJECTION = 'prompt_injection';
    public const CLASS_PRIVATE_SENSITIVE = 'private_sensitive';
    public const FIELD_DEFAULT_DESTINATION = 'default_destination';
    public const FIELD_EMBEDDING_ALLOWED = 'embedding_allowed';
    public const FIELD_MEMORY_ELIGIBLE = 'memory_eligible';
    public const FIELD_REASON = 'reason';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_AUDIT_SESSION = 'audit_session';
    public const FIELD_BLOCKED_EPHEMERAL_EVIDENCE = 'blocked_ephemeral_evidence';
    public const FIELD_CANDIDATE_SIGNAL = 'candidate_signal';
    public const FIELD_CITED_DATA_NOT_INSTRUCTION = 'cited_data_not_instruction';
    public const FIELD_CONVERSATION_TRACE_SIGNAL = 'conversation_trace_signal';
    public const FIELD_LEARNING_SIGNAL = 'learning_signal';
    public const FIELD_MEMORY_CONSTELLATION_CANDIDATE = 'memory_constellation_candidate';
    public const FIELD_PERSONAL_FACT_SIGNAL = 'personal_fact_signal';
    public const FIELD_PRIVATE_REVIEW = 'private_review';
    public const FIELD_PROJECT_EVIDENCE = 'project_evidence';
    public const FIELD_PROJECT_EVIDENCE_SIGNAL = 'project_evidence_signal';
    public const FIELD_REDACT_MINIMIZE = 'redact_minimize';
    public const FIELD_RESPOND_AND_EXPIRE = 'respond_and_expire';
    public const FIELD_STRATEGIC_INSIGHT_SIGNAL = 'strategic_insight_signal';
    public const FIELD_TASK_REMINDER_COLD_FILE = 'task_reminder_cold_file';
    public const FIELD_TASK_ROUTINE = 'task_routine';
    public const FIELD_ALGORITHM = 'algorithm';
    public const FIELD_ESTRATEGICA = 'estrategica';
    public const FIELD_ANALOGIA = 'analogia';
    public const FIELD_COMPILER = 'compiler';
    public const FIELD_FRAMEWORK = 'framework';
    public const FIELD_HAS_SECRET_MARKER = 'has_secret_marker';
    public const FIELD_HAS_URL = 'has_url';
    public const FIELD_HIPOTESE = 'hipotese';
    public const FIELD_HYPOTHESIS = 'hypothesis';
    public const FIELD_IMPERATIVE_VERB = 'imperative_verb';
    public const FIELD_INSIGHT = 'insight';
    public const FIELD_IS_QUESTION = 'is_question';
    public const FIELD_JAILBREAK = 'jailbreak';
    public const FIELD_LATENCY = 'latency';
    public const FIELD_MERGED = 'merged';
    public const FIELD_PATCH = 'patch';
    public const FIELD_PRINCIPIO = 'principio';
    public const FIELD_PRIVACY_HINT = 'privacy_hint';
    public const FIELD_RECURRENCE_COUNT = 'recurrence_count';
    public const FIELD_REFACTOR = 'refactor';
    public const FIELD_REGRESSION = 'regression';
    public const FIELD_RELEASE = 'release';
    public const FIELD_SHIPPED = 'shipped';
    public const FIELD_STRATEGY = 'strategy';
    public const FIELD_STRATEGIC = 'strategic';
    public const FIELD_TECHNICAL_LEARNING_SIGNAL = 'technical_learning_signal';
    public const FIELD_TESE = 'tese';
    public const FIELD_THANKS = 'thanks';
    public const FIELD_THESIS = 'thesis';
    public const FIELD_VALEU = 'valeu';
    public const FIELD_BELEZA = 'beleza';
    public const FIELD_INJECTION_MARKER = 'injection_marker';
    public const FIELD_BUG = 'bug';
    public const FIELD_EPHEMERAL_DEFAULT = 'ephemeral_default';
    public const FIELD_ESTRATEGIA = 'estrategia';
    public const FIELD_IMPERATIVE_TASK = 'imperative_task';
    public const FIELD_OBRIGADO = 'obrigado';
    public const FIELD_RECURRENT = 'recurrent';
    public const FIELD_RECURRENT_EPHEMERAL = 'recurrent_ephemeral';
    public const FIELD_SECRET_MARKER_PRIVACY = 'secret_marker_privacy';
    public const FIELD_TRIVIAL_QUESTION = 'trivial_question';
    public const FIELD_UNTRUSTED_URL = 'untrusted_url';
    public const FIELD_BOA_TARDE = 'boa tarde';
    public const FIELD_BOM_DIA = 'bom dia';
    public const FIELD_BUILD_PASSOU = 'build passou';
    public const FIELD_DEPLOY_OK = 'deploy ok';
    public const FIELD_DISREGARD_ALL = 'disregard all';
    public const FIELD_DISREGARD_PREVIOUS = 'disregard previous';
    public const FIELD_DO_ANYTHING_NOW = 'do anything now';
    public const FIELD_EM_PRODUCAO = 'em producao';
    public const FIELD_ESQUECA_AS_INSTRUCOES = 'esqueca as instrucoes';
    public const FIELD_ESTA_FUNCIONANDO = 'esta funcionando';
    public const FIELD_EU_MORO = 'eu moro';
    public const FIELD_EU_PREFIRO = 'eu prefiro';
    public const FIELD_GOOD_MORNING = 'good morning';
    public const FIELD_IGNORE_ALL_PREVIOUS = 'ignore all previous';
    public const FIELD_IGNORE_AS_INSTRUCOES = 'ignore as instrucoes';
    public const FIELD_IGNORE_PREVIOUS = 'ignore previous';
    public const FIELD_IGNORE_SUAS_INSTRUCOES = 'ignore suas instrucoes';
    public const FIELD_IGNORE_THE_PREVIOUS = 'ignore the previous';
    public const FIELD_MEMORY_LEAK = 'memory leak';
    public const FIELD_MEU_ANIVERSARIO = 'meu aniversario';
    public const FIELD_MEU_NOME_E = 'meu nome e';
    public const FIELD_MINHA_ESPOSA = 'minha esposa';
    public const FIELD_MY_BIRTHDAY = 'my birthday';
    public const FIELD_MY_FAVORITE = 'my favorite';
    public const FIELD_NULL_POINTER = 'null pointer';
    public const FIELD_OVERRIDE_INSTRUCTIONS = 'override instructions';
    public const FIELD_RACE_CONDITION = 'race condition';
    public const FIELD_REVEAL_YOUR = 'reveal your';
    public const FIELD_STACK_TRACE = 'stack trace';
    public const FIELD_SYSTEM_PROMPT = 'system prompt';
    public const FIELD_TESTES_PASSARAM = 'testes passaram';
    public const FIELD_THANK_YOU = 'thank you';
    public const FIELD_TUDO_BEM = 'tudo bem';
    public const FIELD_VOCE_AGORA_E = 'voce agora e';
    public const FIELD_YOU_ARE_NOW = 'you are now';
    public const FIELD_I_LIVE_IN = 'i live in';
    public const FIELD_I_PREFER = 'i prefer';

    /**
     * Canonical class => default destination. Mirrors the existing immune
     * learning kernel lexicon byte-for-byte, except trivial_query whose
     * destination is pinned to 'respond_and_expire' by the slice contract.
     *
     * @var array<string, string>
     */
    public const DESTINATIONS = [
        self::CLASS_TRIVIAL_QUERY => self::FIELD_RESPOND_AND_EXPIRE,
        self::CLASS_OPERATIONAL_EPHEMERAL => self::FIELD_TASK_REMINDER_COLD_FILE,
        self::CLASS_TASK_OR_REMINDER => self::FIELD_TASK_ROUTINE,
        self::CLASS_PROJECT_EVIDENCE => self::FIELD_PROJECT_EVIDENCE,
        self::CLASS_CONVERSATION_TRACE => self::FIELD_AUDIT_SESSION,
        self::CLASS_PERSONAL_FACT_CANDIDATE => self::FIELD_PRIVATE_REVIEW,
        self::CLASS_TECHNICAL_LEARNING_CANDIDATE => self::FIELD_LEARNING_SIGNAL,
        self::CLASS_STRATEGIC_INSIGHT_CANDIDATE => self::FIELD_MEMORY_CONSTELLATION_CANDIDATE,
        self::CLASS_UNTRUSTED_CONTENT => self::FIELD_CITED_DATA_NOT_INSTRUCTION,
        self::CLASS_PROMPT_INJECTION => self::FIELD_BLOCKED_EPHEMERAL_EVIDENCE,
        self::CLASS_PRIVATE_SENSITIVE => self::FIELD_REDACT_MINIMIZE,
    ];

    /**
     * Classes for which embedding is never allowed regardless of strength.
     *
     * @var list<string>
     */
    public const EMBEDDING_FORBIDDEN_CLASSES = [
        self::CLASS_PROMPT_INJECTION,
        self::CLASS_PRIVATE_SENSITIVE,
        self::CLASS_UNTRUSTED_CONTENT,
    ];

    /**
     * Text markers that signal an instruction-override / injection attempt.
     */
    public const INJECTION_MARKERS = [
        self::FIELD_IGNORE_PREVIOUS,
        self::FIELD_IGNORE_ALL_PREVIOUS,
        self::FIELD_IGNORE_THE_PREVIOUS,
        self::FIELD_DISREGARD_PREVIOUS,
        self::FIELD_DISREGARD_ALL,
        self::FIELD_IGNORE_AS_INSTRUCOES,
        self::FIELD_ESQUECA_AS_INSTRUCOES,
        self::FIELD_IGNORE_SUAS_INSTRUCOES,
        self::FIELD_SYSTEM_PROMPT,
        self::FIELD_YOU_ARE_NOW,
        self::FIELD_VOCE_AGORA_E,
        self::FIELD_REVEAL_YOUR,
        self::FIELD_OVERRIDE_INSTRUCTIONS,
        self::FIELD_JAILBREAK,
        self::FIELD_DO_ANYTHING_NOW,
    ];

    /**
     * Strong markers for a strategic insight candidate.
     */
    public const STRATEGIC_MARKERS = [
        self::FIELD_ESTRATEGIA,
        self::FIELD_ESTRATEGICA,
        self::FIELD_ANALOGIA,
        self::FIELD_INSIGHT,
        self::FIELD_TESE,
        self::FIELD_HIPOTESE,
        self::FIELD_PRINCIPIO,
        self::FIELD_STRATEGY,
        self::FIELD_STRATEGIC,
        self::FIELD_THESIS,
        self::FIELD_HYPOTHESIS,
        self::FIELD_FRAMEWORK,
    ];

    /**
     * Strong markers for a technical learning candidate.
     */
    public const TECHNICAL_MARKERS = [
        self::FIELD_BUG,
        self::FIELD_PATCH,
        self::FIELD_REGRESSION,
        self::FIELD_RACE_CONDITION,
        self::FIELD_LATENCY,
        self::FIELD_REFACTOR,
        self::FIELD_STACK_TRACE,
        self::FIELD_NULL_POINTER,
        self::FIELD_MEMORY_LEAK,
        self::FIELD_ALGORITHM,
        self::FIELD_COMPILER,
    ];

    /**
     * Strong markers for a personal fact candidate.
     */
    public const PERSONAL_MARKERS = [
        self::FIELD_EU_PREFIRO,
        self::FIELD_EU_MORO,
        self::FIELD_MEU_ANIVERSARIO,
        self::FIELD_MINHA_ESPOSA,
        self::FIELD_MEU_NOME_E,
        self::FIELD_I_PREFER,
        self::FIELD_MY_BIRTHDAY,
        self::FIELD_I_LIVE_IN,
        self::FIELD_MY_FAVORITE,
    ];

    /**
     * Strong markers for project evidence (e.g. "pausar esta funcionando").
     */
    public const PROJECT_EVIDENCE_MARKERS = [
        self::FIELD_ESTA_FUNCIONANDO,
        ' esta ok',
        self::FIELD_DEPLOY_OK,
        self::FIELD_BUILD_PASSOU,
        self::FIELD_TESTES_PASSARAM,
        self::FIELD_MERGED,
        self::FIELD_RELEASE,
        self::FIELD_SHIPPED,
        self::FIELD_EM_PRODUCAO,
    ];

    /**
     * Markers for a low-value conversational trace.
     */
    public const CONVERSATION_MARKERS = [
        self::FIELD_OBRIGADO,
        self::FIELD_VALEU,
        self::FIELD_BELEZA,
        self::FIELD_TUDO_BEM,
        self::FIELD_BOM_DIA,
        self::FIELD_BOA_TARDE,
        self::FIELD_THANKS,
        self::FIELD_THANK_YOU,
        self::FIELD_GOOD_MORNING,
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
        $hasUrl = $this->flag($metadata, self::FIELD_HAS_URL);
        $hasSecretMarker = $this->flag($metadata, self::FIELD_HAS_SECRET_MARKER);
        $isQuestion = $this->flag($metadata, self::FIELD_IS_QUESTION);
        $imperativeVerb = $this->flag($metadata, self::FIELD_IMPERATIVE_VERB);
        $privacyHint = $this->flag($metadata, self::FIELD_PRIVACY_HINT);
        $recurrenceCount = $this->intFlag($metadata, self::FIELD_RECURRENCE_COUNT);

        $normalized = $this->normalizeText($text);
        $hasInjectionText = $this->containsAny($normalized, self::INJECTION_MARKERS);

        $candidateScores = [
            self::CLASS_STRATEGIC_INSIGHT_CANDIDATE => $this->countMatches($normalized, self::STRATEGIC_MARKERS),
            self::CLASS_TECHNICAL_LEARNING_CANDIDATE => $this->countMatches($normalized, self::TECHNICAL_MARKERS),
            self::CLASS_PERSONAL_FACT_CANDIDATE => $this->countMatches($normalized, self::PERSONAL_MARKERS),
            self::CLASS_PROJECT_EVIDENCE => $this->countMatches($normalized, self::PROJECT_EVIDENCE_MARKERS),
            self::CLASS_CONVERSATION_TRACE => $this->countMatches($normalized, self::CONVERSATION_MARKERS),
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
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_INPUT_CLASS => $inputClass,
            self::FIELD_DEFAULT_DESTINATION => self::DESTINATIONS[$inputClass],
            self::FIELD_MEMORY_ELIGIBLE => $memoryEligible,
            self::FIELD_EMBEDDING_ALLOWED => $embeddingAllowed,
            self::FIELD_REASON => $reason,
            self::FIELD_MATCHED_SIGNALS => $matchedSignals,
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
            return [self::CLASS_PROMPT_INJECTION, self::FIELD_INJECTION_MARKER];
        }

        // 2. Privacy: secret markers / privacy hints redact to sensitive.
        if ($hasSecretMarker) {
            return [self::CLASS_PRIVATE_SENSITIVE, self::FIELD_SECRET_MARKER_PRIVACY];
        }

        if ($privacyHint) {
            return [self::CLASS_PRIVATE_SENSITIVE, self::FIELD_PRIVACY_HINT];
        }

        // 3. Untrusted: foreign / cited content is never a trusted candidate.
        if ($hasUrl) {
            return [self::CLASS_UNTRUSTED_CONTENT, self::FIELD_UNTRUSTED_URL];
        }

        // 4. Candidate-signal strength: strongest lexicon hit wins when > 0.
        $strongest = $this->strongestCandidate($candidateScores);
        if ($strongest !== null) {
            return [$strongest, $this->candidateReason($strongest)];
        }

        // 5. Recurrence / action / question shaping.
        if ($imperativeVerb) {
            return [self::CLASS_TASK_OR_REMINDER, self::FIELD_IMPERATIVE_TASK];
        }

        if ($isQuestion) {
            return [self::CLASS_TRIVIAL_QUERY, self::FIELD_TRIVIAL_QUESTION];
        }

        if ($recurrenceCount >= self::RECURRENCE_MEMORY_THRESHOLD) {
            return [self::CLASS_OPERATIONAL_EPHEMERAL, self::FIELD_RECURRENT_EPHEMERAL];
        }

        // 6. Ephemeral default.
        return [self::CLASS_OPERATIONAL_EPHEMERAL, self::FIELD_EPHEMERAL_DEFAULT];
    }

    private function deriveMemoryEligible(string $inputClass, int $recurrenceCount, bool $hasSecretMarker): bool
    {
        return match ($inputClass) {
            self::CLASS_TRIVIAL_QUERY, self::CLASS_CONVERSATION_TRACE, self::CLASS_PROMPT_INJECTION => false,
            self::CLASS_PRIVATE_SENSITIVE => false,
            self::CLASS_UNTRUSTED_CONTENT => false,
            self::CLASS_TASK_OR_REMINDER => false,
            self::CLASS_OPERATIONAL_EPHEMERAL => $recurrenceCount >= self::RECURRENCE_MEMORY_THRESHOLD,
            self::CLASS_PERSONAL_FACT_CANDIDATE,
            self::CLASS_TECHNICAL_LEARNING_CANDIDATE,
            self::CLASS_STRATEGIC_INSIGHT_CANDIDATE,
            self::CLASS_PROJECT_EVIDENCE => ! $hasSecretMarker,
            default => false,
        };
    }

    private function deriveEmbeddingAllowed(string $inputClass, bool $memoryEligible): bool
    {
        if (in_array($inputClass, self::EMBEDDING_FORBIDDEN_CLASSES, true)) {
            return false;
        }

        return match ($inputClass) {
            self::CLASS_TRIVIAL_QUERY, self::CLASS_CONVERSATION_TRACE, self::CLASS_TASK_OR_REMINDER => false,
            self::CLASS_OPERATIONAL_EPHEMERAL => $memoryEligible,
            self::CLASS_PERSONAL_FACT_CANDIDATE,
            self::CLASS_TECHNICAL_LEARNING_CANDIDATE,
            self::CLASS_STRATEGIC_INSIGHT_CANDIDATE,
            self::CLASS_PROJECT_EVIDENCE => true,
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
            self::CLASS_STRATEGIC_INSIGHT_CANDIDATE => self::FIELD_STRATEGIC_INSIGHT_SIGNAL,
            self::CLASS_TECHNICAL_LEARNING_CANDIDATE => self::FIELD_TECHNICAL_LEARNING_SIGNAL,
            self::CLASS_PERSONAL_FACT_CANDIDATE => self::FIELD_PERSONAL_FACT_SIGNAL,
            self::CLASS_PROJECT_EVIDENCE => self::FIELD_PROJECT_EVIDENCE_SIGNAL,
            self::CLASS_CONVERSATION_TRACE => self::FIELD_CONVERSATION_TRACE_SIGNAL,
            default => self::FIELD_CANDIDATE_SIGNAL,
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
            $signals[] = self::FIELD_HAS_URL;
        }
        if ($hasSecretMarker) {
            $signals[] = self::FIELD_HAS_SECRET_MARKER;
        }
        if ($isQuestion) {
            $signals[] = self::FIELD_IS_QUESTION;
        }
        if ($imperativeVerb) {
            $signals[] = self::FIELD_IMPERATIVE_VERB;
        }
        if ($privacyHint) {
            $signals[] = self::FIELD_PRIVACY_HINT;
        }
        if ($recurrenceCount >= self::RECURRENCE_MEMORY_THRESHOLD) {
            $signals[] = self::FIELD_RECURRENT;
        }
        if ($hasInjectionText) {
            $signals[] = self::FIELD_INJECTION_MARKER;
        }
        foreach ($candidateScores as $class => $score) {
            if ($score > 0) {
                $signals[] = $this->candidateReason($class);
            }
        }

        return AtlasStringListNormalizer::uniqueSortedStrings($signals);
    }

    private function normalizeText(string $text): string
    {
        return AiValueNormalizer::lowerTrimmedString($text);
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
