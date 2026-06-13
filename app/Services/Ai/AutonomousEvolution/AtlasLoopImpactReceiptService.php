<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;

final class AtlasLoopImpactReceiptService
{
    public const SCHEMA_VERSION = 'atlas.loop.impact_receipt.v1';

    /**
     * @param  list<string>  $changedFiles
     * @param  array<string,mixed>  $canary
     * @return array<string,mixed>
     */
    public function build(AtlasLoopProposal $proposal, array $changedFiles, ?string $commit, array $canary, ?int $callers = null): array
    {
        $files = $this->changedFiles($proposal, $changedFiles);
        $size = $this->size((string) $proposal->diff_text, $files);
        [$category, $categoryReason] = $this->category($proposal, $files);
        $targetKind = $this->targetKind($files);
        $score = $this->score($category, $targetKind, $size, $canary, $callers);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'category' => $category,
            'category_reason' => $categoryReason,
            'target_kind' => $targetKind,
            'real_vs_generated' => $targetKind === 'generated' ? 'generated' : 'real',
            'target_path' => (string) $proposal->target_path,
            'changed_files' => array_slice($files, 0, 20),
            'size' => $size,
            // Real production caller count of the target (null = not measured this run;
            // 0 = orphan; >0 = wired). The honest WIRED signal the grade re-resolves.
            'real_callers' => $callers,
            'impact_score' => $score,
            'canary' => [
                'ran' => (bool) ($canary['ran'] ?? false),
                'passed' => $canary['passed'] ?? null,
                'target' => $canary['target'] ?? null,
            ],
            'proposal_hash' => (string) ($proposal->proposal_hash ?? ''),
            'commit' => $commit,
            'recorded_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function aggregate(?int $limit = null): array
    {
        $limit ??= (int) config('atlas.ai.loop.impact_receipts_report_limit', 500);
        $limit = max(1, min(2000, $limit));

        $base = [
            'schema_version' => 'atlas.loop.impact_receipt.aggregate.v1',
            'merged_to_main' => 0,
            'receipted_merges' => 0,
            'coverage_pct' => 0.0,
            'by_category' => [],
            'by_target_kind' => [],
            'real_targets' => 0,
            'generated_targets' => 0,
            'generated_target_pct' => 0.0,
            // WIRED metrics (the compounding axis): share of merges that landed on
            // code with >=1 real production caller, and the mean caller fan-in.
            'wired_merges' => 0,
            'caller_measured_merges' => 0,
            'wired_target_pct' => 0.0,
            'mean_real_callers' => 0.0,
            'avg_impact_score' => 0.0,
            'total_added_lines' => 0,
            'total_deleted_lines' => 0,
            'total_files_changed' => 0,
            'latest' => [],
        ];

        if (
            ! DatabaseTableAvailability::has('atlas_loop_proposals')
            || ! DatabaseTableAvailability::hasColumn('atlas_loop_proposals', 'quality')
        ) {
            return $base;
        }

        try {
            $base['merged_to_main'] = (int) AtlasLoopProposal::query()
                ->where('merged_to_main', true)
                ->count();

            $rows = AtlasLoopProposal::query()
                ->where('merged_to_main', true)
                ->whereNotNull('quality')
                ->orderByDesc('reviewed_at')
                ->limit($limit)
                ->get();
        } catch (Throwable) {
            return $base;
        }

        $scoreSum = 0.0;
        $callerSum = 0;
        $latest = [];
        foreach ($rows as $proposal) {
            $receipt = is_array($proposal->quality) ? ($proposal->quality['_impact_receipt'] ?? null) : null;
            if (! is_array($receipt) || ($receipt['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
                continue;
            }

            $base['receipted_merges']++;
            $category = (string) ($receipt['category'] ?? 'unknown');
            $targetKind = (string) ($receipt['target_kind'] ?? 'unknown');
            $base['by_category'][$category] = ($base['by_category'][$category] ?? 0) + 1;
            $base['by_target_kind'][$targetKind] = ($base['by_target_kind'][$targetKind] ?? 0) + 1;
            if (($receipt['real_vs_generated'] ?? null) === 'generated' || $targetKind === 'generated') {
                $base['generated_targets']++;
            } else {
                $base['real_targets']++;
            }

            $size = is_array($receipt['size'] ?? null) ? $receipt['size'] : [];
            $base['total_added_lines'] += (int) ($size['added_lines'] ?? 0);
            $base['total_deleted_lines'] += (int) ($size['deleted_lines'] ?? 0);
            $base['total_files_changed'] += (int) ($size['files_changed'] ?? 0);
            $scoreSum += (float) ($receipt['impact_score'] ?? 0.0);

            // WIRED accounting: only merges where callers were actually measured
            // count toward the ratio (null = not measured => excluded, not penalised).
            $callers = $receipt['real_callers'] ?? null;
            if (is_int($callers)) {
                $base['caller_measured_merges']++;
                $callerSum += $callers;
                if ($callers > 0) {
                    $base['wired_merges']++;
                }
            }

            if (count($latest) < 5) {
                $latest[] = [
                    'target_path' => (string) ($receipt['target_path'] ?? $proposal->target_path),
                    'category' => $category,
                    'target_kind' => $targetKind,
                    'impact_score' => (float) ($receipt['impact_score'] ?? 0.0),
                    'size' => $size,
                    'commit' => isset($receipt['commit']) ? substr((string) $receipt['commit'], 0, 12) : null,
                ];
            }
        }

        if ($base['merged_to_main'] > 0) {
            $base['coverage_pct'] = round(($base['receipted_merges'] / $base['merged_to_main']) * 100, 1);
        }
        if ($base['receipted_merges'] > 0) {
            $base['avg_impact_score'] = round($scoreSum / $base['receipted_merges'], 3);
            $base['generated_target_pct'] = round(($base['generated_targets'] / $base['receipted_merges']) * 100, 1);
        }
        if ($base['caller_measured_merges'] > 0) {
            $base['wired_target_pct'] = round(($base['wired_merges'] / $base['caller_measured_merges']) * 100, 1);
            $base['mean_real_callers'] = round($callerSum / $base['caller_measured_merges'], 2);
        }

        ksort($base['by_category']);
        ksort($base['by_target_kind']);
        $base['latest'] = $latest;

        return $base;
    }

    /**
     * @param  list<string>  $changedFiles
     * @return list<string>
     */
    private function changedFiles(AtlasLoopProposal $proposal, array $changedFiles): array
    {
        $files = [];
        foreach ($changedFiles as $file) {
            $file = trim(str_replace('\\', '/', $file));
            if ($file !== '') {
                $files[] = $file;
            }
        }

        $diff = (string) $proposal->diff_text;
        if (preg_match_all('/^diff --git a\/(.+?) b\/(.+)$/m', $diff, $matches) > 0) {
            foreach ($matches[2] as $file) {
                $files[] = trim(str_replace('\\', '/', (string) $file));
            }
        }

        $target = trim(str_replace('\\', '/', (string) $proposal->target_path));
        if ($target !== '') {
            $files[] = $target;
        }

        $files = array_values(array_unique(array_filter($files, static fn (string $file): bool => $file !== '')));

        return $files === [] ? ['unknown'] : $files;
    }

    /**
     * @param  list<string>  $files
     * @return array{files_changed:int, php_files_changed:int, added_lines:int, deleted_lines:int, touched_lines:int, bucket:string}
     */
    private function size(string $diff, array $files): array
    {
        $added = 0;
        $deleted = 0;
        foreach (preg_split('/\R/', $diff) ?: [] as $line) {
            if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                continue;
            }
            if (str_starts_with($line, '+')) {
                $added++;
            } elseif (str_starts_with($line, '-')) {
                $deleted++;
            }
        }

        $touched = $added + $deleted;

        return [
            'files_changed' => count($files),
            'php_files_changed' => count(array_filter($files, static fn (string $file): bool => str_ends_with($file, '.php'))),
            'added_lines' => $added,
            'deleted_lines' => $deleted,
            'touched_lines' => $touched,
            'bucket' => $touched <= 8 ? 'small' : ($touched <= 60 ? 'medium' : 'large'),
        ];
    }

    /**
     * @param  list<string>  $files
     * @return array{0:string,1:string}
     */
    private function category(AtlasLoopProposal $proposal, array $files): array
    {
        $text = mb_strtolower((string) $proposal->objective.' '.(string) $proposal->target_path.' '.(string) $proposal->diff_text);
        $paths = mb_strtolower(implode(' ', $files));

        if (str_contains($paths, 'tests/') || preg_match('/\b(test|assert|phpunit|pest|spec)\b/', $text) === 1) {
            return ['test', 'test_path_or_assertion_signal'];
        }
        if (preg_match('/\b(perf|performance|latency|throughput|cache|memory|slow|timeout|n\+1)\b/', $text) === 1) {
            return ['perf', 'performance_signal'];
        }
        if (preg_match('/\b(edge|corner|null|empty|bounds?|boundary|array-form|array form|missing|fallback)\b/', $text) === 1) {
            return ['edge_case', 'edge_case_signal'];
        }
        if (preg_match('/\b(bug|fix|fail|failure|error|exception|broken|regression|correct|repair)\b/', $text) === 1) {
            return ['bug', 'correctness_signal'];
        }

        return ['bug', 'default_correctness_change'];
    }

    /**
     * @param  list<string>  $files
     */
    private function targetKind(array $files): string
    {
        foreach ($files as $file) {
            if (preg_match('#(^|/)Generated(/|$)#', $file) === 1) {
                return 'generated';
            }
        }
        foreach ($files as $file) {
            if (str_starts_with($file, 'tests/')) {
                return 'test';
            }
        }
        foreach ($files as $file) {
            if (str_starts_with($file, 'docs/')) {
                return 'docs';
            }
        }

        return 'real';
    }

    /**
     * @param  array<string,mixed>  $size
     * @param  array<string,mixed>  $canary
     */
    private function score(string $category, string $targetKind, array $size, array $canary, ?int $callers = null): float
    {
        $score = 0.35;
        $score += match ($targetKind) {
            'real' => 0.25,
            'test' => 0.16,
            'docs' => 0.08,
            'generated' => -0.1,
            default => 0.0,
        };
        $score += match ($category) {
            'edge_case', 'perf' => 0.18,
            'bug' => 0.14,
            'test' => 0.1,
            default => 0.0,
        };

        $touched = (int) ($size['touched_lines'] ?? 0);
        $files = (int) ($size['files_changed'] ?? 0);
        $score += min(0.12, $touched / 500);
        $score += min(0.08, $files * 0.02);

        if (($canary['ran'] ?? false) === true) {
            $score += (($canary['passed'] ?? null) === false) ? -0.2 : 0.1;
        } else {
            $score += 0.02;
        }

        // WIRED term: real production callers. null = not measured this run => NO term
        // (byte-identical to the pre-upgrade score, the regression guard). Measured:
        // a wired hub is boosted (up to +0.20), a confirmed orphan (0 callers) is pulled
        // DOWN by 0.20 so it falls below the merge value-gate floor — this is what makes
        // impact_score actually separate "hardened code that runs" from "polished dead
        // scaffolding", instead of every real file floating at ~0.6.
        if ($callers !== null) {
            $score += $callers > 0
                ? min(0.20, log(1 + $callers) / log(1 + 25) * 0.20)
                : -0.20;
        }

        return round(max(0.05, min(1.0, $score)), 3);
    }
}
