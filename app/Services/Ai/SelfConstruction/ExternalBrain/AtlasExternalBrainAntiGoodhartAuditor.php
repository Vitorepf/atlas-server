<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Audits a brain batch against Goodhart quota-gaming patterns at the batch level.
 *
 * Checks (each emits a finding with a concrete repair hint):
 *
 *  template_farm            — >50% of tasks share the same category AND the same
 *                             top-level file directory prefix.
 *  low_variety              — fewer than 2 distinct categories when batch ≥ 5 tasks.
 *  test_count_padding       — >40% of tasks are thin test-gate tasks (1 file, test_gate).
 *  unverifiable_value_claim — >30% of tasks carry an empty or generic value_mechanism.
 *  file_overconcentration   — >50% of tasks target the same top-level directory.
 *  already_satisfied_work   — any task has already_satisfied = true.
 *
 * Verdict:
 *   pass             — no findings
 *   repair_required  — 1–2 findings (batch is salvageable)
 *   reject           — 3+ findings, or any critical finding (template_farm / low_variety)
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainAntiGoodhartAuditor
{
    public const SCHEMA = 'atlas.external_brain.anti_goodhart_auditor.v1';

    public const VERDICT_PASS            = 'pass';
    public const VERDICT_REPAIR_REQUIRED = 'repair_required';
    public const VERDICT_REJECT          = 'reject';

    private const CRITICAL_FINDINGS = ['template_farm', 'template_similarity_farm', 'low_variety', 'value_mechanism_clone'];

    /** @param list<array<string,mixed>> $batch */
    public function audit(array $batch): array
    {
        $total    = count($batch);
        $findings = [];

        if ($total > 0) {
            $findings = array_filter([
                $this->checkTemplateFarm($batch, $total),
                $this->checkTemplateSimilarityFarm($batch, $total),
                $this->checkLowVariety($batch, $total),
                $this->checkTestPadding($batch, $total),
                $this->checkUnverifiableClaims($batch, $total),
                $this->checkFileConcentration($batch, $total),
                $this->checkAlreadySatisfied($batch, $total),
                $this->checkHighScoreMissingProof($batch, $total),
                $this->checkValueMechanismClone($batch, $total),
            ]);
        }

        $findings = array_values($findings);
        $findingNames = array_column($findings, 'finding');
        $hasCritical  = array_intersect($findingNames, self::CRITICAL_FINDINGS) !== [];
        $verdict = match (true) {
            $findings === []                                  => self::VERDICT_PASS,
            $hasCritical || count($findings) >= 3            => self::VERDICT_REJECT,
            default                                          => self::VERDICT_REPAIR_REQUIRED,
        };

        $checksRun = [
            'template_farm', 'template_similarity_farm', 'low_variety',
            'test_count_padding', 'unverifiable_value_claim', 'file_overconcentration',
            'already_satisfied_work', 'high_score_missing_proof', 'value_mechanism_clone',
        ];

        // countermetric_floor: fraction of checks the batch cleared cleanly — the
        // minimum proportion of Goodhart countermetrics a batch must satisfy to be
        // trustworthy. 1.0 = every check is clean; falls as findings accumulate.
        $countermetricFloor = round(1.0 - (count($findings) / count($checksRun)), 3);

        return [
            'schema'              => self::SCHEMA,
            'verdict'             => $verdict,
            'passed'              => $verdict === self::VERDICT_PASS,
            'total_audited'       => $total,
            'finding_count'       => count($findings),
            'findings'            => $findings,
            'checks_run'          => $checksRun,
            'countermetric_floor' => $countermetricFloor,
            'required_repairs'    => array_values(array_column($findings, 'repair_hint')),
        ];
    }

    /** @param list<array<string,mixed>> $batch */
    private function checkTemplateFarm(array $batch, int $total): ?array
    {
        // Count tasks by (category, top-level dir prefix).
        $buckets = [];
        foreach ($batch as $t) {
            $cat    = (string) ($t['category'] ?? '');
            $prefix = $this->topDirPrefix($t);
            $key    = $cat.'|'.$prefix;
            $buckets[$key] = ($buckets[$key] ?? 0) + 1;
        }

        arsort($buckets);
        $topKey   = array_key_first($buckets);
        $topCount = $buckets[$topKey];
        $fraction = $topCount / $total;

        if ($fraction <= 0.50) {
            return null;
        }

        [$cat, $prefix] = explode('|', $topKey, 2);

        return [
            'finding'      => 'template_farm',
            'severity'     => 'critical',
            'affected'     => $topCount,
            'fraction'     => round($fraction, 3),
            'repair_hint'  => "Remove or diversify {$topCount} tasks with category='{$cat}' targeting '{$prefix}'. Replace with tasks from other categories or distinct file families.",
        ];
    }

    /** @param list<array<string,mixed>> $batch */
    private function checkLowVariety(array $batch, int $total): ?array
    {
        if ($total < 5) {
            return null;
        }

        $categories = array_unique(array_map(
            static fn (array $t): string => (string) ($t['category'] ?? ''),
            $batch,
        ));
        $distinctCount = count(array_filter($categories, static fn (string $c): bool => $c !== ''));

        if ($distinctCount >= 2) {
            return null;
        }

        $present = implode(', ', array_filter($categories));

        return [
            'finding'     => 'low_variety',
            'severity'    => 'critical',
            'affected'    => $total,
            'fraction'    => 1.0,
            'repair_hint' => "Batch has only {$distinctCount} distinct category ({$present}). Add tasks from at least one other value category (bug_fix, architecture_unlock, test_gate, runtime_continuity, task_quality_repair, docs_sync, learning_loop).",
        ];
    }

    /** @param list<array<string,mixed>> $batch */
    private function checkTestPadding(array $batch, int $total): ?array
    {
        $thin = array_filter($batch, static function (array $t): bool {
            return (string) ($t['category'] ?? '') === 'test_gate'
                && count((array) ($t['allowed_files'] ?? [])) === 1;
        });
        $fraction = count($thin) / $total;

        if ($fraction <= 0.40) {
            return null;
        }

        $count = count($thin);

        return [
            'finding'     => 'test_count_padding',
            'severity'    => 'warning',
            'affected'    => $count,
            'fraction'    => round($fraction, 3),
            'repair_hint' => "Batch contains {$count} thin test-gate tasks ({$this->pct($fraction)}% of wave). Group pairs into multi-assertion tasks or replace with capability tasks that include their own tests.",
        ];
    }

    /** @param list<array<string,mixed>> $batch */
    private function checkUnverifiableClaims(array $batch, int $total): ?array
    {
        $generic  = ['general', 'misc', 'other', 'unknown', 'tbd', 'n/a'];
        $bad      = array_filter($batch, static function (array $t) use ($generic): bool {
            $vm = strtolower(trim((string) ($t['value_mechanism'] ?? '')));

            return $vm === '' || in_array($vm, $generic, true);
        });
        $fraction = count($bad) / $total;

        if ($fraction <= 0.30) {
            return null;
        }

        $count = count($bad);

        return [
            'finding'     => 'unverifiable_value_claim',
            'severity'    => 'warning',
            'affected'    => $count,
            'fraction'    => round($fraction, 3),
            'repair_hint' => "{$count} tasks have empty or generic value_mechanism ({$this->pct($fraction)}%). Replace with specific claims explaining the exact capability unlocked, e.g. 'enables_autonomous_lease_recovery' or 'closes_runtime_gap:acp_health'.",
        ];
    }

    /** @param list<array<string,mixed>> $batch */
    private function checkFileConcentration(array $batch, int $total): ?array
    {
        $prefixes = [];
        foreach ($batch as $t) {
            $p = $this->topDirPrefix($t);
            if ($p !== '') {
                $prefixes[$p] = ($prefixes[$p] ?? 0) + 1;
            }
        }

        if ($prefixes === []) {
            return null;
        }

        arsort($prefixes);
        $topPrefix = (string) array_key_first($prefixes);
        $topCount  = $prefixes[$topPrefix];
        $fraction  = $topCount / $total;

        if ($fraction <= 0.50) {
            return null;
        }

        return [
            'finding'     => 'file_overconcentration',
            'severity'    => 'warning',
            'affected'    => $topCount,
            'fraction'    => round($fraction, 3),
            'repair_hint' => "{$topCount} tasks ({$this->pct($fraction)}%) target '{$topPrefix}'. Diversify targets to other file families to reduce blast radius and avoid sequential lock contention.",
        ];
    }

    /** @param list<array<string,mixed>> $batch */
    private function checkAlreadySatisfied(array $batch, int $total): ?array
    {
        $satisfied = array_filter($batch, static fn (array $t): bool => (bool) ($t['already_satisfied'] ?? false));
        $count     = count($satisfied);

        if ($count === 0) {
            return null;
        }

        $labels = implode(', ', array_slice(array_column($satisfied, 'label'), 0, 5));

        return [
            'finding'     => 'already_satisfied_work',
            'severity'    => 'warning',
            'affected'    => $count,
            'fraction'    => round($count / $total, 3),
            'repair_hint' => "{$count} task(s) target already-satisfied capabilities ({$labels}). Remove and replace with unsatisfied gaps from the capability rubric.",
        ];
    }

    /**
     * Catches renamed-noun template farms: batches where tasks differ only in proper nouns
     * (e.g., AtlasFooService → AtlasBarService) but share identical structural patterns.
     * Fires when ≥3 tasks and >50% share the same similarity fingerprint.
     *
     * @param list<array<string,mixed>> $batch
     */
    private function checkTemplateSimilarityFarm(array $batch, int $total): ?array
    {
        if ($total < 3) {
            return null;
        }

        $buckets = [];
        foreach ($batch as $t) {
            $fp = $this->similarityFingerprint($t);
            $buckets[$fp] = ($buckets[$fp] ?? 0) + 1;
        }

        arsort($buckets);
        $topCount = $buckets[(string) array_key_first($buckets)];
        $fraction = $topCount / $total;

        if ($fraction <= 0.50 || $topCount < 3) {
            return null;
        }

        return [
            'finding'     => 'template_similarity_farm',
            'severity'    => 'critical',
            'affected'    => $topCount,
            'fraction'    => round($fraction, 3),
            'repair_hint' => "{$topCount} tasks share the same structural fingerprint (normalized objective, category, value_mechanism pattern, file suffix family) — they are renamed-noun clones. Replace with tasks that target genuinely distinct capabilities, categories, or file families.",
        ];
    }

    private function similarityFingerprint(array $task): string
    {
        $text = (string) ($task['objective'] ?? ($task['label'] ?? ''));
        // normalize BEFORE lowercasing so CamelCase identifiers are caught by the regex.
        $normText = strtolower($this->normalizeProperNouns($text));
        $category = (string) ($task['category'] ?? '');
        $vm       = strtolower($this->normalizeProperNouns((string) ($task['value_mechanism'] ?? '')));
        $suffix   = $this->fileSuffixPattern($task);

        return "{$normText}|{$category}|{$vm}|{$suffix}";
    }

    private function normalizeProperNouns(string $text): string
    {
        // Replace CamelCase / PascalCase identifiers with {N}.
        // Use (?<![a-z]) rather than \b because \b treats _ as a word char,
        // missing patterns like _FooServiceAdapter in snake_case value_mechanism strings.
        return preg_replace('/(?<![a-z])[A-Z][a-zA-Z0-9]{2,}/', '{N}', $text) ?? $text;
    }

    private function fileSuffixPattern(array $task): string
    {
        $files = (array) ($task['allowed_files'] ?? []);
        if ($files === []) {
            return '';
        }
        $suffixes = [];
        foreach ($files as $f) {
            $base = basename((string) $f);
            // Extract trailing CamelCase word before .php (Service.php, Test.php, Repository.php…).
            if (preg_match('/([A-Z][a-z]+\.php)$/', $base, $m)) {
                $suffixes[] = $m[1];
            } else {
                $suffixes[] = pathinfo((string) $f, PATHINFO_EXTENSION);
            }
        }
        sort($suffixes);

        return implode(',', array_unique($suffixes));
    }

    /**
     * AC1: high-score tasks (≥0.80) that lack runnable_acceptance or implementation_proof
     * are self-declared — no external audit trail proves the capability gain.
     *
     * @param list<array<string,mixed>> $batch
     */
    private function checkHighScoreMissingProof(array $batch, int $total): ?array
    {
        $bad = array_filter($batch, static function (array $t): bool {
            if ((float) ($t['final_score'] ?? 0.0) < 0.80) {
                return false;
            }
            $runnable = trim((string) ($t['runnable_acceptance'] ?? ''));
            $proof    = trim((string) ($t['implementation_proof'] ?? ''));

            return $runnable === '' || $proof === '';
        });

        $count = count($bad);
        if ($count === 0) {
            return null;
        }

        return [
            'finding'     => 'high_score_missing_proof',
            'severity'    => 'warning',
            'affected'    => $count,
            'fraction'    => round($count / $total, 3),
            'repair_hint' => "{$count} high-score task(s) (score≥0.80) lack runnable_acceptance or implementation_proof. Add a runnable command and concrete implementation evidence to distinguish real capability gain from self-declared progress.",
        ];
    }

    /**
     * AC2: batches where >50% of tasks share the same value_mechanism claim work is
     * a single mechanism renamed — genuine diversity requires distinct mechanisms.
     *
     * @param list<array<string,mixed>> $batch
     */
    private function checkValueMechanismClone(array $batch, int $total): ?array
    {
        $counts = [];
        foreach ($batch as $t) {
            $vm = trim((string) ($t['value_mechanism'] ?? ''));
            if ($vm !== '') {
                $counts[$vm] = ($counts[$vm] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);
        $topVm    = (string) array_key_first($counts);
        $topCount = $counts[$topVm];
        $fraction = $topCount / $total;

        if ($fraction <= 0.50) {
            return null;
        }

        return [
            'finding'     => 'value_mechanism_clone',
            'severity'    => 'critical',
            'affected'    => $topCount,
            'fraction'    => round($fraction, 3),
            'repair_hint' => "{$topCount} tasks ({$this->pct($fraction)}%) share identical value_mechanism '{$topVm}'. Each task must unlock a distinct capability — different mechanism, different gap closed, different system improved.",
        ];
    }

    private function topDirPrefix(array $task): string
    {
        $files = (array) ($task['allowed_files'] ?? []);
        if ($files === []) {
            return '';
        }
        $parts = explode('/', (string) $files[0], 4);

        return implode('/', array_slice($parts, 0, 3));
    }

    private function pct(float $fraction): string
    {
        return (string) round($fraction * 100);
    }
}
