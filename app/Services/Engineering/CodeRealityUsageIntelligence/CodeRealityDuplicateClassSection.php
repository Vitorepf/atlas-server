<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeRealityUsageIntelligence;

use Illuminate\Support\Facades\File;

final class CodeRealityDuplicateClassSection
{
    public function __construct(
        private readonly CodeRealityPrimitives $primitives,
        private readonly CodeRealityRouteAliasSection $routeAlias,
    ) {}


    /**
     * @param  array<int,array<string,mixed>>  $classDuplicateGroups
     * @param  array<int,array<string,mixed>>  $runtimeRouteActionAliasGroups
     * @return array<int,array<string,mixed>>
     */
    public function duplicationTriageQueue(array $classDuplicateGroups, array $runtimeRouteActionAliasGroups): array
    {
        $items = [];
        foreach ($classDuplicateGroups as $group) {
            $value = (string) ($group['value'] ?? '');
            $paths = (array) ($group['paths'] ?? []);
            $severity = match ($value) {
                'operationenvelope' => 'critical',
                'verificationcommandrunner', 'frontmatterparser' => 'high',
                'aiexecutionplan' => 'medium',
                'smokesubject' => 'low',
                default => 'review',
            };
            $items[] = [
                'id' => 'duplicate_class:'.$value,
                'kind' => 'duplicate_class_name',
                'severity' => $severity,
                'status' => 'owner_review_required',
                'paths' => $paths,
                'current_evidence' => $this->duplicateClassEvidenceHint($value),
                'boundary_contract' => $this->duplicateClassBoundaryContract($value),
                'cleanup_recommendation' => $this->duplicateClassCleanupRecommendation($value),
                'why_it_can_confuse_ai' => 'same_short_class_name_can_make_agents_pick_wrong_namespace_or_runtime_boundary',
                'required_decision' => 'reuse_existing|namespace_boundary_intentional|supersede|merge|quarantine',
                'next_commands' => array_values(array_map(
                    static fn (string $path): string => 'php artisan atlas:code-reality reachability --target="'.$path.'" --json',
                    $paths
                )),
            ];
        }

        foreach ($runtimeRouteActionAliasGroups as $group) {
            $action = (string) ($group['value'] ?? '');
            $methodUris = $this->routeAlias->duplicateGroupMethodUris($group);
            $mobileAlias = $this->routeAlias->mobileRouteActionAlias($methodUris);
            $rootApiAlias = $this->routeAlias->rootApiCompatibilityAlias($methodUris);
            $items[] = [
                'id' => 'route_action_alias:'.$action,
                'kind' => 'runtime_route_action_alias',
                'severity' => ($mobileAlias || $rootApiAlias) ? 'low' : 'medium',
                'status' => $rootApiAlias ? 'documented_transport_compatibility_alias' : ($mobileAlias ? 'document_intentional_alias_or_wrapper_boundary' : 'owner_review_required'),
                'action' => $action,
                'method_uris' => $methodUris,
                'boundary_contract' => $this->routeAlias->routeActionAliasBoundaryContract($action, $methodUris, $mobileAlias, $rootApiAlias),
                'why_it_can_confuse_ai' => $rootApiAlias ? 'same_controller_action_is_intentionally_exposed_at_root_and_api_prefix' : 'same_controller_action_is_exposed_through_multiple_urls',
                'required_decision' => $rootApiAlias ? 'keep_as_root_api_transport_compatibility_alias|document_wrapper_contract' : ($mobileAlias ? 'document_as_mobile_alias|split_mobile_wrapper|deprecate_one_path' : 'document_alias_or_merge_paths'),
                'next_commands' => ['php artisan route:list --json'],
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'review' => 3, 'low' => 4];

            return ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9);
        });

        return $items;
    }

    /**
     * @param  array<int,array<string,mixed>>  $classDuplicateGroups
     * @return array<int,array<string,mixed>>
     */
    public function generatedFixtureClassDuplicateGroups(array $classDuplicateGroups): array
    {
        return array_values(array_filter(
            $classDuplicateGroups,
            fn (array $group): bool => $this->duplicateClassGeneratedFixtureEvidence(
                (string) ($group['value'] ?? ''),
                array_values(array_map('strval', (array) ($group['paths'] ?? [])))
            ) !== null
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $generatedFixtureClassDuplicateGroups
     * @return array<int,array<string,mixed>>
     */
    public function generatedFixtureClassBoundaryQueue(array $generatedFixtureClassDuplicateGroups): array
    {
        return array_values(array_map(function (array $group): array {
            $value = (string) ($group['value'] ?? '');
            $paths = array_values(array_map('strval', (array) ($group['paths'] ?? [])));

            return [
                'id' => 'generated_fixture_class_boundary:'.$value,
                'kind' => 'generated_fixture_duplicate_class_boundary',
                'severity' => 'low',
                'status' => 'documented_generated_fixture_boundary',
                'short_name' => $value,
                'paths' => $paths,
                'current_evidence' => $this->duplicateClassEvidenceHint($value),
                'boundary_contract' => $this->duplicateClassBoundaryContract($value),
                'cleanup_recommendation' => $this->duplicateClassCleanupRecommendation($value),
                'required_decision' => 'keep_as_generated_fixture_boundary|change_generator_contract_before_any_rename',
                'next_commands' => array_values(array_map(
                    static fn (string $path): string => 'php artisan atlas:code-reality reachability --target="'.$path.'" --json',
                    $paths
                )),
                'claim_policy' => 'generated_fixture_duplicate_class_is_boundary_inventory_not_production_duplicate_triage',
            ];
        }, $generatedFixtureClassDuplicateGroups));
    }

    /**
     * @param  array<int,array<string,mixed>>  $classDuplicateGroups
     * @return array<int,array<string,mixed>>
     */
    public function blockingClassDuplicateGroups(array $classDuplicateGroups): array
    {
        return array_values(array_filter(
            $classDuplicateGroups,
            fn (array $group): bool => $this->duplicateClassGeneratedFixtureEvidence(
                (string) ($group['value'] ?? ''),
                array_values(array_map('strval', (array) ($group['paths'] ?? [])))
            ) === null
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $classDuplicateGroups
     * @return array<int,array<string,mixed>>
     */
    public function duplicateClassCleanupQueue(array $classDuplicateGroups): array
    {
        $items = [];
        foreach ($classDuplicateGroups as $group) {
            $value = (string) ($group['value'] ?? '');
            $paths = array_values(array_map('strval', (array) ($group['paths'] ?? [])));
            if ($this->duplicateClassGeneratedFixtureEvidence($value, $paths) !== null) {
                continue;
            }
            $recommendation = $this->duplicateClassCleanupRecommendation($value);
            $items[] = [
                'id' => 'duplicate_class_cleanup:'.$value,
                'kind' => 'duplicate_class_cleanup_decision',
                'short_name' => $value,
                'priority' => (int) ($recommendation['priority'] ?? 99),
                'severity' => $this->duplicateClassSeverity($value),
                'paths' => $paths,
                'exact_references' => $this->duplicateClassExactReferences($paths),
                'generated_fixture_evidence' => $this->duplicateClassGeneratedFixtureEvidence($value, $paths),
                'cleanup_type' => $this->duplicateClassCleanupType($value),
                'cleanup_recommendation' => $recommendation,
                'boundary_contract' => $this->duplicateClassBoundaryContract($value),
                'required_before_change' => [
                    'reachability_for_every_path',
                    'deletion_preflight_for_every_path',
                    'owner_doc_decision',
                    'focused_tests',
                    'separate_cleanup_change',
                ],
                'proof_commands' => array_values(array_merge(...array_map(
                    static fn (string $path): array => [
                        'php artisan atlas:code-reality reachability --target="'.$path.'" --json',
                        'php artisan atlas:code-reality deletion-preflight --target="'.$path.'" --json',
                    ],
                    $paths
                ))),
                'focused_tests' => $this->duplicateClassFocusedTests($value),
                'claim_policy' => 'cleanup_queue_is_not_delete_permission',
            ];
        }

        usort($items, static fn (array $a, array $b): int => ((int) ($a['priority'] ?? 99)) <=> ((int) ($b['priority'] ?? 99)));

        return $items;
    }

    /**
     * @param  array<int,string>  $paths
     * @return array<string,mixed>|null
     */
    private function duplicateClassGeneratedFixtureEvidence(string $value, array $paths): ?array
    {
        if ($value !== 'smokesubject') {
            return null;
        }

        return [
            'classification' => 'generated_fixture_inside_smoke_workspace',
            'repo_paths_are_generators_not_production_class_files' => true,
            'generator_paths' => $paths,
            'generated_files' => ['src/SmokeSubject.php', 'tests/SmokeSubjectTest.php'],
            'generated_namespace' => 'Smoke',
            'production_boundary' => [
                'persistence' => false,
                'route_surface' => false,
                'domain_model' => false,
            ],
            'forbidden' => 'do_not_rename_or_delete_generator_commands_as_if_smokesubject_were_a_repo_domain_class',
        ];
    }

    /**
     * @param  array<int,string>  $paths
     * @return array<int,array<string,mixed>>
     */
    private function duplicateClassExactReferences(array $paths): array
    {
        return array_values(array_map(function (string $path): array {
            $fqcn = $this->phpClassFqcn($path);
            $class = $fqcn === null ? null : class_basename($fqcn);
            $namespace = $fqcn === null || $class === null ? null : substr($fqcn, 0, -strlen('\\'.$class));
            $matches = $fqcn === null ? [] : $this->exactTextReferences($fqcn);
            $namespaceLocalMatches = ($namespace === null || $class === null) ? [] : $this->namespaceLocalReferences($namespace, $class);

            return [
                'path' => $path,
                'fqcn' => $fqcn,
                'reference_count' => count($matches),
                'reference_samples' => array_slice($matches, 0, 12),
                'namespace_local_reference_count' => count($namespaceLocalMatches),
                'namespace_local_reference_samples' => array_slice($namespaceLocalMatches, 0, 12),
                'claim_policy' => 'exact_fqcn_refs_are_stronger_than_short_name_reachability',
            ];
        }, $paths));
    }

    private function phpClassFqcn(string $path): ?string
    {
        $content = $this->primitives->readSmallFile(base_path($path));
        if ($content === null) {
            return null;
        }
        if (! preg_match('/^namespace\s+([^;]+);/m', $content, $namespaceMatch)) {
            return null;
        }
        if (! preg_match('/^\s*(?:abstract\s+|final\s+)?(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $content, $classMatch)) {
            return null;
        }

        return trim((string) $namespaceMatch[1]).'\\'.trim((string) $classMatch[1]);
    }

    /**
     * @return array<int,string>
     */
    private function exactTextReferences(string $needle): array
    {
        $matches = [];
        foreach ($this->primitives->allFiles(CodeRealityPrimitives::SEARCH_ROOTS) as $file) {
            $path = $this->primitives->relativePath($file->getPathname());
            if (! $this->primitives->isTextFile($file->getPathname())) {
                continue;
            }
            $content = $this->primitives->readSmallFile($file->getPathname());
            if ($content !== null && str_contains($content, $needle)) {
                $matches[] = $path;
            }
        }

        sort($matches);

        return $matches;
    }

    /**
     * @return array<int,string>
     */
    private function namespaceLocalReferences(string $namespace, string $class): array
    {
        $matches = [];
        foreach ($this->primitives->allFiles(CodeRealityPrimitives::SEARCH_ROOTS) as $file) {
            $path = $this->primitives->relativePath($file->getPathname());
            if (! $this->primitives->isTextFile($file->getPathname())) {
                continue;
            }
            $content = $this->primitives->readSmallFile($file->getPathname());
            if ($content === null) {
                continue;
            }
            if (! preg_match('/^namespace\s+'.preg_quote($namespace, '/').'\s*;/m', $content)) {
                continue;
            }
            if (preg_match('/\b'.preg_quote($class, '/').'\b/', $content)) {
                $matches[] = $path;
            }
        }

        sort($matches);

        return $matches;
    }

    private function duplicateClassSeverity(string $value): string
    {
        return match ($value) {
            'operationenvelope' => 'critical',
            'verificationcommandrunner', 'frontmatterparser' => 'high',
            'aiexecutionplan' => 'medium',
            'smokesubject' => 'low',
            default => 'review',
        };
    }

    private function duplicateClassCleanupType(string $value): string
    {
        return match ($value) {
            'operationenvelope' => 'boundary_doc_then_possible_programming_variant_rename',
            'frontmatterparser' => 'explicit_parser_names_or_adapter',
            'verificationcommandrunner' => 'rename_atlas_dev_gate_interface_or_document_boundary',
            'aiexecutionplan' => 'rename_prompt_value_object_not_persistent_model',
            'smokesubject' => 'exclude_generated_fixture_from_production_duplicate_pressure',
            default => 'owner_review_before_cleanup',
        };
    }

    /**
     * @return array<int,string>
     */
    private function duplicateClassFocusedTests(string $value): array
    {
        return match ($value) {
            'operationenvelope' => [
                'php artisan test --filter=OperationEnvelope',
                'php artisan test tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php',
            ],
            'frontmatterparser' => [
                'php artisan test --filter=FrontmatterParser',
                'php artisan test --filter=EngineeringDocumentationHealthServiceTest',
            ],
            'verificationcommandrunner' => [
                'php artisan test --filter=VerificationCommandRunner',
                'php artisan test --filter=VerificationGate',
            ],
            'aiexecutionplan' => [
                'php artisan test --filter=AiExecutionPlan',
                'php artisan test --filter=AiPromptBuilder',
            ],
            'smokesubject' => [
                'php artisan test --filter=AtlasDevSeniorLoop',
                'php artisan test --filter=AtlasDevDesktopRealSmoke',
            ],
            default => ['php artisan test --filter=<owner-focused-test>'],
        };
    }

    /**
     * @return array<string,string>
     */
    private function duplicateClassEvidenceHint(string $value): array
    {
        return match ($value) {
            'operationenvelope' => [
                'reachability' => 'all_three_known_paths_are_high_reachability',
                'observed_boundary' => 'kernel_envelope_atlas_dev_provider_safe_schema_and_sdd_pipeline_envelope_are_distinct_runtime_shapes',
                'cleanup_bias' => 'do_not_delete; prefer explicit naming or owner doc boundary before merge',
            ],
            'verificationcommandrunner' => [
                'reachability' => 'both_known_paths_are_high_reachability',
                'observed_boundary' => 'atlas_code_concrete_runner_and_atlas_dev_gate_interface_share_short_name',
                'cleanup_bias' => 'consider interface rename or boundary doc; do_not_delete_without_adapter_plan',
            ],
            'frontmatterparser' => [
                'reachability' => 'both_known_paths_are_high_reachability',
                'observed_boundary' => 'semantic_parser_builds_and_validates_docs; vault_parser_is_read_only_vault_shape_parser',
                'cleanup_bias' => 'consider consolidation or explicit parser naming; do_not_delete_without_vault_semantic_tests',
            ],
            'aiexecutionplan' => [
                'reachability' => 'both_known_paths_are_high_reachability',
                'observed_boundary' => 'eloquent_persistent_plan_model_and_prompt_value_object_share_short_name',
                'cleanup_bias' => 'consider value_object_rename; preserve model/table contract',
            ],
            'smokesubject' => [
                'reachability' => 'generated_inside_smoke_workspaces',
                'observed_boundary' => 'local_fixture_class_created_by_smoke_commands_not_production_domain_class',
                'cleanup_bias' => 'low_priority; document_fixture_intent_or_exclude_generated_workspace_subject',
            ],
            default => [
                'reachability' => 'run_target_reachability_before_cleanup',
                'observed_boundary' => 'unknown',
                'cleanup_bias' => 'owner_review_required',
            ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function duplicateClassCleanupRecommendation(string $value): array
    {
        return match ($value) {
            'operationenvelope' => [
                'priority' => 1,
                'decision' => 'keep_boundaries_then_rename_only_with_adapter_plan',
                'safe_next_action' => 'add_or_update_owner_docs_to_name_kernel_vs_atlas_dev_vs_sdd_envelopes_explicitly_before_any_code_merge',
                'rename_candidate' => 'programming_variants_only',
                'delete_allowed' => false,
                'merge_allowed_without_owner_decision' => false,
            ],
            'frontmatterparser' => [
                'priority' => 2,
                'decision' => 'use_explicit_parser_aliases_before_any_rename_or_consolidation',
                'safe_next_action' => 'use CanonicalDocsFrontmatterParser or VaultNoteFrontmatterParser in new code while old names remain compatibility contracts',
                'rename_candidate' => 'App\\Services\\Vault\\FrontmatterParser',
                'delete_allowed' => false,
                'merge_allowed_without_owner_decision' => false,
            ],
            'verificationcommandrunner' => [
                'priority' => 3,
                'decision' => 'use_explicit_runner_aliases_before_any_interface_rename',
                'safe_next_action' => 'use AtlasCodeVerificationCommandRunner for observed-session execution and AtlasDevVerificationCommandRunnerContract for AtlasDev gate injection in new code',
                'rename_candidate' => 'App\\Services\\Ai\\Programming\\AtlasDev\\Gate\\VerificationCommandRunner',
                'delete_allowed' => false,
                'merge_allowed_without_owner_decision' => false,
            ],
            'aiexecutionplan' => [
                'priority' => 4,
                'decision' => 'use_explicit_execution_plan_aliases_before_any_rename',
                'safe_next_action' => 'use PersistentAiExecutionPlan for database plans and AiPromptExecutionPlan for prompt payloads in new code while old names remain compatibility contracts',
                'rename_candidate' => 'App\\Services\\Ai\\ValueObjects\\AiExecutionPlan',
                'delete_allowed' => false,
                'merge_allowed_without_owner_decision' => false,
            ],
            'smokesubject' => [
                'priority' => 5,
                'decision' => 'keep_as_generated_fixture_or_exclude_from_production_duplicate_pressure',
                'safe_next_action' => 'document_fixture_generation_and_do_not_include_it_in_production_cleanup_queue',
                'rename_candidate' => null,
                'delete_allowed' => false,
                'merge_allowed_without_owner_decision' => false,
            ],
            default => [
                'priority' => 99,
                'decision' => 'owner_review_required',
                'safe_next_action' => 'run_reachability_for_every_path_and_update_owner_doc_before_cleanup',
                'rename_candidate' => null,
                'delete_allowed' => false,
                'merge_allowed_without_owner_decision' => false,
            ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function duplicateClassBoundaryContract(string $value): array
    {
        return match ($value) {
            'operationenvelope' => [
                'canonical_owner' => 'kernel_and_programming_boundaries',
                'primary_runtime' => 'app/Services/Ai/Kernel/Envelope/OperationEnvelope.php',
                'owner_docs' => [
                    'docs/engineering-knowledge-base/kernel/contracts.md',
                    'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-02.md',
                    'docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md',
                ],
                'schema_versions' => [
                    'kernel' => 'atlas.envelope.v1',
                    'atlas_dev' => 'atlas.dev.operation_envelope.v1',
                    'sdd_pipeline' => 'local_pipeline_dto_without_kernel_schema',
                ],
                'cleanup_sequence' => [
                    'keep_kernel_operation_envelope_name_and_schema_stable',
                    'keep_app_and_tests_importing_programming_variants_through_explicit_aliases',
                    'add_adapter_or_alias_tests_before_any_programming_variant_rename',
                    'rename_atlas_dev_variant_only_with_surface_adapter_migration',
                    'rename_sdd_variant_only_with_pipeline_adapter_migration',
                    'remove_compatibility_alias_only_after_all_exact_references_are_migrated',
                ],
                'current_migration_state' => $this->operationEnvelopeMigrationState(),
                'proposed_explicit_names' => [
                    'atlas_dev' => 'AtlasDevOperationEnvelope',
                    'sdd_pipeline' => 'SddPipelineOperationEnvelope',
                ],
                'adapter_boundary' => [
                    'atlas_dev_to_kernel' => 'requires_explicit_projection_not_shared_class_name',
                    'sdd_to_kernel' => 'requires_pipeline_result_adapter_not_kernel_envelope_import',
                ],
                'compatibility_aliases' => [
                    'atlas_dev' => 'app/Services/Ai/Programming/AtlasDev/Schemas/AtlasDevOperationEnvelope.php',
                    'sdd_pipeline' => 'app/Services/Ai/Programming/Sdd/Pipeline/SddPipelineOperationEnvelope.php',
                ],
                'specialized_variants' => [
                    'app/Services/Ai/Programming/AtlasDev/Schemas/OperationEnvelope.php',
                    'app/Services/Ai/Programming/Sdd/Pipeline/OperationEnvelope.php',
                ],
                'allowed_direction' => 'kernel_envelope_is_provider_safe_cross_runtime_contract; specialized_envelopes_must_stay_domain_local',
                'forbidden' => 'do_not_import_specialized_programming_envelope_as_kernel_contract_or_merge_without_adapter_plan',
            ],
            'verificationcommandrunner' => [
                'canonical_owner' => 'atlas_code_runner_vs_atlas_dev_gate',
                'primary_runtime' => 'app/Services/AtlasCode/VerificationCommandRunner.php',
                'owner_docs' => [
                    'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-06.md',
                    'docs/engineering-knowledge-base/atlas-dev-patamares.md',
                    'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
                ],
                'contract_features' => [
                    'atlas_code_runner' => [
                        'kind' => 'concrete_service',
                        'schema_version' => 'atlas.code.verification_run.v1',
                        'modes' => ['dry_run', 'execute'],
                        'guards' => ['workspace_check', 'allowlist', 'operator_override_token', 'timeout', 'evidence_persistence'],
                        'primary_consumers' => ['AtlasCodeObservedSessionController'],
                    ],
                    'atlas_dev_gate_runner' => [
                        'kind' => 'interface_contract',
                        'implementation' => 'App\\Services\\Ai\\Programming\\AtlasDev\\Gate\\SymfonyProcessCommandRunner',
                        'result_contract' => 'VerificationCommandResult',
                        'guards' => ['UnsafeCommandPolicy', 'workspace_check', 'timeout'],
                        'primary_consumers' => ['VerificationGate', 'PipelineRunExecutor', 'AtlasDevReadinessService'],
                    ],
                ],
                'specialized_variants' => [
                    'app/Services/Ai/Programming/AtlasDev/Gate/VerificationCommandRunner.php',
                ],
                'cleanup_sequence' => [
                    'keep_atlas_code_concrete_runner_schema_and_evidence_contract_stable',
                    'keep_app_and_tests_importing_runner_variants_through_explicit_aliases',
                    'add_observed_session_and_atlas_dev_gate_tests_before_any_rename',
                    'rename_atlas_dev_gate_interface_only_with_container_binding_and_fake_runner_migration',
                    'remove_compatibility_alias_only_after_all_exact_references_are_migrated',
                ],
                'current_migration_state' => $this->verificationCommandRunnerMigrationState(),
                'proposed_explicit_names' => [
                    'atlas_code' => 'AtlasCodeVerificationCommandRunner',
                    'atlas_dev_gate' => 'AtlasDevVerificationCommandRunnerContract',
                ],
                'adapter_boundary' => [
                    'atlas_code_to_atlas_dev_gate' => 'requires_explicit_adapter_not_short_name_typehint_swap',
                    'atlas_dev_gate_to_atlas_code' => 'requires_observed_session_evidence_adapter_not_interface_reuse',
                ],
                'compatibility_aliases' => [
                    'atlas_code' => 'app/Services/AtlasCode/AtlasCodeVerificationCommandRunner.php',
                    'atlas_dev_gate' => 'app/Services/Ai/Programming/AtlasDev/Gate/AtlasDevVerificationCommandRunnerContract.php',
                ],
                'allowed_direction' => 'atlas_code_concrete_runner_executes_verification; atlas_dev_gate_contract_describes_runner_boundary',
                'forbidden' => 'do_not_swap_interface_and_concrete_runner_by_short_class_name',
            ],
            'frontmatterparser' => [
                'canonical_owner' => 'semantic_docs_parser_vs_vault_parser',
                'primary_runtime' => 'app/Services/Semantic/FrontmatterParser.php',
                'owner_docs' => [
                    'docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md',
                    'docs/engineering-knowledge-base/obsidian-atlas-vault.md',
                    'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
                ],
                'contract_features' => [
                    'semantic_parser' => [
                        'purpose' => 'canonical_engineering_docs_parse_validate_build',
                        'returns_errors' => true,
                        'validates_required_doc_fields' => ['id', 'type', 'title', 'status', 'summary'],
                        'builds_markdown' => true,
                    ],
                    'vault_parser' => [
                        'purpose' => 'read_only_vault_note_and_cartography_shape_parse',
                        'returns_errors' => false,
                        'accepts_dotted_or_hyphenated_keys' => true,
                        'supports_gear_flow_object_lists' => true,
                        'strips_bom' => true,
                    ],
                ],
                'specialized_variants' => [
                    'app/Services/Vault/FrontmatterParser.php',
                ],
                'proposed_explicit_names' => [
                    'semantic_docs' => 'CanonicalDocsFrontmatterParser',
                    'vault_notes' => 'VaultNoteFrontmatterParser',
                ],
                'compatibility_aliases' => [
                    'semantic_docs' => 'app/Services/Semantic/CanonicalDocsFrontmatterParser.php',
                    'vault_notes' => 'app/Services/Vault/VaultNoteFrontmatterParser.php',
                ],
                'cleanup_sequence' => [
                    'keep_semantic_parser_as_canonical_docs_health_and_authority_parser',
                    'keep_vault_parser_as_read_only_vault_and_cartography_shape_parser',
                    'use_explicit_compatibility_aliases_for_new_code',
                    'add_docs_health_authority_vault_reader_and_cartography_tests_before_any_consolidation',
                    'consolidate_only_with_adapter_that_preserves_error_semantics_and_vault_shape_lists',
                    'remove_compatibility_alias_only_after_all_exact_references_are_migrated',
                ],
                'current_migration_state' => $this->frontmatterParserMigrationState(),
                'adapter_boundary' => [
                    'vault_to_canonical_docs' => 'requires_validation_adapter_that_returns_errors_and_enforces_required_fields',
                    'canonical_docs_to_vault' => 'requires_read_only_shape_adapter_that_preserves_vault_cartography_lists',
                ],
                'allowed_direction' => 'semantic_parser_governs_engineering_docs; vault_parser_reads_vault_note_shape',
                'forbidden' => 'do_not_use_vault_parser_as_canonical_engineering_doc_parser',
            ],
            'aiexecutionplan' => [
                'canonical_owner' => 'persistent_model_vs_prompt_value_object',
                'primary_runtime' => 'app/Models/AiExecutionPlan.php',
                'owner_docs' => [
                    'docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md',
                    'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
                ],
                'contract_features' => [
                    'persistent_model' => [
                        'kind' => 'eloquent_model',
                        'table' => 'ai_execution_plans',
                        'primary_consumers' => ['AtlasAutonomousEngineeringService'],
                        'casts' => ['steps', 'expected_files', 'expected_tests', 'risks', 'rollback_plan', 'compounding_memories', 'receipt'],
                        'schema_version_examples' => ['atlas.ai.autonomous_engineering.execution_plan.v1'],
                    ],
                    'prompt_value_object' => [
                        'kind' => 'prompt_runtime_value_object',
                        'factory' => 'AiExecutionPlan::fromTask',
                        'primary_consumers' => ['AiPromptBuilder', 'KernelArchitectureStaticScanner AP-149'],
                        'required_payload' => ['agent_behavior_contract', 'workflow', 'selected_provider', 'tools_allowed', 'quality_gates'],
                        'output_methods' => ['toArray', 'toPromptSection'],
                    ],
                ],
                'specialized_variants' => [
                    'app/Services/Ai/ValueObjects/AiExecutionPlan.php',
                ],
                'cleanup_sequence' => [
                    'keep_persistent_model_table_and_schema_stable',
                    'keep_app_and_tests_importing_execution_plan_variants_through_explicit_aliases',
                    'add_prompt_builder_and_autonomous_engineering_tests_before_any_rename',
                    'rename_prompt_value_object_only_with_prompt_builder_and_static_scanner_migration',
                    'remove_compatibility_alias_only_after_all_exact_references_are_migrated',
                ],
                'current_migration_state' => $this->aiExecutionPlanMigrationState(),
                'proposed_explicit_names' => [
                    'persistent_model' => 'PersistentAiExecutionPlan',
                    'prompt_value_object' => 'AiPromptExecutionPlan',
                ],
                'compatibility_aliases' => [
                    'persistent_model' => 'app/Models/PersistentAiExecutionPlan.php',
                    'prompt_value_object' => 'app/Services/Ai/ValueObjects/AiPromptExecutionPlan.php',
                ],
                'adapter_boundary' => [
                    'model_to_prompt_value_object' => 'requires_explicit_projection_from_persisted_plan_not_direct_type_reuse',
                    'prompt_value_object_to_model' => 'requires_database_model_creation_path_not_prompt_payload_typehint',
                ],
                'allowed_direction' => 'model_represents_database_contract; value_object_represents_prompt_runtime_payload',
                'forbidden' => 'do_not_typehint_value_object_when_database_model_contract_is_required',
            ],
            'smokesubject' => [
                'canonical_owner' => 'generated_smoke_fixture',
                'primary_runtime' => 'generated_fixture_inside_smoke_workspace',
                'owner_docs' => [
                    'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-01.md',
                    'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
                ],
                'contract_features' => [
                    'fixture_generators' => [
                        'kind' => 'generated_workspace_fixture',
                        'primary_consumers' => [
                            'AtlasDevSeniorLoopAuditCommand',
                            'AtlasDevDesktopRealSmokeCommand',
                            'AtlasDevSeniorLoopRunCommand',
                        ],
                        'generated_files' => ['src/SmokeSubject.php', 'tests/SmokeSubjectTest.php'],
                        'intent' => 'exercise_patch_apply_verification_and_desktop_smoke_flow_with_local_workspace_only',
                    ],
                    'production_boundary' => [
                        'kind' => 'non_production_fixture',
                        'namespace' => 'Smoke',
                        'persistence' => false,
                        'route_surface' => false,
                    ],
                ],
                'specialized_variants' => [
                    'app/Console/Commands/AtlasDevSeniorLoopAuditCommand.php',
                    'app/Console/Commands/AtlasDevDesktopRealSmokeCommand.php',
                    'app/Console/Commands/AtlasDevSeniorLoopRunCommand.php',
                ],
                'cleanup_sequence' => [
                    'keep_generator_commands_when_they_prove_distinct_smoke_flows',
                    'never_create_repo_production_class_for_smokesubject',
                    'only_extract_shared_fixture_template_after_atlas_dev_owner_decision',
                    'run_senior_loop_and_desktop_smoke_tests_before_generator_changes',
                ],
                'generator_write_boundary' => [
                    'writes_repo_production_code' => false,
                    'writes_local_smoke_workspace_only' => true,
                    'allowed_generated_paths' => ['src/SmokeSubject.php', 'tests/SmokeSubjectTest.php'],
                    'evidence_scope' => 'smoke_fixture_proves_patch_apply_and_verification_flow_not_atlas_feature_runtime',
                ],
                'allowed_direction' => 'generated_local_fixture_only',
                'forbidden' => 'do_not_treat_as_production_domain_class',
            ],
            default => [
                'canonical_owner' => 'owner_review_required',
                'primary_runtime' => 'owner_review_required',
                'specialized_variants' => [],
                'allowed_direction' => 'declare_before_new_code',
                'forbidden' => 'do_not_choose_by_short_class_name',
            ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function operationEnvelopeMigrationState(): array
    {
        $directAtlasDev = 'use App\\Services\\Ai\\Programming\\AtlasDev\\Schemas\\OperationEnvelope;';
        $directSdd = 'use App\\Services\\Ai\\Programming\\Sdd\\Pipeline\\OperationEnvelope;';
        $aliasAtlasDev = 'use App\\Services\\Ai\\Programming\\AtlasDev\\Schemas\\AtlasDevOperationEnvelope as OperationEnvelope;';
        $aliasSdd = 'use App\\Services\\Ai\\Programming\\Sdd\\Pipeline\\SddPipelineOperationEnvelope as OperationEnvelope;';

        $counts = [
            'app_and_tests_direct_programming_imports' => 0,
            'atlas_dev_alias_imports' => 0,
            'sdd_pipeline_alias_imports' => 0,
        ];

        foreach (['app', 'tests'] as $root) {
            foreach (File::allFiles(base_path($root)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                if (str_ends_with($file->getPathname(), 'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php')) {
                    continue;
                }

                $contents = File::get($file->getPathname());
                $counts['app_and_tests_direct_programming_imports'] += substr_count($contents, $directAtlasDev);
                $counts['app_and_tests_direct_programming_imports'] += substr_count($contents, $directSdd);
                $counts['atlas_dev_alias_imports'] += substr_count($contents, $aliasAtlasDev);
                $counts['sdd_pipeline_alias_imports'] += substr_count($contents, $aliasSdd);
            }
        }

        return [
            ...$counts,
            'remaining_exact_refs_are_boundary_docs_or_compatibility_alias_files' => $counts['app_and_tests_direct_programming_imports'] === 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function verificationCommandRunnerMigrationState(): array
    {
        $directAtlasCode = 'use App\\Services\\AtlasCode\\VerificationCommandRunner;';
        $directAtlasDevGate = 'use App\\Services\\Ai\\Programming\\AtlasDev\\Gate\\VerificationCommandRunner;';
        $aliasAtlasCode = 'use App\\Services\\AtlasCode\\AtlasCodeVerificationCommandRunner as VerificationCommandRunner;';
        $aliasAtlasDevGate = 'use App\\Services\\Ai\\Programming\\AtlasDev\\Gate\\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;';

        $counts = [
            'app_and_tests_direct_runner_imports' => 0,
            'atlas_code_alias_imports' => 0,
            'atlas_dev_gate_alias_imports' => 0,
        ];

        foreach (['app', 'tests'] as $root) {
            foreach (File::allFiles(base_path($root)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                if (str_ends_with($file->getPathname(), 'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php')) {
                    continue;
                }

                $contents = File::get($file->getPathname());
                $counts['app_and_tests_direct_runner_imports'] += substr_count($contents, $directAtlasCode);
                $counts['app_and_tests_direct_runner_imports'] += substr_count($contents, $directAtlasDevGate);
                $counts['atlas_code_alias_imports'] += substr_count($contents, $aliasAtlasCode);
                $counts['atlas_dev_gate_alias_imports'] += substr_count($contents, $aliasAtlasDevGate);
            }
        }

        return [
            ...$counts,
            'remaining_exact_refs_are_boundary_docs_or_compatibility_alias_files' => $counts['app_and_tests_direct_runner_imports'] === 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function frontmatterParserMigrationState(): array
    {
        $directSemantic = 'use App\\Services\\Semantic\\FrontmatterParser;';
        $directVault = 'use App\\Services\\Vault\\FrontmatterParser;';
        $aliasSemantic = 'use App\\Services\\Semantic\\CanonicalDocsFrontmatterParser;';
        $aliasVault = 'use App\\Services\\Vault\\VaultNoteFrontmatterParser;';

        $counts = [
            'app_and_tests_direct_parser_imports' => 0,
            'canonical_docs_alias_imports' => 0,
            'vault_note_alias_imports' => 0,
            'vault_note_local_typehints' => 0,
        ];

        foreach (['app', 'tests'] as $root) {
            foreach (File::allFiles(base_path($root)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                if (str_ends_with($file->getPathname(), 'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php')) {
                    continue;
                }

                $contents = File::get($file->getPathname());
                $counts['app_and_tests_direct_parser_imports'] += substr_count($contents, $directSemantic);
                $counts['app_and_tests_direct_parser_imports'] += substr_count($contents, $directVault);
                $counts['canonical_docs_alias_imports'] += substr_count($contents, $aliasSemantic);
                $counts['vault_note_alias_imports'] += substr_count($contents, $aliasVault);
                if ($root === 'app') {
                    $counts['vault_note_local_typehints'] += substr_count($contents, 'private readonly VaultNoteFrontmatterParser $parser');
                }
            }
        }

        return [
            ...$counts,
            'remaining_exact_refs_are_boundary_docs_or_compatibility_alias_files' => $counts['app_and_tests_direct_parser_imports'] === 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function aiExecutionPlanMigrationState(): array
    {
        $directModel = 'use App\\Models\\AiExecutionPlan;';
        $directPromptValueObject = 'use App\\Services\\Ai\\ValueObjects\\AiExecutionPlan;';
        $aliasModel = 'use App\\Models\\PersistentAiExecutionPlan as AiExecutionPlan;';
        $aliasPromptValueObject = 'use App\\Services\\Ai\\ValueObjects\\AiPromptExecutionPlan as AiExecutionPlan;';

        $counts = [
            'app_and_tests_direct_execution_plan_imports' => 0,
            'persistent_model_alias_imports' => 0,
            'prompt_value_object_alias_imports' => 0,
        ];

        foreach (['app', 'tests'] as $root) {
            foreach (File::allFiles(base_path($root)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = File::get($file->getPathname());
                $counts['app_and_tests_direct_execution_plan_imports'] += substr_count($contents, $directModel);
                $counts['app_and_tests_direct_execution_plan_imports'] += substr_count($contents, $directPromptValueObject);
                $counts['persistent_model_alias_imports'] += substr_count($contents, $aliasModel);
                $counts['prompt_value_object_alias_imports'] += substr_count($contents, $aliasPromptValueObject);
            }
        }

        return [
            ...$counts,
            'remaining_exact_refs_are_boundary_docs_or_compatibility_alias_files' => $counts['app_and_tests_direct_execution_plan_imports'] === 0,
        ];
    }
}
