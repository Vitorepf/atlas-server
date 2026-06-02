<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Services\Ai\AiContextPackBuilder;
use App\Services\Ai\Compounding\AtlasCompoundingMemoryService;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Programming\Sdd\Compilers\SpecCritic;
use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use App\Services\Ai\VerifiedExecution\AtlasVerifiedExecutionRuntimeService;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Engineering Run Conductor — the connective governed spine.
 *
 * Composes (does NOT duplicate) the already-real Patamar 4 subsystems into ONE
 * operator-invokable, provider-agnostic engineering run:
 *
 *   work/intent
 *     -> AtlasSwarmConductorService::dispatch()   Constitutional Kernel +
 *        Autonomy Admission gates + ADML route -> governed multi-arm envelope
 *     -> AtlasSwarmExecutorService::execute()       real cross-provider fan-out
 *        via the Production Resolver (LIVE) or a deterministic plan (SHADOW);
 *        per-arm ADML live-outcome feedback; canonical tie-break winner
 *     -> [optional] AtlasVerifiedExecutionRuntimeService::verifyDiff()
 *        blocking verification gate when the winner carries code changes
 *     -> governed engineering-run envelope (schema-tagged, run_hash)
 *
 * Provider-agnostic: every provider decision flows through the swarm layer,
 * which resolves providers from the OPEN {@see \App\Services\Ai\AiProviderManager}
 * registry. A new provider (Hermes runtime, a self-hosted model, a future
 * engine) participates with zero change in this class.
 *
 * Sovereignty: the effective mode is SHADOW (plan + govern, zero provider
 * spend) UNLESS the caller asks for LIVE *and* the production resolver is
 * enabled (config flag) *and* Autonomy Admission authorizes real spend
 * (ALLOW_AUTONOMOUS, or ALLOW_WITH_APPROVAL under an explicit operator
 * approval). A requested-but-unauthorized LIVE downgrades to SHADOW with an
 * explicit reason — never a silent escalation to real spend.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-engineering-run-conductor.md
 *
 * Claim policy: provider-safe. Atlas substitutes Claude Code / Cursor / Codex
 * as products while using their engines underneath. No benchmark, rivals or
 * superiority claims.
 */
final class AtlasEngineeringRunConductorService
{
    public const ENVELOPE_SCHEMA = 'atlas.engineering_run.envelope.v1';

    public const COMPOUNDING_CANDIDATE_SCHEMA = 'atlas.engineering_run.compounding_candidate.v1';

    public const CONTEXT_INJECTION_SCHEMA = 'atlas.engineering_run.context_injection.v1';

    public const MODE_SHADOW = 'shadow';

    public const MODE_LIVE = 'live';

    public const STATUS_NO_DISPATCH = 'no_dispatch';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_VERIFIED_BLOCKED = 'verified_blocked';

    public const STATUS_SPEC_BLOCKED = 'spec_blocked';

    public const LIVE_FLAG = 'atlas.patamar4.swarm_production_resolver_enabled';

    public function __construct(
        private readonly AtlasSwarmConductorService $conductor,
        private readonly AtlasSwarmExecutorService $executor,
        private readonly AtlasSwarmProductionResolverService $productionResolver,
        private readonly ?AtlasVerifiedExecutionRuntimeService $verifiedExecution = null,
        private readonly ?AtlasCompoundingMemoryService $compoundingMemory = null,
        private readonly ?SpecCritic $specCritic = null,
        private readonly ?AiContextPackBuilder $contextPackBuilder = null,
        private readonly ?AtlasCompoundingRuntimeService $compoundingRuntime = null,
        private readonly ?AtlasLiveCodeDeliveryService $codeDelivery = null,
        private readonly ?AtlasConductorRoutingMemory $routingMemory = null,
    ) {}

