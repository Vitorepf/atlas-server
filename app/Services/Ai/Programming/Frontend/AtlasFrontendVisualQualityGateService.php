<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class AtlasFrontendVisualQualityGateService
{
    public const SCHEMA_VERSION = 'atlas.frontend.visual_quality_gate.v1';

    public const REPORT_SCHEMA_VERSION = 'atlas.frontend.visual_quality_report.v1';

    public const TEMPLATE_SCHEMA_VERSION = 'atlas.frontend.visual_quality_report_template.v1';

    /**
     * @return array<string,mixed>
     */
    public function inspect(string $reportPath): array
    {
        $reportPath = trim($reportPath);
        $blockers = [];
        $warnings = [];
        $report = [];
        $raw = '';

        if ($reportPath === '' || ! File::isFile($reportPath)) {
            return $this->result('blocked', $reportPath, '', ['visual_quality_report_missing'], [], []);
        }

        $raw = File::get($reportPath);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->result('blocked', $reportPath, $raw, ['visual_quality_report_json_invalid'], [], []);
        }
        $report = $decoded;

        if (($report['schema_version'] ?? null) !== self::REPORT_SCHEMA_VERSION) {
            $blockers[] = 'schema_version_invalid';
        }

        foreach (['status', 'task_spec_hash', 'routes', 'viewports', 'checks', 'artifacts'] as $field) {
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

        $routes = is_array($report['routes'] ?? null) ? array_values($report['routes']) : [];
        if ($routes === []) {
            $blockers[] = 'routes_missing';
        }

        $viewports = is_array($report['viewports'] ?? null) ? array_values(array_map('strval', $report['viewports'])) : [];
        foreach ($this->requiredViewports() as $viewport) {
            if (! in_array($viewport, $viewports, true) && trim((string) ($report['viewport_exception_reason'] ?? '')) === '') {
                $blockers[] = 'missing_viewport_'.$viewport;
            }
        }

        $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
        foreach ($this->requiredChecks() as $check) {
            if (! array_key_exists($check, $checks)) {
                $blockers[] = 'missing_check_'.$check;

                continue;
            }
            $value = $checks[$check];
            $passed = $value === true || $value === 'passed' || (is_array($value) && in_array(($value['status'] ?? null), ['passed', 'warning'], true));
            $reasoned = is_array($value) && trim((string) ($value['reason'] ?? '')) !== '';
            if (! $passed && ! $reasoned) {
                $blockers[] = 'check_not_passed_or_reasoned_'.$check;
            }
        }

        $artifactResults = is_array($report['artifacts'] ?? null)
            ? $this->inspectArtifacts($report['artifacts'])
            : [];

        if (! is_array($report['artifacts'] ?? null)) {
            $blockers[] = 'artifacts_must_be_array';
        }

        $presentKinds = array_values(array_unique(array_map(
            fn (array $artifact): string => (string) ($artifact['kind'] ?? ''),
            array_filter($artifactResults, fn (array $artifact): bool => ($artifact['status'] ?? null) === 'present'),
        )));

        foreach ($this->requiredArtifactKinds() as $kind) {
            if (! in_array($kind, $presentKinds, true)) {
                $blockers[] = 'missing_artifact_kind_'.$kind;
            }
        }

        foreach ($artifactResults as $artifact) {
            foreach ((array) ($artifact['blockers'] ?? []) as $blocker) {
                $blockers[] = $blocker;
            }
            foreach ((array) ($artifact['warnings'] ?? []) as $warning) {
                $warnings[] = $warning;
            }
        }

        return $this->result(
            $blockers === [] ? ((string) ($report['status'] ?? '') === 'warning' ? 'warning' : 'passed') : 'blocked',
            $reportPath,
            $raw,
            array_values(array_unique($blockers)),
            array_values(array_unique($warnings)),
            $artifactResults,
            [
                'task_spec_hash' => preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($report['task_spec_hash'] ?? '')))
                    ? strtolower((string) $report['task_spec_hash'])
                    : null,
                'routes_count' => count($routes),
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

        $reportPath = $outputDirectory.'/visual-quality-report.json';
        if (! File::isFile($reportPath)) {
            File::put($reportPath, json_encode([
                'schema_version' => self::REPORT_SCHEMA_VERSION,
                'status' => 'passed',
                'task_spec_hash' => '<sha256-64-hex>',
                'routes' => ['/'],
                'viewports' => $this->requiredViewports(),
                'checks' => array_fill_keys($this->requiredChecks(), 'passed'),
                'artifacts' => array_map(fn (string $kind): array => [
                    'kind' => $kind,
                    'path' => 'artifacts/'.$kind.'.json',
                    'sha256' => '<sha256-64-hex>',
                ], $this->requiredArtifactKinds()),
                'notes' => 'Store artifact refs and hashes only. Do not store raw prompts, customer source, cookies, tokens or provider secrets.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        $payload = [
            'schema_version' => self::TEMPLATE_SCHEMA_VERSION,
            'status' => 'ready',
            'template_type' => 'frontend_visual_quality_report',
            'report_path_hash' => hash('sha256', $reportPath),
            'required_viewports' => $this->requiredViewports(),
            'required_checks' => $this->requiredChecks(),
            'required_artifact_kinds' => $this->requiredArtifactKinds(),
            'claim_policy' => [
                'template_is_not_evidence' => true,
                'visual_completion_requires_passed_inspection' => true,
                'screenshot_alone_is_insufficient' => true,
                'raw_prompt_or_customer_source_forbidden' => true,
            ],
        ];
        $payload['template_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    public function requiredViewports(): array
    {
        return ['desktop', 'tablet', 'mobile'];
    }

    /**
     * @return array<int,string>
     */
    public function requiredChecks(): array
    {
        return [
            'console',
            'a11y_or_reason',
            'performance_or_reason',
            'text_overlap',
            'responsive_fit',
            'state_transitions',
            'anti_slop',
        ];
    }

    /**
     * @return array<int,string>
     */
    public function requiredArtifactKinds(): array
    {
        return [
            'screenshot_set',
            'visual_smoke_manifest',
            'design_5d_review',
            'anti_slop_report',
            'console_report',
            'a11y_or_reason',
            'performance_or_reason',
            'state_transition_report',
            'receipt',
        ];
    }

    /**
     * @param  array<int,mixed>  $artifacts
     * @return array<int,array<string,mixed>>
     */
    private function inspectArtifacts(array $artifacts): array
    {
        return array_values(array_map(function (mixed $artifact): array {
            if (! is_array($artifact)) {
                return [
                    'status' => 'blocked',
                    'blockers' => ['artifact_entry_invalid'],
                    'warnings' => [],
                ];
            }

            $kind = (string) ($artifact['kind'] ?? '');
            $path = (string) ($artifact['path'] ?? '');
            $expectedHash = strtolower((string) ($artifact['sha256'] ?? ''));
            $blockers = [];

            if (! in_array($kind, $this->requiredArtifactKinds(), true)) {
                $blockers[] = 'artifact_kind_invalid';
            }
            if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
                $blockers[] = 'artifact_path_invalid';
            }
            if (! preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
                $blockers[] = 'artifact_hash_invalid';
            }

            return [
                'kind' => $kind,
                'path_hash' => $path !== '' ? hash('sha256', $path) : null,
                'status' => $blockers === [] ? 'present' : 'blocked',
                'sha256' => preg_match('/^[a-f0-9]{64}$/', $expectedHash) ? $expectedHash : null,
                'blockers' => array_values(array_unique($blockers)),
                'warnings' => [],
            ];
        }, $artifacts));
    }

    /**
     * @param  array<int,string>  $blockers
     * @param  array<int,string>  $warnings
     * @param  array<int,array<string,mixed>>  $artifacts
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function result(string $status, string $reportPath, string $raw, array $blockers, array $warnings, array $artifacts, array $extra = []): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'gate_type' => 'frontend_visual_quality',
            'source' => self::class,
            'report_schema_version' => self::REPORT_SCHEMA_VERSION,
            'report_path_hash' => $reportPath !== '' ? hash('sha256', $reportPath) : null,
            'report_hash' => $raw !== '' ? hash('sha256', $raw) : null,
            'required_viewports' => $this->requiredViewports(),
            'required_checks' => $this->requiredChecks(),
            'required_artifact_kinds' => $this->requiredArtifactKinds(),
            'artifact_results' => $artifacts,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'summary' => [
                'artifact_count' => count($artifacts),
                'blocker_count' => count($blockers),
                'warning_count' => count($warnings),
            ],
            'claim_policy' => [
                'visual_completion_claim_allowed' => in_array($status, ['passed', 'warning'], true) && $blockers === [],
                'screenshot_alone_is_insufficient' => true,
                'multi_viewport_state_and_quality_evidence_required' => true,
                'raw_customer_source_returned' => false,
            ],
            ...array_filter($extra, fn (mixed $value): bool => $value !== null),
        ];
        $payload['gate_hash'] = MissionCanonicalHash::sha256($payload);

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
}
