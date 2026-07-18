<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

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

    /**
     * Default cognitive-quarantine state. Every input is born here.
     * Mirrors the doc "Default State" block exactly.
     *
     * @var array<string,bool|string>
     */
    private const DEFAULT_STATE = [
        'memory_eligible' => false,
        'context_eligible' => false,
        'constellation_eligible' => false,
        self::FIELD_EMBEDDING_ALLOWED => false,
        self::FIELD_PROMOTION_STATUS => 'unclassified',
    ];

    /**
     * Input Classes table: class => [destination, can_become_memory].
     * self::FIELD_CAN_BECOME_MEMORY === false means the class can NEVER be auto-promoted
     * to knowledge (the doc "Nao" / "Nao como conhecimento" rows).
     *
     * @var array<string,array{destination:string,can_become_memory:bool}>
     */
    private const INPUT_CLASSES = [
        'trivial_query' => [self::FIELD_DESTINATION => 'answer_and_expire', self::FIELD_CAN_BECOME_MEMORY => false],
        self::FIELD_OPERATIONAL_EPHEMERAL => [self::FIELD_DESTINATION => 'task_reminder_cold_file', self::FIELD_CAN_BECOME_MEMORY => false],
        'task_or_reminder' => [self::FIELD_DESTINATION => 'task_routine', self::FIELD_CAN_BECOME_MEMORY => false],
        'project_evidence' => [self::FIELD_DESTINATION => 'project_evidence', self::FIELD_CAN_BECOME_MEMORY => true],
        'conversation_trace' => [self::FIELD_DESTINATION => 'audit_session', self::FIELD_CAN_BECOME_MEMORY => false],
        'personal_fact_candidate' => [self::FIELD_DESTINATION => 'private_review', self::FIELD_CAN_BECOME_MEMORY => true],
        'technical_learning_candidate' => [self::FIELD_DESTINATION => 'learning_signal', self::FIELD_CAN_BECOME_MEMORY => true],
        'strategic_insight_candidate' => [self::FIELD_DESTINATION => 'memory_constellation_candidate', self::FIELD_CAN_BECOME_MEMORY => true],
        self::FIELD_UNTRUSTED_CONTENT => [self::FIELD_DESTINATION => 'cited_data_not_instruction', self::FIELD_CAN_BECOME_MEMORY => false],
        'prompt_injection' => [self::FIELD_DESTINATION => 'blocked_ephemeral_evidence', self::FIELD_CAN_BECOME_MEMORY => false],
        'private_sensitive' => [self::FIELD_DESTINATION => 'redact_minimize', self::FIELD_CAN_BECOME_MEMORY => true],
    ];

    /**
     * Promotion gate ladder G0..G8, in documented order. Each gate is keyed by a
     * boolean signal the caller supplies; a missing/false signal fails the gate.
     *
     * @var array<int,array{id:string,signal:string,question:string}>
     */
    private const GATES = [
        [self::FIELD_ID => self::FIELD_G0, self::FIELD_SIGNAL => self::FIELD_CAPTURE_CONSENTED, self::FIELD_QUESTION => 'pode capturar com consentimento, privacy e retention?'],
        [self::FIELD_ID => self::FIELD_G1, self::FIELD_SIGNAL => self::FIELD_ATOMIC_CLAIM, self::FIELD_QUESTION => 'ha claim atomico, tipo, escopo e fonte?'],
        [self::FIELD_ID => self::FIELD_G2, self::FIELD_SIGNAL => self::FIELD_FUTURE_SIGNAL, self::FIELD_QUESTION => 'ha utilidade futura, novidade ou recorrencia?'],
        [self::FIELD_ID => self::FIELD_G3, self::FIELD_SIGNAL => self::FIELD_PROVIDER_SAFE, self::FIELD_QUESTION => 'e provider-safe, sem segredo e sem dado sensivel desnecessario?'],
        [self::FIELD_ID => self::FIELD_G4, self::FIELD_SIGNAL => self::FIELD_NO_CONTRADICTION, self::FIELD_QUESTION => 'conflita com memoria, codigo, docs ou decisao mais nova?'],
        [self::FIELD_ID => self::FIELD_G5, self::FIELD_SIGNAL => self::FIELD_OUTCOME_VALIDATED, self::FIELD_QUESTION => 'foi validado por feedback, teste, replay ou uso?'],
        [self::FIELD_ID => self::FIELD_G6, self::FIELD_SIGNAL => 'scope_resolved', self::FIELD_QUESTION => 'vale para global, workspace, projeto, tarefa, dominio ou sessao?'],
        [self::FIELD_ID => self::FIELD_G7, self::FIELD_SIGNAL => 'promotion_mode_set', self::FIELD_QUESTION => 'auto, review humano, proposal ou bloqueio?'],
        [self::FIELD_ID => self::FIELD_G8, self::FIELD_SIGNAL => 'probation_entered', self::FIELD_QUESTION => 'entra como watch antes de trusted?'],
    ];

    /** Categories the doc forbids from embedding by default. */
    private const EMBEDDING_DENY = [
        'trivial_query',
        self::FIELD_OPERATIONAL_EPHEMERAL,
        'private_sensitive',
        'prompt_injection',
        'raw_private_note',
        self::FIELD_UNTRUSTED_CONTENT,
    ];

    /** Required metadata on every vector before similarity is allowed to run. */
    private const VECTOR_REQUIRED_META = [
        'origin', 'trust_level', 'privacy', 'retention',
        'expires_at', self::FIELD_EMBEDDING_ALLOWED, 'tombstone_status',
    ];

    /** Clearances Constelacao demands before admitting any semantic state. */
    private const CONSTELLATION_CLEARANCES = [
        'provenance', 'semantic_value', self::FIELD_SCOPE,
        'reversibility', 'privacy_clearance', 'reason',
    ];

    /** Scopes that carry critical/policy weight (Rule 7: never auto-promote). */
    private const CRITICAL_SCOPES = ['global', 'policy'];

    /** Memory states the doc permits. */
    public const MEMORY_STATES = [
        'candidate', 'watch', 'trusted', 'conflicted', 'stale',
        'deprecated', 'archived', 'blocked_private', 'tombstoned',
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
            'state' => self::DEFAULT_STATE,
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
        $scope = $this->normalize((string) ($candidate[self::FIELD_SCOPE] ?? 'session'));

        $reasons = [];
        $requestedMode = $this->normalize((string) ($candidate['promotion_mode'] ?? self::FIELD_AUTO));
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
            $failedGate ??= 'class_ineligible';
            $reasons[] = 'class_cannot_become_memory';
        }

        $allPassed = $failedGate === null;

        $promote = $allPassed && $promotionMode !== 'blocked';

        // G8 probation: a promoted memory enters as `watch`, never straight to
        // `trusted`. If not promoted it stays a `candidate`.
        $resultingState = $promote ? 'watch' : 'candidate';

        if ($promote) {
            $reasons[] = 'all_gates_passed';
            $reasons[] = sprintf('enters_probation_as:%s', $resultingState);
        }

        return [
            'schema' => self::SCHEMA,
            self::FIELD_INPUT_CLASS => isset(self::INPUT_CLASSES[$inputClass]) ? $inputClass : self::FIELD_UNTRUSTED_CONTENT,
            self::FIELD_CAN_BECOME_MEMORY => $canBecomeMemory,
            'gate_results' => $gateResults,
            'passed_gates' => $passedGates,
            'failed_gate' => $failedGate,
            self::FIELD_GATE_STATUSES => $verdict[self::FIELD_GATE_STATUSES],
            self::FIELD_PROMOTION_STATUS => $verdict[self::FIELD_PROMOTION_STATUS],
            self::FIELD_BLOCKING_GATE_IDS => $verdict[self::FIELD_BLOCKING_GATE_IDS],
            self::FIELD_PENDING_GATE_IDS => $verdict[self::FIELD_PENDING_GATE_IDS],
            'immune_signals' => $immuneSignals,
            'promote' => $promote,
            'promotion_mode' => $promotionMode,
            'resulting_state' => $resultingState,
            self::FIELD_REASONS => array_values(array_unique($reasons)),
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function promotionImmuneSignals(array $candidate, string $inputClass, string $scope, string $promotionMode): array
    {
        $scopeResolved = (bool) ($candidate['scope_resolved'] ?? false);

        return [
            'consent_granted' => (bool) ($candidate[self::FIELD_CAPTURE_CONSENTED] ?? false),
            self::FIELD_PRIVACY_CLASS => (string) ($candidate[self::FIELD_PRIVACY_CLASS] ?? 'internal'),
            'retention_ok' => (bool) ($candidate[self::FIELD_CAPTURE_CONSENTED] ?? false),
            'atomic_claim_present' => (bool) ($candidate[self::FIELD_ATOMIC_CLAIM] ?? false),
            'claim_type' => $inputClass,
            'claim_source_present' => (bool) ($candidate[self::FIELD_ATOMIC_CLAIM] ?? false),
            'future_utility' => (bool) ($candidate[self::FIELD_FUTURE_SIGNAL] ?? false),
            self::FIELD_NOVELTY => (bool) ($candidate[self::FIELD_NOVELTY] ?? false),
            'recurrence_count' => (int) ($candidate['recurrence_count'] ?? 0),
            self::FIELD_PROVIDER_SAFE => (bool) ($candidate[self::FIELD_PROVIDER_SAFE] ?? false),
            self::FIELD_CONTAINS_SECRET => (bool) ($candidate[self::FIELD_CONTAINS_SECRET] ?? false),
            self::FIELD_CONTAINS_SENSITIVE_UNNECESSARY => (bool) ($candidate[self::FIELD_CONTAINS_SENSITIVE_UNNECESSARY] ?? false),
            'contradicts_newer' => ! (bool) ($candidate[self::FIELD_NO_CONTRADICTION] ?? false),
            self::FIELD_OUTCOME_VALIDATED => (bool) ($candidate[self::FIELD_OUTCOME_VALIDATED] ?? false),
            self::FIELD_SCOPE => $scopeResolved ? $scope : '',
            'promotion_mode_hint' => (bool) ($candidate['promotion_mode_set'] ?? false) ? $promotionMode : '',
            'on_probation' => ! (bool) ($candidate['probation_entered'] ?? false),
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
            $passed = $failedGate === null && ($gateStatuses[$gateId] ?? 'pending') === 'pass';
            $gateResults[] = [
                self::FIELD_ID => $gateId,
                'passed' => $passed,
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
        if (! (bool) config('atlas.cognitive_immune.promotion_gate_evaluator_enabled', false)) {
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
            $reasons[] = 'missing_required_metadata';
        }

        // The flag must be explicitly true; default-false quarantine holds.
        $flag = (bool) ($vector[self::FIELD_EMBEDDING_ALLOWED] ?? false);
        if (! $flag) {
            $reasons[] = 'embedding_allowed_flag_false';
        }

        $allowed = $reasons === [];

        return [
            'allowed' => $allowed,
            self::FIELD_REASONS => $allowed ? ['safety_filters_passed_before_similarity'] : $reasons,
            'missing_meta' => $missing,
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
        if ($class !== 'strategic_insight_candidate') {
            $reasons[] = 'class_not_constellation_grade';
        }

        $missing = [];
        foreach (self::CONSTELLATION_CLEARANCES as $clearance) {
            if (empty($clearances[$clearance])) {
                $missing[] = $clearance;
            }
        }
        if ($missing !== []) {
            $reasons[] = 'missing_clearance';
        }

        $eligible = $reasons === [];

        return [
            'eligible' => $eligible,
            'missing_clearances' => $missing,
            self::FIELD_REASONS => $eligible ? ['constellation_eligible_true'] : $reasons,
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
            'ttl_expiration', 'confidence_decay', 'supersession',
            'archival', 'hard_delete', 'negative_memory',
        ];
        $k = $this->normalize($kind);
        $errors = [];

        if (! in_array($k, $allowedKinds, true)) {
            $errors[] = 'unknown_forgetting_kind';
        }
        if (AiValueNormalizer::trimmedStringOrNull($reason) === null) {
            $errors[] = 'missing_reason';
        }
        if (AiValueNormalizer::trimmedStringOrNull($evidenceRef) === null) {
            $errors[] = 'missing_evidence';
        }

        return [
            'valid' => $errors === [],
            'kind' => $k,
            'reason' => $reason,
            self::FIELD_REASONS => $errors === [] ? ['receipt_complete'] : $errors,
        ];
    }

    /**
     * @return list<string>
     */
    public function nonNegotiableRules(): array
    {
        return [
            'raw_capture_never_enters_context_builder_directly',
            'chat_transcript_never_becomes_memory_silently',
            'archive_is_not_memory_approved',
            'delete_propagates_to_memory_embeddings_caches_constellation',
            'every_memory_has_scope_source_state_use_reason',
            'retrieval_without_reason_is_a_bug',
            'learning_never_alters_critical_behavior_without_proposal_or_review',
            'prefer_insufficient_context_over_retrieving_garbage',
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
        $allowed = [self::FIELD_AUTO, self::FIELD_REVIEW, 'proposal', 'blocked'];
        $mode = in_array($requestedMode, $allowed, true) ? $requestedMode : self::FIELD_REVIEW;

        if (in_array($scope, self::CRITICAL_SCOPES, true) && $mode === self::FIELD_AUTO) {
            $reasons[] = 'critical_scope_forced_review';

            return self::FIELD_REVIEW;
        }

        return $mode;
    }

    private function normalize(string $value): string
    {
        return AiValueNormalizer::lowerTrimmedString($value);
    }
}