    /**
     * @param  array<string,mixed>  $work     ['task_category','role','framework','parallelism','scope','requested_autonomy','input','privacy_class']
     * @param  array<string,mixed>  $options  ['mode'=>shadow|live,'verify'=>bool,'changed_files'=>list<string>,'tests'=>array,'evidence_refs'=>list<string>]
     * @return array<string,mixed>
     */
    public function run(array $work, array $options = []): array
    {
        $requestedMode = ($options['mode'] ?? self::MODE_SHADOW) === self::MODE_LIVE ? self::MODE_LIVE : self::MODE_SHADOW;
        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        // 0. SDD scope gate (opt-in) — when the caller supplies a spec, an
        //    ambiguous/untestable spec is blocked BEFORE any dispatch or spend.
        $specReview = $this->reviewSpecGate($options);
        if ($specReview !== null && ($specReview['has_blocking_questions'] ?? false) === true) {
            return $this->specBlockedEnvelope($generatedAt, $requestedMode, $work, $specReview);
        }

        // 0.5 Learned auto-routing — when the operator gave no provider, consult
        //     the conductor's own routing memory (learned from prior real runs)
        //     and inject the best performer as the bootstrap provider, so the
        //     runtime self-routes without --provider after it has accrued data.
        $autoRouted = null;
        if ($this->routingMemory !== null && trim((string) ($work['forced_provider'] ?? '')) === '') {
            $autoRouted = $this->routingMemory->recommend((string) ($work['task_category'] ?? ''), (string) ($work['role'] ?? ''));
            if (is_array($autoRouted) && (string) ($autoRouted['provider'] ?? '') !== '') {
                $work['forced_provider'] = (string) $autoRouted['provider'];
            }
        }

        // 1. Governed plan + route (Constitutional Kernel + Autonomy Admission + ADML).
        $dispatch = $this->conductor->dispatch($work);
        $arms = (array) ($dispatch['arms'] ?? []);
        $effective = (int) ($dispatch['effective_parallelism'] ?? 0);

        if ($arms === [] || $effective === 0) {
            // Governed no-op: kernel block or insufficient routing evidence.
            return $this->envelope($generatedAt, self::MODE_SHADOW, $requestedMode, self::STATUS_NO_DISPATCH, $dispatch, null, null, $work, null, ['specReview' => $specReview]);
        }

        // 2. Resolve the effective mode under the sovereignty guards.
        $operatorApproved = ($options['operator_approved'] ?? false) === true;
        [$mode, $downgradeReason] = $this->effectiveMode($requestedMode, $dispatch, $operatorApproved);

        // 3. Governed context assembly (provider-safe) — accrued memory recall
        //    (always, graceful-empty) + opt-in full Context Pack (code-intel + KB)
        //    folded into the prompt so LIVE arms carry context/quality.
        $recalled = $this->recallGovernedMemory($work);
        $contextPack = $this->buildContextPack($work, $options);
        $baseInput = (string) ($work['input'] ?? ($work['task_category'] ?? ''));

        // 4. Wire the resolver for the chosen mode (SHADOW = deterministic plan, no spend).
        $this->executor->setResolver($this->resolverForMode($mode));

        // 5. Real cross-provider execution (+ per-arm ADML feedback + tie-break winner).
        $context = [
            'task_category' => (string) ($work['task_category'] ?? ''),
            'role' => (string) ($work['role'] ?? ''),
            'framework' => $work['framework'] ?? null,
            'privacy_class' => (string) ($work['privacy_class'] ?? 'normal'),
            'input' => $this->composeInput($baseInput, $recalled, $contextPack),
            'recalled_memory' => $recalled,
        ];
        $execution = $this->executor->execute($dispatch, $context);

        $evidenceRefs = array_values(array_filter((array) ($options['evidence_refs'] ?? []), 'is_string'));

        // 6. Optional blocking verification gate (real patch verifier).
        $verification = null;
        $status = self::STATUS_EXECUTED;
        if (($options['verify'] ?? false) === true && $this->verifiedExecution !== null) {
            $verification = $this->verifiedExecution->verifyDiff([
                'changed_files' => array_values((array) ($options['changed_files'] ?? [])),
                'tests' => (array) ($options['tests'] ?? []),
                'no_test_reason' => $options['no_test_reason'] ?? null,
                'evidence_refs' => $evidenceRefs,
            ]);
            if (($verification['status'] ?? null) === AtlasVerifiedExecutionRuntimeService::STATUS_BLOCKED) {
                $status = self::STATUS_VERIFIED_BLOCKED;
            }
        }

        // 7. Close the compounding loop (LIVE-only, opt-in) — feed the real run
        //    outcome to the existing compounding pipeline so it learns. NEVER on
        //    SHADOW (a planned outcome must not pollute the learning system).
        $compoundingRecord = $this->maybeRecordCompounding($mode, $status, $execution, $work, $evidenceRefs, $options);

        // 8. Real code delivery (LIVE-only, opt-in) — the routed winner provider
        //    produces a syntax-verified artifact in an isolated sandbox,
        //    certified for review (never merged).
        $codeDelivery = $this->maybeDeliverCode($mode, $status, $execution, $work, $options);

        // 9. Feed learned routing: a LIVE success records (task, role, provider,
        //    result, latency) so future runs auto-route without --provider.
        $this->recordRouting($mode, $status, $execution, $work);

        return $this->envelope($generatedAt, $mode, $requestedMode, $status, $dispatch, $execution, $verification, $work, $downgradeReason, [
            'recalled' => $recalled,
            'evidenceRefs' => $evidenceRefs,
            'specReview' => $specReview,
            'contextPack' => $contextPack,
            'compoundingRecord' => $compoundingRecord,
            'codeDelivery' => $codeDelivery,
            'autoRouted' => $autoRouted,
        ]);
    }

