<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

use App\Services\Ai\Cognition\CognitiveImmunePromotionGateEvaluator;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Atlas Memory Cognitive Immune And Learning Kernel decider.
 *
 * Pure, deterministic runtime for the cognitive immune contract: "capture much,
 * believe little, promote with evidence, recover with precision, forget with
 * discipline, and stop noise from contaminating memory, context, Decide or
 * Constelacao." The doc declares concrete decision rules this service enforces;
 * it NEVER writes a database, calls a provider, embeds a vector or mutates
 * memory. It only decides whether a raw capture is eligible to advance.
 *
 * Enforced contracts:
 *
 *   1. Default State (cognitive quarantine): every input is born ineligible for
 *      memory, context, constellation and embedding with promotion_status
 *      `unclassified`. `defaultState()` returns exactly that closed shape.
 *
 *   2. Input Classes table: `classify()` maps each documented class to its
 *      default destination and whether it can ever become memory. Classes the
 *      doc marks "Nao" (trivial_query, operational_ephemeral, task_or_reminder,
 *      conversation_trace, prompt_injection) can never be auto-promoted to
 *      knowledge; candidate classes require evidence/review.
 *
 *   3. Promotion Gates G0-G8: `evaluatePromotion()` runs the documented gate
 *      ladder in order. ALL gates must pass to promote. The first failing gate
 *      stops promotion and the verdict reports it. Rule 7 is hard-wired: a
 *      candidate whose scope is critical (global/policy) can never resolve to
 *      `auto` promotion mode — it is forced to `review`/`proposal`. A capture
 *      that did not pass G0 can never be promoted (Rule 1).
 *
 *   4. Embedding Quarantine: `embeddingAllowed()` keeps the six documented
 *      non-embeddable categories out of the vector store by default and refuses
 *      any vector lacking the required provenance metadata.
 *
 *   5. Constelacao Gate: `constellationEligible()` admits only inputs carrying
 *      every documented clearance (provenance, semantic_value, scope,
 *      reversibility, privacy clearance, reason) AND requires the candidate to
 *      already be a promoted strategic candidate.
 *
 *   6. Non-Negotiable Rules: `nonNegotiableRules()` enumerates the 8 hard rules;
 *      `forgettingReceipt()` proves every demotion carries a reason + evidence
 *      (Rule via Forgetting section); retrieval without a reason is a bug.
 *
 * @see docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
 */
