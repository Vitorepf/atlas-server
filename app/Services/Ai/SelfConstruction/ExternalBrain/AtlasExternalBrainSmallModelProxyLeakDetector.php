<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure, deterministic detector: catches small-model origination that looks
 * structurally valid but is actually proxy work, template repetition, missing
 * evidence, or benchmark-overfit output.
 *
 * Leak signals:
 *   template_repetition  — template_signature appears in prior_signatures
 *   missing_evidence     — no runnable gate in acceptance_criteria OR evidence_fields empty
 *   cosmetic_wrapper     — objective < MIN_OBJECTIVE_WORDS AND contains cosmetic keyword
 *   benchmark_overfit    — acceptance_criteria exist but all are score/metric-only (no runnable gate)
 *
 * rejected=true when any leak_reason fires.
 * quality_signal always present: runnable flag, word count, evidence count.
 */
final class AtlasExternalBrainSmallModelProxyLeakDetector
{
    public const SCHEMA = 'atlas.external_brain.small_model_proxy_leak_detector.v1';

    public const MIN_OBJECTIVE_WORDS = 20;

    private const COSMETIC_KEYWORDS = ['wrap', 'wrapper', 'delegate', 'pass-through', 'passthrough', 'proxy', 'facade'];

    private const RUNNABLE_MARKERS = ['test', 'exits 0', 'runnable', 'assert', 'verify', 'gate'];

    private const SCORE_KEYWORDS = ['score', 'metric', 'percentage', '%', 'rate', 'benchmark', 'threshold'];

    /**
     * @param  array<string,mixed>  $input  candidate_spec
     * @return array<string,mixed>
     */
    public function detect(array $input): array
    {
        $spec = is_array($input['candidate_spec'] ?? null) ? $input['candidate_spec'] : [];
        $objective = strtolower((string) ($spec['objective'] ?? ''));
        $acceptance = is_array($spec['acceptance_criteria'] ?? null) ? $spec['acceptance_criteria'] : [];
        $evidence = is_array($spec['evidence_fields'] ?? null) ? $spec['evidence_fields'] : [];
        $signature = (string) ($spec['template_signature'] ?? '');
        $priorSigs = is_array($spec['prior_signatures'] ?? null) ? $spec['prior_signatures'] : [];

        $leakReasons = [];

        // 1. Template repetition
        if ($signature !== '' && in_array($signature, $priorSigs, true)) {
            $leakReasons[] = 'template_repetition';
        }

        // 2. Missing runnable evidence
        $hasRunnable = false;
        foreach ($acceptance as $criterion) {
            $lower = strtolower((string) $criterion);
            foreach (self::RUNNABLE_MARKERS as $marker) {
                if (str_contains($lower, $marker)) {
                    $hasRunnable = true;
                    break 2;
                }
            }
        }
        if (! $hasRunnable || empty($evidence)) {
            $leakReasons[] = 'missing_evidence';
        }

        // 3. Cosmetic wrapper — short objective that only describes thin delegation
        $wordCount = str_word_count($objective);
        $hasCosmeticKeyword = false;
        foreach (self::COSMETIC_KEYWORDS as $kw) {
            if (str_contains($objective, $kw)) {
                $hasCosmeticKeyword = true;
                break;
            }
        }
        if ($wordCount < self::MIN_OBJECTIVE_WORDS && $hasCosmeticKeyword) {
            $leakReasons[] = 'cosmetic_wrapper';
        }

        // 4. Benchmark overfit — criteria exist but all are score/metric-only, no runnable gate
        if (count($acceptance) > 0 && ! $hasRunnable) {
            $allMetricOnly = true;
            foreach ($acceptance as $criterion) {
                $lower = strtolower((string) $criterion);
                $hasScore = false;
                foreach (self::SCORE_KEYWORDS as $kw) {
                    if (str_contains($lower, $kw)) {
                        $hasScore = true;
                        break;
                    }
                }
                if (! $hasScore) {
                    $allMetricOnly = false;
                    break;
                }
            }
            if ($allMetricOnly) {
                $leakReasons[] = 'benchmark_overfit';
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'rejected' => count($leakReasons) > 0,
            'leak_reasons' => $leakReasons,
            'quality_signal' => [
                'has_runnable_acceptance' => $hasRunnable,
                'objective_word_count' => $wordCount,
                'evidence_field_count' => count($evidence),
                'acceptance_criteria_count' => count($acceptance),
                'template_collision' => in_array('template_repetition', $leakReasons, true),
            ],
        ];
    }
}
