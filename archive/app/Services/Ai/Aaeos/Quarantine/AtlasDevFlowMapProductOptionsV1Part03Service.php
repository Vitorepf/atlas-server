<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;

/**
 * Atlas Dev Flow Map And Product Options v1 · Parte 3 — pure, deterministic
 * decider for the CLOSED, CONSOLIDATED slice of the flow/product map doc.
 *
 * This doc is a cartography-readable recorte. Its "Regras para IA" forbid turning
 * a hipótese / diário / opção futura / comparação into runtime. This decider
 * therefore encodes ONLY the parts the doc itself marks as settled:
 *
 *   - "Sintese Dos 5 Agentes Especializados" → "Decisoes consolidadas": a closed
 *     set of hard rules ("Todo write precisa de mini-spec, task contract, scope
 *     guard e receipt"; "`passed` so existe com evidence"; "Risco R4/R5 gera
 *     plan-only ... nao patch Dev"; "Code Intelligence e autoridade operacional";
 *     the measurement arm stays frozen until a local proof).
 *   - "Decisao atual apos corte de escopo": the things explicitly OUT of scope
 *     today (no measurement competition, no provider challenge, no claim of win).
 *   - The three-speed architecture (provider puro / Atlas Dev fast path / Forge
 *     heavy path) named throughout the recorte.
 *   - "Estado Atual" → "O que ainda nao existe como driver proprio": the factual
 *     gate that `atlas_dev_light` real-run blocks honestly with
 *     `atlas_dev_light_driver_pending`; the real path is
 *     atlas:cli:dev → atlas:ai:chat → provider | Engineering Harness.
 *
 * It does NOT execute anything, does NOT promote any hypothesis/backlog item to a
 * contract, and touches no database. It only lets a caller ASK, deterministically:
 *   - is this proposed write allowed under the consolidated write rule?
 *   - which of the three speeds applies to a (risk, obra, clean) shape, and may
 *     Atlas Dev fast-path patch at all?
 *   - is this driver runnable yet, or must it block with the documented pending
 *     reason?
 *   - is a given item still explicitly OUT of scope per the scope-cut decision?
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-03.md
 */
final class AtlasDevFlowMapProductOptionsV1Part03Service
{
    /** Stable decision kind this decider emits. */
    public const DECISION_KIND = 'atlas_dev.flow_map_and_product_options.v1.part_03';

    /** The owner index this recorte is a canonical child of. */
    public const OWNER_DOC = 'atlas-dev-flow-map-and-product-options-v1';

    /**
     * The honest blocker the doc states for the dedicated Atlas Dev driver:
     * the `atlas_dev_light` arm is declared but real-run still blocks with this
     * reason because the dedicated driver does not exist yet.
     */
    public const DRIVER_PENDING_REASON = 'atlas_dev_light_driver_pending';

    /**
     * The real Atlas Dev path that exists today (in order), per "Estado Atual".
     *
     * @var list<string>
     */
    public const REAL_DEV_PATH = ['atlas:cli:dev', 'atlas:ai:chat', 'provider_or_engineering_harness'];

    /**
     * "Decisoes consolidadas" — the closed set of hard rules the five agents
     * converged on. These are settled decisions, not hypotheses. Key => stable
     * machine token; value => the asserted invariant in plain words.
     *
     * @var array<string,string>
     */
    public const CONSOLIDATED_DECISIONS = [
        'fast_path_vs_heavy_path' => 'atlas_dev_plus_sonnet_is_daily_fast_path; forge_is_robust_heavy_path',
        'every_write_needs_full_envelope' => 'every_write_needs_mini_spec_task_contract_scope_guard_and_receipt',
        'context_by_tiers' => 'context_is_selected_by_tiers_not_dumped_whole_into_the_prompt',
        'code_intelligence_authority' => 'code_intelligence_is_the_operational_authority_to_find_files_and_symbols',
        'cheap_limited_repair' => 'repair_is_cheap_limited_same_provider_model_and_based_on_a_real_error',
        'passed_needs_evidence' => 'passed_only_exists_with_evidence; needs_review_is_not_a_full_success',
        'r4_r5_is_plan_only' => 'risk_r4_or_r5_yields_plan_only_plus_promotion_preview_not_a_dev_patch',
        'measurement_frozen' => 'the_measurement_arm_stays_frozen_until_a_local_proof',
    ];

