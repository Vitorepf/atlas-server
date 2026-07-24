<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class AtlasFrontendDesignReviewService
{
    public const SCHEMA_VERSION = 'atlas.frontend.design_review.v1';

    public const REPORT_SCHEMA_VERSION = 'atlas.frontend.design_review_report.v1';

    public const TEMPLATE_SCHEMA_VERSION = 'atlas.frontend.design_review_report_template.v1';

    /**
     * @return array<string,mixed>
     */
    public function inspect(string $reportPath): array
    {
        $reportPath = trim($reportPath);
        if ($reportPath === '' || ! File::isFile($reportPath)) {
            return $this->result('blocked', $reportPath, '', ['design_review_report_missing'], [], []);
        }

        $raw = File::get($reportPath);
        $report = json_decode($raw, true);
        if (! is_array($report)) {
            return $this->result('blocked', $reportPath, $raw, ['design_review_report_json_invalid'], [], []);
        }

        $blockers = [];
        $warnings = [];
        if (($report['schema_version'] ?? null) !== self::REPORT_SCHEMA_VERSION) {
            $blockers[] = 'schema_version_invalid';
        }
        foreach (['status', 'task_spec_hash', 'dimensions', 'evidence_refs'] as $field) {
            if (! array_key_exists($field, $report) || $report[$field] === null || $report[$field] === '') {
                $blockers[] = 'missing_'.$field;
            }
        }
        if (! preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($report['task_spec_hash'] ?? '')))) {
            $blockers[] = 'task_spec_hash_invalid';
        }
        if (! in_array((string) ($report['status'] ?? ''), ['passed', 'warning'], true)) {
            $blockers[] = 'review_status_not_passed_or_warning';
        }
        if ($this->hasForbiddenRawFields($report)) {
            $blockers[] = 'forbidden_raw_prompt_source_or_customer_field_present';
        }

        $dimensionResults = $this->inspectDimensions(is_array($report['dimensions'] ?? null) ? $report['dimensions'] : []);
        foreach ($dimensionResults as $dimension) {
            foreach ((array) ($dimension['blockers'] ?? []) as $blocker) {
                $blockers[] = $blocker;
            }
        }

        $evidenceRefs = array_values(array_filter((array) ($report['evidence_refs'] ?? []), 'is_string'));
        if (count($evidenceRefs) < 2) {
            $blockers[] = 'evidence_refs_insufficient';
        }
        foreach ($evidenceRefs as $ref) {
            if ($this->looksSensitive($ref)) {
                $blockers[] = 'evidence_ref_contains_sensitive_term';
            }
        }

        $overallScore = $this->overallScore($dimensionResults);
        if ($overallScore < 8.0) {
            $blockers[] = 'design_review_score_below_threshold';
        } elseif ($overallScore < 8.5) {
            $warnings[] = 'design_review_score_near_threshold';
        }

        return $this->result(
            $blockers === [] ? ((string) ($report['status'] ?? '') === 'warning' ? 'warning' : 'passed') : 'blocked',
            $reportPath,
            $raw,
            array_values(array_unique($blockers)),
            array_values(array_unique($warnings)),
            $dimensionResults,
            [
                'task_spec_hash' => preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($report['task_spec_hash'] ?? '')))
                    ? strtolower((string) $report['task_spec_hash'])
                    : null,
                'frontend_app_scope' => AtlasFrontendAppScope::fromPayload($report),
                'overall_score' => $overallScore,
                'evidence_ref_count' => count($evidenceRefs),
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function writeTemplate(string $outputDirectory): array
    {
        $outputDirectory = rtrim(trim($outputDirectory), DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($outputDirectory);

        $reportPath = $outputDirectory.'/design-review-report.json';
        if (! File::isFile($reportPath)) {
            File::put($reportPath, json_encode([
                'schema_version' => self::REPORT_SCHEMA_VERSION,
                'status' => 'passed',
                'task_spec_hash' => '<sha256-64-hex>',
                'dimensions' => collect($this->requiredDimensions())->mapWithKeys(fn (string $dimension): array => [
                    $dimension => [
                        'score' => 8,
                        'rationale' => 'Replace with concise evidence-backed rationale.',
                        'evidence_refs' => ['receipt://replace-me'],
                    ],
                ])->all(),
                'evidence_refs' => ['receipt://visual-quality', 'receipt://anti-slop'],
                'notes' => 'Store refs and concise rationale only. Do not store raw prompts, customer source, cookies, tokens or provider secrets.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        $payload = [
            'schema_version' => self::TEMPLATE_SCHEMA_VERSION,
            'status' => 'ready',
            'template_type' => 'frontend_design_5d_review_report',
            'report_path_hash' => hash('sha256', $reportPath),
            'required_dimensions' => $this->requiredDimensions(),
            'minimum_overall_score' => 8.0,
            'claim_policy' => [
                'template_is_not_evidence' => true,
                'visual_completion_requires_passed_5d_review' => true,
                'raw_prompt_or_customer_source_forbidden' => true,
            ],
        ];
        $payload['template_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    public function requiredDimensions(): array
    {
        return [
            'philosophy_consistency',
            'visual_hierarchy',
            'craft_execution',
            'functional_clarity',
            'originality',
        ];
    }

    /**
     * @param  array<string,mixed>  $dimensions
     * @return array<int,array<string,mixed>>
     */
    private function inspectDimensions(array $dimensions): array
    {
        return array_map(function (string $dimension) use ($dimensions): array {
            $entry = $dimensions[$dimension] ?? null;
            $blockers = [];
            if (! is_array($entry)) {
                return [
                    'dimension' => $dimension,
                    'score' => null,
                    'status' => 'blocked',
                    'blockers' => ['missing_dimension_'.$dimension],
                ];
            }

            $score = $entry['score'] ?? null;
            if (! is_numeric($score) || (float) $score < 1 || (float) $score > 10) {
                $blockers[] = 'invalid_score_'.$dimension;
            }
            if (trim((string) ($entry['rationale'] ?? '')) === '') {
                $blockers[] = 'missing_rationale_'.$dimension;
            }
            if (count(array_filter((array) ($entry['evidence_refs'] ?? []), 'is_string')) === 0) {
                $blockers[] = 'missing_evidence_ref_'.$dimension;
            }

            return [
                'dimension' => $dimension,
                'score' => is_numeric($score) ? round((float) $score, 2) : null,
                'status' => $blockers === [] ? 'present' : 'blocked',
                'blockers' => $blockers,
            ];
        }, $this->requiredDimensions());
    }

    /**
     * @param  array<int,array<string,mixed>>  $dimensionResults
     */
    private function overallScore(array $dimensionResults): float
    {
        $scores = array_values(array_filter(array_map(
            fn (array $dimension): mixed => $dimension['score'] ?? null,
            $dimensionResults,
        ), 'is_numeric'));

        return $scores === [] ? 0.0 : round(array_sum($scores) / count($this->requiredDimensions()), 2);
    }

    /**
     * @param  array<int,string>  $blockers
     * @param  array<int,string>  $warnings
     * @param  array<int,array<string,mixed>>  $dimensions
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function result(string $status, string $reportPath, string $raw, array $blockers, array $warnings, array $dimensions, array $extra = []): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'review_type' => 'frontend_design_5d_review',
            'source' => self::class,
            'report_schema_version' => self::REPORT_SCHEMA_VERSION,
            'report_path_hash' => $reportPath !== '' ? hash('sha256', $reportPath) : null,
            'report_hash' => $raw !== '' ? hash('sha256', $raw) : null,
            'required_dimensions' => $this->requiredDimensions(),
            'dimension_results' => $dimensions,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'claim_policy' => [
                'design_review_claim_allowed' => in_array($status, ['passed', 'warning'], true) && $blockers === [],
                'visual_completion_claim_requires_5d_review' => true,
                'raw_customer_source_returned' => false,
            ],
            ...array_filter($extra, fn (mixed $value): bool => $value !== null),
        ];
        $payload['review_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hasForbiddenRawFields(array $payload): bool
    {
        $forbidden = ['raw_prompt', 'prompt', 'source', 'raw_source', 'customer_source', 'customer_data'];

        foreach ($payload as $key => $value) {
            if (in_array(Str::snake((string) $key), $forbidden, true)) {
                return true;
            }
            if (is_array($value) && $this->hasForbiddenRawFields($value)) {
                return true;
            }
        }

        return false;
    }

    private function looksSensitive(string $value): bool
    {
        $normalized = Str::ascii(strtolower($value));

        return str_contains($normalized, 'raw_prompt')
            || str_contains($normalized, 'customer_source')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'token')
            || str_contains($normalized, 'cookie');
    }
}
