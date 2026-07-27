<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance;

use App\Models\AiJob;
use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\Caching\AiCallCostGuard;

/**
 * Shared governance consult — the SAME cost/route governance the manager runs,
 * made consultable by the muscle paths that spawn providers DIRECTLY (Forge/loop
 * CLI, Dev claude) WITHOUT forcing them through AiProviderManager::get() (which
 * would break streaming / worktree execution). This is the "same root" seam:
 * one governance primitive, consulted everywhere, not manager-locked.
 *
 * It DELEGATES to the existing primitives — it does NOT reimplement them:
 *   - ADML route recommendation: {@see AtlasDecideGatewayConsultationService::consult()}
 *     (includes Constitutional Kernel + Autonomy Admission via ADGW);
 *   - per-call pre-cost verdict:  {@see AiCallCostGuard::evaluate()};
 *   - trust-budget advisory:      {@see AtlasTrustBudgetService::check()} (never consumes).
 *
 * SOBERANIA — advisory-first. By default it only MEASURES what the manager
 * would have routed/charged and records the muscle execution as CONSULTED
 * (governed via the shared seam) so the bypass rate falls honestly. The cost
 * guard CAN block — but only when the operator flips
 * config('atlas.ai.governance.enforce') ON (default OFF => byte-identical) AND a
 * cost_guard.hard_units threshold is configured. Fail-open: any consult error
 * degrades to a no-op advisory that never blocks a spawn.
 *
 * Schema: atlas.ai.governance.provider_consult.v1
 */
final class ProviderGovernanceConsult
{
    public const SCHEMA = 'atlas.ai.governance.provider_consult.v1';

    public function __construct(
        private readonly AtlasDecideGatewayConsultationService $adml,
        private readonly AiCallCostGuard $costGuard,
        private readonly ProviderGovernanceCoverageLedger $coverage,
        private readonly AtlasTrustBudgetService $trustBudget,
    ) {}

