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
            $this->definition('git', 'Git', 'version_control', 'sensor', ['repo_state', 'diff', 'history'], ['git'], ['host', 'workspace'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T0', 'cost' => 'instant', 'trigger' => 'interactive', 'authority_group' => 'repo_state']),
            $this->definition('ripgrep', 'ripgrep', 'code_intelligence', 'sensor', ['text_search', 'context_discovery'], ['rg'], ['host'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T0', 'cost' => 'instant', 'trigger' => 'interactive', 'authority_group' => 'text_search']),
            $this->definition('composer', 'Composer', 'php_quality', 'validator', ['dependency_validate', 'php_scripts'], ['composer'], ['host', 'workspace'], ['reads_workspace', 'may_use_network'], 'medium', 'advisory', ['tier' => 'T1', 'cost' => 'local_fast', 'authority_group' => 'php_dependency_hygiene']),
            $this->definition('laravel_pint', 'Laravel Pint', 'php_quality', 'validator', ['php_format_check'], ['vendor/bin/pint'], ['workspace'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T1', 'cost' => 'local_fast', 'authority_group' => 'formatter']),
            $this->definition('phpstan', 'PHPStan', 'php_quality', 'validator', ['php_static_analysis'], ['vendor/bin/phpstan'], ['workspace'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T1', 'cost' => 'local_fast', 'authority_group' => 'php_static_analysis']),
            $this->definition('psalm', 'Psalm', 'php_quality', 'validator', ['php_static_analysis', 'php_taint_analysis'], ['vendor/bin/psalm'], ['workspace'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T1', 'cost' => 'local_fast', 'authority_group' => 'php_static_analysis', 'authority_role' => 'primary_or_complementary']),
            $this->definition('typescript', 'TypeScript Compiler', 'frontend_quality', 'validator', ['typescript_typecheck'], ['node_modules/.bin/tsc'], ['workspace'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T1', 'cost' => 'local_fast', 'authority_group' => 'ts_js_type_lint']),
            $this->definition('biome', 'Biome', 'frontend_quality', 'validator', ['js_ts_lint', 'format_check'], ['node_modules/.bin/biome'], ['workspace'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T1', 'cost' => 'local_fast', 'authority_group' => 'ts_js_type_lint', 'authority_role' => 'primary_or_complementary']),
            $this->definition('eslint', 'ESLint', 'frontend_quality', 'validator', ['js_ts_lint'], ['node_modules/.bin/eslint'], ['workspace'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T1', 'cost' => 'local_fast', 'authority_group' => 'ts_js_type_lint', 'authority_role' => 'primary_or_complementary']),
            $this->definition('shellcheck', 'ShellCheck', 'shell_quality', 'validator', ['shell_lint'], ['shellcheck'], ['host'], ['reads_workspace'], 'low'),
            $this->definition('hadolint', 'Hadolint', 'container_quality', 'validator', ['dockerfile_lint'], ['hadolint'], ['host'], ['reads_workspace'], 'low'),
            $this->definition('gitleaks', 'Gitleaks', 'security', 'scanner', ['secret_scan'], ['gitleaks'], ['host'], ['reads_workspace', 'secret_sensitive_output'], 'high', 'blocks_resolved', ['tier' => 'T1', 'authority_group' => 'secret_scan']),
            $this->definition('semgrep', 'Semgrep', 'security', 'scanner', ['static_security_scan', 'bug_pattern_scan'], ['semgrep'], ['host'], ['reads_workspace'], 'medium', 'advisory', ['tier' => 'T2', 'cost' => 'review_medium', 'trigger' => 'pr_review_or_release', 'authority_group' => 'semantic_sast', 'authority_role' => 'complementary']),
            $this->definition('atlas_code_intelligence', 'Atlas Code Intelligence', 'code_intelligence', 'internal_analyzer', ['module_index', 'symbol_index', 'drift_audit', 'doc_link_audit'], ['internal'], ['atlas_internal'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T0', 'cost' => 'instant', 'trigger' => 'interactive_or_context_pack', 'authority_group' => 'semantic_code_intelligence']),
            $this->definition('atlas_visual_smoke', 'Atlas Visual Smoke', 'browser_automation', 'internal_sensor', ['local_web_probe', 'dom_snapshot', 'screenshot', 'trace', 'baseline_compare'], ['internal'], ['atlas_internal'], ['network', 'starts_browser', 'may_start_server'], 'medium', 'advisory', ['tier' => 'T2', 'cost' => 'review_medium', 'authority_group' => 'visual_regression']),
            $this->definition('atlas_api_contract', 'Atlas API Contract', 'api_contract', 'internal_validator', ['openapi_detect', 'route_contract_diff', 'response_contract_check'], ['internal'], ['atlas_internal'], ['reads_workspace'], 'medium', 'blocks_resolved', ['tier' => 'T1', 'authority_group' => 'api_contract']),
            $this->definition('playwright', 'Playwright', 'browser_automation', 'recorder', ['browser_open', 'screenshot', 'trace', 'visual_smoke'], ['node_modules/.bin/playwright', 'playwright'], ['workspace', 'atlas_managed', 'host'], ['network', 'starts_browser', 'may_start_server'], 'medium', 'advisory', ['tier' => 'T2', 'cost' => 'review_medium', 'authority_group' => 'visual_regression', 'authority_role' => 'complementary']),
            $this->definition('cypress', 'Cypress', 'browser_automation', 'recorder', ['browser_e2e', 'screenshot'], ['node_modules/.bin/cypress'], ['workspace'], ['network', 'starts_browser', 'may_start_server'], 'medium', 'advisory', ['tier' => 'T2', 'cost' => 'review_medium', 'authority_group' => 'visual_regression', 'authority_role' => 'complementary']),
            $this->definition('docker', 'Docker', 'environment', 'actuator', ['container_runtime', 'compose_stack', 'artifact_export'], ['docker'], ['host'], ['can_start_services', 'network', 'writes_workspace_mounted_state'], 'high', 'requires_human', ['tier' => 'T2', 'cost' => 'review_medium', 'authority_group' => 'environment']),
            $this->definition('trivy', 'Trivy', 'security', 'scanner', ['filesystem_vulnerability_scan', 'container_scan', 'iac_scan', 'secret_scan'], ['trivy'], ['host'], ['reads_workspace', 'may_use_network'], 'high', 'blocks_release', ['tier' => 'T3', 'cost' => 'release_heavy', 'trigger' => 'release_or_nightly', 'authority_group' => 'vulnerability_scan']),
            $this->definition('syft', 'Syft', 'supply_chain', 'scanner', ['sbom_generation'], ['syft'], ['host'], ['reads_workspace'], 'medium', 'advisory', ['tier' => 'T3', 'cost' => 'release_heavy', 'trigger' => 'release_or_nightly', 'authority_group' => 'sbom']),
            $this->definition('grype', 'Grype', 'supply_chain', 'scanner', ['sbom_vulnerability_scan'], ['grype'], ['host'], ['reads_workspace', 'may_use_network'], 'high', 'blocks_release', ['tier' => 'T3', 'cost' => 'release_heavy', 'trigger' => 'release_or_nightly', 'authority_group' => 'vulnerability_scan', 'authority_role' => 'complementary']),
            $this->definition('osv_scanner', 'OSV-Scanner', 'security', 'scanner', ['lockfile_vulnerability_scan'], ['osv-scanner'], ['host'], ['reads_workspace', 'may_use_network'], 'high', 'blocks_release', ['tier' => 'T2', 'cost' => 'review_medium', 'trigger' => 'pr_review_or_release', 'authority_group' => 'dependency_vulnerability']),
            ...$this->programmingPowerTools(),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function programmingPowerTools(): array
    {
        return [
            $this->definition('serena', 'Serena', 'semantic_code_intelligence', 'mcp_tool', ['symbol_lookup', 'find_references', 'semantic_edit', 'mcp_bridge'], ['serena'], ['host'], ['reads_workspace', 'writes_workspace_via_mcp'], 'high', 'requires_human', ['tier' => 'T0', 'cost' => 'interactive', 'trigger' => 'interactive', 'authority_group' => 'semantic_code_intelligence', 'authority_role' => 'complementary']),
            $this->definition('tree_sitter', 'Tree-sitter', 'code_graph', 'analyzer', ['ast_parse', 'code_graph'], ['tree-sitter'], ['host'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T0', 'cost' => 'local_fast', 'trigger' => 'context_pack_or_index', 'authority_group' => 'code_graph']),
            $this->definition('ast_grep', 'ast-grep', 'structural_search', 'analyzer', ['structural_search', 'structural_rewrite'], ['ast-grep', 'sg'], ['host'], ['reads_workspace', 'may_write_workspace'], 'medium', 'advisory', ['tier' => 'T0', 'cost' => 'instant', 'trigger' => 'interactive', 'authority_group' => 'structural_search']),
            $this->definition('universal_ctags', 'Universal Ctags', 'symbol_index', 'analyzer', ['symbol_index'], ['ctags'], ['host'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T0', 'cost' => 'local_fast', 'authority_group' => 'semantic_code_intelligence', 'authority_role' => 'fallback']),
            $this->definition('codeql', 'CodeQL CLI', 'semantic_sast', 'scanner', ['semantic_sast', 'sarif_output'], ['codeql'], ['host'], ['reads_workspace', 'may_use_network'], 'high', 'blocks_release', ['tier' => 'T2', 'cost' => 'review_medium', 'trigger' => 'pr_review_or_release', 'authority_group' => 'semantic_sast']),
            $this->definition('infer', 'Infer', 'bug_finder', 'scanner', ['static_bug_finding'], ['infer'], ['host'], ['reads_workspace'], 'medium', 'advisory', ['tier' => 'T2', 'cost' => 'review_medium', 'authority_group' => 'static_bug_finding']),
            $this->definition('checkov', 'Checkov', 'iac_security', 'scanner', ['iac_security'], ['checkov'], ['host'], ['reads_workspace'], 'high', 'blocks_release', ['tier' => 'T2', 'cost' => 'review_medium', 'authority_group' => 'iac_security']),
            $this->definition('terrascan', 'Terrascan', 'iac_security', 'scanner', ['iac_security'], ['terrascan'], ['host'], ['reads_workspace'], 'medium', 'advisory', ['tier' => 'T2', 'cost' => 'review_medium', 'authority_group' => 'iac_security', 'authority_role' => 'complementary']),
            $this->definition('kube_linter', 'kube-linter', 'kubernetes_policy', 'scanner', ['kubernetes_lint'], ['kube-linter'], ['host'], ['reads_workspace'], 'medium', 'advisory', ['tier' => 'T2', 'cost' => 'review_medium', 'authority_group' => 'kubernetes_policy']),
            $this->definition('kube_score', 'kube-score', 'kubernetes_policy', 'scanner', ['kubernetes_score'], ['kube-score'], ['host'], ['reads_workspace'], 'medium', 'advisory', ['tier' => 'T2', 'cost' => 'review_medium', 'authority_group' => 'kubernetes_policy', 'authority_role' => 'complementary']),
            $this->definition('dockle', 'Dockle', 'container_hardening', 'scanner', ['container_hardening'], ['dockle'], ['host'], ['reads_workspace'], 'medium', 'advisory', ['tier' => 'T2', 'cost' => 'review_medium', 'authority_group' => 'container_hardening']),
            $this->definition('scancode', 'ScanCode Toolkit', 'license_compliance', 'scanner', ['license_scan', 'copyright_scan'], ['scancode'], ['host'], ['reads_workspace'], 'medium', 'blocks_release', ['tier' => 'T3', 'cost' => 'release_heavy', 'trigger' => 'release_or_nightly', 'authority_group' => 'license_compliance']),
            $this->definition('ort', 'OSS Review Toolkit', 'license_compliance', 'scanner', ['license_scan', 'dependency_review'], ['ort'], ['host'], ['reads_workspace'], 'medium', 'blocks_release', ['tier' => 'T3', 'cost' => 'release_heavy', 'trigger' => 'release_or_nightly', 'authority_group' => 'license_compliance', 'authority_role' => 'complementary']),
            $this->definition('licensee', 'Licensee', 'license_compliance', 'scanner', ['repo_license_detect'], ['licensee'], ['host'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T1', 'cost' => 'local_fast', 'authority_group' => 'license_compliance', 'authority_role' => 'fallback']),
            $this->definition('rector', 'Rector', 'mechanical_refactor', 'refactor', ['php_refactor', 'dry_run'], ['vendor/bin/rector'], ['workspace'], ['reads_workspace', 'may_write_workspace'], 'high', 'requires_human', ['tier' => 'T2', 'cost' => 'review_medium', 'authority_group' => 'mechanical_refactor']),
            $this->definition('phpmd', 'PHP Mess Detector', 'maintainability', 'scanner', ['php_smell_scan', 'complexity_scan'], ['vendor/bin/phpmd'], ['workspace'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T1', 'cost' => 'local_fast', 'authority_group' => 'php_maintainability']),
            $this->definition('phpcpd', 'PHPCPD', 'duplication', 'scanner', ['php_duplication_scan'], ['vendor/bin/phpcpd'], ['workspace'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T2', 'cost' => 'review_medium', 'authority_group' => 'duplication']),
            $this->definition('composer_require_checker', 'Composer Require Checker', 'dependency_hygiene', 'scanner', ['undeclared_dependency_scan'], ['vendor/bin/composer-require-checker'], ['workspace'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T1', 'authority_group' => 'php_dependency_hygiene']),
            $this->definition('composer_unused', 'Composer Unused', 'dependency_hygiene', 'scanner', ['unused_dependency_scan'], ['vendor/bin/composer-unused'], ['workspace'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T1', 'authority_group' => 'php_dependency_hygiene']),
            $this->definition('knip', 'Knip', 'dead_code', 'scanner', ['unused_exports', 'unused_dependencies'], ['node_modules/.bin/knip'], ['workspace'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T1', 'authority_group' => 'ts_js_dead_code']),
            $this->definition('ts_prune', 'ts-prune', 'dead_code', 'scanner', ['unused_exports'], ['node_modules/.bin/ts-prune'], ['workspace'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T1', 'authority_group' => 'ts_js_dead_code', 'authority_role' => 'fallback']),
            $this->definition('schemathesis', 'Schemathesis', 'api_contract_fuzzing', 'tester', ['openapi_property_testing', 'api_fuzzing'], ['schemathesis'], ['host'], ['reads_workspace', 'network', 'may_start_server'], 'medium', 'blocks_resolved', ['tier' => 'T2', 'cost' => 'review_medium', 'authority_group' => 'api_contract', 'authority_role' => 'complementary']),
            $this->definition('pact', 'Pact', 'consumer_provider_contract', 'tester', ['consumer_provider_contract'], ['node_modules/.bin/pact'], ['workspace'], ['reads_workspace', 'network'], 'medium', 'blocks_resolved', ['tier' => 'T2', 'cost' => 'review_medium', 'authority_group' => 'api_contract', 'authority_role' => 'complementary']),
            $this->definition('prism', 'Prism', 'openapi_validation_mock', 'tester', ['openapi_mock', 'openapi_validation'], ['node_modules/.bin/prism'], ['workspace'], ['reads_workspace', 'network', 'may_start_server'], 'medium', 'advisory', ['tier' => 'T2', 'authority_group' => 'api_contract', 'authority_role' => 'complementary']),
            $this->definition('wiremock', 'WireMock', 'external_api_mock', 'tester', ['external_api_mock'], ['wiremock'], ['host'], ['network', 'may_start_server'], 'medium', 'advisory', ['tier' => 'T2', 'authority_group' => 'api_contract', 'authority_role' => 'complementary']),
            $this->definition('bruno', 'Bruno', 'api_collection', 'tester', ['api_collection_run'], ['bru'], ['host'], ['reads_workspace', 'network'], 'medium', 'advisory', ['tier' => 'T2', 'authority_group' => 'api_contract', 'authority_role' => 'complementary']),
            $this->definition('infection', 'Infection', 'mutation_testing', 'tester', ['php_mutation_testing'], ['vendor/bin/infection'], ['workspace'], ['reads_workspace'], 'medium', 'blocks_release', ['tier' => 'T3', 'cost' => 'release_heavy', 'trigger' => 'release_or_nightly', 'authority_group' => 'mutation_testing']),
            $this->definition('stryker', 'Stryker', 'mutation_testing', 'tester', ['js_ts_mutation_testing'], ['node_modules/.bin/stryker'], ['workspace'], ['reads_workspace'], 'medium', 'blocks_release', ['tier' => 'T3', 'cost' => 'release_heavy', 'trigger' => 'release_or_nightly', 'authority_group' => 'mutation_testing']),
            $this->definition('fast_check', 'fast-check', 'property_testing', 'tester', ['property_testing'], ['node_modules/.bin/fast-check'], ['workspace'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T2', 'authority_group' => 'property_testing']),
            $this->definition('axe_core', 'axe-core', 'accessibility', 'tester', ['accessibility_scan'], ['node_modules/.bin/axe'], ['workspace'], ['reads_workspace', 'network', 'starts_browser'], 'medium', 'blocks_resolved', ['tier' => 'T2', 'authority_group' => 'accessibility']),
            $this->definition('pa11y', 'Pa11y', 'accessibility_smoke', 'tester', ['accessibility_smoke'], ['node_modules/.bin/pa11y'], ['workspace'], ['reads_workspace', 'network', 'starts_browser'], 'medium', 'advisory', ['tier' => 'T2', 'authority_group' => 'accessibility', 'authority_role' => 'complementary']),
            $this->definition('lighthouse_ci', 'Lighthouse CI', 'frontend_performance', 'tester', ['frontend_performance_budget'], ['node_modules/.bin/lhci'], ['workspace'], ['reads_workspace', 'network', 'starts_browser'], 'medium', 'blocks_release', ['tier' => 'T3', 'cost' => 'release_heavy', 'trigger' => 'release_or_nightly', 'authority_group' => 'frontend_performance']),
            $this->definition('dependency_cruiser', 'dependency-cruiser', 'architecture_boundary', 'scanner', ['js_ts_architecture_boundary'], ['node_modules/.bin/depcruise'], ['workspace'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T2', 'authority_group' => 'ts_js_architecture']),
            $this->definition('madge', 'Madge', 'architecture_boundary', 'scanner', ['js_ts_cycle_scan'], ['node_modules/.bin/madge'], ['workspace'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T2', 'authority_group' => 'ts_js_architecture', 'authority_role' => 'complementary']),
            $this->definition('deptrac', 'Deptrac', 'architecture_boundary', 'scanner', ['php_architecture_boundary'], ['vendor/bin/deptrac'], ['workspace'], ['reads_workspace'], 'low', 'advisory', ['tier' => 'T2', 'authority_group' => 'php_architecture']),
            $this->definition('aider', 'Aider', 'external_coding_agent', 'agent', ['repo_map', 'multi_file_edit'], ['aider'], ['host'], ['reads_workspace', 'writes_workspace', 'may_use_network'], 'high', 'requires_human', ['tier' => 'T2', 'cost' => 'review_medium', 'authority_group' => 'external_coding_agent', 'authority_role' => 'executor']),
            $this->definition('continue', 'Continue', 'external_coding_agent', 'agent', ['ide_agent', 'code_assist'], ['continue'], ['host'], ['reads_workspace', 'writes_workspace', 'may_use_network'], 'high', 'requires_human', ['tier' => 'T2', 'authority_group' => 'external_coding_agent', 'authority_role' => 'executor']),
            $this->definition('openhands', 'OpenHands', 'external_coding_agent', 'agent', ['autonomous_software_agent'], ['openhands'], ['host'], ['reads_workspace', 'writes_workspace', 'may_use_network'], 'high', 'requires_human', ['tier' => 'T3', 'cost' => 'release_heavy', 'authority_group' => 'external_coding_agent', 'authority_role' => 'executor']),
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
        array $options = [],
    ): array {
        $executionTier = (string) ($options['tier'] ?? 'T1');
        $expectedCost = (string) ($options['cost'] ?? match ($executionTier) {
            'T0' => 'instant',
            'T2' => 'review_medium',
            'T3' => 'release_heavy',
            default => 'local_fast',
        });
        $defaultTrigger = (string) ($options['trigger'] ?? match ($executionTier) {
            'T0' => 'interactive',
            'T2' => 'pr_review_or_policy',
            'T3' => 'release_or_nightly',
            default => 'local_fast_or_policy',
        });
        $authorityRole = (string) ($options['authority_role'] ?? 'primary');
        $authorityGroup = (string) ($options['authority_group'] ?? $category);
        $safeCommands = $options['safe_commands'] ?? (in_array('atlas_internal', $layers, true) ? [] : [[
            'name' => 'version',
            'description' => 'Detecta a versao da ferramenta sem analisar o workspace.',
            'command' => ['{binary}', '--version'],
            'dry_run_default' => true,
            'network_allowed' => false,
            'max_execution_tier' => $executionTier,
            'sandbox_mode' => 'workspace',
            'privacy_level' => 'standard',
            'task_type' => 'diagnostic',
            'requires_provider_safe' => false,
        ]]);

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
            'execution_tier' => $executionTier,
            'expected_cost' => $expectedCost,
            'default_trigger' => $defaultTrigger,
            'authority_role' => $authorityRole,
            'authority_group' => $authorityGroup,
            'capabilities_json' => $capabilities,
            'runtime_json' => ['execution_layers' => $layers],
            'detect_json' => ['binaries' => $binaries],
            'outputs_json' => ['stdout', 'stderr', 'json'],
            'risks_json' => $risks,
            'metadata' => [
                'seeded_by' => 'atlas_super_tool_runtime_core_v0',
                'execution_tier' => $executionTier,
                'expected_cost' => $expectedCost,
                'default_trigger' => $defaultTrigger,
                'authority_role' => $authorityRole,
                'authority_group' => $authorityGroup,
                'safe_commands' => $safeCommands,
            ],
        ];
    }
}
