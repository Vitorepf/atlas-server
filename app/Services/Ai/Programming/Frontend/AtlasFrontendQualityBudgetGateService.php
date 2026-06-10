<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class AtlasFrontendQualityBudgetGateService
{
    public const SCHEMA_VERSION = 'atlas.frontend.quality_budget_gate.v1';

    public const REPORT_SCHEMA_VERSION = 'atlas.frontend.quality_budget_report.v1';

    public const TEMPLATE_SCHEMA_VERSION = 'atlas.frontend.quality_budget_report_template.v1';

    /**
     * @return array<string,mixed>
     */
    public function inspect(string $reportPath): array
    {
        $reportPath = trim($reportPath);
        if ($reportPath === '' || ! File::isFile($reportPath)) {
            return $this->result('blocked', $reportPath, '', ['quality_budget_report_missing'], [], []);
        }

        $raw = File::get($reportPath);
        $report = json_decode($raw, true);
        if (! is_array($report)) {
            return $this->result('blocked', $reportPath, $raw, ['quality_budget_report_json_invalid'], [], []);
        }

        $blockers = [];
        $warnings = [];
        if (($report['schema_version'] ?? null) !== self::REPORT_SCHEMA_VERSION) {
            $blockers[] = 'schema_version_invalid';
        }
        foreach (['status', 'task_spec_hash', 'viewports', 'metrics'] as $field) {
            if (! array_key_exists($field, $report) || $report[$field] === null || $report[$field] === '') {
                $blockers[] = 'missing_'.$field;
            }
        }
        if (! preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($report['task_spec_hash'] ?? '')))) {
            $blockers[] = 'task_spec_hash_invalid';
        }
        if (! in_array((string) ($report['status'] ?? ''), ['passed', 'warning'], true)) {
            $blockers[] = 'report_status_not_passed_or_warning';
        }
        if ($this->hasForbiddenRawFields($report)) {
            $blockers[] = 'forbidden_raw_prompt_source_or_customer_field_present';
        }

        $viewports = is_array($report['viewports'] ?? null) ? array_values(array_map('strval', $report['viewports'])) : [];
        foreach ($this->requiredViewports() as $viewport) {
            if (! in_array($viewport, $viewports, true)) {
                $blockers[] = 'missing_viewport_'.$viewport;
            }
        }

        $metrics = is_array($report['metrics'] ?? null) ? $report['metrics'] : [];
        $metricResults = $this->metricResults($metrics);
        foreach ($metricResults as $metric) {
            if (($metric['status'] ?? null) === 'blocked') {
                $blockers[] = 'budget_failed_'.$metric['id'];
            }
            if (($metric['status'] ?? null) === 'warning') {
                $warnings[] = 'budget_near_limit_'.$metric['id'];
            }
        }

        $approvedException = (bool) ($report['operator_approved_exception'] ?? false);
        if ($blockers !== [] && $approvedException) {
            $warnings[] = 'operator_approved_budget_exception';
            $blockers = array_values(array_filter(
                $blockers,
                fn (string $blocker): bool => ! str_starts_with($blocker, 'budget_failed_'),
            ));
        }

        return $this->result(
            $blockers === [] ? ($warnings === [] ? 'passed' : 'warning') : 'blocked',
            $reportPath,
            $raw,
            array_values(array_unique($blockers)),
            array_values(array_unique($warnings)),
            $metricResults,
            [
                'task_spec_hash' => preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($report['task_spec_hash'] ?? '')))
                    ? strtolower((string) $report['task_spec_hash'])
                    : null,
                'frontend_app_scope' => AtlasFrontendAppScope::fromPayload($report),
                'verified_viewports' => $viewports,
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
        $reportPath = $outputDirectory.'/quality-budget-report.json';

        if (! File::isFile($reportPath)) {
            File::put($reportPath, json_encode([
                'schema_version' => self::REPORT_SCHEMA_VERSION,
                'status' => 'passed',
                'task_spec_hash' => '<sha256-64-hex>',
                'viewports' => $this->requiredViewports(),
                'metrics' => collect($this->budgets())->mapWithKeys(fn (array $budget, string $id): array => [
                    $id => $budget['max'],
                ])->all(),
                'operator_approved_exception' => false,
                'notes' => 'Use measured values only. Do not include raw prompts, customer source, cookies, tokens or provider secrets.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        $payload = [
            'schema_version' => self::TEMPLATE_SCHEMA_VERSION,
            'status' => 'ready',
            'template_type' => 'frontend_quality_budget_report',
            'report_path_hash' => hash('sha256', $reportPath),
            'required_viewports' => $this->requiredViewports(),
            'budgets' => $this->budgets(),
            'claim_policy' => [
                'template_is_not_budget_evidence' => true,
                'frontend_completion_requires_passed_budget_gate' => true,
                'raw_customer_source_returned' => false,
            ],
        ];
        $payload['template_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,array{max:float|int, warning:float|int, unit:string}>
     */
    public function budgets(): array
    {
        return [
            'critical_a11y_violations' => ['max' => 0, 'warning' => 0, 'unit' => 'count'],
            'serious_a11y_violations' => ['max' => 0, 'warning' => 0, 'unit' => 'count'],
            'contrast_failures' => ['max' => 0, 'warning' => 0, 'unit' => 'count'],
            'lcp_ms' => ['max' => 2500, 'warning' => 2200, 'unit' => 'ms'],
            'inp_ms' => ['max' => 200, 'warning' => 160, 'unit' => 'ms'],
            'cls' => ['max' => 0.1, 'warning' => 0.08, 'unit' => 'score'],
            'js_transfer_kb' => ['max' => 250, 'warning' => 220, 'unit' => 'kb'],
            'text_overlap_count' => ['max' => 0, 'warning' => 0, 'unit' => 'count'],
            'horizontal_overflow_count' => ['max' => 0, 'warning' => 0, 'unit' => 'count'],
            'console_error_count' => ['max' => 0, 'warning' => 0, 'unit' => 'count'],
        ];
    }

    /**
     * @return array<int,string>
     */
    public function requiredViewports(): array
    {
        return ['desktop', 'tablet', 'mobile'];
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @return array<int,array<string,mixed>>
     */
    private function metricResults(array $metrics): array
    {
        $results = [];
        foreach ($this->budgets() as $id => $budget) {
            $value = $metrics[$id] ?? null;
            $numeric = is_numeric($value);
            $status = 'passed';
            if (! $numeric || (float) $value > (float) $budget['max']) {
                $status = 'blocked';
            } elseif ((float) $value > (float) $budget['warning']) {
                $status = 'warning';
            }

            $results[] = [
                'id' => $id,
                'status' => $status,
                'value' => $numeric ? (float) $value : null,
                'max' => $budget['max'],
                'warning' => $budget['warning'],
                'unit' => $budget['unit'],
            ];
        }

        return $results;
    }

    /**
     * @param  array<int,string>  $blockers
     * @param  array<int,string>  $warnings
     * @param  array<int,array<string,mixed>>  $metrics
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function result(string $status, string $reportPath, string $raw, array $blockers, array $warnings, array $metrics, array $extra = []): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'gate_type' => 'frontend_quality_budget',
            'source' => self::class,
            'report_schema_version' => self::REPORT_SCHEMA_VERSION,
            'report_path_hash' => $reportPath !== '' ? hash('sha256', $reportPath) : null,
            'report_hash' => $raw !== '' ? hash('sha256', $raw) : null,
            'required_viewports' => $this->requiredViewports(),
            'budgets' => $this->budgets(),
            'metric_results' => $metrics,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'claim_policy' => [
                'frontend_quality_budget_claim_allowed' => in_array($status, ['passed', 'warning'], true) && $blockers === [],
                'objective_measured_values_required' => true,
                'operator_exception_does_not_authorize_world_best_claim' => true,
                'raw_customer_source_returned' => false,
            ],
            ...array_filter($extra, fn (mixed $value): bool => $value !== null),
        ];
        $payload['quality_budget_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hasForbiddenRawFields(array $payload): bool
    {
        $forbidden = ['raw_prompt', 'prompt', 'raw_source', 'customer_source', 'customer_data', 'cookie', 'token', 'secret'];

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

}
