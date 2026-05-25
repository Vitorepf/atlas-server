<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class AtlasFrontendDesignSystemDriftGateService
{
    public const SCHEMA_VERSION = 'atlas.frontend.design_system_drift_gate.v1';

    public const REPORT_SCHEMA_VERSION = 'atlas.frontend.design_system_drift_report.v1';

    public const TEMPLATE_SCHEMA_VERSION = 'atlas.frontend.design_system_drift_report_template.v1';

    /**
     * @return array<string,mixed>
     */
    public function inspect(string $reportPath): array
    {
        $reportPath = trim($reportPath);
        $blockers = [];
        $warnings = [];
        $raw = '';

        if ($reportPath === '' || ! File::isFile($reportPath)) {
            return $this->result('blocked', $reportPath, '', ['design_system_drift_report_missing'], [], []);
        }

        $raw = File::get($reportPath);
        $report = json_decode($raw, true);
        if (! is_array($report)) {
            return $this->result('blocked', $reportPath, $raw, ['design_system_drift_report_json_invalid'], [], []);
        }

        if (($report['schema_version'] ?? null) !== self::REPORT_SCHEMA_VERSION) {
            $blockers[] = 'schema_version_invalid';
        }
        foreach (['status', 'profile_hash', 'task_spec_hash', 'changed_files', 'expected_tokens', 'used_tokens', 'component_usage', 'artifacts'] as $field) {
            if (! array_key_exists($field, $report) || $report[$field] === null || $report[$field] === '') {
                $blockers[] = 'missing_'.$field;
            }
        }
        foreach (['profile_hash', 'task_spec_hash'] as $field) {
            if (! preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($report[$field] ?? '')))) {
                $blockers[] = $field.'_invalid';
            }
        }
        if (! in_array((string) ($report['status'] ?? ''), ['passed', 'warning'], true)) {
            $blockers[] = 'report_status_not_passed_or_warning';
        }
        if ($this->hasForbiddenRawFields($report)) {
            $blockers[] = 'forbidden_raw_prompt_source_customer_or_token_field_present';
        }

        $expectedTokens = is_array($report['expected_tokens'] ?? null) ? $report['expected_tokens'] : [];
        $usedTokens = is_array($report['used_tokens'] ?? null) ? $report['used_tokens'] : [];
        foreach ($this->requiredTokenCategories() as $category) {
            if (! is_array($expectedTokens[$category] ?? null) || $expectedTokens[$category] === []) {
                $blockers[] = 'missing_expected_'.$category;
            }
            if (! is_array($usedTokens[$category] ?? null)) {
                $blockers[] = 'missing_used_'.$category;
            }
        }

        $approvedExceptions = $this->approvedExceptions($report);
        $tokenDrift = $this->tokenDrift($expectedTokens, $usedTokens, $approvedExceptions);
        foreach ($tokenDrift['blockers'] as $blocker) {
            $blockers[] = $blocker;
        }
        foreach ($tokenDrift['warnings'] as $warning) {
            $warnings[] = $warning;
        }

        $componentUsage = is_array($report['component_usage'] ?? null) ? $report['component_usage'] : [];
        $newComponents = array_values(array_filter((array) ($componentUsage['new_components'] ?? []), 'is_string'));
        $reusedComponents = array_values(array_filter((array) ($componentUsage['reused_components'] ?? []), 'is_string'));
        if ($newComponents !== [] && ! in_array('new_components_approved', $approvedExceptions, true)) {
            $blockers[] = 'new_components_without_approval';
        }
        if ($reusedComponents === [] && $newComponents === []) {
            $blockers[] = 'component_usage_missing';
        }

        $artifactResults = is_array($report['artifacts'] ?? null) ? $this->inspectArtifacts($report['artifacts']) : [];
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
        }

        return $this->result($blockers === [] ? ((string) ($report['status'] ?? '') === 'warning' ? 'warning' : 'passed') : 'blocked', $reportPath, $raw, array_values(array_unique($blockers)), array_values(array_unique($warnings)), $artifactResults, [
            'token_drift' => $tokenDrift['summary'],
            'new_component_count' => count($newComponents),
            'reused_component_count' => count($reusedComponents),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function writeTemplate(string $outputDirectory): array
    {
        $outputDirectory = rtrim(trim($outputDirectory), DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($outputDirectory);
        $reportPath = $outputDirectory.'/design-system-drift-report.json';

        if (! File::isFile($reportPath)) {
            File::put($reportPath, json_encode([
                'schema_version' => self::REPORT_SCHEMA_VERSION,
                'status' => 'passed',
                'profile_hash' => '<sha256-64-hex>',
                'task_spec_hash' => '<sha256-64-hex>',
                'changed_files' => ['src/components/Example.tsx'],
                'expected_tokens' => [
                    'palette_tokens' => ['primary', 'surface', 'accent'],
                    'typography_tokens' => ['body', 'heading'],
                    'spacing_radius_tokens' => ['space-2', 'space-4', 'radius-sm'],
                ],
                'used_tokens' => [
                    'palette_tokens' => ['primary', 'surface'],
                    'typography_tokens' => ['body'],
                    'spacing_radius_tokens' => ['space-2', 'radius-sm'],
                ],
                'component_usage' => [
                    'reused_components' => ['Button', 'Card'],
                    'new_components' => [],
                ],
                'approved_exceptions' => [],
                'artifacts' => array_map(fn (string $kind): array => [
                    'kind' => $kind,
                    'path' => 'artifacts/'.$kind.'.json',
                    'sha256' => '<sha256-64-hex>',
                ], $this->requiredArtifactKinds()),
                'notes' => 'Store token names, component names, refs and hashes only. Do not store raw source, prompts, secrets or customer data.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        $payload = [
            'schema_version' => self::TEMPLATE_SCHEMA_VERSION,
            'status' => 'ready',
            'template_type' => 'frontend_design_system_drift_report',
            'report_path_hash' => hash('sha256', $reportPath),
            'required_token_categories' => $this->requiredTokenCategories(),
            'required_artifact_kinds' => $this->requiredArtifactKinds(),
            'claim_policy' => [
                'template_is_not_evidence' => true,
                'design_system_claim_requires_passed_inspection' => true,
                'unapproved_new_tokens_or_components_block_claim' => true,
                'raw_customer_source_forbidden' => true,
            ],
        ];
        $payload['template_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    public function requiredTokenCategories(): array
    {
        return ['palette_tokens', 'typography_tokens', 'spacing_radius_tokens'];
    }

    /**
     * @return array<int,string>
     */
    public function requiredArtifactKinds(): array
    {
        return [
            'design_system_refs',
            'token_diff_report',
            'component_usage_report',
            'visual_quality_report',
            'receipt',
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<int,string>
     */
    private function approvedExceptions(array $report): array
    {
        return array_values(array_filter((array) ($report['approved_exceptions'] ?? []), 'is_string'));
    }

    /**
     * @param  array<string,mixed>  $expectedTokens
     * @param  array<string,mixed>  $usedTokens
     * @param  array<int,string>  $approvedExceptions
     * @return array<string,mixed>
     */
    private function tokenDrift(array $expectedTokens, array $usedTokens, array $approvedExceptions): array
    {
        $blockers = [];
        $warnings = [];
        $summary = [];

        foreach ($this->requiredTokenCategories() as $category) {
            $expected = array_values(array_filter((array) ($expectedTokens[$category] ?? []), 'is_string'));
            $used = array_values(array_filter((array) ($usedTokens[$category] ?? []), 'is_string'));
            $unexpected = array_values(array_diff($used, $expected));
            $unused = array_values(array_diff($expected, $used));
            if ($unexpected !== [] && ! in_array($category.'_exceptions_approved', $approvedExceptions, true)) {
                $blockers[] = 'unexpected_'.$category;
            }
            if ($unused !== []) {
                $warnings[] = 'unused_'.$category;
            }
            $summary[$category] = [
                'expected_count' => count($expected),
                'used_count' => count($used),
                'unexpected_count' => count($unexpected),
                'unused_count' => count($unused),
            ];
        }

        return [
            'blockers' => $blockers,
            'warnings' => $warnings,
            'summary' => $summary,
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
                return ['status' => 'blocked', 'blockers' => ['artifact_entry_invalid'], 'warnings' => []];
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
            'gate_type' => 'frontend_design_system_drift',
            'source' => self::class,
            'report_schema_version' => self::REPORT_SCHEMA_VERSION,
            'report_path_hash' => $reportPath !== '' ? hash('sha256', $reportPath) : null,
            'report_hash' => $raw !== '' ? hash('sha256', $raw) : null,
            'required_token_categories' => $this->requiredTokenCategories(),
            'required_artifact_kinds' => $this->requiredArtifactKinds(),
            'artifact_results' => $artifacts,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'claim_policy' => [
                'design_system_adaptation_claim_allowed' => in_array($status, ['passed', 'warning'], true) && $blockers === [],
                'unapproved_new_tokens_or_components_block_claim' => true,
                'token_component_refs_must_be_hashed' => true,
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
        $forbidden = ['raw_prompt', 'prompt', 'source', 'raw_source', 'customer_source', 'customer_data', 'secret', 'token'];

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