final class AtlasMemoryCognitiveImmuneLearningKernelService
{
    /** Stable schema id for the verdict envelope this service emits. */
    public const SCHEMA = 'atlas.memory.cognitive_immune_learning_kernel.v1';
    public const FIELD_G0 = 'G0';
    public const FIELD_G1 = 'G1';
    public const FIELD_G2 = 'G2';
    public const FIELD_G3 = 'G3';
    public const FIELD_G4 = 'G4';
    public const FIELD_G5 = 'G5';
    public const FIELD_G6 = 'G6';
    public const FIELD_G7 = 'G7';
    public const FIELD_G8 = 'G8';
    public const FIELD_CAN_BECOME_MEMORY = 'can_become_memory';
    public const FIELD_DESTINATION = 'destination';
    public const FIELD_ID = 'id';
    public const FIELD_QUESTION = 'question';
    public const FIELD_SIGNAL = 'signal';
    public const FIELD_UNTRUSTED_CONTENT = 'untrusted_content';
    public const FIELD_REASONS = 'reasons';
    public const FIELD_ATOMIC_CLAIM = 'atomic_claim';
    public const FIELD_CAPTURE_CONSENTED = 'capture_consented';
    public const FIELD_EMBEDDING_ALLOWED = 'embedding_allowed';
    public const FIELD_GATE_STATUSES = 'gate_statuses';
    public const FIELD_OUTCOME_VALIDATED = 'outcome_validated';
    public const FIELD_PROMOTION_STATUS = 'promotion_status';
    public const FIELD_PROVIDER_SAFE = 'provider_safe';
    public const FIELD_AUTO = 'auto';
    public const FIELD_REVIEW = 'review';
    public const FIELD_SCOPE = 'scope';
    public const FIELD_BLOCKING_GATE_IDS = 'blocking_gate_ids';
    public const FIELD_CONTAINS_SECRET = 'contains_secret';
    public const FIELD_CONTAINS_SENSITIVE_UNNECESSARY = 'contains_sensitive_unnecessary';
    public const FIELD_FUTURE_SIGNAL = 'future_signal';
    public const FIELD_INPUT_CLASS = 'input_class';
    public const FIELD_NO_CONTRADICTION = 'no_contradiction';
    public const FIELD_NOVELTY = 'novelty';
    public const FIELD_OPERATIONAL_EPHEMERAL = 'operational_ephemeral';
    public const FIELD_PENDING_GATE_IDS = 'pending_gate_ids';
    public const FIELD_PRIVACY_CLASS = 'privacy_class';
    public const FIELD_PRIVATE_SENSITIVE = 'private_sensitive';
    public const FIELD_PROBATION_ENTERED = 'probation_entered';
    public const FIELD_PROJECT_EVIDENCE = 'project_evidence';
    public const FIELD_PROMOTION_MODE = 'promotion_mode';
    public const FIELD_PROMOTION_MODE_SET = 'promotion_mode_set';
    public const FIELD_REASON = 'reason';
    public const FIELD_RECURRENCE_COUNT = 'recurrence_count';
    public const FIELD_SCOPE_RESOLVED = 'scope_resolved';
    public const FIELD_STRATEGIC_INSIGHT_CANDIDATE = 'strategic_insight_candidate';
    public const FIELD_PROMPT_INJECTION = 'prompt_injection';
    public const FIELD_WATCH = 'watch';
    public const FIELD_BLOCKED = 'blocked';
    public const FIELD_TRIVIAL_QUERY = 'trivial_query';
    public const FIELD_ALLOWED = 'allowed';
    public const FIELD_ANSWER_AND_EXPIRE = 'answer_and_expire';
    public const FIELD_ARCHIVAL = 'archival';
    public const FIELD_ARCHIVE_IS_NOT_MEMORY_APPROVED = 'archive_is_not_memory_approved';
    public const FIELD_ATOMIC_CLAIM_PRESENT = 'atomic_claim_present';
    public const FIELD_ARCHIVED = 'archived';
    public const FIELD_AUDIT_SESSION = 'audit_session';
    public const FIELD_BLOCKED_EPHEMERAL_EVIDENCE = 'blocked_ephemeral_evidence';
    public const FIELD_CANDIDATE = 'candidate';
    public const FIELD_CITED_DATA_NOT_INSTRUCTION = 'cited_data_not_instruction';
    public const FIELD_CLAIM_SOURCE_PRESENT = 'claim_source_present';
    public const FIELD_CLAIM_TYPE = 'claim_type';
    public const FIELD_CONFIDENCE_DECAY = 'confidence_decay';
    public const FIELD_CONSENT_GRANTED = 'consent_granted';
    public const FIELD_CONSTELLATION_ELIGIBLE = 'constellation_eligible';
    public const FIELD_CONSTELLATION_ELIGIBLE_TRUE = 'constellation_eligible_true';
    public const FIELD_BLOCKED_PRIVATE = 'blocked_private';
    public const FIELD_CONTEXT_ELIGIBLE = 'context_eligible';
    public const FIELD_CONTRADICTS_NEWER = 'contradicts_newer';
    public const FIELD_CONVERSATION_TRACE = 'conversation_trace';
    public const FIELD_ELIGIBLE = 'eligible';
    public const FIELD_FAILED_GATE = 'failed_gate';
    public const FIELD_FUTURE_UTILITY = 'future_utility';
    public const FIELD_GATE_RESULTS = 'gate_results';
    public const FIELD_HARD_DELETE = 'hard_delete';
    public const FIELD_IMMUNE_SIGNALS = 'immune_signals';
    public const FIELD_INTERNAL = 'internal';
    public const FIELD_KIND = 'kind';
    public const FIELD_LEARNING_SIGNAL = 'learning_signal';
    public const FIELD_MEMORY_CONSTELLATION_CANDIDATE = 'memory_constellation_candidate';
    public const FIELD_MEMORY_ELIGIBLE = 'memory_eligible';
    public const FIELD_MISSING_CLEARANCES = 'missing_clearances';
    public const FIELD_MISSING_META = 'missing_meta';
    public const FIELD_ON_PROBATION = 'on_probation';
    public const FIELD_NEGATIVE_MEMORY = 'negative_memory';
    public const FIELD_PASS = 'pass';
    public const FIELD_PASSED = 'passed';
    public const FIELD_PASSED_GATES = 'passed_gates';
    public const FIELD_PENDING = 'pending';
    public const FIELD_PERSONAL_FACT_CANDIDATE = 'personal_fact_candidate';
    public const FIELD_PRIVATE_REVIEW = 'private_review';
    public const FIELD_PROMOTE = 'promote';
    public const FIELD_PROMOTION_MODE_HINT = 'promotion_mode_hint';
    public const FIELD_PROPOSAL = 'proposal';
    public const FIELD_RAW_PRIVATE_NOTE = 'raw_private_note';
    public const FIELD_RECEIPT_COMPLETE = 'receipt_complete';
    public const FIELD_REDACT_MINIMIZE = 'redact_minimize';
    public const FIELD_RESULTING_STATE = 'resulting_state';
    public const FIELD_RETENTION = 'retention';
    public const FIELD_RETENTION_OK = 'retention_ok';
    public const FIELD_RETRIEVAL_WITHOUT_REASON_IS_A_BUG = 'retrieval_without_reason_is_a_bug';
    public const FIELD_REVERSIBILITY = 'reversibility';
    public const FIELD_EXPIRES_AT = 'expires_at';
    public const FIELD_PRIVACY_CLEARANCE = 'privacy_clearance';
    public const FIELD_SAFETY_FILTERS_PASSED_BEFORE_SIMILARITY = 'safety_filters_passed_before_similarity';
    public const FIELD_SCHEMA = 'schema';
    public const FIELD_SEMANTIC_VALUE = 'semantic_value';
    public const FIELD_SESSION = 'session';
    public const FIELD_STALE = 'stale';
    public const FIELD_STATE = 'state';
    public const FIELD_SUPERSESSION = 'supersession';
    public const FIELD_TASK_OR_REMINDER = 'task_or_reminder';
    public const FIELD_TASK_REMINDER_COLD_FILE = 'task_reminder_cold_file';
    public const FIELD_TASK_ROUTINE = 'task_routine';
    public const FIELD_TECHNICAL_LEARNING_CANDIDATE = 'technical_learning_candidate';
    public const FIELD_TOMBSTONE_STATUS = 'tombstone_status';
    public const FIELD_TOMBSTONED = 'tombstoned';
    public const FIELD_TRUST_LEVEL = 'trust_level';
    public const FIELD_TRUSTED = 'trusted';
    public const FIELD_UNCLASSIFIED = 'unclassified';
    public const FIELD_CONFLICTED = 'conflicted';
    public const FIELD_DEPRECATED = 'deprecated';
    public const FIELD_PRIVACY = 'privacy';
    public const FIELD_VALID = 'valid';
    public const FIELD_ALL_GATES_PASSED = 'all_gates_passed';
    public const FIELD_ATLAS_COGNITIVE_IMMUNE_PROMOTION_GATE_EVALUATOR_ENABLED = 'atlas.cognitive_immune.promotion_gate_evaluator_enabled';
    public const FIELD_AUTO__REVIEW_HUMANO__PROPOSAL_OU_BLOQUEIO_ = 'auto, review humano, proposal ou bloqueio?';
    public const FIELD_CHAT_TRANSCRIPT_NEVER_BECOMES_MEMORY_SILENTLY = 'chat_transcript_never_becomes_memory_silently';
    public const FIELD_CLASS_CANNOT_BECOME_MEMORY = 'class_cannot_become_memory';
    public const FIELD_CLASS_INELIGIBLE = 'class_ineligible';
    public const FIELD_CLASS_NOT_CONSTELLATION_GRADE = 'class_not_constellation_grade';
    public const FIELD_CONFLITA_COM_MEMORIA__CODIGO__DOCS_OU_DECISAO_MAIS_NOVA_ = 'conflita com memoria, codigo, docs ou decisao mais nova?';
    public const FIELD_CRITICAL_SCOPE_FORCED_REVIEW = 'critical_scope_forced_review';
    public const FIELD_DELETE_PROPAGATES_TO_MEMORY_EMBEDDINGS_CACHES_CONSTELLATION = 'delete_propagates_to_memory_embeddings_caches_constellation';
    public const FIELD_E_PROVIDER_SAFE__SEM_SEGREDO_E_SEM_DADO_SENSIVEL_DESNECESSARIO_ = 'e provider-safe, sem segredo e sem dado sensivel desnecessario?';
    public const FIELD_EMBEDDING_ALLOWED_FLAG_FALSE = 'embedding_allowed_flag_false';
    public const FIELD_ENTRA_COMO_WATCH_ANTES_DE_TRUSTED_ = 'entra como watch antes de trusted?';
    public const FIELD_EVERY_MEMORY_HAS_SCOPE_SOURCE_STATE_USE_REASON = 'every_memory_has_scope_source_state_use_reason';
    public const FIELD_FOI_VALIDADO_POR_FEEDBACK__TESTE__REPLAY_OU_USO_ = 'foi validado por feedback, teste, replay ou uso?';
    public const FIELD_GLOBAL = 'global';
    public const FIELD_HA_CLAIM_ATOMICO__TIPO__ESCOPO_E_FONTE_ = 'ha claim atomico, tipo, escopo e fonte?';
    public const FIELD_HA_UTILIDADE_FUTURA__NOVIDADE_OU_RECORRENCIA_ = 'ha utilidade futura, novidade ou recorrencia?';
    public const FIELD_LEARNING_NEVER_ALTERS_CRITICAL_BEHAVIOR_WITHOUT_PROPOSAL_OR_REVIEW = 'learning_never_alters_critical_behavior_without_proposal_or_review';
    public const FIELD_MISSING_CLEARANCE = 'missing_clearance';
    public const FIELD_MISSING_EVIDENCE = 'missing_evidence';
    public const FIELD_MISSING_REASON = 'missing_reason';
    public const FIELD_MISSING_REQUIRED_METADATA = 'missing_required_metadata';
    public const FIELD_ORIGIN = 'origin';
    public const FIELD_PODE_CAPTURAR_COM_CONSENTIMENTO__PRIVACY_E_RETENTION_ = 'pode capturar com consentimento, privacy e retention?';
    public const FIELD_POLICY = 'policy';
    public const FIELD_PREFER_INSUFFICIENT_CONTEXT_OVER_RETRIEVING_GARBAGE = 'prefer_insufficient_context_over_retrieving_garbage';
    public const FIELD_PROVENANCE = 'provenance';
    public const FIELD_RAW_CAPTURE_NEVER_ENTERS_CONTEXT_BUILDER_DIRECTLY = 'raw_capture_never_enters_context_builder_directly';
    public const FIELD_TTL_EXPIRATION = 'ttl_expiration';
    public const FIELD_UNKNOWN_FORGETTING_KIND = 'unknown_forgetting_kind';
    public const FIELD_VALE_PARA_GLOBAL__WORKSPACE__PROJETO__TAREFA__DOMINIO_OU_SESSAO_ = 'vale para global, workspace, projeto, tarefa, dominio ou sessao?';

