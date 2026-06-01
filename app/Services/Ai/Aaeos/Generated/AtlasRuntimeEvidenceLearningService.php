<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Pure, deterministic decider for the Runtime / Evidence / Learning plane
 * contract.
 *
 * This is NOT the live runtime, the live Evidence Ledger, or the live Learning
 * executor (those are App\Services\Ai\Telemetry\*, the Evidence Ledger models,
 * and App\Services\Ai\Compounding\AtlasLearningMutationRuntimeService). This
 * service answers the *contract* questions the doc states — over plain typed
 * arrays, with no database, no models and no side effects — so the three-plane
 * separation can be pinned and reused independently of any pipeline.
 *
 * Rules implemented (mapped to the doc sections):
 *
 *   1. Runtime role routing — "Runtime Roles" + decision "Language runtimes are
 *      divided by scope, not fashion." A unit of work routes to the runtime
 *      whose documented scope owns it (Laravel kernel/receipts/ledger/
 *      orchestration; Python AI/data/ML/agents; Go edge/streaming/webhooks;
 *      Swift mobile/Secure Enclave; Providers reasoning; Super Tool Runtime
 *      tools/gates/evidence). An unknown scope is NOT guessed — it routes
 *      nowhere and is flagged for placement.
 *
 *   2. Evidence admissibility — "Evidence Plane": every meaningful runtime
 *      action emits Evidence, and only the enumerated event kinds (decision,
 *      provider_call, tool_run, gate_result, repair_attempt, output_rendered,
 *      proposal_created, human_review_outcome) are admitted. An unknown kind is
 *      rejected; the plane is a closed vocabulary.
 *
 *   3. Learning-output classification — "Learning Plane": Learning consumes
 *      Evidence and produces exactly six output kinds (memory_signal,
 *      provider_performance_change, retrieval_improvement, repair_heuristic,
 *      curator_proposal, documentation_health_finding). Anything else is not a
 *      valid Learning output.
 *
 *   4. Critical-change gate — decision "Critical learning changes require
 *      proposal review" + "Learning does not silently mutate critical
 *      behavior." A Learning output that targets critical behavior may NOT be
 *      auto-applied: it must be routed to proposal review. A non-critical output
 *      may auto-apply. The direction is one-way: critical always escalates.
 *
 *   5. Plane direction — the planes flow Runtime -> Evidence -> Learning, and
 *      Learning re-enters only through reviewed proposals, never by writing
 *      runtime behavior directly. This decider refuses any Learning->Runtime
 *      edge that is not a reviewed proposal.
 *
 * @see docs/engineering-knowledge-base/master-architecture/runtime-evidence-learning.md
 */
final class AtlasRuntimeEvidenceLearningService
{
    /** Stable schema id this service stamps on every verdict. */
    public const RECEIPT_SCHEMA = 'atlas.runtime_evidence_learning.contract.v1';

    /** Runtime role identifiers (the "Runtime Roles" table rows). */
    public const RUNTIME_LARAVEL = 'laravel';
    public const RUNTIME_PYTHON = 'python';
    public const RUNTIME_GO = 'go';
    public const RUNTIME_SWIFT = 'swift';
    public const RUNTIME_PROVIDERS = 'providers';
    public const RUNTIME_SUPER_TOOL = 'super_tool_runtime';

    /** Sentinel when no documented runtime owns the work's scope. */
    public const RUNTIME_UNPLACED = 'unplaced';