    /**
     * Consult ADML + cost-guard for a muscle spawn, record it as CONSULTED, and
     * return the advisory. NEVER throws.
     *
     * @param  array{
     *   provider:string, surface:string, prompt?:string,
     *   task_category?:string, role?:string, framework?:?string, privacy_class?:string,
     *   flow_id?:?string, risk_level?:?string, kind?:?string
     * }  $ctx
     * @return array{
     *   provider:string, surface:string, adml_verdict:string, adml_route:?string,
     *   kernel_decision:string, admission_decision:string,
     *   trust_budget:array<string,mixed>, cost:array<string,mixed>,
     *   enforce:bool, should_block:bool, reason:?string,
     *   would_have_blocked:bool, would_have_blocked_reason:?string,
     *   candidate_hard_units:float
     * }
     */
    public function consultBeforeSpawn(array $ctx): array
    {
        $provider = (string) ($ctx['provider'] ?? 'unknown');
        $surface = (string) ($ctx['surface'] ?? 'unknown');

        // 1. ADML route recommendation (advisory). Delegates to the consult
        //    service directly — NOT getRecommended(), which would resolve a
        //    provider through the manager and mis-count this as COVERED.
        $admlVerdict = 'no_consultation';
        $admlRoute = null;
        $kernelDecision = 'no_consultation';
        $admissionDecision = 'no_consultation';
        try {
            $rec = $this->adml->consult([
                'task_category' => (string) ($ctx['task_category'] ?? 'programming'),
                'role' => (string) ($ctx['role'] ?? 'executor'),
                'framework' => $ctx['framework'] ?? null,
                'privacy_class' => (string) ($ctx['privacy_class'] ?? 'normal'),
                'actor' => $surface,
            ]);
            $admlVerdict = (string) ($rec['verdict'] ?? 'no_consultation');
            $route = is_array($rec['active_route'] ?? null) ? ($rec['active_route']['provider'] ?? null) : null;
            $admlRoute = is_string($route) ? $route : null;
            $kernelDecision = (string) ($rec['kernel_decision'] ?? 'no_consultation');
            $admissionDecision = (string) ($rec['admission_decision'] ?? 'no_consultation');
        } catch (\Throwable) {
            $admlVerdict = 'consultation_error';
        }

        // 2. Cost-guard pre-cost verdict (advisory). Reuses the SAME AiCallCostGuard
        //    the manager's CachingAiProvider wraps + the SAME cost_guard config.
        $cost = [];
        try {
            [$soft, $hard] = $this->thresholds();
            $job = (new AiJob)->forceFill([
                'kind' => (string) ($ctx['kind'] ?? 'atlas_programming'),
                'payload' => array_filter([
                    'flow_id' => $ctx['flow_id'] ?? null,
                    'risk_level' => $ctx['risk_level'] ?? null,
                ], static fn ($v): bool => $v !== null),
            ]);
            $cost = $this->costGuard->evaluate($job, (string) ($ctx['prompt'] ?? ''), $soft, $hard);
        } catch (\Throwable) {
            $cost = [];
        }

        // 3. Trust-budget advisory (ACK/ATBS seam). Muscle spawns are external
        //    low-risk consults — check only, never consume in this path.
        $trustBudget = [];
        try {
            $trustBudget = $this->trustBudget->check(
                AtlasTrustBudgetService::TIER_LOW,
                AtlasTrustBudgetService::CLASS_EXTERNAL,
            );
        } catch (\Throwable) {
            $trustBudget = [];
        }

        $enforce = (bool) (function_exists('config') ? config('atlas.ai.governance.enforce', false) : false);
        $hardExceeded = ($cost['hard_exceeded'] ?? false) === true;
        $trustDenied = ($trustBudget['verdict'] ?? '') === AtlasTrustBudgetService::VERDICT_DENY_BUDGET_EXCEEDED;
        $kernelBlocked = $kernelDecision === AtlasConstitutionalKernelService::DECISION_BLOCK;
        $shouldBlock = $enforce && ($hardExceeded || $trustDenied || $kernelBlocked);
        $reason = match (true) {
            $enforce && $kernelBlocked => 'constitutional_kernel_block',
            $enforce && $trustDenied => 'trust_budget_exceeded',
            $enforce && $hardExceeded => 'cost_guard_hard_exceeded',
            default => null,
        };

        // ENG-10 — advisory would-have-blocked meter: simulate enforce ON with the
        // candidate hard ceiling (derived from ledger traffic or env override).
        // Never blocks the spawn — only records what WOULD have happened.
        [$wouldHaveBlocked, $wouldHaveBlockedReason, $candidateHardUnits] = $this->simulateWouldHaveBlocked(
            $cost,
            $trustDenied,
            $kernelBlocked,
        );

        // 4. Record the muscle execution as CONSULTED (governed via the shared
        //    seam) so the bypass rate falls honestly. The advisory is captured
        //    for audit; recording NEVER blocks the spawn.
        $this->coverage->recordConsulted($provider, $surface, [
            'executor' => $this->labelOrNull($ctx['executor'] ?? null),
            'actor' => $this->labelOrNull($ctx['actor'] ?? null),
            'adml_verdict' => $admlVerdict,
            'adml_route' => $admlRoute,
            'pre_cost_units' => isset($cost['pre_cost_units']) ? (float) $cost['pre_cost_units'] : null,
            'cost_soft_warn' => (bool) ($cost['soft_warn'] ?? false),
            'cost_hard_exceeded' => $hardExceeded,
            'kernel_decision' => $kernelDecision,
            'trust_budget_verdict' => (string) ($trustBudget['verdict'] ?? ''),
            'enforce' => $enforce,
            'blocked' => $shouldBlock,
            'would_have_blocked' => $wouldHaveBlocked,
            'would_have_blocked_reason' => $wouldHaveBlockedReason,
            'candidate_hard_units' => $candidateHardUnits,
        ]);

        return [
            'provider' => $provider,
            'surface' => $surface,
            'adml_verdict' => $admlVerdict,
            'adml_route' => $admlRoute,
            'kernel_decision' => $kernelDecision,
            'admission_decision' => $admissionDecision,
            'trust_budget' => $trustBudget,
            'cost' => $cost,
            'enforce' => $enforce,
            'should_block' => $shouldBlock,
            'reason' => $reason,
            'would_have_blocked' => $wouldHaveBlocked,
            'would_have_blocked_reason' => $wouldHaveBlockedReason,
            'candidate_hard_units' => $candidateHardUnits,
        ];
    }

    private function labelOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $label = strtolower(trim((string) $value));

        return $label !== '' ? $label : null;
    }

    /**
     * ENG-10 — simulate enforce=true with the candidate hard ceiling.
     *
     * @param  array<string,mixed>  $cost
     * @return array{0:bool,1:?string,2:float}
     */
    private function simulateWouldHaveBlocked(array $cost, bool $trustDenied, bool $kernelBlocked): array
    {
        $candidateHardUnits = $this->coverage->resolveCandidateHardUnits();
        $preCostUnits = (float) ($cost['pre_cost_units'] ?? 0.0);
        $candidateHardExceeded = $candidateHardUnits > 0.0 && $preCostUnits > $candidateHardUnits;

        $wouldHaveBlocked = $candidateHardExceeded || $trustDenied || $kernelBlocked;
        $wouldHaveBlockedReason = match (true) {
            $kernelBlocked => 'constitutional_kernel_block',
            $trustDenied => 'trust_budget_exceeded',
            $candidateHardExceeded => ProviderGovernanceCoverageLedger::REASON_COST_GUARD_CANDIDATE_HARD_EXCEEDED,
            default => null,
        };

        return [$wouldHaveBlocked, $wouldHaveBlockedReason, $candidateHardUnits];
    }

    /**
     * @return array{0:float,1:float} [soft, hard] — the SAME cost_guard config
     *   the manager's CachingAiProvider reads (one currency, not a fork).
     */
    private function thresholds(): array
    {
        $guard = function_exists('config') ? config('atlas.ai.cache.cost_guard', []) : [];
        $guard = is_array($guard) ? $guard : [];

        return [
            (float) ($guard['soft_units'] ?? 0),
            (float) ($guard['hard_units'] ?? 0),
        ];
    }
}