    /**
     * Default cognitive-quarantine state. Every input is born here.
     * Mirrors the doc "Default State" block exactly.
     *
     * @var array<string,bool|string>
     */
    private const DEFAULT_STATE = [
        self::FIELD_MEMORY_ELIGIBLE => false,
        self::FIELD_CONTEXT_ELIGIBLE => false,
        self::FIELD_CONSTELLATION_ELIGIBLE => false,
        self::FIELD_EMBEDDING_ALLOWED => false,
        self::FIELD_PROMOTION_STATUS => self::FIELD_UNCLASSIFIED,
    ];

    /**
     * Input Classes table: class => [destination, can_become_memory].
     * self::FIELD_CAN_BECOME_MEMORY === false means the class can NEVER be auto-promoted
     * to knowledge (the doc "Nao" / "Nao como conhecimento" rows).
     *
     * @var array<string,array{destination:string,can_become_memory:bool}>
     */
    private const INPUT_CLASSES = [
        self::FIELD_TRIVIAL_QUERY => [self::FIELD_DESTINATION => self::FIELD_ANSWER_AND_EXPIRE, self::FIELD_CAN_BECOME_MEMORY => false],
        self::FIELD_OPERATIONAL_EPHEMERAL => [self::FIELD_DESTINATION => self::FIELD_TASK_REMINDER_COLD_FILE, self::FIELD_CAN_BECOME_MEMORY => false],
        self::FIELD_TASK_OR_REMINDER => [self::FIELD_DESTINATION => self::FIELD_TASK_ROUTINE, self::FIELD_CAN_BECOME_MEMORY => false],
        self::FIELD_PROJECT_EVIDENCE => [self::FIELD_DESTINATION => self::FIELD_PROJECT_EVIDENCE, self::FIELD_CAN_BECOME_MEMORY => true],
        self::FIELD_CONVERSATION_TRACE => [self::FIELD_DESTINATION => self::FIELD_AUDIT_SESSION, self::FIELD_CAN_BECOME_MEMORY => false],
        self::FIELD_PERSONAL_FACT_CANDIDATE => [self::FIELD_DESTINATION => self::FIELD_PRIVATE_REVIEW, self::FIELD_CAN_BECOME_MEMORY => true],
        self::FIELD_TECHNICAL_LEARNING_CANDIDATE => [self::FIELD_DESTINATION => self::FIELD_LEARNING_SIGNAL, self::FIELD_CAN_BECOME_MEMORY => true],
        self::FIELD_STRATEGIC_INSIGHT_CANDIDATE => [self::FIELD_DESTINATION => self::FIELD_MEMORY_CONSTELLATION_CANDIDATE, self::FIELD_CAN_BECOME_MEMORY => true],
        self::FIELD_UNTRUSTED_CONTENT => [self::FIELD_DESTINATION => self::FIELD_CITED_DATA_NOT_INSTRUCTION, self::FIELD_CAN_BECOME_MEMORY => false],
        self::FIELD_PROMPT_INJECTION => [self::FIELD_DESTINATION => self::FIELD_BLOCKED_EPHEMERAL_EVIDENCE, self::FIELD_CAN_BECOME_MEMORY => false],
        self::FIELD_PRIVATE_SENSITIVE => [self::FIELD_DESTINATION => self::FIELD_REDACT_MINIMIZE, self::FIELD_CAN_BECOME_MEMORY => true],
    ];

