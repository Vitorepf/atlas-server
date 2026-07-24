<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

use App\Services\Ai\Rivals\Core\RivalsCurriculumLadder;
use App\Services\Ai\Rivals\Core\RivalsExcellenceMeasure;

/**
 * P2g-EVOL / R108: Autônomos self-evolution quality loop (path-core).
 *
 * Pure policy — no I/O, no ACDE, no operator eng. Turns Rivals/measure signals
 * into structured quality-evolution intents that brain/seed may enqueue.
 * Map: docs/engineering-knowledge-base/atlas-autonomos-self-evolution-quality-loop.md
 */
final class AaeosSelfEvolutionQualityLoop
{
    public const SCHEMA = 'atlas.aaeos.self_evolution_quality_loop.v1';

    public const DISPOSITION_STOP_THE_LINE = 'stop_the_line';

    public const DISPOSITION_PROMOTE_CURRICULUM = 'promote_curriculum';

    public const DISPOSITION_ORIGINATE_QUALITY = 'originate_quality_work';

    public const DISPOSITION_HOLD = 'hold';

    public const PRIORITY_STOP_THE_LINE = 100;

    public const PRIORITY_PROMOTE = 80;

    public const PRIORITY_QUALITY = 60;

    /** Substring markers that mark proxy-only Goodhart work (anti-faxina). */
    private const PROXY_MARKERS = [
        'loc_only',
        'test_count_only',
        'landing_rate_only',
        'increase_test_count',
        'bump_coverage_proxy',
        'cosmetic_cleanup_only',
        'formatting_only',
        'rename_only_proxy',
        'proxy_cleanup',
        'scorecard_vanity',
    ];

    /**
     * Ingress: measure receipt → quality evolution plan (structured intents).
     *
     * @param  array<string,mixed>  $measure  typically RivalsExcellenceMeasure::report()
     * @param  array<string,mixed>  $context  optional curriculum / saturation facts
     * @return array<string,mixed>
     */
    public static function planFromMeasure(array $measure, array $context = []): array
    {
        $m = is_numeric($measure['M_excellence'] ?? null)
            ? (float) $measure['M_excellence']
            : null;
        $negative = (bool) ($measure['multiplier_negative'] ?? ($m !== null && $m < 1.0));
        $stopLine = (bool) ($measure['stop_the_line'] ?? $negative);
        $saturated = (bool) ($measure['frontier_saturated'] ?? $context['frontier_saturated'] ?? false);
        $domainRate = (float) ($context['domain_pass_rate'] ?? $measure['domain_pass_rate'] ?? 0.0);
        $promotionBar = (float) ($context['promotion_bar'] ?? 0.90);
        $stable = (bool) ($context['stable_window'] ?? false);
        $levelId = (string) ($context['level_id']
            ?? $measure['curriculum_level_id']
            ?? 'L1_frontier_engineering');
        $role = RivalsCurriculumLadder::normalizeRole((string) (
            $context['curriculum_role']
            ?? $measure['curriculum_role']
            ?? RivalsCurriculumLadder::ROLE_FRONTIER
        ));
        $operatorCausal = (int) ($context['operator_task_causal_count'] ?? 0);

        $blockers = [];
        if ($operatorCausal > 0) {
            $blockers[] = 'operator_task_causal_forbidden';
        }

        $disposition = self::DISPOSITION_HOLD;
        $priority = 0;
        $intents = [];
        $promotion = null;

        if ($stopLine || $negative || ($m !== null && $m < 1.0)) {
            $disposition = self::DISPOSITION_STOP_THE_LINE;
            $priority = self::PRIORITY_STOP_THE_LINE;
            $intents[] = self::intent(
                kind: 'channel_or_path_repair',
                objective: 'Stop-the-line: repair R104 channel and QoS path so Atlas is not worse than raw (M_excellence < 1).',
                priority: self::PRIORITY_STOP_THE_LINE,
                excellenceTarget: 'restore_M_ge_1',
                antiProxy: 'Work must improve dual-arm frontier measure or transport honesty — not LOC/test-count/landing proxies.',
                remeasure: true,
            );
        } elseif ($saturated || ($stable && $domainRate >= $promotionBar)) {
            $promotion = RivalsCurriculumLadder::evaluatePromotion([
                'curriculum_role' => $role ?? RivalsCurriculumLadder::ROLE_FRONTIER,
                'level_id' => $levelId,
                'predecessor_level_id' => $context['predecessor_level_id'] ?? null,
                'next_level_id' => $context['next_level_id'] ?? 'L2_horizon_unsolved',
                'domain_pass_rate' => max($domainRate, $saturated ? $promotionBar : $domainRate),
                'promotion_bar' => $promotionBar,
                'stable_window' => $stable || $saturated,
            ]);
            $disposition = self::DISPOSITION_PROMOTE_CURRICULUM;
            $priority = self::PRIORITY_PROMOTE;
            $next = (string) ($promotion['next_level_id'] ?? $context['next_level_id'] ?? 'L2_horizon_unsolved');
            $intents[] = self::intent(
                kind: 'next_level_excellence',
                objective: 'Promote curriculum after frontier saturation: land real excellence work for next school level '.$next.' (Court+Floor+Repair, not proxy cleanup).',
                priority: self::PRIORITY_PROMOTE,
                excellenceTarget: 'reach_next_curriculum_level:'.$next,
                antiProxy: 'Must advance EXCELLENCE_PASS rate on the new frontier suite — forbid LOC/test-count/landing-rate-only tasks.',
                remeasure: true,
                extra: [
                    'curriculum_promotion' => $promotion,
                    'demote_prior_level_to' => RivalsCurriculumLadder::ROLE_SANITY,
                ],
            );
        } elseif ($m !== null && $m < (float) ($context['ambition_m_threshold'] ?? 50.0)) {
            $disposition = self::DISPOSITION_ORIGINATE_QUALITY;
            $priority = self::PRIORITY_QUALITY;
            $intents[] = self::intent(
                kind: 'raise_m_excellence',
                objective: 'Originate real quality path work to raise dual-arm M_excellence on non-saturated frontier (same μ).',
                priority: self::PRIORITY_QUALITY,
                excellenceTarget: 'raise_M_excellence',
                antiProxy: 'Must move N_atlas/N_raw on frontier measure; proxy cleanup is refused.',
                remeasure: true,
            );
        }

        // Filter any accidental proxy-shaped intents (fail closed).
        $admitted = [];
        $refusedProxy = [];
        foreach ($intents as $intent) {
            if (self::isProxyIntent($intent)) {
                $refusedProxy[] = $intent;
                $blockers[] = 'proxy_only_evolution_refused';

                continue;
            }
            $admitted[] = $intent;
        }

        // Fail-closed: any plan blocker clears enqueue intents (e.g. operator causal).
        if ($blockers !== []) {
            $admitted = [];
        }

        if ($disposition !== self::DISPOSITION_HOLD && $admitted === [] && $blockers === []) {
            $blockers[] = 'no_real_work_enqueued';
        }

        $enqueueAllowed = $blockers === [] && $admitted !== [];

        return [
            'schema' => self::SCHEMA,
            'disposition' => $disposition,
            'priority' => $priority,
            'enqueue_allowed' => $enqueueAllowed,
            'enqueue_intents' => $admitted,
            'refused_proxy_intents' => $refusedProxy,
            'blockers' => array_values(array_unique($blockers)),
            'curriculum_promotion' => $promotion,
            'measure_snapshot' => [
                'M_excellence' => $m,
                'N_raw' => $measure['N_raw'] ?? null,
                'N_atlas' => $measure['N_atlas'] ?? null,
                'multiplier_negative' => $negative,
                'frontier_saturated' => $saturated,
                'curriculum_role' => $role,
                'curriculum_level_id' => $levelId,
            ],
            'operator_task_causal_count' => 0,
            'operator_eng_required' => false,
            'remeasure' => [
                'required' => $enqueueAllowed,
                'command' => 'php artisan atlas:rivals --json',
                'after' => 'land_under_qos',
            ],
            'uses_existing_qos_path' => true,
            'acde_forbidden' => true,
            'brain_surface' => 'atlas:brain:next|atlas:brain:seed',
            'muscle_surface' => 'atlas:task next',
        ];
    }