    /**
     * "Decisao atual apos corte de escopo" — items explicitly OUT of scope today.
     * Asking about any of these returns out_of_scope=true. Tokens are stable.
     *
     * @var list<string>
     */
    public const OUT_OF_SCOPE_TODAY = [
        'measurement_competition',
        'provider_model_challenge',
        'competitive_measurement_battery',
        'prompt_battery',
        'cost_normalized_score',
        'private_evaluation_oracle',
        'claim_of_win',
    ];

    /**
     * The three speeds of the architecture named across the recorte.
     *
     * @var list<string>
     */
    public const SPEEDS = ['provider_puro', 'atlas_dev_fast_path', 'forge_heavy_path'];

    /**
     * "Decisoes consolidadas" write rule: every write needs the full envelope
     * (mini-spec + task contract + scope guard + receipt). This method enforces it
     * as a closed gate and additionally enforces the R4/R5 plan-only invariant —
     * at R4/R5 a Dev patch is NEVER allowed regardless of envelope completeness.
     *
     * @param  array<string,mixed>  $input
     *         risk_level     : string  (R0..R5; R4/R5 forbids a Dev patch)
     *         has_mini_spec      : bool
     *         has_task_contract  : bool
     *         has_scope_guard    : bool
     *         has_receipt        : bool
     * @return array{
     *   write_allowed:bool, mode:string, missing_envelope:list<string>,
     *   risk_level:string, reason:string
     * }
     */
    public function writeGate(array $input): array
    {
        $risk = $this->normalizeRisk($input['risk_level'] ?? 'R0');

        $required = [
            'mini_spec' => ($input['has_mini_spec'] ?? false) === true,
            'task_contract' => ($input['has_task_contract'] ?? false) === true,
            'scope_guard' => ($input['has_scope_guard'] ?? false) === true,
            'receipt' => ($input['has_receipt'] ?? false) === true,
        ];

        $missing = [];
        foreach ($required as $key => $present) {
            if (! $present) {
                $missing[] = $key;
            }
        }

        // Hard invariant: R4/R5 never produce a Dev patch — they go plan-only.
        if (in_array($risk, ['R4', 'R5'], true)) {
            return [
                'write_allowed' => false,
                'mode' => 'plan_only_plus_promotion_preview',
                'missing_envelope' => $missing,
                'risk_level' => $risk,
                'reason' => 'risk_r4_or_r5_is_plan_only_not_a_dev_patch',
            ];
        }

        if ($missing !== []) {
            return [
                'write_allowed' => false,
                'mode' => 'blocked_incomplete_envelope',
                'missing_envelope' => $missing,
                'risk_level' => $risk,
                'reason' => 'write_requires_mini_spec_task_contract_scope_guard_and_receipt',
            ];
        }

        return [
            'write_allowed' => true,
            'mode' => 'dev_patch',
            'missing_envelope' => [],
            'risk_level' => $risk,
            'reason' => 'full_envelope_present_and_risk_below_r4',
        ];
    }