    /**
     * Promotion gate ladder G0..G8, in documented order. Each gate is keyed by a
     * boolean signal the caller supplies; a missing/false signal fails the gate.
     *
     * @var array<int,array{id:string,signal:string,question:string}>
     */
    private const GATES = [
        [self::FIELD_ID => self::FIELD_G0, self::FIELD_SIGNAL => self::FIELD_CAPTURE_CONSENTED, self::FIELD_QUESTION => self::FIELD_PODE_CAPTURAR_COM_CONSENTIMENTO__PRIVACY_E_RETENTION_],
        [self::FIELD_ID => self::FIELD_G1, self::FIELD_SIGNAL => self::FIELD_ATOMIC_CLAIM, self::FIELD_QUESTION => self::FIELD_HA_CLAIM_ATOMICO__TIPO__ESCOPO_E_FONTE_],
        [self::FIELD_ID => self::FIELD_G2, self::FIELD_SIGNAL => self::FIELD_FUTURE_SIGNAL, self::FIELD_QUESTION => self::FIELD_HA_UTILIDADE_FUTURA__NOVIDADE_OU_RECORRENCIA_],
        [self::FIELD_ID => self::FIELD_G3, self::FIELD_SIGNAL => self::FIELD_PROVIDER_SAFE, self::FIELD_QUESTION => self::FIELD_E_PROVIDER_SAFE__SEM_SEGREDO_E_SEM_DADO_SENSIVEL_DESNECESSARIO_],
        [self::FIELD_ID => self::FIELD_G4, self::FIELD_SIGNAL => self::FIELD_NO_CONTRADICTION, self::FIELD_QUESTION => self::FIELD_CONFLITA_COM_MEMORIA__CODIGO__DOCS_OU_DECISAO_MAIS_NOVA_],
        [self::FIELD_ID => self::FIELD_G5, self::FIELD_SIGNAL => self::FIELD_OUTCOME_VALIDATED, self::FIELD_QUESTION => self::FIELD_FOI_VALIDADO_POR_FEEDBACK__TESTE__REPLAY_OU_USO_],
        [self::FIELD_ID => self::FIELD_G6, self::FIELD_SIGNAL => self::FIELD_SCOPE_RESOLVED, self::FIELD_QUESTION => self::FIELD_VALE_PARA_GLOBAL__WORKSPACE__PROJETO__TAREFA__DOMINIO_OU_SESSAO_],
        [self::FIELD_ID => self::FIELD_G7, self::FIELD_SIGNAL => self::FIELD_PROMOTION_MODE_SET, self::FIELD_QUESTION => self::FIELD_AUTO__REVIEW_HUMANO__PROPOSAL_OU_BLOQUEIO_],
        [self::FIELD_ID => self::FIELD_G8, self::FIELD_SIGNAL => self::FIELD_PROBATION_ENTERED, self::FIELD_QUESTION => self::FIELD_ENTRA_COMO_WATCH_ANTES_DE_TRUSTED_],
    ];