    /**
     * @param  array<string,mixed>|null  $execution
     * @param  array<string,mixed>  $work
     */
    private function recordRouting(string $mode, string $status, ?array $execution, array $work): void
    {
        if ($this->routingMemory === null || $mode !== self::MODE_LIVE || $status !== self::STATUS_EXECUTED) {
            return;
        }
        $winner = is_array($execution['winner'] ?? null) ? $execution['winner'] : null;
        if ($winner === null) {
            return;
        }
        $this->routingMemory->record([
            'task_category' => (string) ($work['task_category'] ?? ''),
            'role' => (string) ($work['role'] ?? ''),
            'provider' => (string) ($winner['provider'] ?? ''),
            'model' => (string) ($winner['model'] ?? ''),
            'result' => (string) ($winner['result'] ?? ''),
            'latency_ms' => $winner['latency_ms'] ?? null,
        ]);
    }

    /**
     * @param  array<string,mixed>  $dispatch
     * @return array{0:string,1:?string}  [effectiveMode, downgradeReason]
     */
    private function effectiveMode(string $requestedMode, array $dispatch, bool $operatorApproved): array
    {
        return self::decideMode(
            $requestedMode,
            $this->liveEnabled(),
            (string) ($dispatch['admission_decision'] ?? ''),
            $operatorApproved,
        );
    }

    /**
     * Pure sovereignty-guard decision (no I/O) so every branch is exhaustively
     * testable. LIVE is an ALLOWLIST: real provider spend is reached ONLY when
     * the production resolver is enabled AND the admission verdict explicitly
     * authorizes it — autonomously (ALLOW_AUTONOMOUS) or under an explicit
     * operator approval (ALLOW_WITH_APPROVAL + $operatorApproved). A DENY, an
     * un-approved approval-required verdict, OR any empty/unknown decision (e.g.
     * from a future dispatch source) downgrades to SHADOW with an explicit
     * reason — never a silent escalation to real spend.
     *
     * @return array{0:string,1:?string}  [effectiveMode, downgradeReason]
     */
    public static function decideMode(string $requestedMode, bool $liveEnabled, string $admissionDecision, bool $operatorApproved): array
    {
        if ($requestedMode !== self::MODE_LIVE) {
            return [self::MODE_SHADOW, null];
        }
        if (! $liveEnabled) {
            return [self::MODE_SHADOW, 'production_resolver_disabled'];
        }
        if ($admissionDecision === AtlasAutonomyAdmissionService::DECISION_ALLOW_AUTONOMOUS) {
            return [self::MODE_LIVE, null];
        }
        if ($admissionDecision === AtlasAutonomyAdmissionService::DECISION_ALLOW_WITH_APPROVAL) {
            return $operatorApproved
                ? [self::MODE_LIVE, null]
                : [self::MODE_SHADOW, 'autonomy_requires_operator_approval'];
        }
        if ($admissionDecision === AtlasAutonomyAdmissionService::DECISION_DENY) {
            return [self::MODE_SHADOW, 'autonomy_admission_denied'];
        }

        // Empty/unknown verdict — fail closed, never escalate to spend.
        return [self::MODE_SHADOW, 'autonomy_decision_unrecognized'];
    }

