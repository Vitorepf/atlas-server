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

    public const HIGH_GATE_THRESHOLD          = 0.80;
    public const REAL_QUALITY_THRESHOLD       = 0.70;
    public const DIVERSITY_THRESHOLD          = 0.50;
    public const COMPOUNDING_THRESHOLD        = 0.50;
    public const HELDOUT_GAP_THRESHOLD        = 0.25;
    public const GIVE_BACK_THRESHOLD          = 0.25;
    public const POISON_THRESHOLD             = 0.10;
    public const TEMPLATE_REPETITION_THRESHOLD = 0.70;

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

        foreach ($metrics as $m) {
            $id              = (string)  ($m['variant_id']              ?? 'unknown');
            $gateRate        = (float)   ($m['gate_pass_rate']          ?? 0.0);
            $commitRate      = (float)   ($m['commit_success_rate']     ?? 0.0);
            $valueRate       = (float)   ($m['value_proof_rate']        ?? 0.0);
            $diversity       = (float)   ($m['diversity_score']         ?? 0.0);
            $compounding     = (float)   ($m['compounding_impact']      ?? 0.0);
            $giveBackRate    = (float)   ($m['give_back_rate']          ?? 0.0);
            $poisonRate      = (float)   ($m['poison_rate']             ?? 0.0);
            $benchmarkRate   = (float)   ($m['benchmark_pass_rate']     ?? 0.0);
            $heldoutRate     = (float)   ($m['heldout_pass_rate']       ?? 0.0);
            $templateRepetition = (float) ($m['template_repetition_score'] ?? 0.0);

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

            if ($gateHighWithDecline || $heldoutOverfit || $templateFarm) {
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
                $suspectScaffolds[] = [
                    'variant_id'               => $id,
                    'gate_pass_rate'           => $gateRate,
                    'heldout_gap'              => $heldoutGap,
                    'template_repetition_score' => $templateRepetition,
                    'declining_metrics'        => $decliningMetrics,
                    'reasons'                  => $reasons,
                ];
                if ($heldoutOverfit) {
                    $anyHeldoutOverfit = true;
                }
                if ($templateFarm) {
                    $anyTemplateFarm = true;
                }
            }
        }

        $suspectCount = count($suspectScaffolds);
        $recommendedAction = $this->recommendAction($anyTemplateFarm, $suspectCount, $anyHeldoutOverfit);

        return [
            'schema_version'            => self::SCHEMA,
            'overfit_detected'          => $suspectCount > 0,
            'suspect_scaffolds'         => $suspectScaffolds,
            'evidence_trend'            => $evidenceTrend,
            'heldout_gap'               => $anyHeldoutOverfit,
            'template_shape_repetition' => $anyTemplateFarm,
            'benchmark_to_heldout_gap'  => $benchmarkToHeldoutGap,
            'recommended_action'        => $recommendedAction,
        ];
    }

    private function recommendAction(bool $templateFarm, int $suspectCount, bool $heldoutOnly): string
    {
        if ($templateFarm) {
            return 'retire_template';
        }
        if ($suspectCount >= 2) {
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
}
