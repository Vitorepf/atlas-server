<?php

namespace App\Services\Tools;

class AtlasToolDefinitionCatalog
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function definitions(): array
    {
        return [
            $this->definition('git', 'Git', 'version_control', 'sensor', ['repo_state', 'diff', 'history'], ['git'], ['host', 'workspace'], ['reads_workspace'], 'low'),
            $this->definition('ripgrep', 'ripgrep', 'code_intelligence', 'sensor', ['text_search', 'context_discovery'], ['rg'], ['host'], ['reads_workspace'], 'low'),
            $this->definition('composer', 'Composer', 'php_quality', 'validator', ['dependency_validate', 'php_scripts'], ['composer'], ['host', 'workspace'], ['reads_workspace', 'may_use_network'], 'medium'),
            $this->definition('laravel_pint', 'Laravel Pint', 'php_quality', 'validator', ['php_format_check'], ['vendor/bin/pint'], ['workspace'], ['reads_workspace'], 'low'),
            $this->definition('phpstan', 'PHPStan', 'php_quality', 'validator', ['php_static_analysis'], ['vendor/bin/phpstan'], ['workspace'], ['reads_workspace'], 'low'),
            $this->definition('psalm', 'Psalm', 'php_quality', 'validator', ['php_static_analysis'], ['vendor/bin/psalm'], ['workspace'], ['reads_workspace'], 'low'),
            $this->definition('typescript', 'TypeScript Compiler', 'frontend_quality', 'validator', ['typescript_typecheck'], ['node_modules/.bin/tsc'], ['workspace'], ['reads_workspace'], 'low'),
            $this->definition('biome', 'Biome', 'frontend_quality', 'validator', ['js_ts_lint', 'format_check'], ['node_modules/.bin/biome'], ['workspace'], ['reads_workspace'], 'low'),
            $this->definition('eslint', 'ESLint', 'frontend_quality', 'validator', ['js_ts_lint'], ['node_modules/.bin/eslint'], ['workspace'], ['reads_workspace'], 'low'),
            $this->definition('shellcheck', 'ShellCheck', 'shell_quality', 'validator', ['shell_lint'], ['shellcheck'], ['host'], ['reads_workspace'], 'low'),
            $this->definition('hadolint', 'Hadolint', 'container_quality', 'validator', ['dockerfile_lint'], ['hadolint'], ['host'], ['reads_workspace'], 'low'),
            $this->definition('gitleaks', 'Gitleaks', 'security', 'scanner', ['secret_scan'], ['gitleaks'], ['host'], ['reads_workspace', 'secret_sensitive_output'], 'high', 'blocks_resolved'),
            $this->definition('semgrep', 'Semgrep', 'security', 'scanner', ['static_security_scan', 'bug_pattern_scan'], ['semgrep'], ['host'], ['reads_workspace'], 'medium'),
            $this->definition('atlas_code_intelligence', 'Atlas Code Intelligence', 'code_intelligence', 'internal_analyzer', ['module_index', 'symbol_index', 'drift_audit', 'doc_link_audit'], ['internal'], ['atlas_internal'], ['reads_workspace'], 'low'),
            $this->definition('atlas_visual_smoke', 'Atlas Visual Smoke', 'browser_automation', 'internal_sensor', ['local_web_probe', 'dom_snapshot', 'screenshot', 'trace', 'baseline_compare'], ['internal'], ['atlas_internal'], ['network', 'starts_browser', 'may_start_server'], 'medium'),
            $this->definition('playwright', 'Playwright', 'browser_automation', 'recorder', ['browser_open', 'screenshot', 'trace', 'visual_smoke'], ['node_modules/.bin/playwright', 'playwright'], ['workspace', 'atlas_managed', 'host'], ['network', 'starts_browser', 'may_start_server'], 'medium'),
            $this->definition('cypress', 'Cypress', 'browser_automation', 'recorder', ['browser_e2e', 'screenshot'], ['node_modules/.bin/cypress'], ['workspace'], ['network', 'starts_browser', 'may_start_server'], 'medium'),
            $this->definition('docker', 'Docker', 'environment', 'actuator', ['container_runtime', 'compose_stack', 'artifact_export'], ['docker'], ['host'], ['can_start_services', 'network', 'writes_workspace_mounted_state'], 'high', 'requires_human'),
            $this->definition('trivy', 'Trivy', 'security', 'scanner', ['filesystem_vulnerability_scan', 'container_scan', 'iac_scan'], ['trivy'], ['host'], ['reads_workspace', 'may_use_network'], 'high', 'blocks_release'),
            $this->definition('syft', 'Syft', 'supply_chain', 'scanner', ['sbom_generation'], ['syft'], ['host'], ['reads_workspace'], 'medium'),
            $this->definition('grype', 'Grype', 'supply_chain', 'scanner', ['sbom_vulnerability_scan'], ['grype'], ['host'], ['reads_workspace', 'may_use_network'], 'high', 'blocks_release'),
            $this->definition('osv_scanner', 'OSV-Scanner', 'security', 'scanner', ['lockfile_vulnerability_scan'], ['osv-scanner'], ['host'], ['reads_workspace', 'may_use_network'], 'high', 'blocks_release'),
        ];
    }

    /**
     * @param  array<int,string>  $capabilities
     * @param  array<int,string>  $binaries
     * @param  array<int,string>  $layers
     * @param  array<int,string>  $risks
     * @return array<string,mixed>
     */
    private function definition(
        string $slug,
        string $name,
        string $category,
        string $type,
        array $capabilities,
        array $binaries,
        array $layers,
        array $risks,
        string $riskLevel,
        string $failurePolicy = 'advisory',
    ): array {
        return [
            'slug' => $slug,
            'name' => $name,
            'type' => $type,
            'category' => $category,
            'description' => 'Atlas managed registry entry for '.$name.'.',
            'license_posture' => 'open_source',
            'cost_posture' => 'free_local',
            'default_enabled' => true,
            'default_timeout_seconds' => in_array('starts_browser', $risks, true) ? 120 : 60,
            'default_failure_policy' => $failurePolicy,
            'risk_level' => $riskLevel,
            'status' => 'active',
            'capabilities_json' => $capabilities,
            'runtime_json' => ['execution_layers' => $layers],
            'detect_json' => ['binaries' => $binaries],
            'outputs_json' => ['stdout', 'stderr', 'json'],
            'risks_json' => $risks,
            'metadata' => ['seeded_by' => 'atlas_super_tool_runtime_core_v0'],
        ];
    }
}
