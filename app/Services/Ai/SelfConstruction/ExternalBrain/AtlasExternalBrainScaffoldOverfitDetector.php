<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Detects scaffold variants over-optimised for passing gates/benchmarks while
 * real Atlas value falls (Goodhart's Law guard).
 *
 * SUSPECT criteria (AC2) — any of:
 *   A. gate_pass_rate ≥ HIGH_GATE_THRESHOLD AND at least one quality signal degraded:
 *      - commit_success_rate < REAL_QUALITY_THRESHOLD
 *      - value_proof_rate    < REAL_QUALITY_THRESHOLD
 *      - diversity_score     < DIVERSITY_THRESHOLD
 *      - compounding_impact  < COMPOUNDING_THRESHOLD
 *      - give_back_rate      > GIVE_BACK_THRESHOLD   (new)
 *      - poison_rate         > POISON_THRESHOLD       (new)
 *   B. benchmark_pass_rate − heldout_pass_rate > HELDOUT_GAP_THRESHOLD
 *   C. template_repetition_score > TEMPLATE_REPETITION_THRESHOLD           (new)
 *
 * SEPARATE SIGNALS (AC3):
 *   template_shape_repetition — any variant exceeded template repetition threshold
 *   benchmark_to_heldout_gap  — per-variant float gap values
 *
 * RECOMMENDED ACTION (first match, AC4):
 *   retire_template      — template farm detected
 *   reset_scaffold       — ≥2 severe non-template suspects
 *   expand_heldout_set   — heldout-gap only failure
 *   monitor              — 1 borderline suspect
 *   none                 — clean
 */
final class AtlasExternalBrainScaffoldOverfitDetector
{
    public const SCHEMA = 'atlas.external_brain.scaffold_overfit_detector.v1';

    public const HIGH_GATE_THRESHOLD              = 0.80;
    public const REAL_QUALITY_THRESHOLD           = 0.70;
    public const DIVERSITY_THRESHOLD              = 0.50;
    public const COMPOUNDING_THRESHOLD            = 0.50;
    public const STRUCTURAL_LEVERAGE_THRESHOLD    = 0.50;
    public const HELDOUT_GAP_THRESHOLD            = 0.25;
    public const GIVE_BACK_THRESHOLD              = 0.25;
    public const POISON_THRESHOLD                 = 0.10;
    public const TEMPLATE_REPETITION_THRESHOLD    = 0.70;
    public const GATE_KEYWORD_DENSITY_THRESHOLD   = 0.60;
    public const NARROW_FIXTURE_THRESHOLD         = 0.60;

    // AC2/AC3: named overfit category per suspect — separate from the free-form `reasons` list,
    // so a caller can route on a single canonical category instead of parsing reason strings.
    public const CATEGORY_TEMPLATE_FARM        = 'template_farm_overfit';
    public const CATEGORY_NARROW_FIXTURE       = 'narrow_fixture_overfit';
    public const CATEGORY_GATE_OVERFIT         = 'gate_overfit';
    public const CATEGORY_HELDOUT_OVERFIT      = 'heldout_overfit';
    public const CATEGORY_SCHEMA_ONLY_OVERFIT  = 'schema_only_overfit';
    public const CATEGORY_QUALITY_DECLINE      = 'quality_decline_overfit';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function detect(array $input): array
    {
        $metrics = is_array($input['scaffold_metrics'] ?? null) ? $input['scaffold_metrics'] : [];

        $suspectScaffolds          = [];
        $evidenceTrend             = [];
        $benchmarkToHeldoutGap     = [];
        $anyHeldoutOverfit         = false;
        $anyTemplateFarm           = false;
        $anyGateStuffing           = false;
        $anySchemaOnly             = false;
        $anyHiddenProxyRegression  = false;

        foreach ($metrics as $m) {
            $id              = (string)  ($m['variant_id']              ?? 'unknown');
            $gateRate        = (float)   ($m['gate_pass_rate']          ?? 0.0);
            $commitRate      = (float)   ($m['commit_success_rate']     ?? 0.0);
            $valueRate       = (float)   ($m['value_proof_rate']        ?? 0.0);
            $diversity       = (float)   ($m['diversity_score']         ?? 0.0);
            $compounding     = (float)   ($m['compounding_impact']      ?? 0.0);
            $structuralLeverage = (float) ($m['structural_leverage_score'] ?? 0.0);
            $giveBackRate    = (float)   ($m['give_back_rate']          ?? 0.0);
            $poisonRate      = (float)   ($m['poison_rate']             ?? 0.0);
            $benchmarkRate   = (float)   ($m['benchmark_pass_rate']     ?? 0.0);
            $heldoutRate     = (float)   ($m['heldout_pass_rate']       ?? 0.0);
            $templateRepetition        = (float) ($m['template_repetition_score']  ?? 0.0);
            $gateKeywordDensity        = (float) ($m['gate_keyword_density']        ?? 0.0);
            $narrowFixtureScore        = (float) ($m['narrow_fixture_score']        ?? 0.0);
            $schemaChangeOnly          = (bool)  ($m['schema_change_only']          ?? false);
            $evidenceQualityDelta      = (float) ($m['evidence_quality_delta']      ?? 0.0);
            $replayAccuracyDelta       = (float) ($m['replay_accuracy_delta']       ?? 0.0);
            $escalationQualityDelta    = (float) ($m['escalation_quality_delta']    ?? 0.0);

            $heldoutGap = round($benchmarkRate - $heldoutRate, 4);
            $benchmarkToHeldoutGap[$id] = $heldoutGap;

            // Quality degradation signals
            $decliningMetrics = [];
            if ($commitRate   < self::REAL_QUALITY_THRESHOLD) {
                $decliningMetrics[] = 'commit_success_rate';
            }
            if ($valueRate    < self::REAL_QUALITY_THRESHOLD) {
                $decliningMetrics[] = 'value_proof_rate';
            }
            if ($diversity    < self::DIVERSITY_THRESHOLD) {
                $decliningMetrics[] = 'diversity_score';
            }
            if ($compounding  < self::COMPOUNDING_THRESHOLD) {
                $decliningMetrics[] = 'compounding_impact';
            }
            if (array_key_exists('structural_leverage_score', $m) && $structuralLeverage < self::STRUCTURAL_LEVERAGE_THRESHOLD) {
                $decliningMetrics[] = 'structural_leverage_score';
            }
            if ($giveBackRate > self::GIVE_BACK_THRESHOLD) {
                $decliningMetrics[] = 'give_back_rate';
            }
            if ($poisonRate   > self::POISON_THRESHOLD) {
                $decliningMetrics[] = 'poison_rate';
            }

            $decliningCount = count($decliningMetrics);
            $trend = $decliningCount >= 2 ? 'declining' : ($decliningCount === 1 ? 'borderline' : 'stable');
            $evidenceTrend[$id] = ['trend' => $trend, 'declining_metrics' => $decliningMetrics];

            // Suspect conditions
            $gateHighWithDecline = $gateRate >= self::HIGH_GATE_THRESHOLD && $decliningCount > 0;
            $heldoutOverfit      = $heldoutGap > self::HELDOUT_GAP_THRESHOLD;
            $templateFarm        = $templateRepetition > self::TEMPLATE_REPETITION_THRESHOLD;
            // D: gate-keyword stuffing — scaffold criteria saturated with gate keywords, no capability proof
            $gateStuffing        = $gateKeywordDensity > self::GATE_KEYWORD_DENSITY_THRESHOLD;
            // F: narrow-fixture hack — scaffold hard-codes to specific fixture shapes/values instead
            // of teaching general capability; a small model trained on it learns the fixture, not the skill.
            $narrowFixtureHack   = $narrowFixtureScore > self::NARROW_FIXTURE_THRESHOLD;
            // E: schema-only scaffold — only changes output format with no quality improvement in any dimension
            $schemaOnly          = $schemaChangeOnly
                && $evidenceQualityDelta   <= 0.0
                && $replayAccuracyDelta    <= 0.0
                && $escalationQualityDelta <= 0.0;
            // G: hidden proxy regression — high gate pass rate achieved through schema-only reformatting
            // or gate-keyword stuffing without actual quality improvement, creating a false sense of progress.
            $hiddenProxyRegression = $gateRate >= self::HIGH_GATE_THRESHOLD
                && ($schemaOnly || $gateKeywordDensity > self::GATE_KEYWORD_DENSITY_THRESHOLD)
                && $evidenceQualityDelta   <= 0.0
                && $replayAccuracyDelta    <= 0.0
                && $escalationQualityDelta <= 0.0;

            if ($gateHighWithDecline || $heldoutOverfit || $templateFarm || $gateStuffing || $schemaOnly || $narrowFixtureHack || $hiddenProxyRegression) {
                $reasons = [];
                if ($gateHighWithDecline) {
                    $reasons[] = 'high_gate_low_real_quality';
                }
                if ($heldoutOverfit) {
                    $reasons[] = 'heldout_gap_exceeds_threshold';
                }
                if ($templateFarm) {
                    $reasons[] = 'template_shape_repetition';
                }
                if ($gateStuffing) {
                    $reasons[] = 'gate_keyword_stuffing';
                }
                if ($schemaOnly) {
                    $reasons[] = 'schema_only_scaffold';
                }
                if ($narrowFixtureHack) {
                    $reasons[] = 'narrow_fixture_overfit';
                }
                if ($hiddenProxyRegression) {
                    $reasons[] = 'hidden_proxy_regression';
                }
                // AC2/AC3: a single canonical category, priority-ordered — template farming and
                // fixture hacking are the most dangerous (they actively teach the wrong behavior),
                // so they outrank a plain gate-wording match.
                $overfitCategory = match (true) {
                    $templateFarm      => self::CATEGORY_TEMPLATE_FARM,
                    $narrowFixtureHack => self::CATEGORY_NARROW_FIXTURE,
                    $gateStuffing      => self::CATEGORY_GATE_OVERFIT,
                    $heldoutOverfit    => self::CATEGORY_HELDOUT_OVERFIT,
                    $schemaOnly        => self::CATEGORY_SCHEMA_ONLY_OVERFIT,
                    default            => self::CATEGORY_QUALITY_DECLINE,
                };
                $suspectScaffolds[] = [
                    'variant_id'                => $id,
                    'gate_pass_rate'            => $gateRate,
                    'heldout_gap'               => $heldoutGap,
                    'template_repetition_score' => $templateRepetition,
                    'hidden_proxy_regression'   => $hiddenProxyRegression,
                    'declining_metrics'         => $decliningMetrics,
                    'reasons'                   => $reasons,
                    'overfit_category'          => $overfitCategory,
                ];
                if ($heldoutOverfit) {
                    $anyHeldoutOverfit = true;
                }
                if ($templateFarm) {
                    $anyTemplateFarm = true;
                }
                if ($gateStuffing) {
                    $anyGateStuffing = true;
                }
                if ($schemaOnly) {
                    $anySchemaOnly = true;
                }
                if ($hiddenProxyRegression) {
                    $anyHiddenProxyRegression = true;
                }
            }
        }

        $suspectCount      = count($suspectScaffolds);
        $recommendedAction = $this->recommendAction($anyTemplateFarm, $suspectCount, $anyHeldoutOverfit, $anyHiddenProxyRegression);

        return [
            'schema_version'            => self::SCHEMA,
            'overfit_detected'          => $suspectCount > 0,
            'hidden_proxy_regression'   => $anyHiddenProxyRegression,
            'suspect_scaffolds'         => $suspectScaffolds,
            'evidence_trend'            => $evidenceTrend,
            'heldout_gap'               => $anyHeldoutOverfit,
            'template_shape_repetition' => $anyTemplateFarm,
            'benchmark_to_heldout_gap'  => $benchmarkToHeldoutGap,
            'recommended_action'        => $recommendedAction,
            'risk_score'                => $this->computeRiskScore($suspectCount, $anyTemplateFarm, $anyGateStuffing, $anySchemaOnly, $anyHiddenProxyRegression),
            'blocking_reason'           => $this->blockingReason($recommendedAction),
            'repair_hints'              => $this->buildRepairHints($suspectScaffolds),
        ];
    }

    private function recommendAction(bool $templateFarm, int $suspectCount, bool $heldoutOnly, bool $hiddenProxyRegression): string
    {
        if ($templateFarm) {
            return 'retire_template';
        }
        if ($suspectCount >= 2 || $hiddenProxyRegression) {
            return 'reset_scaffold';
        }
        if ($heldoutOnly) {
            return 'expand_heldout_set';
        }
        if ($suspectCount === 1) {
            return 'monitor';
        }
        return 'none';
    }

    private function computeRiskScore(int $suspects, bool $template, bool $gateStuffing, bool $schemaOnly, bool $hiddenProxy): float
    {
        $score = min(1.0, $suspects * 0.25);
        if ($template)   { $score = min(1.0, $score + 0.25); }
        if ($gateStuffing) { $score = min(1.0, $score + 0.15); }
        if ($schemaOnly) { $score = min(1.0, $score + 0.10); }
        if ($hiddenProxy) { $score = min(1.0, $score + 0.20); }
        return round($score, 2);
    }

    private function blockingReason(string $action): string
    {
        return match ($action) {
            'retire_template'    => 'template_farm_detected:retire_and_diversify',
            'reset_scaffold'     => 'multiple_quality_degradations:reset_required',
            'expand_heldout_set' => 'benchmark_to_heldout_gap_too_large:expand_evaluation_set',
            'monitor'            => 'borderline_overfit:monitor_and_recheck',
            default              => '',
        };
    }

    /** @param list<array<string,mixed>> $suspects @return list<string> */
    private function buildRepairHints(array $suspects): array
    {
        $map = [
            'template_shape_repetition'    => 'retire_template_and_introduce_novel_signal_patterns',
            'gate_keyword_stuffing'         => 'remove_gate_keyword_saturation_and_add_concrete_capability_proof',
            'schema_only_scaffold'          => 'add_evidence_quality_or_replay_accuracy_improvement_not_schema_only',
            'high_gate_low_real_quality'    => 'raise_real_quality_signals_before_relying_on_gate_pass_rate',
            'heldout_gap_exceeds_threshold'  => 'expand_heldout_evaluation_set_to_close_benchmark_heldout_gap',
            'narrow_fixture_overfit'         => 'generalize_scaffold_beyond_specific_fixture_shapes_and_values',
            'hidden_proxy_regression'        => 'remove_proxy_optimizations_and_add_genuine_capability_proof',
        ];

        $hints = [];
        $seen  = [];
        foreach ($suspects as $suspect) {
            foreach ((array) ($suspect['reasons'] ?? []) as $reason) {
                if (! isset($seen[$reason]) && isset($map[$reason])) {
                    $hints[]      = $map[$reason];
                    $seen[$reason] = true;
                }
            }
        }

        return $hints !== [] ? $hints : ['no_overfit_detected_scaffold_is_healthy'];
    }
}
