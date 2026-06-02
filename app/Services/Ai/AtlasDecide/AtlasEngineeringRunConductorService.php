<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
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

    public const MODE_SHADOW = 'shadow';

    public const MODE_LIVE = 'live';

    public const STATUS_NO_DISPATCH = 'no_dispatch';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_VERIFIED_BLOCKED = 'verified_blocked';

    public const LIVE_FLAG = 'atlas.patamar4.swarm_production_resolver_enabled';

    public function __construct(
        private readonly AtlasSwarmConductorService $conductor,
        private readonly AtlasSwarmExecutorService $executor,
        private readonly AtlasSwarmProductionResolverService $productionResolver,
        private readonly ?AtlasVerifiedExecutionRuntimeService $verifiedExecution = null,
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

        // 1. Governed plan + route (Constitutional Kernel + Autonomy Admission + ADML).
        $dispatch = $this->conductor->dispatch($work);
        $arms = (array) ($dispatch['arms'] ?? []);
        $effective = (int) ($dispatch['effective_parallelism'] ?? 0);

        if ($arms === [] || $effective === 0) {
            // Governed no-op: kernel block or insufficient routing evidence.
            return $this->envelope($generatedAt, self::MODE_SHADOW, $requestedMode, self::STATUS_NO_DISPATCH, $dispatch, null, null, $work);
        }

        // 2. Resolve the effective mode under the sovereignty guards.
        $operatorApproved = ($options['operator_approved'] ?? false) === true;
        [$mode, $downgradeReason] = $this->effectiveMode($requestedMode, $dispatch, $operatorApproved);

        // 3. Wire the resolver for the chosen mode (SHADOW = deterministic plan, no spend).
        $this->executor->setResolver($this->resolverForMode($mode));

        // 4. Real cross-provider execution (+ per-arm ADML feedback + tie-break winner).
        $context = [
            'task_category' => (string) ($work['task_category'] ?? ''),
            'role' => (string) ($work['role'] ?? ''),
            'framework' => $work['framework'] ?? null,
            'privacy_class' => (string) ($work['privacy_class'] ?? 'normal'),
            'input' => (string) ($work['input'] ?? ($work['task_category'] ?? '')),
        ];
        $execution = $this->executor->execute($dispatch, $context);

        // 5. Optional blocking verification gate (real patch verifier).
        $verification = null;
        $status = self::STATUS_EXECUTED;
        if (($options['verify'] ?? false) === true && $this->verifiedExecution !== null) {
            $verification = $this->verifiedExecution->verifyDiff([
                'changed_files' => array_values((array) ($options['changed_files'] ?? [])),
                'tests' => (array) ($options['tests'] ?? []),
                'no_test_reason' => $options['no_test_reason'] ?? null,
                'evidence_refs' => array_values((array) ($options['evidence_refs'] ?? [])),
            ]);
            if (($verification['status'] ?? null) === AtlasVerifiedExecutionRuntimeService::STATUS_BLOCKED) {
                $status = self::STATUS_VERIFIED_BLOCKED;
            }
        }

        return $this->envelope($generatedAt, $mode, $requestedMode, $status, $dispatch, $execution, $verification, $work, $downgradeReason);
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
    ): array {
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
            'verification' => $verification,
            'compounding_candidate' => $this->compoundingCandidate($status, $dispatch, $winner),
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
    private function compoundingCandidate(string $status, array $dispatch, ?array $winner): ?array
    {
        if ($status !== self::STATUS_EXECUTED || $winner === null || ($winner['result'] ?? null) !== 'success') {
            return null;
        }

        return [
            'schema_version' => self::COMPOUNDING_CANDIDATE_SCHEMA,
            'claim' => 'route '.((string) ($winner['provider'] ?? 'unknown')).' produced a success outcome for task_category='.((string) ($dispatch['task_category'] ?? '')),
            'provider' => $winner['provider'] ?? null,
            'model' => $winner['model'] ?? null,
            'promotion_allowed' => false,
            'requires' => ['evidence_refs', 'confidence>=70', 'revalidation_policy'],
        ];
    }
}