    /** Categories the doc forbids from embedding by default. */
    private const EMBEDDING_DENY = [
        self::FIELD_TRIVIAL_QUERY,
        self::FIELD_OPERATIONAL_EPHEMERAL,
        self::FIELD_PRIVATE_SENSITIVE,
        self::FIELD_PROMPT_INJECTION,
        self::FIELD_RAW_PRIVATE_NOTE,
        self::FIELD_UNTRUSTED_CONTENT,
    ];

    /** Required metadata on every vector before similarity is allowed to run. */
    private const VECTOR_REQUIRED_META = [
        self::FIELD_ORIGIN, self::FIELD_TRUST_LEVEL, self::FIELD_PRIVACY, self::FIELD_RETENTION,
        self::FIELD_EXPIRES_AT, self::FIELD_EMBEDDING_ALLOWED, self::FIELD_TOMBSTONE_STATUS,
    ];

    /** Clearances Constelacao demands before admitting any semantic state. */
    private const CONSTELLATION_CLEARANCES = [
        self::FIELD_PROVENANCE, self::FIELD_SEMANTIC_VALUE, self::FIELD_SCOPE,
        self::FIELD_REVERSIBILITY, self::FIELD_PRIVACY_CLEARANCE, self::FIELD_REASON,
    ];

    /** Scopes that carry critical/policy weight (Rule 7: never auto-promote). */
    private const CRITICAL_SCOPES = [self::FIELD_GLOBAL, self::FIELD_POLICY];

    /** Memory states the doc permits. */
    public const MEMORY_STATES = [
        self::FIELD_CANDIDATE, self::FIELD_WATCH, self::FIELD_TRUSTED, self::FIELD_CONFLICTED, self::FIELD_STALE,
        self::FIELD_DEPRECATED, self::FIELD_ARCHIVED, self::FIELD_BLOCKED_PRIVATE, self::FIELD_TOMBSTONED,
    ];

    /**
     * Consolidated AAEOS kernel (lazily constructed; pure, zero ctor deps).
     * evaluatePromotion() adapts its legacy envelope from this single G0-G8
     * authority; evaluatePromotionGates() remains the explicit opt-in raw helper.
     */
    private ?CognitiveImmunePromotionGateEvaluator $promotionGateEvaluator;

    public function __construct(?CognitiveImmunePromotionGateEvaluator $promotionGateEvaluator = null)
    {
        $this->promotionGateEvaluator = $promotionGateEvaluator;
    }

    /**
     * Default cognitive-quarantine state for a brand-new input.
     *
     * @return array<string,bool|string>
     */
    public function defaultState(): array
    {
        return self::DEFAULT_STATE;
    }

    /**
     * @return list<string>
     */
    public function inputClasses(): array
    {
        return array_keys(self::INPUT_CLASSES);
    }

    /**
     * Classify a raw capture into its documented Input Class. Unknown classes
     * fall back to the most conservative class (untrusted_content): cited data,
     * never instruction, never memory.
     *
     * @return array{class:string,destination:string,can_become_memory:bool,state:array<string,bool|string>}
     */
    public function classify(string $inputClass): array
    {
        $key = $this->normalize($inputClass);
        $row = self::INPUT_CLASSES[$key] ?? self::INPUT_CLASSES[self::FIELD_UNTRUSTED_CONTENT];
        $resolved = isset(self::INPUT_CLASSES[$key]) ? $key : self::FIELD_UNTRUSTED_CONTENT;

        return [
            'class' => $resolved,
            self::FIELD_DESTINATION => $row[self::FIELD_DESTINATION],
            self::FIELD_CAN_BECOME_MEMORY => $row[self::FIELD_CAN_BECOME_MEMORY],
            // Classification alone never lifts quarantine.
            self::FIELD_STATE => self::DEFAULT_STATE,
        ];
    }

