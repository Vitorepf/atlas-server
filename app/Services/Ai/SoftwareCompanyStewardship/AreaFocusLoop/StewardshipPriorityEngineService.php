<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-771 · Stewardship Priority Engine.
 *
 * Orders 24/7 stewardship work by maximum product/engineering advance under
 * robustness constraints. It is intentionally deterministic and read-only:
 * it ranks candidates; AP-756/AP-769 still own branch and merge actions.
 */
final class StewardshipPriorityEngineService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.priority_engine.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    /** @var array<string,int> */
    private const SEVERITY_WEIGHT = [
        'critical' => 100,
        'high' => 78,
        'medium' => 48,
        'low' => 20,
        'info' => 8,
    ];

    /** @var array<string,int> */
    private const KIND_ADVANCEMENT = [
        'bug' => 95,
        'risk' => 88,
        'test' => 76,
        'gap' => 72,
        'doc' => 48,
        'cleanup' => 42,
        'insight' => 25,
    ];

    /** @var array<string,int> */
    private const OWNER_MULTIPLIER = [
        'atlas_dev' => 12,
        'forge' => 12,
        'dev_forge' => 14,
        'agentic_engineering_os' => 14,
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function rank(array $input): array
    {
        $areaId = (string) ($input['area_id'] ?? self::DEFAULT_AREA_ID);
        $candidates = $this->candidates($input);
        if ($candidates === []) {
            return $this->blocked($areaId, 'priority_candidates_required', 'AP-771 requires findings, branches or candidate work items to rank.');
        }

        $ranked = [];
        foreach ($candidates as $index => $candidate) {
            $ranked[] = $this->scoreCandidate($candidate, $index);
        }

        usort($ranked, static function (array $a, array $b): int {
            return ((float) ($b['priority_score'] ?? 0.0) <=> (float) ($a['priority_score'] ?? 0.0))
                ?: ((int) ($a['original_index'] ?? 0) <=> (int) ($b['original_index'] ?? 0));
        });

        foreach ($ranked as $i => &$item) {
            $item['rank'] = $i + 1;
        }
        unset($item);

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-771',
            'status' => self::STATUS_READY,
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-748', 'AP-756', 'AP-769', 'AP-770', 'AP-771'],
            'ranking_policy' => [
                'objective' => 'maximize_advancement_and_robustness_without_increasing_branch_conflict_risk',
                'score_components' => [
                    'advancement',
                    'robustness',
                    'risk_reduction',
                    'mergeability',
                    'confidence',
                    'conflict_penalty',
                    'blast_radius_penalty',
                ],
                'deterministic' => true,
                'mutates_repo' => false,
            ],
            'candidate_count' => count($ranked),
            'top_candidate' => $ranked[0] ?? null,
            'ranked_candidates' => $ranked,
            'claim_policy' => [
                'provider_invoked' => false,
                'branch_created' => false,
                'merge_performed' => false,
                'deploys' => false,
                'touches_secrets' => false,
                'autonomous_selection_possible' => true,
                'operator_review_still_required_for_irreversible_actions' => true,
            ],
            'generated_at' => $this->now(),
        ];
        $payload['priority_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function candidates(array $input): array
    {
        if (is_array($input['candidates'] ?? null)) {
            return array_values(array_filter($input['candidates'], 'is_array'));
        }

        if (is_array($input['findings'] ?? null)) {
            return array_values(array_filter($input['findings'], 'is_array'));
        }

        if (is_array($input['deep_scan_report'] ?? null)) {
            return array_values(array_filter((array) ($input['deep_scan_report']['findings'] ?? []), 'is_array'));
        }

        if (is_array($input['branches'] ?? null)) {
            return array_values(array_filter($input['branches'], 'is_array'));
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function scoreCandidate(array $candidate, int $index): array
    {
        $kind = strtolower((string) ($candidate['kind'] ?? $candidate['type'] ?? $candidate['classification'] ?? 'gap'));
        $severity = strtolower((string) ($candidate['severity'] ?? 'medium'));
        $owner = strtolower((string) ($candidate['owner_candidate'] ?? $candidate['recommended_owner'] ?? $candidate['owner'] ?? ''));
        $confidence = $this->clamp01((float) ($candidate['confidence'] ?? 0.74));
        $files = $this->files($candidate);
        $fileCount = count($files);

        $advancement = (self::KIND_ADVANCEMENT[$kind] ?? 55) + (self::OWNER_MULTIPLIER[$owner] ?? 0);
        $riskReduction = self::SEVERITY_WEIGHT[$severity] ?? 42;
        $robustness = $this->robustnessScore($kind, $files, $candidate);
        $mergeability = $this->mergeabilityScore($files, $candidate);
        $conflictPenalty = $this->conflictPenalty($files, $candidate);
        $blastRadiusPenalty = min(35, max(0, $fileCount - 3) * 5);

        $score = ($advancement * 0.30)
            + ($robustness * 0.24)
            + ($riskReduction * 0.20)
            + ($mergeability * 0.16)
            + ($confidence * 100 * 0.10)
            - $conflictPenalty
            - $blastRadiusPenalty;

        $score = round(max(0, min(100, $score)), 2);

        return [
            'rank' => null,
            'original_index' => $index,
            'priority_score' => $score,
            'priority_band' => $this->band($score),
            'candidate_id' => (string) ($candidate['id'] ?? $candidate['finding_id'] ?? $candidate['branch_ref'] ?? 'candidate_'.$index),
            'title' => (string) ($candidate['title'] ?? $candidate['summary'] ?? $candidate['branch_ref'] ?? 'Untitled stewardship candidate'),
            'kind' => $kind,
            'severity' => $severity,
            'owner' => $owner,
            'affected_files' => $files,
            'score_breakdown' => [
                'advancement' => $advancement,
                'robustness' => $robustness,
                'risk_reduction' => $riskReduction,
                'mergeability' => $mergeability,
                'confidence' => round($confidence * 100, 2),
                'conflict_penalty' => $conflictPenalty,
                'blast_radius_penalty' => $blastRadiusPenalty,
            ],
            'recommended_execution_order' => $this->recommendedOrder($score, $kind, $files),
            'autonomy_hint' => $this->autonomyHint($score, $files, $kind),
            'source' => $candidate,
        ];
    }

    /**
     * @param  list<string>  $files
     */
    private function robustnessScore(string $kind, array $files, array $candidate): int
    {
        $score = in_array($kind, ['test', 'bug', 'risk'], true) ? 84 : 62;
        if ($files !== [] && $this->allDocsOrTests($files)) {
            $score += 10;
        }
        if ((bool) ($candidate['has_tests'] ?? $candidate['test_coverage_available'] ?? false)) {
            $score += 8;
        }

        return max(0, min(100, $score));
    }

    /**
     * @param  list<string>  $files
     */
    private function mergeabilityScore(array $files, array $candidate): int
    {
        if ((bool) ($candidate['merge_conflict_detected'] ?? false)) {
            return 0;
        }
        if ($files === []) {
            return 55;
        }
        if ($this->allDocsOrTests($files)) {
            return 95;
        }

        return count($files) <= 3 ? 70 : 45;
    }

    /**
     * @param  list<string>  $files
     */
    private function conflictPenalty(array $files, array $candidate): int
    {
        $penalty = 0;
        if ((bool) ($candidate['merge_conflict_detected'] ?? false)) {
            $penalty += 55;
        }
        foreach ($files as $file) {
            if (str_starts_with($file, 'config/') || str_starts_with($file, 'routes/') || str_contains($file, 'Command.php')) {
                $penalty += 6;
            }
        }

        return min(60, $penalty);
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return list<string>
     */
    private function files(array $candidate): array
    {
        $files = $candidate['affected_files'] ?? $candidate['changed_files'] ?? data_get($candidate, 'gitkraken_review_surface.changed_files', []);
        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $files), static fn (string $f): bool => $f !== ''));
    }

    /**
     * @param  list<string>  $files
     */
    private function allDocsOrTests(array $files): bool
    {
        foreach ($files as $file) {
            if (! str_starts_with($file, 'docs/') && ! str_starts_with($file, 'tests/') && ! str_ends_with($file, 'Test.php')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function recommendedOrder(float $score, string $kind, array $files): array
    {
        $steps = ['rank_first_when_budget_available'];
        if ($score >= 75 && $this->allDocsOrTests($files)) {
            $steps[] = 'eligible_for_ap769_safe_auto_merge_after_validation';
        } elseif (in_array($kind, ['bug', 'test', 'risk'], true)) {
            $steps[] = 'route_to_atlas_dev_or_forge_before_lower_advancement_cleanup';
        } else {
            $steps[] = 'keep_reviewable_in_product_mode';
        }

        return $steps;
    }

    /**
     * @param  list<string>  $files
     */
    private function autonomyHint(float $score, array $files, string $kind): string
    {
        if ($score >= 80 && $this->allDocsOrTests($files)) {
            return 'auto_merge_candidate_after_ap769_validation';
        }
        if ($score >= 70 && in_array($kind, ['bug', 'test', 'risk'], true)) {
            return 'high_priority_operator_review';
        }

        return 'normal_review_queue';
    }

    private function band(float $score): string
    {
        return match (true) {
            $score >= 85 => 'P0_maximum_advancement',
            $score >= 70 => 'P1_high_advancement',
            $score >= 50 => 'P2_standard',
            default => 'P3_defer',
        };
    }

    private function clamp01(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-771',
            'status' => self::STATUS_BLOCKED,
            'area_id' => $areaId,
            'reason' => $reason,
            'detail' => $detail,
            'blockers' => [$reason],
            'claim_policy' => [
                'provider_invoked' => false,
                'branch_created' => false,
                'merge_performed' => false,
            ],
            'generated_at' => $this->now(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['priority_hash'], $copy['generated_at']);

        return $copy;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