    /**
     * Documented scope -> owning runtime. "Divided by scope, not fashion": the
     * mapping is by responsibility, and is a closed set.
     *
     * @var array<string,string>
     */
    private const SCOPE_TO_RUNTIME = [
        // Laravel: Kernel, API, auth, policy, receipts, ledger, orchestration.
        'kernel' => self::RUNTIME_LARAVEL,
        'api' => self::RUNTIME_LARAVEL,
        'auth' => self::RUNTIME_LARAVEL,
        'policy' => self::RUNTIME_LARAVEL,
        'receipts' => self::RUNTIME_LARAVEL,
        'ledger' => self::RUNTIME_LARAVEL,
        'orchestration' => self::RUNTIME_LARAVEL,
        // Python: AI/data runtime, Graph RAG, embeddings, ML, agents, simulations.
        'ai' => self::RUNTIME_PYTHON,
        'data' => self::RUNTIME_PYTHON,
        'graph_rag' => self::RUNTIME_PYTHON,
        'embeddings' => self::RUNTIME_PYTHON,
        'ml' => self::RUNTIME_PYTHON,
        'agents' => self::RUNTIME_PYTHON,
        'simulations' => self::RUNTIME_PYTHON,
        // Go: edge ingestion, concurrency, streaming, webhooks, LiveKit server layer.
        'edge_ingestion' => self::RUNTIME_GO,
        'streaming' => self::RUNTIME_GO,
        'webhooks' => self::RUNTIME_GO,
        'livekit_server' => self::RUNTIME_GO,
        // Swift: mobile and Apple-native edge, sensors, Secure Enclave, local UX.
        'mobile' => self::RUNTIME_SWIFT,
        'sensors' => self::RUNTIME_SWIFT,
        'secure_enclave' => self::RUNTIME_SWIFT,
        'local_ux' => self::RUNTIME_SWIFT,
        // Providers: reasoning engines behind Provider Drivers.
        'reasoning' => self::RUNTIME_PROVIDERS,
        // Super Tool Runtime: tools, recipes, normalizers, gates and evidence.
        'tools' => self::RUNTIME_SUPER_TOOL,
        'recipes' => self::RUNTIME_SUPER_TOOL,
        'normalizers' => self::RUNTIME_SUPER_TOOL,
        'gates' => self::RUNTIME_SUPER_TOOL,
        'evidence' => self::RUNTIME_SUPER_TOOL,
    ];

    /**
     * Closed vocabulary of Evidence event kinds (the "Evidence Plane" list).
     *
     * @var list<string>
     */
    public const EVIDENCE_EVENT_KINDS = [
        'decision',
        'provider_call',
        'tool_run',
        'gate_result',
        'repair_attempt',
        'output_rendered',
        'proposal_created',
        'human_review_outcome',
    ];

    /**
     * Closed vocabulary of Learning output kinds (the "Learning Plane" list).
     *
     * @var list<string>
     */
    public const LEARNING_OUTPUT_KINDS = [
        'memory_signal',
        'provider_performance_change',
        'retrieval_improvement',
        'repair_heuristic',
        'curator_proposal',
        'documentation_health_finding',
    ];

    /** Disposition of a Learning output. */
    public const DISPOSITION_AUTO_APPLY = 'auto_apply';
    public const DISPOSITION_PROPOSAL_REVIEW = 'proposal_review';
    public const DISPOSITION_REJECTED = 'rejected';