    /**
     * Classify which of the three documented speeds applies to a task shape, and
     * whether the Atlas Dev fast path may patch. Mirrors the consolidated rule:
     * declared Obra / very long auditable work → forge_heavy_path; R4/R5 →
     * forge_heavy_path (plan-only in Dev); otherwise atlas_dev_fast_path. There is
     * no rule in this recorte that routes to a bare provider, so `provider_puro`
     * is only ever named as a measurement baseline (which is frozen), never as a
     * routing target here.
     *
     * @param  array<string,mixed>  $input
     *         risk_level    : string  (R0..R5)
     *         obra_declared : bool    operator declared an Obra / long auditable work
     *         clean_task    : bool    informational; does not change the speed
     * @return array{
     *   speed:string, fast_path_may_patch:bool, risk_level:string, reasons:list<string>
     * }
     */
    public function speedFor(array $input): array
    {
        $risk = $this->normalizeRisk($input['risk_level'] ?? 'R0');
        $obraDeclared = ($input['obra_declared'] ?? false) === true;
        $reasons = [];

        if ($obraDeclared) {
            $reasons[] = 'obra_declared_routes_to_heavy_path';

            return [
                'speed' => 'forge_heavy_path',
                'fast_path_may_patch' => false,
                'risk_level' => $risk,
                'reasons' => $reasons,
            ];
        }

        if (in_array($risk, ['R4', 'R5'], true)) {
            $reasons[] = 'risk_r4_or_r5_routes_to_heavy_path_plan_only';

            return [
                'speed' => 'forge_heavy_path',
                'fast_path_may_patch' => false,
                'risk_level' => $risk,
                'reasons' => $reasons,
            ];
        }

        $reasons[] = 'default_daily_speed_is_atlas_dev_fast_path';

        return [
            'speed' => 'atlas_dev_fast_path',
            'fast_path_may_patch' => true,
            'risk_level' => $risk,
            'reasons' => $reasons,
        ];
    }

    /**
     * "Estado Atual" gate: the dedicated Atlas Dev driver does not exist yet, so a
     * real run of the declared `atlas_dev_light` arm MUST block with the documented
     * pending reason. Only an explicitly-provided dedicated driver (which the doc
     * says still needs to be created) would make it runnable. Until then the
     * caller is told the real path that does exist.
     *
     * @param  bool  $dedicatedDriverReady  has the dedicated driver actually been built?
     * @return array{
     *   arm:string, runnable:bool, blocks:bool, reason:string, real_path:list<string>
     * }
     */
    public function driverRunGate(bool $dedicatedDriverReady = false): array
    {
        if ($dedicatedDriverReady) {
            return [
                'arm' => 'atlas_dev_light',
                'runnable' => true,
                'blocks' => false,
                'reason' => 'dedicated_driver_present',
                'real_path' => self::REAL_DEV_PATH,
            ];
        }

        return [
            'arm' => 'atlas_dev_light',
            'runnable' => false,
            'blocks' => true,
            'reason' => self::DRIVER_PENDING_REASON,
            'real_path' => self::REAL_DEV_PATH,
        ];
    }

    /**
     * "Decisao atual apos corte de escopo": is this item still explicitly out of
     * scope today? Unknown tokens are treated as in scope (this gate only asserts
     * the documented out-of-scope cut, it does not invent new exclusions).
     *
     * @return array{item:string, out_of_scope:bool, reason:string}
     */
    public function scopeCheck(string $item): array
    {
        $token = strtolower(trim($item));
        $out = in_array($token, self::OUT_OF_SCOPE_TODAY, true);

        return [
            'item' => $token,
            'out_of_scope' => $out,
            'reason' => $out
                ? 'excluded_by_post_scope_cut_decision'
                : 'not_in_the_documented_out_of_scope_cut',
        ];
    }

    /**
     * The closed list of consolidated decisions (stable tokens → asserted rule).
     *
     * @return array<string,string>
     */
    public function consolidatedDecisions(): array
    {
        return self::CONSOLIDATED_DECISIONS;
    }

    /**
     * Stable manifest of the slice this decider governs (for the command/probe).
     *
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        return [
            'decision_kind' => self::DECISION_KIND,
            'doc' => 'docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-03.md',
            'owner_doc' => self::OWNER_DOC,
            'speeds' => self::SPEEDS,
            'consolidated_decision_keys' => array_keys(self::CONSOLIDATED_DECISIONS),
            'out_of_scope_today' => self::OUT_OF_SCOPE_TODAY,
            'driver_pending_reason' => self::DRIVER_PENDING_REASON,
            'real_dev_path' => self::REAL_DEV_PATH,
        ];
    }

    /**
     * Normalize a risk token to upper-case R0..R5 (anything else → 'R0').
     */
    private function normalizeRisk(mixed $risk): string
    {
        return AtlasAaeosValueNormalizer::riskCodeR0ToR5($risk, 'R0');
    }
}