    private function liveEnabled(): bool
    {
        return function_exists('config') && (bool) config(self::LIVE_FLAG, false);
    }

    private function resolverForMode(string $mode): Closure
    {
        if ($mode === self::MODE_LIVE) {
            return $this->productionResolver->asClosure();
        }

        // SHADOW: deterministic governed plan — the top-ranked (recommended) arm
        // "wins" via the executor tie-break, with zero provider invocation.
        return static function (array $arm, array $context): array {
            return [
                'result' => 'success',
                'latency_ms' => 0,
                'quality_score' => null,
                'output' => 'shadow_planned:'.((string) ($arm['provider'] ?? '')),
            ];
        };
    }

    /**
     * @param  array<string,mixed>  $dispatch
     * @param  array<string,mixed>|null  $execution
     * @param  array<string,mixed>|null  $verification
     * @param  array<string,mixed>  $work
     * @return array<string,mixed>
     */
    private function envelope(
        string $generatedAt,
        string $mode,
        string $requestedMode,
        string $status,
        array $dispatch,
        ?array $execution,
        ?array $verification,
        array $work,
        ?string $downgradeReason = null,
        array $extras = [],
    ): array {
        $recalled = (array) ($extras['recalled'] ?? []);
        $evidenceRefs = (array) ($extras['evidenceRefs'] ?? []);
        $specReview = is_array($extras['specReview'] ?? null) ? $extras['specReview'] : null;
        $contextPack = is_array($extras['contextPack'] ?? null) ? $extras['contextPack'] : null;
        $compoundingRecord = is_array($extras['compoundingRecord'] ?? null) ? $extras['compoundingRecord'] : null;
        $codeDelivery = is_array($extras['codeDelivery'] ?? null) ? $extras['codeDelivery'] : null;
        $autoRouted = is_array($extras['autoRouted'] ?? null) ? $extras['autoRouted'] : null;

        $winner = $execution['winner'] ?? null;
        if (! is_array($winner)) {
            $winner = null;
        }

        $env = [
            'schema_version' => self::ENVELOPE_SCHEMA,
            'generated_at' => $generatedAt,
            'mode' => $mode,
            'requested_mode' => $requestedMode,
            'mode_downgrade_reason' => $downgradeReason,
            'status' => $status,
            'task_category' => (string) ($work['task_category'] ?? ''),
            'role' => (string) ($work['role'] ?? ''),
            'dispatch_id' => $dispatch['dispatch_id'] ?? null,
            'kernel_decision' => $dispatch['kernel_decision'] ?? null,
            'admission_decision' => $dispatch['admission_decision'] ?? null,
            'requested_parallelism' => $dispatch['requested_parallelism'] ?? null,
            'effective_parallelism' => (int) ($dispatch['effective_parallelism'] ?? 0),
            'arms' => array_map(static fn (array $a): array => [
                'arm_id' => $a['arm_id'] ?? null,
                'provider' => $a['provider'] ?? null,
                'model' => $a['model'] ?? null,
                'origin' => $a['origin'] ?? null,
            ], array_values((array) ($dispatch['arms'] ?? []))),
            'winner' => $winner,
            'auto_routed' => $autoRouted,
            'verification' => $verification,
            'context_injection' => $this->contextInjectionSummary($recalled, $contextPack),
            'spec_review' => $this->specReviewSummary($specReview),
            'compounding_candidate' => $this->compoundingCandidate($status, $dispatch, $winner, $evidenceRefs),
            'compounding_record' => $compoundingRecord,
            'code_delivery' => $codeDelivery,
            'claim_policy' => [
                'aggregate_winner_claim_allowed' => false,
                'rivals_claim_allowed' => false,
                'benchmark_claim_allowed' => false,
                'superiority_claim_allowed' => false,
            ],
        ];

        $env['run_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::ENVELOPE_SCHEMA,
            'dispatch_id' => $env['dispatch_id'],
            'status' => $status,
            'mode' => $mode,
            'winner_arm_id' => $winner['arm_id'] ?? null,
        ], JSON_THROW_ON_ERROR));

        return $env;
    }

    /**
     * Emit the raw material for compounding memory — a provider-safe signal the
     * compounding pipeline can promote once it carries evidence + confidence.
     * The conductor does not force AiLearningCandidate construction here (that
     * is the compounding pipeline's job); it surfaces the honest signal so a
     * certified run can feed learning instead of evaporating.
     *
     * @param  array<string,mixed>  $dispatch
     * @param  array<string,mixed>|null  $winner
     * @return array<string,mixed>|null
     */
    private function compoundingCandidate(string $status, array $dispatch, ?array $winner, array $evidenceRefs): ?array
    {
        if ($status !== self::STATUS_EXECUTED || $winner === null || ($winner['result'] ?? null) !== 'success') {
            return null;
        }

        $quality = $winner['quality_score'] ?? null;

        return [
            'schema_version' => self::COMPOUNDING_CANDIDATE_SCHEMA,
            'claim' => 'route '.((string) ($winner['provider'] ?? 'unknown')).' produced a success outcome for task_category='.((string) ($dispatch['task_category'] ?? '')),
            'flow_id' => (string) ($dispatch['task_category'] ?? ''),
            'provider' => $winner['provider'] ?? null,
            'model' => $winner['model'] ?? null,
            'confidence_signal' => is_numeric($quality) ? (float) $quality : null,
            'evidence_refs' => $evidenceRefs,
            'revalidation_policy' => 'revalidate_on_failure_or_expiry',
            // The compounding PIPELINE owns promotion (evidence + confidence>=70 +
            // revalidation gate via AtlasCompoundingMemoryService::promote). The
            // conductor only emits a pipeline-ready signal — it never promotes
            // here, which would be a parallel mechanism the canon forbids.
            'promotion_allowed' => false,
            'pipeline_ready' => $evidenceRefs !== [],
            'requires' => ['evidence_refs', 'confidence>=70', 'revalidation_policy'],
        ];
    }

    /**
     * Provider-safe governed recall: approved compounding memories scoped to the
     * task's flow (global + task-specific). Returns [] gracefully when no memory
     * source is wired or no memory store exists — never breaks the run.
     *
     * @param  array<string,mixed>  $work
     * @return list<array<string,mixed>>
     */
    private function recallGovernedMemory(array $work): array
    {
        if ($this->compoundingMemory === null) {
            return [];
        }
        $flowKey = (string) ($work['flow_id'] ?? $work['task_category'] ?? '');
        if ($flowKey === '') {
            return [];
        }
        try {
            return array_values($this->compoundingMemory->approvedForFlow($flowKey, 5));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Fold recalled governed memory into the prompt the providers receive. The
     * base intent is always preserved verbatim; memory is appended under a
     * clearly-labelled, provider-safe header.
     *
     * @param  list<array<string,mixed>>  $recalled
     */
    private function injectMemoryIntoInput(string $baseInput, array $recalled): string
    {
        $lines = [];
        foreach ($recalled as $memory) {
            $claim = trim((string) ($memory['claim'] ?? ''));
            if ($claim !== '') {
                $lines[] = '- '.$claim;
            }
        }
        if ($lines === []) {
            return $baseInput;
        }

        return $baseInput."\n\n[Atlas governed memory — accrued learning, provider-safe]\n".implode("\n", $lines);
    }

    /**
     * SDD scope gate (opt-in): critique a caller-supplied spec. Returns null
     * when no spec is supplied or no critic is wired (gate skipped, run
     * proceeds); otherwise the SpecCritic review.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>|null
     */
    private function reviewSpecGate(array $options): ?array
    {
        if ($this->specCritic === null) {
            return null;
        }
        $spec = $options['spec'] ?? null;
        if (! is_array($spec) || $spec === []) {
            return null;
        }

        return $this->specCritic->review($spec, ['digest' => 'engineering_run']);
    }

    /**
     * @param  array<string,mixed>|null  $specReview
     * @return array<string,mixed>|null
     */
    private function specReviewSummary(?array $specReview): ?array
    {
        if ($specReview === null) {
            return null;
        }

        return [
            'status' => $specReview['status'] ?? null,
            'has_blocking_questions' => (bool) ($specReview['has_blocking_questions'] ?? false),
            'clarification_questions' => array_values((array) ($specReview['clarification_questions'] ?? [])),
        ];
    }

    /**
     * Governed envelope for an SDD-gate block — the run never dispatched and
     * never spent; the operator gets the clarification questions to resolve.
     *
     * @param  array<string,mixed>  $work
     * @param  array<string,mixed>  $specReview
     * @return array<string,mixed>
     */
    private function specBlockedEnvelope(string $generatedAt, string $requestedMode, array $work, array $specReview): array
    {
        $env = [
            'schema_version' => self::ENVELOPE_SCHEMA,
            'generated_at' => $generatedAt,
            'mode' => self::MODE_SHADOW,
            'requested_mode' => $requestedMode,
            'mode_downgrade_reason' => null,
            'status' => self::STATUS_SPEC_BLOCKED,
            'task_category' => (string) ($work['task_category'] ?? ''),
            'role' => (string) ($work['role'] ?? ''),
            'dispatch_id' => null,
            'kernel_decision' => null,
            'admission_decision' => null,
            'requested_parallelism' => null,
            'effective_parallelism' => 0,
            'arms' => [],
            'winner' => null,
            'verification' => null,
            'context_injection' => $this->contextInjectionSummary([]),
            'spec_review' => $this->specReviewSummary($specReview),
            'compounding_candidate' => null,
            'claim_policy' => [
                'aggregate_winner_claim_allowed' => false,
                'rivals_claim_allowed' => false,
                'benchmark_claim_allowed' => false,
                'superiority_claim_allowed' => false,
            ],
        ];
        $env['run_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::ENVELOPE_SCHEMA,
            'status' => self::STATUS_SPEC_BLOCKED,
            'task' => $env['task_category'],
        ], JSON_THROW_ON_ERROR));

        return $env;
    }

    /**
     * @param  list<array<string,mixed>>  $recalled
     * @return array<string,mixed>
     */
    private function contextInjectionSummary(array $recalled, ?array $contextPack = null): array
    {
        return [
            'schema_version' => self::CONTEXT_INJECTION_SCHEMA,
            'recalled_count' => count($recalled),
            'memory_ids' => array_values(array_filter(array_map(
                static fn (array $m): ?string => isset($m['memory_id']) ? (string) $m['memory_id'] : null,
                $recalled,
            ))),
            'context_pack_present' => $contextPack !== null,
            'context_pack_refs' => $contextPack !== null ? (int) ($contextPack['ref_count'] ?? 0) : 0,
        ];
    }

    /**
     * Close the compounding loop by feeding the REAL run outcome to the existing
     * compounding pipeline (AtlasCompoundingRuntimeService::recordExecution →
     * distill → promote). Guardrails:
     *   - LIVE only — a SHADOW plan is not a real outcome and must never train
     *     the learning system.
     *   - opt-in (options['compound'] === true) — recordExecution is heavy.
     *   - only on a successful executed run.
     *   - fully guarded — a compounding failure never breaks the engineering run.
     *
     * @param  array<string,mixed>|null  $execution
     * @param  array<string,mixed>  $work
     * @param  list<string>  $evidenceRefs
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>|null
     */
    private function maybeRecordCompounding(string $mode, string $status, ?array $execution, array $work, array $evidenceRefs, array $options): ?array
    {
        if ($this->compoundingRuntime === null
            || $mode !== self::MODE_LIVE
            || ($options['compound'] ?? false) !== true
            || $status !== self::STATUS_EXECUTED) {
            return null;
        }
        $winner = is_array($execution['winner'] ?? null) ? $execution['winner'] : null;
        if ($winner === null || ($winner['result'] ?? null) !== 'success') {
            return null;
        }

        try {
            $quality = $winner['quality_score'] ?? null;
            $result = $this->compoundingRuntime->recordExecution([
                'outcome_status' => 'passed',
                'flow_id' => (string) ($work['task_category'] ?? ''),
                'run_id' => (string) ($execution['dispatch_id'] ?? ''),
                'evidence_refs' => $evidenceRefs,
                'execution_quality' => is_numeric($quality) ? max(0.0, min(100.0, (float) $quality * 100)) : null,
            ]);

            return [
                'recorded' => true,
                'learning_candidate_status' => $result['learning_candidate']['status'] ?? null,
                'compounding_memory_id' => $result['compounding_memory']['id'] ?? null,
            ];
        } catch (\Throwable) {
            return ['recorded' => false, 'error' => 'compounding_record_failed'];
        }
    }

    /**
     * Real code delivery via the routed winner provider (LIVE-only, opt-in).
     * The provider generates a syntax-verified artifact in an isolated sandbox,
     * certified for review — never merged. LIVE-only because it spends real
     * provider tokens; SHADOW stays no-spend.
     *
     * @param  array<string,mixed>|null  $execution
     * @param  array<string,mixed>  $work
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>|null
     */
    private function maybeDeliverCode(string $mode, string $status, ?array $execution, array $work, array $options): ?array
    {
        if ($this->codeDelivery === null
            || $mode !== self::MODE_LIVE
            || ($options['deliver_code'] ?? false) !== true
            || $status !== self::STATUS_EXECUTED) {
            return null;
        }
        $winner = is_array($execution['winner'] ?? null) ? $execution['winner'] : null;
        if ($winner === null || ($winner['result'] ?? null) !== 'success') {
            return null;
        }

        $goal = (string) ($work['input'] ?? ($work['task_category'] ?? ''));
        try {
            return $this->codeDelivery->deliver($goal, [
                'provider' => (string) ($winner['provider'] ?? ''),
                'model' => (string) ($winner['model'] ?? ''),
                'target_file' => (string) ($options['target_file'] ?? 'AtlasGeneratedSnippet.php'),
                'verify_run' => ($options['verify_run'] ?? false) === true,
                'multi_file' => ($options['multi_file'] ?? false) === true,
            ]);
        } catch (\Throwable) {
            return ['status' => 'blocked', 'certified' => false, 'blocked_reason' => 'code_delivery_failed'];
        }
    }

    /**
     * Opt-in full Context Pack assembly (code-intel + KB + memory) via the
     * canonical AiContextPackBuilder. OFF unless options['rich_context'] is
     * true, so SHADOW planning stays fast; fully guarded so a builder error or
     * an unpopulated index never breaks the run. Returns
     * {prompt_section, ref_count} or null.
     *
     * @param  array<string,mixed>  $work
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>|null
     */
    private function buildContextPack(array $work, array $options): ?array
    {
        if ($this->contextPackBuilder === null || ($options['rich_context'] ?? false) !== true) {
            return null;
        }
        try {
            $input = (string) ($work['input'] ?? ($work['task_category'] ?? ''));
            if ($input === '') {
                return null;
            }
            $role = (string) ($work['role'] ?? 'orquestrador');
            $task = AiTaskRequest::fromInput(
                $input,
                ['agent_slug' => $role, 'payload' => ['privacy_class' => (string) ($work['privacy_class'] ?? 'normal')]],
                ['agent' => $role, 'intent' => 'engineering'],
            );
            $pack = $this->contextPackBuilder->build($input, $task, []);

            return [
                'prompt_section' => $pack->toPromptSection(),
                'ref_count' => count($pack->contextRefs()),
            ];
        } catch (\Throwable) {
            return null; // context assembly must never break the run
        }
    }

    /**
     * Compose the final prompt: base intent verbatim + governed memory recall +
     * opt-in Context Pack section. Each layer is additive and skipped when empty.
     *
     * @param  list<array<string,mixed>>  $recalled
     * @param  array<string,mixed>|null  $contextPack
     */
    private function composeInput(string $baseInput, array $recalled, ?array $contextPack): string
    {
        $input = $this->injectMemoryIntoInput($baseInput, $recalled);
        $section = is_array($contextPack) ? (string) ($contextPack['prompt_section'] ?? '') : '';
        if ($section !== '') {
            $input .= "\n\n".$section;
        }

        return $input;
    }
}