    /**
     * Route a unit of work to the runtime whose scope owns it.
     *
     * Decision: "Language runtimes are divided by scope, not fashion." The scope
     * is matched against the documented responsibility map; an unknown scope is
     * never guessed — it routes to `unplaced` and must be placed by a human.
     *
     * @param  string  $scope  documented scope key (e.g. ledger, embeddings, webhooks).
     *
     * @return array{schema:string,routed:bool,runtime:string,scope:string,reason:string}
     */
    public function routeRuntime(string $scope): array
    {
        $key = strtolower(trim($scope));

        if ($key !== '' && array_key_exists($key, self::SCOPE_TO_RUNTIME)) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'routed' => true,
                'runtime' => self::SCOPE_TO_RUNTIME[$key],
                'scope' => $key,
                'reason' => 'scope_owned_by_runtime',
            ];
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'routed' => false,
            'runtime' => self::RUNTIME_UNPLACED,
            'scope' => $key,
            'reason' => 'unknown_scope_not_guessed',
        ];
    }

    /**
     * Admit (or reject) an Evidence event by kind.
     *
     * "Every meaningful runtime action emits Evidence" — but the plane is a
     * closed vocabulary. A kind outside the documented list is rejected so the
     * Evidence plane stays a faithful, auditable enumeration.
     *
     * @param  string  $kind  proposed evidence event kind.
     *
     * @return array{schema:string,admitted:bool,kind:string,reason:string}
     */
    public function admitEvidence(string $kind): array
    {
        $normalized = strtolower(trim($kind));

        if (in_array($normalized, self::EVIDENCE_EVENT_KINDS, true)) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'admitted' => true,
                'kind' => $normalized,
                'reason' => 'documented_evidence_kind',
            ];
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'admitted' => false,
            'kind' => $normalized,
            'reason' => 'unknown_evidence_kind',
        ];
    }

    /**
     * Classify a Learning output, and decide its disposition.
     *
     * "Learning consumes Evidence and produces [six output kinds]." A kind
     * outside that list is rejected. For a valid kind, the disposition follows
     * the hard invariant: "Learning does not silently mutate critical behavior"
     * + "Critical learning changes require proposal review." If the output
     * targets critical behavior it is routed to proposal review (never
     * auto-applied); otherwise it may auto-apply.
     *
     * @param  string  $outputKind        one of LEARNING_OUTPUT_KINDS.
     * @param  bool  $targetsCriticalBehavior  does the change touch critical behavior?
     *
     * @return array{schema:string,valid_output:bool,output_kind:string,critical:bool,disposition:string,requires_proposal_review:bool,reason:string}
     */
    public function classifyLearningOutput(string $outputKind, bool $targetsCriticalBehavior): array
    {
        $kind = strtolower(trim($outputKind));

        if (! in_array($kind, self::LEARNING_OUTPUT_KINDS, true)) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'valid_output' => false,
                'output_kind' => $kind,
                'critical' => $targetsCriticalBehavior,
                'disposition' => self::DISPOSITION_REJECTED,
                'requires_proposal_review' => false,
                'reason' => 'unknown_learning_output_kind',
            ];
        }

        // A curator_proposal is, by definition, already a proposal — it is the
        // review path itself and is therefore never an auto-apply.
        if ($kind === 'curator_proposal') {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'valid_output' => true,
                'output_kind' => $kind,
                'critical' => $targetsCriticalBehavior,
                'disposition' => self::DISPOSITION_PROPOSAL_REVIEW,
                'requires_proposal_review' => true,
                'reason' => 'proposal_is_the_review_path',
            ];
        }

        if ($targetsCriticalBehavior) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'valid_output' => true,
                'output_kind' => $kind,
                'critical' => true,
                'disposition' => self::DISPOSITION_PROPOSAL_REVIEW,
                'requires_proposal_review' => true,
                'reason' => 'critical_change_requires_proposal_review',
            ];
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'valid_output' => true,
            'output_kind' => $kind,
            'critical' => false,
            'disposition' => self::DISPOSITION_AUTO_APPLY,
            'requires_proposal_review' => false,
            'reason' => 'non_critical_learning_may_auto_apply',
        ];
    }

    /**
     * Decide whether a Learning output may re-enter the Runtime plane.
     *
     * Plane direction is Runtime -> Evidence -> Learning, and Learning re-enters
     * only through a reviewed proposal. A critical change with no review, or any
     * change presented as a direct Learning->Runtime write, is refused.
     *
     * @param  bool  $targetsCriticalBehavior  does the change touch critical behavior?
     * @param  bool  $reviewedProposal         was it approved through proposal review?
     *
     * @return array{schema:string,may_apply_to_runtime:bool,critical:bool,reviewed_proposal:bool,reason:string}
     */
    public function mayReenterRuntime(bool $targetsCriticalBehavior, bool $reviewedProposal): array
    {
        if ($targetsCriticalBehavior && ! $reviewedProposal) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'may_apply_to_runtime' => false,
                'critical' => true,
                'reviewed_proposal' => false,
                'reason' => 'critical_behavior_needs_reviewed_proposal',
            ];
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'may_apply_to_runtime' => true,
            'critical' => $targetsCriticalBehavior,
            'reviewed_proposal' => $reviewedProposal,
            'reason' => $targetsCriticalBehavior
                ? 'critical_change_with_reviewed_proposal'
                : 'non_critical_change_allowed',
        ];
    }

    /**
     * End-to-end plane summary for a single runtime action.
     *
     * Walks Runtime (route) -> Evidence (admit) -> Learning (classify) and
     * returns a combined, auditable verdict that a command can emit as-is.
     *
     * @param  string  $scope             documented scope key for the work.
     * @param  string  $evidenceKind      evidence event kind the action emits.
     * @param  string  $learningOutputKind  learning output the action feeds (may be '').
     * @param  bool  $targetsCriticalBehavior  does the learning change touch critical behavior?
     *
     * @return array{schema:string,runtime:array{schema:string,routed:bool,runtime:string,scope:string,reason:string},evidence:array{schema:string,admitted:bool,kind:string,reason:string},learning:?array{schema:string,valid_output:bool,output_kind:string,critical:bool,disposition:string,requires_proposal_review:bool,reason:string},coherent:bool}
     */
    public function summarizeAction(
        string $scope,
        string $evidenceKind,
        string $learningOutputKind = '',
        bool $targetsCriticalBehavior = false
    ): array {
        $runtime = $this->routeRuntime($scope);
        $evidence = $this->admitEvidence($evidenceKind);
        $learning = trim($learningOutputKind) === ''
            ? null
            : $this->classifyLearningOutput($learningOutputKind, $targetsCriticalBehavior);

        $coherent = $runtime['routed']
            && $evidence['admitted']
            && ($learning === null || $learning['valid_output']);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'runtime' => $runtime,
            'evidence' => $evidence,
            'learning' => $learning,
            'coherent' => $coherent,
        ];
    }
}