    /**
     * @param  array<string,mixed>  $intent
     */
    public static function isProxyIntent(array $intent): bool
    {
        $blob = strtolower(trim(
            (string) ($intent['kind'] ?? '').' '.
            (string) ($intent['objective'] ?? '').' '.
            (string) ($intent['excellence_target'] ?? '').' '.
            (string) ($intent['anti_proxy'] ?? '')
        ));
        if ($blob === '') {
            return true;
        }
        foreach (self::PROXY_MARKERS as $marker) {
            if (str_contains($blob, $marker)) {
                return true;
            }
        }
        if ((bool) ($intent['proxy_only'] ?? false)) {
            return true;
        }
        // Explicit Goodhart metrics as sole objective.
        if ((bool) ($intent['sole_metric_loc'] ?? false)
            || (bool) ($intent['sole_metric_test_count'] ?? false)
            || (bool) ($intent['sole_metric_landing_rate'] ?? false)) {
            return true;
        }

        return false;
    }

    /**
     * Admit a caller-supplied intent (e.g. brain draft) under the same anti-proxy law.
     *
     * @param  array<string,mixed>  $intent
     * @return array{admit:bool, blockers:list<string>}
     */
    public static function admitIntent(array $intent): array
    {
        $blockers = [];
        if (self::isProxyIntent($intent)) {
            $blockers[] = 'proxy_only_evolution_refused';
        }
        if (trim((string) ($intent['objective'] ?? '')) === '') {
            $blockers[] = 'objective_required';
        }
        if (trim((string) ($intent['anti_proxy'] ?? '')) === '') {
            $blockers[] = 'anti_proxy_contract_required';
        }
        if ((int) ($intent['operator_task_causal_count'] ?? 0) > 0) {
            $blockers[] = 'operator_task_causal_forbidden';
        }

        return [
            'admit' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * Convenience: measure → plan using excellence report helpers.
     *
     * @param  array<string,mixed>  $rawMeasureContext
     * @param  array<string,mixed>  $loopContext
     * @return array<string,mixed>
     */
    public static function planFromRawRates(array $rawMeasureContext, array $loopContext = []): array
    {
        return self::planFromMeasure(RivalsExcellenceMeasure::report($rawMeasureContext), $loopContext);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private static function intent(
        string $kind,
        string $objective,
        int $priority,
        string $excellenceTarget,
        string $antiProxy,
        bool $remeasure,
        array $extra = [],
    ): array {
        return array_merge([
            'schema' => 'atlas.aaeos.quality_evolution_intent.v1',
            'kind' => $kind,
            'objective' => $objective,
            'priority' => $priority,
            'excellence_target' => $excellenceTarget,
            'anti_proxy' => $antiProxy,
            'proxy_only' => false,
            'operator_task_causal_count' => 0,
            'remeasure_after_land' => $remeasure,
            'executor_mode' => AaeosExecutorMode::AUTONOMOS,
            'qos_path_required' => true,
        ], $extra);
    }
}
