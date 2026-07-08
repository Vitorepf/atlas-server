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
 *   - ADML route recommendation: {@see AtlasDecideGatewayConsultationService::consult()};
 *   - per-call pre-cost verdict:  {@see AiCallCostGuard::evaluate()}.
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
     *   cost:array<string,mixed>, enforce:bool, should_block:bool, reason:?string
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

        $enforce = (bool) (function_exists('config') ? config('atlas.ai.governance.enforce', false) : false);
        $hardExceeded = ($cost['hard_exceeded'] ?? false) === true;
        $shouldBlock = $enforce && $hardExceeded;

        // 3. Record the muscle execution as CONSULTED (governed via the shared
        //    seam) so the bypass rate falls honestly. The advisory is captured
        //    for audit; recording NEVER blocks the spawn.
        $this->coverage->recordConsulted($provider, $surface, [
            'adml_verdict' => $admlVerdict,
            'adml_route' => $admlRoute,
            'cost_soft_warn' => (bool) ($cost['soft_warn'] ?? false),
            'cost_hard_exceeded' => $hardExceeded,
            'enforce' => $enforce,
            'blocked' => $shouldBlock,
        ]);

        return [
            'provider' => $provider,
            'surface' => $surface,
            'adml_verdict' => $admlVerdict,
            'adml_route' => $admlRoute,
            'cost' => $cost,
            'enforce' => $enforce,
            'should_block' => $shouldBlock,
            'reason' => $shouldBlock ? 'cost_guard_hard_exceeded' : null,
        ];
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
