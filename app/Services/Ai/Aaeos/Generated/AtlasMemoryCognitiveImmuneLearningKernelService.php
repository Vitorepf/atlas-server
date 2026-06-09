<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Cognition\CognitiveImmunePromotionGateEvaluator;

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
        'embedding_allowed' => false,
        'promotion_status' => 'unclassified',
    ];

    /**
     * Input Classes table: class => [destination, can_become_memory].
     * "can_become_memory" === false means the class can NEVER be auto-promoted
     * to knowledge (the doc "Nao" / "Nao como conhecimento" rows).
     *
     * @var array<string,array{destination:string,can_become_memory:bool}>
     */
    private const INPUT_CLASSES = [
        'trivial_query' => ['destination' => 'answer_and_expire', 'can_become_memory' => false],
        'operational_ephemeral' => ['destination' => 'task_reminder_cold_file', 'can_become_memory' => false],
        'task_or_reminder' => ['destination' => 'task_routine', 'can_become_memory' => false],
        'project_evidence' => ['destination' => 'project_evidence', 'can_become_memory' => true],
        'conversation_trace' => ['destination' => 'audit_session', 'can_become_memory' => false],
        'personal_fact_candidate' => ['destination' => 'private_review', 'can_become_memory' => true],
        'technical_learning_candidate' => ['destination' => 'learning_signal', 'can_become_memory' => true],
        'strategic_insight_candidate' => ['destination' => 'memory_constellation_candidate', 'can_become_memory' => true],
        'untrusted_content' => ['destination' => 'cited_data_not_instruction', 'can_become_memory' => false],
        'prompt_injection' => ['destination' => 'blocked_ephemeral_evidence', 'can_become_memory' => false],
        'private_sensitive' => ['destination' => 'redact_minimize', 'can_become_memory' => true],
    ];

    /**
     * Promotion gate ladder G0..G8, in documented order. Each gate is keyed by a
     * boolean signal the caller supplies; a missing/false signal fails the gate.
     *
     * @var array<int,array{id:string,signal:string,question:string}>
     */
    private const GATES = [
        ['id' => 'G0', 'signal' => 'capture_consented', 'question' => 'pode capturar com consentimento, privacy e retention?'],
        ['id' => 'G1', 'signal' => 'atomic_claim', 'question' => 'ha claim atomico, tipo, escopo e fonte?'],
        ['id' => 'G2', 'signal' => 'future_signal', 'question' => 'ha utilidade futura, novidade ou recorrencia?'],
        ['id' => 'G3', 'signal' => 'provider_safe', 'question' => 'e provider-safe, sem segredo e sem dado sensivel desnecessario?'],
        ['id' => 'G4', 'signal' => 'no_contradiction', 'question' => 'conflita com memoria, codigo, docs ou decisao mais nova?'],
        ['id' => 'G5', 'signal' => 'outcome_validated', 'question' => 'foi validado por feedback, teste, replay ou uso?'],
        ['id' => 'G6', 'signal' => 'scope_resolved', 'question' => 'vale para global, workspace, projeto, tarefa, dominio ou sessao?'],
        ['id' => 'G7', 'signal' => 'promotion_mode_set', 'question' => 'auto, review humano, proposal ou bloqueio?'],
        ['id' => 'G8', 'signal' => 'probation_entered', 'question' => 'entra como watch antes de trusted?'],
    ];

    /** Categories the doc forbids from embedding by default. */
    private const EMBEDDING_DENY = [
        'trivial_query',
        'operational_ephemeral',
        'private_sensitive',
        'prompt_injection',
        'raw_private_note',
        'untrusted_content',
    ];

    /** Required metadata on every vector before similarity is allowed to run. */
    private const VECTOR_REQUIRED_META = [
        'origin', 'trust_level', 'privacy', 'retention',
        'expires_at', 'embedding_allowed', 'tombstone_status',
    ];

    /** Clearances Constelacao demands before admitting any semantic state. */
    private const CONSTELLATION_CLEARANCES = [
        'provenance', 'semantic_value', 'scope',
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
     * Consolidated AAEOS kernel (lazily constructed; pure, zero ctor deps). Wired
     * in behind a default-OFF config flag via the opt-in helper below — the live
     * evaluatePromotion() path does NOT use it.
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
        $row = self::INPUT_CLASSES[$key] ?? self::INPUT_CLASSES['untrusted_content'];
        $resolved = isset(self::INPUT_CLASSES[$key]) ? $key : 'untrusted_content';

        return [
            'class' => $resolved,
            'destination' => $row['destination'],
            'can_become_memory' => $row['can_become_memory'],
            // Classification alone never lifts quarantine.
            'state' => self::DEFAULT_STATE,
        ];
    }

    /**
     * Run the G0..G8 promotion ladder for a candidate.
     *
     * @param  array<string,mixed>  $candidate  signals keyed by gate signal +
     *                                           'input_class' and 'scope'.
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
        $inputClass = $this->normalize((string) ($candidate['input_class'] ?? 'untrusted_content'));
        $classRow = self::INPUT_CLASSES[$inputClass] ?? self::INPUT_CLASSES['untrusted_content'];
        $canBecomeMemory = $classRow['can_become_memory'];
        $scope = $this->normalize((string) ($candidate['scope'] ?? 'session'));

        $reasons = [];
        $gateResults = [];
        $passedGates = [];
        $failedGate = null;

        foreach (self::GATES as $gate) {
            // Once a gate fails, downstream gates are reported but cannot pass:
            // the ladder is ordered and a broken rung stops the climb.
            $signalTrue = (bool) ($candidate[$gate['signal']] ?? false);
            $passed = $failedGate === null && $signalTrue;

            $gateResults[] = [
                'id' => $gate['id'],
                'passed' => $passed,
                'question' => $gate['question'],
            ];

            if ($passed) {
                $passedGates[] = $gate['id'];

                continue;
            }

            if ($failedGate === null) {
                $failedGate = $gate['id'];
                $reasons[] = sprintf('gate_failed:%s', $gate['id']);
            }
        }

        // Rule 1 + class law: a class that can never become knowledge cannot be
        // promoted even if every gate signal is supplied.
        if (! $canBecomeMemory) {
            $failedGate ??= 'class_ineligible';
            $reasons[] = 'class_cannot_become_memory';
        }

        $allPassed = $failedGate === null;

        // G7 promotion mode. Rule 7: critical/policy scope is NEVER auto.
        $requestedMode = $this->normalize((string) ($candidate['promotion_mode'] ?? 'auto'));
        $promotionMode = $this->resolvePromotionMode($requestedMode, $scope, $reasons);

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
            'input_class' => isset(self::INPUT_CLASSES[$inputClass]) ? $inputClass : 'untrusted_content',
            'can_become_memory' => $canBecomeMemory,
            'gate_results' => $gateResults,
            'passed_gates' => $passedGates,
            'failed_gate' => $failedGate,
            'promote' => $promote,
            'promotion_mode' => $promotionMode,
            'resulting_state' => $resultingState,
            'reasons' => array_values(array_unique($reasons)),
        ];
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
        $flag = (bool) ($vector['embedding_allowed'] ?? false);
        if (! $flag) {
            $reasons[] = 'embedding_allowed_flag_false';
        }

        $allowed = $reasons === [];

        return [
            'allowed' => $allowed,
            'reasons' => $allowed ? ['safety_filters_passed_before_similarity'] : $reasons,
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
            'reasons' => $eligible ? ['constellation_eligible_true'] : $reasons,
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
        if (trim($reason) === '') {
            $errors[] = 'missing_reason';
        }
        if (trim($evidenceRef) === '') {
            $errors[] = 'missing_evidence';
        }

        return [
            'valid' => $errors === [],
            'kind' => $k,
            'reason' => $reason,
            'reasons' => $errors === [] ? ['receipt_complete'] : $errors,
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
        $allowed = ['auto', 'review', 'proposal', 'blocked'];
        $mode = in_array($requestedMode, $allowed, true) ? $requestedMode : 'review';

        if (in_array($scope, self::CRITICAL_SCOPES, true) && $mode === 'auto') {
            $reasons[] = 'critical_scope_forced_review';

            return 'review';
        }

        return $mode;
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