    /**
     * Run the G0..G8 promotion ladder for a candidate.
     *
     * @param  array<string,mixed>  $candidate  signals keyed by gate signal +
     *                                          self::FIELD_INPUT_CLASS and self::FIELD_SCOPE.
     * @return array{
     *     schema:string,
     *     input_class:string,
     *     can_become_memory:bool,
     *     gate_results:list<array{id:string,passed:bool,question:string}>,
     *     passed_gates:list<string>,
     *     failed_gate:?string,
     *     promote:bool,
     *     promotion_mode:string,
     *     resulting_state:string,
     *     reasons:list<string>
     * }
     */
    public function evaluatePromotion(array $candidate): array
    {
        $inputClass = $this->normalize((string) ($candidate[self::FIELD_INPUT_CLASS] ?? self::FIELD_UNTRUSTED_CONTENT));
        $classRow = self::INPUT_CLASSES[$inputClass] ?? self::INPUT_CLASSES[self::FIELD_UNTRUSTED_CONTENT];
        $canBecomeMemory = $classRow[self::FIELD_CAN_BECOME_MEMORY];
        $scope = $this->normalize((string) ($candidate[self::FIELD_SCOPE] ?? self::FIELD_SESSION));

        $reasons = [];
        $requestedMode = $this->normalize((string) ($candidate[self::FIELD_PROMOTION_MODE] ?? self::FIELD_AUTO));
        $promotionMode = $this->resolvePromotionMode($requestedMode, $scope, $reasons);
        $immuneSignals = $this->promotionImmuneSignals($candidate, $inputClass, $scope, $promotionMode);
        $verdict = ($this->promotionGateEvaluator ??= new CognitiveImmunePromotionGateEvaluator)->evaluate($immuneSignals);
        [$gateResults, $passedGates, $failedGate] = $this->legacyGateProjection($verdict[self::FIELD_GATE_STATUSES]);
        if ($failedGate !== null) {
            $reasons[] = sprintf('gate_failed:%s', $failedGate);
        }

        // Rule 1 + class law: a class that can never become knowledge cannot be
        // promoted even if every gate signal is supplied.
        if (! $canBecomeMemory) {
            $failedGate ??= self::FIELD_CLASS_INELIGIBLE;
            $reasons[] = self::FIELD_CLASS_CANNOT_BECOME_MEMORY;
        }

        $allPassed = $failedGate === null;

        $promote = $allPassed && $promotionMode !== self::FIELD_BLOCKED;

        // G8 probation: a promoted memory enters as `watch`, never straight to
        // `trusted`. If not promoted it stays a `candidate`.
        $resultingState = $promote ? self::FIELD_WATCH : self::FIELD_CANDIDATE;

        if ($promote) {
            $reasons[] = self::FIELD_ALL_GATES_PASSED;
            $reasons[] = sprintf('enters_probation_as:%s', $resultingState);
        }

        return [
            self::FIELD_SCHEMA => self::SCHEMA,
            self::FIELD_INPUT_CLASS => isset(self::INPUT_CLASSES[$inputClass]) ? $inputClass : self::FIELD_UNTRUSTED_CONTENT,
            self::FIELD_CAN_BECOME_MEMORY => $canBecomeMemory,
            self::FIELD_GATE_RESULTS => $gateResults,
            self::FIELD_PASSED_GATES => $passedGates,
            self::FIELD_FAILED_GATE => $failedGate,
            self::FIELD_GATE_STATUSES => $verdict[self::FIELD_GATE_STATUSES],
            self::FIELD_PROMOTION_STATUS => $verdict[self::FIELD_PROMOTION_STATUS],
            self::FIELD_BLOCKING_GATE_IDS => $verdict[self::FIELD_BLOCKING_GATE_IDS],
            self::FIELD_PENDING_GATE_IDS => $verdict[self::FIELD_PENDING_GATE_IDS],
            self::FIELD_IMMUNE_SIGNALS => $immuneSignals,
            self::FIELD_PROMOTE => $promote,
            self::FIELD_PROMOTION_MODE => $promotionMode,
            self::FIELD_RESULTING_STATE => $resultingState,
            self::FIELD_REASONS => array_values(array_unique($reasons)),
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function promotionImmuneSignals(array $candidate, string $inputClass, string $scope, string $promotionMode): array
    {
        $scopeResolved = (bool) ($candidate[self::FIELD_SCOPE_RESOLVED] ?? false);

        return [
            self::FIELD_CONSENT_GRANTED => (bool) ($candidate[self::FIELD_CAPTURE_CONSENTED] ?? false),
            self::FIELD_PRIVACY_CLASS => (string) ($candidate[self::FIELD_PRIVACY_CLASS] ?? self::FIELD_INTERNAL),
            self::FIELD_RETENTION_OK => (bool) ($candidate[self::FIELD_CAPTURE_CONSENTED] ?? false),
            self::FIELD_ATOMIC_CLAIM_PRESENT => (bool) ($candidate[self::FIELD_ATOMIC_CLAIM] ?? false),
            self::FIELD_CLAIM_TYPE => $inputClass,
            self::FIELD_CLAIM_SOURCE_PRESENT => (bool) ($candidate[self::FIELD_ATOMIC_CLAIM] ?? false),
            self::FIELD_FUTURE_UTILITY => (bool) ($candidate[self::FIELD_FUTURE_SIGNAL] ?? false),
            self::FIELD_NOVELTY => (bool) ($candidate[self::FIELD_NOVELTY] ?? false),
            self::FIELD_RECURRENCE_COUNT => (int) ($candidate[self::FIELD_RECURRENCE_COUNT] ?? 0),
            self::FIELD_PROVIDER_SAFE => (bool) ($candidate[self::FIELD_PROVIDER_SAFE] ?? false),
            self::FIELD_CONTAINS_SECRET => (bool) ($candidate[self::FIELD_CONTAINS_SECRET] ?? false),
            self::FIELD_CONTAINS_SENSITIVE_UNNECESSARY => (bool) ($candidate[self::FIELD_CONTAINS_SENSITIVE_UNNECESSARY] ?? false),
            self::FIELD_CONTRADICTS_NEWER => ! (bool) ($candidate[self::FIELD_NO_CONTRADICTION] ?? false),
            self::FIELD_OUTCOME_VALIDATED => (bool) ($candidate[self::FIELD_OUTCOME_VALIDATED] ?? false),
            self::FIELD_SCOPE => $scopeResolved ? $scope : '',
            self::FIELD_PROMOTION_MODE_HINT => (bool) ($candidate[self::FIELD_PROMOTION_MODE_SET] ?? false) ? $promotionMode : '',
            self::FIELD_ON_PROBATION => ! (bool) ($candidate[self::FIELD_PROBATION_ENTERED] ?? false),
        ];
    }

    /**
     * @param  array<string,string>  $gateStatuses
     * @return array{0:list<array{id:string,passed:bool,question:string}>,1:list<string>,2:?string}
     */
    private function legacyGateProjection(array $gateStatuses): array
    {
        $gateResults = [];
        $passedGates = [];
        $failedGate = null;

        foreach (self::GATES as $gate) {
            $gateId = $gate[self::FIELD_ID];
            $passed = $failedGate === null && ($gateStatuses[$gateId] ?? self::FIELD_PENDING) === self::FIELD_PASS;
            $gateResults[] = [
                self::FIELD_ID => $gateId,
                self::FIELD_PASSED => $passed,
                self::FIELD_QUESTION => $gate[self::FIELD_QUESTION],
            ];

            if ($passed) {
                $passedGates[] = $gateId;

                continue;
            }

            $failedGate ??= $gateId;
        }

        return [$gateResults, $passedGates, $failedGate];
    }

    /**
     * Opt-in (default-OFF) — evaluate the G0..G8 promotion ladder via the
     * consolidated CognitiveImmunePromotionGateEvaluator kernel, which adds
     * richer per-gate semantics (forbid-gate default-pass for G3/G4, confirm-gates
     * that stay pending until evidence, and a distinct blocked/trusted/watch/
     * candidate/unclassified promotion_status taxonomy) the local evaluatePromotion()
     * does not have. New behavior; returns null when
     * `atlas.cognitive_immune.promotion_gate_evaluator_enabled` is OFF, so the
     * live evaluatePromotion() path is unchanged unless the operator opts in.
     *
     * @param  array<string,mixed>  $signals  raw candidate signals (see kernel).
     * @return array{
     *     schema_version: string,
     *     gate_statuses: array<string,string>,
     *     promotion_status: string,
     *     blocking_gate_ids: list<string>,
     *     pending_gate_ids: list<string>,
     *     reasons: array<string,string>,
     *     autonomous_promotion_allowed: bool
     * }|null
     */
    public function evaluatePromotionGates(array $signals): ?array
    {
        if (! (bool) config(self::FIELD_ATLAS_COGNITIVE_IMMUNE_PROMOTION_GATE_EVALUATOR_ENABLED, false)) {
            return null;
        }

        return ($this->promotionGateEvaluator ??= new CognitiveImmunePromotionGateEvaluator)->evaluate($signals);
    }

    /**
     * Embedding quarantine decision. A vector is only allowed when its class is
     * not in the deny list AND every required provenance field is present and
     * embedding_allowed is explicitly true.
     *
     * @param  array<string,mixed>  $vector
     * @return array{allowed:bool,reasons:list<string>,missing_meta:list<string>}
     */
    public function embeddingAllowed(string $inputClass, array $vector = []): array
    {
        $class = $this->normalize($inputClass);
        $reasons = [];

        if (in_array($class, self::EMBEDDING_DENY, true)) {
            $reasons[] = sprintf('class_denied:%s', $class);
        }

        $missing = [];
        foreach (self::VECTOR_REQUIRED_META as $field) {
            if (! array_key_exists($field, $vector) || $vector[$field] === null || $vector[$field] === '') {
                $missing[] = $field;
            }
        }
        if ($missing !== []) {
            $reasons[] = self::FIELD_MISSING_REQUIRED_METADATA;
        }

        // The flag must be explicitly true; default-false quarantine holds.
        $flag = (bool) ($vector[self::FIELD_EMBEDDING_ALLOWED] ?? false);
        if (! $flag) {
            $reasons[] = self::FIELD_EMBEDDING_ALLOWED_FLAG_FALSE;
        }

        $allowed = $reasons === [];

        return [
            self::FIELD_ALLOWED => $allowed,
            self::FIELD_REASONS => $allowed ? [self::FIELD_SAFETY_FILTERS_PASSED_BEFORE_SIMILARITY] : $reasons,
            self::FIELD_MISSING_META => $missing,
        ];
    }

    /**
     * Constelacao gate. Admits only a promoted strategic candidate that carries
     * every documented clearance.
     *
     * @param  array<string,mixed>  $clearances
     * @return array{eligible:bool,missing_clearances:list<string>,reasons:list<string>}
     */
    public function constellationEligible(string $inputClass, array $clearances = []): array
    {
        $class = $this->normalize($inputClass);
        $reasons = [];

        // Only strategic insight candidates may even reach the gate.
        if ($class !== self::FIELD_STRATEGIC_INSIGHT_CANDIDATE) {
            $reasons[] = self::FIELD_CLASS_NOT_CONSTELLATION_GRADE;
        }

        $missing = [];
        foreach (self::CONSTELLATION_CLEARANCES as $clearance) {
            if (empty($clearances[$clearance])) {
                $missing[] = $clearance;
            }
        }
        if ($missing !== []) {
            $reasons[] = self::FIELD_MISSING_CLEARANCE;
        }

        $eligible = $reasons === [];

        return [
            self::FIELD_ELIGIBLE => $eligible,
            self::FIELD_MISSING_CLEARANCES => $missing,
            self::FIELD_REASONS => $eligible ? [self::FIELD_CONSTELLATION_ELIGIBLE_TRUE] : $reasons,
        ];
    }

    /**
     * Forgetting receipt. Every demotion must carry a reason + evidence, else it
     * is rejected (the doc: "Toda despromocao deve gerar forgetting_receipt com
     * motivo e evidencia").
     *
     * @return array{valid:bool,kind:string,reason:string,reasons:list<string>}
     */
    public function forgettingReceipt(string $kind, string $reason, string $evidenceRef): array
    {
        $allowedKinds = [
            self::FIELD_TTL_EXPIRATION, self::FIELD_CONFIDENCE_DECAY, self::FIELD_SUPERSESSION,
            self::FIELD_ARCHIVAL, self::FIELD_HARD_DELETE, self::FIELD_NEGATIVE_MEMORY,
        ];
        $k = $this->normalize($kind);
        $errors = [];

        if (! in_array($k, $allowedKinds, true)) {
            $errors[] = self::FIELD_UNKNOWN_FORGETTING_KIND;
        }
        if (AiValueNormalizer::trimmedStringOrNull($reason) === null) {
            $errors[] = self::FIELD_MISSING_REASON;
        }
        if (AiValueNormalizer::trimmedStringOrNull($evidenceRef) === null) {
            $errors[] = self::FIELD_MISSING_EVIDENCE;
        }

        return [
            self::FIELD_VALID => $errors === [],
            self::FIELD_KIND => $k,
            self::FIELD_REASON => $reason,
            self::FIELD_REASONS => $errors === [] ? [self::FIELD_RECEIPT_COMPLETE] : $errors,
        ];
    }

    /**
     * @return list<string>
     */
    public function nonNegotiableRules(): array
    {
        return [
            self::FIELD_RAW_CAPTURE_NEVER_ENTERS_CONTEXT_BUILDER_DIRECTLY,
            self::FIELD_CHAT_TRANSCRIPT_NEVER_BECOMES_MEMORY_SILENTLY,
            self::FIELD_ARCHIVE_IS_NOT_MEMORY_APPROVED,
            self::FIELD_DELETE_PROPAGATES_TO_MEMORY_EMBEDDINGS_CACHES_CONSTELLATION,
            self::FIELD_EVERY_MEMORY_HAS_SCOPE_SOURCE_STATE_USE_REASON,
            self::FIELD_RETRIEVAL_WITHOUT_REASON_IS_A_BUG,
            self::FIELD_LEARNING_NEVER_ALTERS_CRITICAL_BEHAVIOR_WITHOUT_PROPOSAL_OR_REVIEW,
            self::FIELD_PREFER_INSUFFICIENT_CONTEXT_OVER_RETRIEVING_GARBAGE,
        ];
    }

    /**
     * Resolve G7 promotion mode. Critical/policy scope can never be `auto`
     * (Rule 7 + "Learning nao altera comportamento critico sem proposal/review").
     *
     * @param  list<string>  $reasons
     */
    private function resolvePromotionMode(string $requestedMode, string $scope, array &$reasons): string
    {
        $allowed = [self::FIELD_AUTO, self::FIELD_REVIEW, self::FIELD_PROPOSAL, self::FIELD_BLOCKED];
        $mode = in_array($requestedMode, $allowed, true) ? $requestedMode : self::FIELD_REVIEW;

        if (in_array($scope, self::CRITICAL_SCOPES, true) && $mode === self::FIELD_AUTO) {
            $reasons[] = self::FIELD_CRITICAL_SCOPE_FORCED_REVIEW;

            return self::FIELD_REVIEW;
        }

        return $mode;
    }

    private function normalize(string $value): string
    {
        return AiValueNormalizer::lowerTrimmedString($value);
    }
}
