<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure, deterministic detector: catches small-model origination that looks
 * structurally valid but is actually proxy work, template repetition, missing
 * evidence, or benchmark-overfit output.
 *
 * Leak signals (7 total):
 *   template_repetition  — template_signature appears in prior_signatures
 *   missing_evidence     — no runnable gate in acceptance_criteria OR evidence_fields empty
 *   cosmetic_wrapper     — objective < MIN_OBJECTIVE_WORDS AND contains cosmetic keyword
 *   benchmark_overfit    — acceptance_criteria exist but all are score/metric-only (no runnable gate)
 *   generic_objective    — combined objective+acceptance has no concrete FQCN or path reference
 *   scaffold_repetition  — generic filler phrases found in objective or acceptance criteria
 *   excessive_similarity — objective Jaccard similarity >= SIMILARITY_THRESHOLD vs any recent_accepted_spec
 *
 * rejected=true when any leak_reason fires.
 *
 * NEW OUTPUT FIELDS:
 *   severity           — 'clean' | 'low' | 'medium' | 'high' (based on proxy_leak_score)
 *   proxy_leak_score   — [0.0, 1.0], weighted sum of active signal weights
 *   killed_reason      — primary (first) leak signal, or null if clean
 *   repair_prompt_hint — actionable hint for repairing the primary leak, or null if clean
 *   quality_signal     — runnable flag, word count, evidence count, template collision
 *
 * INPUT: { candidate_spec: { objective, acceptance_criteria, evidence_fields,
 *          template_signature, prior_signatures, recent_accepted_specs? } }
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainSmallModelProxyLeakDetector
{
    public const SCHEMA = 'atlas.external_brain.small_model_proxy_leak_detector.v1';

    public const MIN_OBJECTIVE_WORDS  = 20;
    public const SIMILARITY_THRESHOLD = 0.65;

    private const COSMETIC_KEYWORDS = ['wrap', 'wrapper', 'delegate', 'pass-through', 'passthrough', 'proxy', 'facade'];

    private const RUNNABLE_MARKERS = ['test', 'exits 0', 'runnable', 'assert', 'verify', 'gate'];

    private const SCORE_KEYWORDS = ['score', 'metric', 'percentage', '%', 'rate', 'benchmark', 'threshold'];

    private const SCAFFOLD_PHRASES = [
        'implement the following',
        'as specified above',
        'as described above',
        'according to the requirements',
        'the following requirements',
        'following the existing pattern',
        'based on the above',
        'todo: implement',
        'your implementation here',
    ];

    /** Per-signal contribution to proxy_leak_score. */
    private const SIGNAL_WEIGHTS = [
        'template_repetition'  => 0.30,
        'missing_evidence'     => 0.25,
        'cosmetic_wrapper'     => 0.20,
        'benchmark_overfit'    => 0.20,
        'generic_objective'    => 0.20,
        'scaffold_repetition'  => 0.20,
        'excessive_similarity' => 0.35,
    ];

    private const REPAIR_HINTS = [
        'template_repetition'  => 'Generate a fresh spec with a distinct template_signature; avoid copy-paste from prior runs.',
        'missing_evidence'     => 'Add a concrete runnable gate to acceptance_criteria (e.g., php artisan test --filter=XxxTest) and populate evidence_fields.',
        'cosmetic_wrapper'     => 'Expand objective to describe real logic delivered, not just delegation. Remove cosmetic wrapper framing.',
        'benchmark_overfit'    => 'Replace metric-only criteria with a runnable gate that proves behavior, not just a score.',
        'generic_objective'    => 'Name the concrete class (PascalCase FQCN) or file path in the objective or acceptance criteria.',
        'scaffold_repetition'  => 'Remove generic scaffold phrases; describe the specific implementation decision and logic being added.',
        'excessive_similarity' => 'This spec is too similar to a recently accepted spec. Originate a genuinely distinct objective targeting a different problem.',
    ];

    /**
     * @param  array<string,mixed>  $input  candidate_spec container
     * @return array<string,mixed>
     */
    public function detect(array $input): array
    {
        $spec          = is_array($input['candidate_spec'] ?? null) ? $input['candidate_spec'] : [];
        $objectiveRaw  = (string) ($spec['objective'] ?? '');
        $objective     = strtolower($objectiveRaw);
        $acceptance    = is_array($spec['acceptance_criteria'] ?? null) ? $spec['acceptance_criteria'] : [];
        $evidence      = is_array($spec['evidence_fields'] ?? null) ? $spec['evidence_fields'] : [];
        $signature     = (string) ($spec['template_signature'] ?? '');
        $priorSigs     = is_array($spec['prior_signatures'] ?? null) ? $spec['prior_signatures'] : [];
        $recentSpecs   = is_array($spec['recent_accepted_specs'] ?? null) ? $spec['recent_accepted_specs'] : [];

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
        $wordCount          = str_word_count($objective);
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
                $lower    = strtolower((string) $criterion);
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

        // 5. Generic objective — no concrete FQCN or path reference in objective + acceptance
        //    Use original-cased combined text so PascalCase class names are detectable.
        $combinedRaw = $objectiveRaw . ' ' . implode(' ', $acceptance);
        if ($objectiveRaw !== '' && ! $this->hasConcreteTarget($combinedRaw)) {
            $leakReasons[] = 'generic_objective';
        }

        // 6. Scaffold repetition — generic filler phrases that indicate template copy-paste
        $combinedLower = strtolower($combinedRaw);
        foreach (self::SCAFFOLD_PHRASES as $phrase) {
            if (str_contains($combinedLower, $phrase)) {
                $leakReasons[] = 'scaffold_repetition';
                break;
            }
        }

        // 7. Excessive similarity — objective too similar to a recently accepted spec
        foreach ($recentSpecs as $priorText) {
            if ($this->jaccardSimilarity($objectiveRaw, (string) $priorText) >= self::SIMILARITY_THRESHOLD) {
                $leakReasons[] = 'excessive_similarity';
                break;
            }
        }

        $proxyLeakScore   = $this->computeScore($leakReasons);
        $severity         = $this->computeSeverity($proxyLeakScore);
        $killedReason     = $leakReasons[0] ?? null;
        $repairPromptHint = $killedReason !== null
            ? (self::REPAIR_HINTS[$killedReason] ?? 'Review and revise the spec before escalating to frontier.')
            : null;

        return [
            'schema_version'     => self::SCHEMA,
            'rejected'           => count($leakReasons) > 0,
            'leak_reasons'       => $leakReasons,
            'severity'           => $severity,
            'proxy_leak_score'   => $proxyLeakScore,
            'killed_reason'      => $killedReason,
            'repair_prompt_hint' => $repairPromptHint,
            'quality_signal'     => [
                'has_runnable_acceptance'    => $hasRunnable,
                'objective_word_count'       => $wordCount,
                'evidence_field_count'       => count($evidence),
                'acceptance_criteria_count'  => count($acceptance),
                'template_collision'         => in_array('template_repetition', $leakReasons, true),
            ],
        ];
    }

    /** True when the text contains a PascalCase multi-segment class name or a file path prefix. */
    private function hasConcreteTarget(string $text): bool
    {
        // PascalCase with 2+ segments: FooBar, AtlasScorerTest, SomeHandler
        if (preg_match('/\b[A-Z][a-z]+(?:[A-Z][a-z]+)+\b/', $text)) {
            return true;
        }
        // Forward-slash file path prefix: app/, tests/, src/, lib/
        if (preg_match('/\b(?:app|tests|src|lib)\//', $text)) {
            return true;
        }
        return false;
    }

    /** Jaccard similarity on lower-cased word sets. Returns [0.0, 1.0]. */
    private function jaccardSimilarity(string $a, string $b): float
    {
        $aWords = array_unique(str_word_count(strtolower($a), 1) ?: []);
        $bWords = array_unique(str_word_count(strtolower($b), 1) ?: []);
        if ($aWords === [] && $bWords === []) {
            return 0.0;
        }
        $intersection = count(array_intersect($aWords, $bWords));
        $union        = count(array_unique(array_merge($aWords, $bWords)));
        return $union > 0 ? $intersection / $union : 0.0;
    }

    /** @param list<string> $leakReasons */
    private function computeScore(array $leakReasons): float
    {
        $score = 0.0;
        foreach ($leakReasons as $reason) {
            $score += self::SIGNAL_WEIGHTS[$reason] ?? 0.15;
        }
        return min(1.0, round($score, 6));
    }

    private function computeSeverity(float $score): string
    {
        if ($score <= 0.0) {
            return 'clean';
        }
        if ($score <= 0.25) {
            return 'low';
        }
        if ($score <= 0.50) {
            return 'medium';
        }
        return 'high';
    }
}
