<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Atlas Workspace Intelligence System runtime.
 *
 * Read-only first implementation of the AWIS family:
 * - AWIS: workspace binding/readiness.
 * - AWTR: workspace twin projection.
 * - ACIOS: long-conversation continuity without raw prompt stuffing.
 * - AWAF: operational artifacts.
 * - AWAIR: artifact intelligence, replay, simulation and visual projection.
 * - AWCO: artifact certification/readiness.
 * - AWEF: cross-workspace pattern hints without private transfer.
 */
final class AtlasWorkspaceIntelligenceRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.workspace_intelligence.runtime.v1';

    public const FAMILY = [
        'AWIS' => 'Atlas Workspace Intelligence System',
        'AWTR' => 'Atlas Workspace Twin Runtime',
        'ACIOS' => 'Atlas Continuity Intelligence OS',
        'AWAF' => 'Atlas Workspace Artifact Fabric',
        'AWAIR' => 'Atlas Workspace Artifact Intelligence Runtime',
        'AWCO' => 'Atlas Workspace Contract Orchestrator',
        'AWEF' => 'Atlas Workspace Evolution Fabric',
    ];

    public function __construct(
        private readonly AtlasCodeWorkspaceProfileService $profiles,
        private readonly AtlasWorkspaceExecutionBoundaryAuditService $boundaryAudit,
    ) {}

    /**
     * @param  array<int,string>  $conversationTexts
     * @return array<string,mixed>
     */
    public function certify(?string $workspace = null, string $task = '', array $conversationTexts = []): array
    {
        $profile = $this->resolveProfile($workspace);
        $workspaceReport = $this->workspaceReport($profile);
        $twin = $this->workspaceTwin($profile);
        $continuity = $this->continuity($profile, $conversationTexts);
        $artifacts = $this->artifacts($profile, $task, $twin, $continuity);
        $artifactIntelligence = $this->artifactIntelligence($profile, $task, $artifacts, $twin, $continuity);
        $contracts = $this->contracts($workspaceReport, $artifacts);
        $evolution = $this->evolution($profile, $twin);
        $executionBoundaries = $this->executionBoundarySummary($this->boundaryAudit->audit());
        $registryEditing = $this->registryEditingSummary();
        $surfaceContracts = $this->surfaceContractSummary();
        $checks = $this->checks($workspaceReport, $twin, $continuity, $artifacts, $artifactIntelligence, $contracts, $evolution, $executionBoundaries, $registryEditing, $surfaceContracts);
        $summary = $this->summary($checks);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now()->toISOString(),
            'status' => $this->status($summary),
            'summary' => $summary,
            'family' => self::FAMILY,
            'workspace' => $workspaceReport,
            'awtr' => $twin,
            'acios' => $continuity,
            'awaf' => $artifacts,
            'awair' => $artifactIntelligence,
            'awco' => $contracts,
            'awef' => $evolution,
            'execution_boundaries' => $executionBoundaries,
            'registry_editing' => $registryEditing,
            'surface_contracts' => $surfaceContracts,
            'checks' => $checks,
            'claim_policy' => [
                'read_only' => true,
                'invokes_provider' => false,
                'transfers_raw_cross_workspace' => false,
                'raw_conversation_used_as_prompt' => false,
                'known_execution_boundaries_audited' => data_get($executionBoundaries, 'status') === 'ready',
                'unclassified_workspace_process_boundaries_allowed' => false,
                'execution_boundary_audit_required' => true,
                'ui_registry_editing_complete' => data_get($registryEditing, 'status') === 'ready',
                'desktop_mobile_surface_contracts_complete' => data_get($surfaceContracts, 'status') === 'ready',
            ],
        ];

        $payload['runtime_hash'] = $this->hashWithoutGeneratedAt($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function twin(?string $workspace = null): array
    {
        return $this->workspaceTwin($this->resolveProfile($workspace));
    }

    /**
     * @return array<string,mixed>
     */
    public function contractOrchestration(?string $workspace = null, string $task = ''): array
    {
        $profile = $this->resolveProfile($workspace);
        $workspaceReport = $this->workspaceReport($profile);
        $twin = $this->workspaceTwin($profile);
        $continuity = $this->continuity($profile, []);
        $artifacts = $this->artifacts($profile, $task, $twin, $continuity);

        return $this->contracts($workspaceReport, $artifacts);
    }

    /**
     * @return array<string,mixed>
     */
    public function evolutionFabric(?string $workspace = null): array
    {
        $profile = $this->resolveProfile($workspace);

        return $this->evolution($profile, $this->workspaceTwin($profile));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveProfile(?string $workspace): ?array
    {
        $slug = is_string($workspace) && trim($workspace) !== ''
            ? trim($workspace)
            : $this->profiles->defaultSlug();

        return $this->profiles->findBySlug($slug) ?? $this->profiles->findByPath($slug);
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @return array<string,mixed>
     */
    private function workspaceReport(?array $profile): array
    {
        if ($profile === null) {
            return [
                'schema_version' => 'atlas.awis.workspace_binding.v1',
                'status' => 'blocked',
                'workspace_id' => null,
                'workspace_active' => false,
                'readiness_status' => 'blocked',
                'blockers' => ['workspace_not_registered'],
            ];
        }

        $path = (string) ($profile['workspace_path'] ?? '');
        $exists = (bool) ($profile['workspace_path_exists'] ?? false);
        $rootHash = $exists ? $this->workspaceRootHash($path) : null;
        $blockers = [];
        if (! $exists) {
            $blockers[] = 'workspace_path_missing_or_inaccessible';
        }
        if ((array) ($profile['test_commands'] ?? []) === []) {
            $blockers[] = 'workspace_test_commands_missing';
        }

        return [
            'schema_version' => 'atlas.awis.workspace_binding.v1',
            'status' => $blockers === [] ? 'ready' : 'limited',
            'workspace_id' => (string) $profile['slug'],
            'workspace_name' => (string) $profile['name'],
            'workspace_active' => true,
            'workspace_path_exists' => $exists,
            'workspace_hash' => $rootHash,
            'memory_scope' => 'workspace',
            'cartography_scope' => 'workspace',
            'readiness_status' => $blockers === [] ? 'ready' : 'limited',
            'blockers' => $blockers,
            'commands_count' => count((array) ($profile['commands'] ?? [])),
            'test_commands_count' => count((array) ($profile['test_commands'] ?? [])),
            'critical_areas_count' => count((array) ($profile['critical_areas'] ?? [])),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @return array<string,mixed>
     */
    private function workspaceTwin(?array $profile): array
    {
        if ($profile === null) {
            return [
                'schema_version' => 'atlas.workspace_twin.v1',
                'status' => 'blocked',
                'blockers' => ['workspace_not_registered'],
            ];
        }

        $path = (string) ($profile['workspace_path'] ?? '');
        $exists = (bool) ($profile['workspace_path_exists'] ?? false);
        $stack = $this->detectStack($path, (string) ($profile['stack_summary'] ?? ''));
        $docs = $this->ownerDocs($path);
        $commands = array_values(array_filter(array_merge(
            array_values((array) ($profile['commands'] ?? [])),
            (array) ($profile['test_commands'] ?? []),
            (array) ($profile['build_commands'] ?? []),
        ), 'is_string'));

        $genome = [
            'schema_version' => 'atlas.workspace_genome.v1',
            'workspace_id' => (string) $profile['slug'],
            'stack' => $stack,
            'docs_status' => (string) ($profile['docs_status'] ?? 'unknown'),
            'production_status' => (string) ($profile['production_status'] ?? 'unknown'),
            'risk_floor' => (string) data_get($profile, 'safety.risk_floor', $profile['default_risk'] ?? 'medium'),
            'owner_docs' => $docs,
            'test_families' => array_values((array) ($profile['test_commands'] ?? [])),
            'risk_zones' => array_values((array) ($profile['critical_areas'] ?? [])),
        ];
        $genome['genome_hash'] = MissionCanonicalHash::sha256($genome);

        $livingCodeMap = [
            'schema_version' => 'atlas.workspace_living_code_map.v1',
            'root_exists' => $exists,
            'owner_docs' => $docs,
            'critical_areas' => array_values((array) ($profile['critical_areas'] ?? [])),
            'source_policy' => 'repo_docs_code_tests_and_receipts_only',
        ];
        $livingCodeMap['code_map_hash'] = MissionCanonicalHash::sha256($livingCodeMap);

        $commandRegistry = [
            'schema_version' => 'atlas.workspace_command_registry.v1',
            'commands' => $commands,
            'command_count' => count($commands),
            'has_test_entrypoint' => (array) ($profile['test_commands'] ?? []) !== [],
        ];
        $commandRegistry['command_registry_hash'] = MissionCanonicalHash::sha256($commandRegistry);

        $riskMap = $this->riskMap($profile);
        $riskMap['risk_map_hash'] = MissionCanonicalHash::sha256($riskMap);

        $payload = [
            'schema_version' => 'atlas.workspace_twin.v1',
            'status' => $exists ? 'ready' : 'limited',
            'workspace_id' => (string) $profile['slug'],
            'genome' => $genome,
            'living_code_map' => $livingCodeMap,
            'context_autopilot' => [
                'schema_version' => 'atlas.workspace_context_autopilot.v1',
                'strategy' => 'owner_docs_plus_task_artifacts_plus_focused_tests',
                'required_context_units' => ['workspace_brief', 'task_packet', 'context_pack', 'test_plan', 'risk_sheet'],
                'raw_conversation_policy' => 'hash_and_excerpt_only',
                'stale_policy' => 'block_mutative_execution_when_workspace_or_twin_not_ready',
            ],
            'test_command_intelligence' => [
                'schema_version' => 'atlas.workspace_test_command_intelligence.v1',
                'commands' => array_values((array) ($profile['test_commands'] ?? [])),
                'has_focused_entrypoint' => (array) ($profile['test_commands'] ?? []) !== [],
                'fallback_policy' => 'block_or_request_operator_test_command_when_missing',
            ],
            'command_registry' => $commandRegistry,
            'risk_fragility_map' => $riskMap,
            'provider_skill_memory' => [
                'schema_version' => 'atlas.workspace_provider_skill_memory.v1',
                'status' => 'shadow',
                'decision_policy' => 'never_route_provider_from_unverified_preference',
            ],
            'workspace_learning_loop' => [
                'schema_version' => 'atlas.workspace_learning_loop.v1',
                'status' => 'ready_for_outcome_bridge',
                'feeds' => ['AEMOR', 'AWEF', 'workspace_runbook'],
            ],
            'stale' => false,
        ];
        $payload['twin_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<int,string>  $conversationTexts
     * @return array<string,mixed>
     */
    private function continuity(?array $profile, array $conversationTexts): array
    {
        $segments = [];
        $decisions = [];
        $blockers = [];
        foreach ($conversationTexts as $index => $text) {
            $clean = trim($text);
            if ($clean === '') {
                continue;
            }
            $segments[] = [
                'source' => 'conversation_'.$index,
                'source_hash' => hash('sha256', $clean),
                'excerpt' => mb_substr(preg_replace('/\s+/', ' ', $clean) ?? $clean, 0, 180),
            ];
            if (preg_match_all('/\b(decidido|decisao|decision|bloqueio|blocker|feito|done)\b/iu', $clean, $matches)) {
                foreach ($matches[1] as $match) {
                    $lower = mb_strtolower((string) $match);
                    if (str_contains($lower, 'bloque') || str_contains($lower, 'blocker')) {
                        $blockers[] = 'conversation_mentions_blocker';
                    } else {
                        $decisions[] = 'conversation_mentions_'.$lower;
                    }
                }
            }
        }

        $truthPack = [
            'workspace_id' => $profile['slug'] ?? null,
            'current_goal' => null,
            'active_decisions' => array_values(array_unique($decisions)),
            'open_blockers' => array_values(array_unique($blockers)),
            'canonical_sources' => [
                'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
                'docs/engineering-knowledge-base/atlas-continuity-intelligence-os.md',
            ],
            'raw_conversation_in_prompt' => false,
        ];

        $payload = [
            'schema_version' => 'atlas.continuity_intelligence.v1',
            'status' => $profile === null ? 'blocked' : 'ready',
            'workspace_id' => $profile['slug'] ?? null,
            'raw_archive' => [
                'conversation_count' => count($conversationTexts),
                'archive_hash' => MissionCanonicalHash::sha256(array_map(
                    static fn (string $text): string => hash('sha256', $text),
                    $conversationTexts,
                )),
                'access_mode' => 'audit_only',
            ],
            'segmentation_map' => $segments,
            'decision_ledger' => array_values(array_unique($decisions)),
            'conflict_report' => [],
            'current_truth_pack' => $truthPack,
            'task_context_pack_policy' => [
                'uses_raw_conversation' => false,
                'uses_hash_refs' => true,
                'requires_workspace' => true,
            ],
        ];
        $payload['continuity_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<string,mixed>  $twin
     * @param  array<string,mixed>  $continuity
     * @return array<string,mixed>
     */
    private function artifacts(?array $profile, string $task, array $twin, array $continuity): array
    {
        $workspaceId = $profile['slug'] ?? null;
        $task = trim($task) !== '' ? trim($task) : 'workspace readiness and context preparation';

        $artifacts = [
            $this->artifact('workspace_brief', $workspaceId, [
                'summary' => (string) ($profile['stack_summary'] ?? 'workspace unavailable'),
                'stack' => data_get($twin, 'genome.stack', []),
                'risk_floor' => data_get($twin, 'genome.risk_floor'),
            ]),
            $this->artifact('task_packet', $workspaceId, [
                'task' => $task,
                'scope' => 'workspace_scoped',
                'likely_areas' => data_get($twin, 'risk_fragility_map.sensitive_areas', []),
                'definition_of_done' => ['tests selected', 'artifact certified', 'outcome recorded'],
            ]),
            $this->artifact('context_pack', $workspaceId, [
                'sources' => data_get($continuity, 'current_truth_pack.canonical_sources', []),
                'raw_conversation_included' => false,
            ]),
            $this->artifact('execution_plan', $workspaceId, [
                'steps' => ['inspect', 'patch_or_plan', 'run_focused_tests', 'record_outcome'],
                'mutative_execution_requires_certified_contracts' => true,
            ]),
            $this->artifact('test_plan', $workspaceId, [
                'focused_tests' => data_get($twin, 'test_command_intelligence.commands', []),
                'skip_reason' => data_get($twin, 'test_command_intelligence.commands') === [] ? 'no_test_commands_registered' : null,
            ]),
            $this->artifact('risk_sheet', $workspaceId, [
                'risk_floor' => data_get($twin, 'genome.risk_floor'),
                'sensitive_areas' => data_get($twin, 'risk_fragility_map.sensitive_areas', []),
            ]),
            $this->artifact('handoff_packet', $workspaceId, [
                'provider_safe' => true,
                'raw_conversation_included' => false,
                'allowed_context' => ['workspace_brief', 'task_packet', 'context_pack', 'test_plan', 'risk_sheet'],
            ]),
            $this->artifact('failure_capsule', $workspaceId, [
                'status' => 'empty_until_failure',
                'captures' => ['minimal_error', 'likely_cause', 'suspect_files', 'next_attempt'],
            ]),
            $this->artifact('outcome_record', $workspaceId, [
                'status' => 'pending',
                'records_future_run' => true,
            ]),
            $this->artifact('workspace_runbook', $workspaceId, [
                'commands' => data_get($twin, 'command_registry', []),
                'risk_floor' => data_get($twin, 'genome.risk_floor'),
                'docs' => data_get($twin, 'living_code_map.owner_docs', []),
            ]),
        ];

        return [
            'schema_version' => 'atlas.workspace_artifact_fabric.v1',
            'status' => $profile === null ? 'blocked' : 'ready',
            'workspace_id' => $workspaceId,
            'artifacts' => $artifacts,
            'artifact_count' => count($artifacts),
            'artifact_fabric_hash' => MissionCanonicalHash::sha256($artifacts),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<string,mixed>  $artifactFabric
     * @param  array<string,mixed>  $twin
     * @param  array<string,mixed>  $continuity
     * @return array<string,mixed>
     */
    private function artifactIntelligence(?array $profile, string $task, array $artifactFabric, array $twin, array $continuity): array
    {
        $artifacts = array_values(array_filter(
            (array) ($artifactFabric['artifacts'] ?? []),
            'is_array',
        ));
        $workspaceId = $profile['slug'] ?? null;
        $nodes = array_map(
            static fn (array $artifact): array => [
                'id' => (string) ($artifact['artifact_hash'] ?? ''),
                'type' => (string) ($artifact['artifact_type'] ?? 'unknown'),
                'status' => (string) ($artifact['status'] ?? 'unknown'),
                'consumer' => match ((string) ($artifact['artifact_type'] ?? '')) {
                    'task_packet', 'context_pack', 'test_plan', 'risk_sheet' => 'atlas_dev',
                    'workspace_brief', 'execution_plan', 'handoff_packet', 'outcome_record' => 'atlas_forge',
                    'failure_capsule' => 'repair_loop',
                    'workspace_runbook' => 'cartography_and_human',
                    default => 'unknown',
                },
            ],
            $artifacts,
        );

        $edges = [];
        $byType = collect($artifacts)->keyBy('artifact_type');
        foreach ([
            ['workspace_brief', 'task_packet', 'informs'],
            ['task_packet', 'context_pack', 'requires'],
            ['task_packet', 'test_plan', 'requires'],
            ['task_packet', 'risk_sheet', 'requires'],
            ['context_pack', 'handoff_packet', 'projects'],
            ['test_plan', 'execution_plan', 'validates'],
            ['risk_sheet', 'execution_plan', 'guards'],
            ['failure_capsule', 'outcome_record', 'feeds'],
            ['outcome_record', 'workspace_runbook', 'updates'],
        ] as [$from, $to, $relation]) {
            $fromArtifact = $byType->get($from);
            $toArtifact = $byType->get($to);
            if (is_array($fromArtifact) && is_array($toArtifact)) {
                $edges[] = [
                    'from' => (string) ($fromArtifact['artifact_hash'] ?? ''),
                    'to' => (string) ($toArtifact['artifact_hash'] ?? ''),
                    'relation' => $relation,
                ];
            }
        }

        $quality = array_map(function (array $artifact): array {
            $hasWorkspace = ($artifact['workspace_id'] ?? null) !== null;
            $hasSources = (array) ($artifact['source_hashes'] ?? []) !== [];

            return [
                'artifact_type' => (string) ($artifact['artifact_type'] ?? 'unknown'),
                'artifact_hash' => (string) ($artifact['artifact_hash'] ?? ''),
                'coverage' => $hasWorkspace && $hasSources ? 1.0 : 0.0,
                'freshness' => $hasWorkspace ? 'ready' : 'blocked',
                'source_integrity' => $hasSources ? 'ready' : 'blocked',
                'consumer_fit' => ($artifact['status'] ?? null) === 'blocked' ? 'blocked' : 'ready',
                'quality_score' => $hasWorkspace && $hasSources ? 0.98 : 0.0,
            ];
        }, $artifacts);

        $payload = [
            'schema_version' => 'atlas.workspace_artifact_intelligence.v1',
            'status' => $workspaceId === null ? 'blocked' : 'ready',
            'workspace_id' => $workspaceId,
            'workspace_hash' => $this->artifactWorkspaceHash($profile),
            'artifact_lake' => [
                'schema_version' => 'atlas.workspace_artifact_lake.v1',
                'artifact_count' => count($artifacts),
                'certifiable_artifacts' => count(array_filter($artifacts, static fn (array $artifact): bool => ($artifact['workspace_id'] ?? null) !== null)),
                'lake_hash' => MissionCanonicalHash::sha256($artifacts),
            ],
            'artifact_graph' => [
                'schema_version' => 'atlas.workspace_artifact_graph.v1',
                'nodes' => $nodes,
                'edges' => $edges,
                'graph_hash' => MissionCanonicalHash::sha256([$nodes, $edges]),
            ],
            'artifact_branching' => [
                'schema_version' => 'atlas.workspace_artifact_branching.v1',
                'branches' => [
                    ['id' => 'minimal_patch', 'risk' => data_get($twin, 'genome.risk_floor', 'medium')],
                    ['id' => 'forge_escalation', 'risk' => 'controlled_high'],
                ],
            ],
            'artifact_replay' => [
                'schema_version' => 'atlas.workspace_artifact_replay.v1',
                'replay_ready' => $workspaceId !== null && count($artifacts) >= 10,
                'required_inputs' => ['workspace_id', 'artifact_hash', 'source_hashes', 'task_packet', 'context_pack', 'test_plan'],
                'raw_conversation_required' => false,
            ],
            'artifact_simulation' => [
                'schema_version' => 'atlas.workspace_artifact_simulation.v1',
                'decision' => $workspaceId === null ? 'blocked' : 'ready',
                'blockers' => $workspaceId === null ? ['workspace_not_registered'] : [],
                'likely_areas' => data_get($twin, 'risk_fragility_map.sensitive_areas', []),
                'escalate_to_forge_when' => ['multi_domain', 'high_uncertainty', 'repeated_failure'],
            ],
            'artifact_context_compiler' => [
                'schema_version' => 'atlas.workspace_artifact_context_compiler.v1',
                'task' => trim($task) !== '' ? trim($task) : 'workspace readiness and context preparation',
                'context_units' => ['workspace_brief', 'task_packet', 'context_pack', 'test_plan', 'risk_sheet'],
                'raw_conversation_included' => false,
                'current_truth_pack_hash' => MissionCanonicalHash::sha256(data_get($continuity, 'current_truth_pack', [])),
            ],
            'artifact_quality_governor' => [
                'schema_version' => 'atlas.workspace_artifact_quality_governor.v1',
                'quality' => $quality,
                'minimum_executable_score' => 0.95,
                'all_executable_artifacts_ready' => collect($quality)->every(fn (array $item): bool => (float) $item['quality_score'] >= 0.95),
            ],
            'artifact_cartography_projection' => [
                'schema_version' => 'atlas.workspace_artifact_cartography_projection.v1',
                'visual_layers' => ['workspace', 'artifact_graph', 'stale_nodes', 'blockers', 'provider_handoff'],
                'text_policy' => 'modal_only_for_details',
                'human_scan_mode' => 'graph_first',
            ],
            'artifact_marketplace' => [
                'schema_version' => 'atlas.workspace_artifact_marketplace.v1',
                'privacy_policy' => 'patterns_only_no_raw_cross_workspace',
                'reusable_templates' => ['login_test_plan', 'auth_risk_sheet', 'provider_handoff_packet'],
            ],
            'artifact_outcome_learning' => [
                'schema_version' => 'atlas.workspace_artifact_outcome_learning.v1',
                'feeds' => ['AEMOR', 'AWEF', 'workspace_runbook'],
                'requires_real_outcome' => true,
            ],
        ];
        $payload['artifact_intelligence_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>|null  $profile
     */
    private function artifactWorkspaceHash(?array $profile): ?string
    {
        if ($profile === null || ! (bool) ($profile['workspace_path_exists'] ?? false)) {
            return null;
        }

        return $this->workspaceRootHash((string) ($profile['workspace_path'] ?? ''));
    }

    /**
     * @param  array<string,mixed>  $workspaceReport
     * @param  array<string,mixed>  $artifactFabric
     * @return array<string,mixed>
     */
    private function contracts(array $workspaceReport, array $artifactFabric): array
    {
        $certifications = [];
        foreach ((array) ($artifactFabric['artifacts'] ?? []) as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }
            $blockers = [];
            if (($workspaceReport['workspace_active'] ?? false) !== true) {
                $blockers[] = 'workspace_not_active';
            }
            if (($artifact['workspace_id'] ?? null) === null) {
                $blockers[] = 'artifact_missing_workspace_id';
            }
            if ((array) ($artifact['source_hashes'] ?? []) === []) {
                $blockers[] = 'artifact_missing_source_hashes';
            }

            $certifications[] = [
                'schema_version' => 'atlas.workspace_artifact_certification.v1',
                'artifact_type' => (string) ($artifact['artifact_type'] ?? 'unknown'),
                'artifact_hash' => (string) ($artifact['artifact_hash'] ?? ''),
                'status' => $blockers === [] ? 'certified' : 'blocked',
                'blocking_reasons' => $blockers,
            ];
        }

        $blocked = array_values(array_filter(
            $certifications,
            static fn (array $cert): bool => ($cert['status'] ?? null) !== 'certified',
        ));

        $payload = [
            'schema_version' => 'atlas.workspace_contract_orchestrator.v1',
            'status' => $blocked === [] ? 'ready' : 'blocked',
            'certification_envelope' => [
                'schema_version' => 'atlas.workspace_contract_certification_envelope.v1',
                'artifact_count' => count($certifications),
                'certified_count' => count($certifications) - count($blocked),
                'blocked_count' => count($blocked),
                'quality_policy' => 'all_artifacts_must_have_workspace_and_source_hashes',
            ],
            'certifications' => $certifications,
            'blocked_artifacts' => $blocked,
            'blocked_count' => count($blocked),
            'execution_readiness_status' => $blocked === [] ? 'ready' : 'blocked',
            'versioning_policy' => [
                'schema_version' => 'atlas.workspace_contract_versioning_policy.v1',
                'version_source' => 'artifact_hash_plus_workspace_hash',
                'reissue_required_when' => ['workspace_hash_changes', 'artifact_hash_changes', 'source_hashes_change'],
            ],
            'invalidation_rules' => [
                'schema_version' => 'atlas.workspace_contract_invalidation_rules.v1',
                'block_when' => ['missing_workspace_id', 'missing_source_hashes', 'workspace_not_active', 'artifact_stale'],
                'never_autocertify_from' => ['raw_conversation', 'provider_freeform_text', 'cartography_visual_only'],
            ],
            'orchestration_plan' => [
                'schema_version' => 'atlas.workspace_contract_orchestration_plan.v1',
                'mutative_consumers' => ['atlas_dev', 'atlas_forge', 'provider_invocation', 'subagent_handoff'],
                'required_before_provider' => ['workspace_binding', 'artifact_certification', 'shadow_execution'],
            ],
        ];
        $payload['contract_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<string,mixed>  $twin
     * @return array<string,mixed>
     */
    private function evolution(?array $profile, array $twin): array
    {
        $patterns = [];
        if (in_array('laravel', (array) data_get($twin, 'genome.stack', []), true)) {
            $patterns[] = [
                'pattern_id' => 'laravel_artisan_test_gate',
                'privacy_level' => 'abstracted',
                'applies_to' => ['php', 'laravel'],
                'evidence_refs' => ['workspace.test_commands'],
            ];
        }
        if (in_array('typescript', (array) data_get($twin, 'genome.stack', []), true)) {
            $patterns[] = [
                'pattern_id' => 'typescript_typecheck_before_release',
                'privacy_level' => 'abstracted',
                'applies_to' => ['typescript'],
                'evidence_refs' => ['workspace.build_commands'],
            ];
        }

        $patternLibrary = [
            'schema_version' => 'atlas.workspace_pattern_library.v1',
            'patterns' => $patterns,
            'pattern_count' => count($patterns),
            'privacy_level' => 'abstracted_only',
        ];
        $failureSignatureBank = [
            'schema_version' => 'atlas.workspace_failure_signature_bank.v1',
            'signatures' => [
                [
                    'signature_id' => 'stale_workspace_context',
                    'avoidance_policy' => ['refresh_awis', 'rebuild_twin', 'regenerate_artifacts'],
                    'confidence' => 0.8,
                    'evidence_refs' => ['awis.execution_gate'],
                ],
            ],
        ];

        $payload = [
            'schema_version' => 'atlas.workspace_evolution_fabric.v1',
            'status' => $profile === null ? 'blocked' : 'ready',
            'workspace_id' => $profile['slug'] ?? null,
            'privacy_preserving_transfer' => true,
            'pattern_library' => $patternLibrary,
            'failure_signature_bank' => $failureSignatureBank,
            'privacy_transfer_gate' => [
                'schema_version' => 'atlas.workspace_privacy_transfer_gate.v1',
                'allows_raw_cross_workspace' => false,
                'allowed_transfer_units' => ['abstract_pattern', 'failure_signature', 'test_strategy', 'runbook_shape'],
                'block_when' => ['raw_context_present', 'workspace_specific_secret', 'customer_data_present'],
            ],
            'workspace_benchmark' => [
                'schema_version' => 'atlas.workspace_benchmark_shadow.v1',
                'mode' => 'read_only_shadow',
                'signals' => ['has_tests', 'has_owner_docs', 'has_awis_gate', 'has_artifact_graph'],
                'score_basis' => 'structural_evidence_only',
            ],
        ];
        $payload['evolution_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function checks(array ...$sections): array
    {
        $checks = [
            $this->check('awis_workspace_binding', data_get($sections, '0.workspace_active') === true, 'critical'),
            $this->check('awis_workspace_readiness', data_get($sections, '0.readiness_status') === 'ready', 'critical'),
            $this->check('awtr_workspace_twin', data_get($sections, '1.twin_hash') !== null, 'critical'),
            $this->check('acios_no_raw_conversation_prompt', data_get($sections, '2.task_context_pack_policy.uses_raw_conversation') === false, 'critical'),
            $this->check('awaf_artifacts_generated', (int) data_get($sections, '3.artifact_count', 0) >= 10, 'critical'),
            $this->check('awair_artifact_intelligence_ready', data_get($sections, '4.artifact_replay.replay_ready') === true, 'critical'),
            $this->check('awco_artifacts_certified', data_get($sections, '5.execution_readiness_status') === 'ready', 'critical'),
            $this->check('awef_privacy_preserving_transfer', data_get($sections, '6.privacy_preserving_transfer') === true, 'critical'),
            $this->check('awis_execution_boundaries_audited', data_get($sections, '7.status') === 'ready'
                && (int) data_get($sections, '7.process_inventory_unclassified', 1) === 0, 'critical'),
            $this->check('awis_registry_editing_contract_complete', data_get($sections, '8.status') === 'ready', 'critical'),
            $this->check('awis_desktop_mobile_surface_contracts_complete', data_get($sections, '9.status') === 'ready', 'critical'),
        ];

        return $checks;
    }

    /**
     * @return array<string,mixed>
     */
    private function surfaceContractSummary(): array
    {
        $desktopSurfacePath = base_path('../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/AtlasAiSurface.tsx');
        $desktopPickerPath = base_path('../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiWorkspacePicker.tsx');
        $desktopThreadListPath = base_path('../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiThreadList.tsx');
        $desktopFusionTestPath = base_path('../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/__tests__/conversationFusionContract.test.ts');
        $desktopSelectorTestPath = base_path('../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/__tests__/workspaceSelectorContract.test.ts');
        $mobileModelPath = base_path('../atlas-app/components/sheets/atlas-ai/AtlasAiWorkspaceModel.ts');
        $mobileSelectorModelPath = base_path('../atlas-app/components/sheets/atlas-ai/AtlasAiMobileWorkspaceModel.ts');
        $mobileSelectorSheetPath = base_path('../atlas-app/components/sheets/atlas-ai/AtlasAiWorkspaceSheet.tsx');
        $mobileSheetPath = base_path('../atlas-app/components/sheets/AtlasAiSheet.tsx');
        $mobileFooterPath = base_path('../atlas-app/components/sheets/atlas-ai/AtlasAiComposerFooter.tsx');
        $mobileContextPath = base_path('../atlas-app/components/sheets/atlas-ai/AtlasAiContextSheet.tsx');
        $mobileTestPath = base_path('../atlas-app/scripts/atlas-ai-workspace-context.test.ts');
        $mobileSelectorTestPath = base_path('../atlas-app/scripts/atlas-ai-mobile-workspace-selector.test.ts');

        $desktopSurface = File::exists($desktopSurfacePath) ? (string) File::get($desktopSurfacePath) : '';
        $desktopPicker = File::exists($desktopPickerPath) ? (string) File::get($desktopPickerPath) : '';
        $desktopThreadList = File::exists($desktopThreadListPath) ? (string) File::get($desktopThreadListPath) : '';
        $desktopFusionTest = File::exists($desktopFusionTestPath) ? (string) File::get($desktopFusionTestPath) : '';
        $desktopSelectorTest = File::exists($desktopSelectorTestPath) ? (string) File::get($desktopSelectorTestPath) : '';
        $mobileModel = File::exists($mobileModelPath) ? (string) File::get($mobileModelPath) : '';
        $mobileSelectorModel = File::exists($mobileSelectorModelPath) ? (string) File::get($mobileSelectorModelPath) : '';
        $mobileSelectorSheet = File::exists($mobileSelectorSheetPath) ? (string) File::get($mobileSelectorSheetPath) : '';
        $mobileSheet = File::exists($mobileSheetPath) ? (string) File::get($mobileSheetPath) : '';
        $mobileFooter = File::exists($mobileFooterPath) ? (string) File::get($mobileFooterPath) : '';
        $mobileContext = File::exists($mobileContextPath) ? (string) File::get($mobileContextPath) : '';
        $mobileTest = File::exists($mobileTestPath) ? (string) File::get($mobileTestPath) : '';
        $mobileSelectorTest = File::exists($mobileSelectorTestPath) ? (string) File::get($mobileSelectorTestPath) : '';

        $requirements = [
            'desktop_workspace_picker_present' => str_contains($desktopSurface, '<AtlasAiWorkspacePicker'),
            'desktop_last_project_selector_tested' => str_contains($desktopSelectorTest, 'last persisted project')
                && str_contains($desktopSelectorTest, 'persistWorkspaceSlug'),
            'desktop_workspace_lock_present' => str_contains($desktopSurface, 'workspaceLock') && str_contains($desktopSurface, 'effectiveWorkspaceSlug'),
            'desktop_picker_search_and_create_present' => str_contains($desktopPicker, 'Pesquisar projetos') && str_contains($desktopPicker, "onOpenWorkspaceProfile?.('create')"),
            'desktop_drag_merge_room_present' => str_contains($desktopThreadList, 'atlas-ai-conversation-merge-room') && str_contains($desktopThreadList, 'Solte em outra conversa para criar um pack AWIS'),
            'desktop_drag_fusion_persists_artifact' => str_contains($desktopSurface, 'refreshConversationFusion(threadIds, { persist: true })') && str_contains($desktopFusionTest, 'Drag thread-to-thread fusion'),
            'mobile_workspace_model_present' => str_contains($mobileModel, 'workspaceContextFromThreadAndTrace'),
            'mobile_context_sheet_awis_present' => str_contains($mobileContext, 'workspace AWIS') && str_contains($mobileContext, 'fixo nesta conversa'),
            'mobile_workspace_context_tested' => str_contains($mobileTest, 'Mobile ContextSheet must expose AWIS workspace scope'),
            'mobile_workspace_selector_present' => str_contains($mobileSheet, 'listAtlasWorkspaceProfiles')
                && str_contains($mobileFooter, 'workspaceLabel')
                && str_contains($mobileSheet, '<AtlasAiWorkspaceSheet')
                && str_contains($mobileSelectorSheet, 'Escolher projeto'),
            'mobile_workspace_selector_search_present' => str_contains($mobileSelectorSheet, 'TextInput')
                && str_contains($mobileSelectorSheet, 'workspacePickerOptions'),
            'mobile_workspace_create_present' => str_contains($mobileSheet, 'createAtlasWorkspaceProfile')
                && str_contains($mobileSelectorSheet, 'ADICIONAR NOVO PROJETO')
                && str_contains($mobileSelectorTest, 'Mobile Atlas AI must create workspace profiles from the selector'),
            'mobile_workspace_lock_payload_present' => str_contains($mobileSheet, 'mobileWorkspacePayload(mobileWorkspaceLock)')
                && str_contains($mobileSelectorModel, 'atlas.mobile_ai.workspace_scope.v1'),
            'mobile_workspace_selector_tested' => str_contains($mobileSelectorTest, 'Mobile Atlas AI must fetch workspace profiles from backend')
                && str_contains($mobileSelectorTest, 'Mobile Atlas AI submit must use locked workspace slug'),
        ];
        $missing = array_keys(array_filter($requirements, static fn (bool $ok): bool => ! $ok));

        $payload = [
            'schema_version' => 'atlas.workspace_intelligence.surface_contracts.v1',
            'status' => $missing === [] ? 'ready' : 'blocked',
            'requirements' => $requirements,
            'missing' => $missing,
            'surface_policy' => [
                'desktop_conversation_workspace_mutation_allowed_after_start' => false,
                'desktop_drag_thread_to_thread_creates_awis_pack' => true,
                'mobile_context_sheet_must_show_awis_scope' => true,
                'mobile_conversation_workspace_mutation_allowed_after_start' => false,
                'mobile_submit_must_emit_awis_workspace_scope' => true,
            ],
        ];
        $payload['surface_contract_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function registryEditingSummary(): array
    {
        $routePath = base_path('routes/api.php');
        $controllerPath = app_path('Http/Controllers/AtlasCodeWorkspaceController.php');
        $servicePath = app_path('Services/AtlasCode/AtlasCodeWorkspaceProfileService.php');
        $modelPath = app_path('Models/AtlasWorkspaceProfile.php');
        $migrationPath = database_path('migrations/2026_05_25_022000_create_atlas_workspace_profiles.php');
        $commandPath = app_path('Console/Commands/AtlasWorkspaceIntelligenceCommand.php');

        $routeSource = File::exists($routePath) ? (string) File::get($routePath) : '';
        $controllerSource = File::exists($controllerPath) ? (string) File::get($controllerPath) : '';
        $serviceSource = File::exists($servicePath) ? (string) File::get($servicePath) : '';
        $commandSource = File::exists($commandPath) ? (string) File::get($commandPath) : '';

        $requirements = [
            'migration_present' => File::exists($migrationPath),
            'model_present' => File::exists($modelPath),
            'api_list_route' => str_contains($routeSource, "Route::get('/projects/workspaces'"),
            'api_create_route' => str_contains($routeSource, "Route::post('/projects/workspaces'"),
            'api_show_route' => str_contains($routeSource, "Route::get('/projects/workspaces/{slug}'"),
            'api_update_route' => str_contains($routeSource, "Route::patch('/projects/workspaces/{slug}'"),
            'api_archive_route' => str_contains($routeSource, "Route::delete('/projects/workspaces/{slug}'"),
            'controller_index' => str_contains($controllerSource, 'function index('),
            'controller_show' => str_contains($controllerSource, 'function show('),
            'controller_store' => str_contains($controllerSource, 'function store('),
            'controller_update' => str_contains($controllerSource, 'function update('),
            'controller_destroy' => str_contains($controllerSource, 'function destroy('),
            'service_upsert' => str_contains($serviceSource, 'function upsertPersistedProfile('),
            'service_archive' => str_contains($serviceSource, 'function archivePersistedProfile('),
            'cli_register' => str_contains($commandSource, 'registerWorkspace('),
            'cli_list' => str_contains($commandSource, "'list'"),
        ];
        $missing = array_keys(array_filter($requirements, static fn (bool $ok): bool => ! $ok));

        $payload = [
            'schema_version' => 'atlas.workspace_intelligence.registry_editing.v1',
            'status' => $missing === [] ? 'ready' : 'blocked',
            'requirements' => $requirements,
            'missing' => $missing,
            'api_actions' => ['list', 'show', 'create', 'update', 'archive'],
            'cli_actions' => ['list', 'register'],
            'storage' => [
                'table' => 'atlas_workspace_profiles',
                'archive_policy' => 'status_archived_no_hard_delete',
            ],
        ];
        $payload['registry_editing_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $audit
     * @return array<string,mixed>
     */
    private function executionBoundarySummary(array $audit): array
    {
        return [
            'schema_version' => 'atlas.workspace_intelligence.execution_boundary_summary.v1',
            'status' => (string) ($audit['status'] ?? 'blocked'),
            'guarded_boundaries_total' => (int) data_get($audit, 'summary.total', 0),
            'guarded_boundaries_failed' => (int) data_get($audit, 'summary.failed', 0),
            'process_inventory_total' => (int) data_get($audit, 'process_inventory.total', 0),
            'process_inventory_failed' => (int) data_get($audit, 'process_inventory.failed', 0),
            'process_inventory_unclassified' => (int) data_get($audit, 'process_inventory.unclassified', 0),
            'process_inventory_by_classification' => (array) data_get($audit, 'process_inventory.by_classification', []),
            'audit_hash' => (string) ($audit['audit_hash'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function check(string $id, bool $passed, string $severity): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'passed' : 'failed',
            'severity' => $severity,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<string,int>
     */
    private function summary(array $checks): array
    {
        $failed = array_values(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? null) !== 'passed'));
        $critical = array_values(array_filter($failed, static fn (array $check): bool => ($check['severity'] ?? null) === 'critical'));

        return [
            'total' => count($checks),
            'passed' => count($checks) - count($failed),
            'failed' => count($failed),
            'critical_failed' => count($critical),
        ];
    }

    /**
     * @param  array<string,int>  $summary
     */
    private function status(array $summary): string
    {
        return ($summary['critical_failed'] ?? 0) > 0
            ? 'blocked'
            : 'ready';
    }

    /**
     * @param  array<string,mixed>  $profile
     * @return array<string,mixed>
     */
    private function riskMap(array $profile): array
    {
        $areas = array_values((array) ($profile['critical_areas'] ?? []));

        return [
            'risk_floor' => (string) data_get($profile, 'safety.risk_floor', $profile['default_risk'] ?? 'medium'),
            'sensitive_areas' => $areas,
            'requires_senior_review' => in_array((string) ($profile['production_status'] ?? ''), ['production'], true),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function detectStack(string $path, string $summary): array
    {
        $stack = [];
        $summary = mb_strtolower($summary);
        foreach (['laravel', 'php', 'typescript', 'react', 'expo', 'tauri', 'node'] as $needle) {
            if (str_contains($summary, $needle)) {
                $stack[] = $needle;
            }
        }
        $files = [
            'composer.json' => 'php',
            'artisan' => 'laravel',
            'package.json' => 'node',
            'tsconfig.json' => 'typescript',
            'atlas-app/app.json' => 'expo',
            'atlas-desktop/apps/desktop/src-tauri/tauri.conf.json' => 'tauri',
        ];
        foreach ($files as $file => $tag) {
            if ($path !== '' && File::exists(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file)) {
                $stack[] = $tag;
            }
        }

        return array_values(array_unique($stack));
    }

    /**
     * @return array<int,string>
     */
    private function ownerDocs(string $path): array
    {
        $docs = [
            'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
            'docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md',
            'docs/engineering-knowledge-base/atlas-workspace-artifact-fabric.md',
            'docs/engineering-knowledge-base/atlas-workspace-contract-orchestrator.md',
        ];

        return array_values(array_filter(
            $docs,
            fn (string $doc): bool => $path === '' || File::exists(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'atlas-server'.DIRECTORY_SEPARATOR.$doc) || File::exists(base_path($doc)),
        ));
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function artifact(string $type, ?string $workspaceId, array $body): array
    {
        $payload = [
            'schema_version' => 'atlas.workspace_artifact.v1',
            'artifact_type' => $type,
            'workspace_id' => $workspaceId,
            'status' => $workspaceId === null ? 'blocked' : 'draft',
            'body' => $body,
            'source_hashes' => [
                hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            ],
            'valid_until' => null,
        ];
        $payload['artifact_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    private function workspaceRootHash(string $path): string
    {
        $real = realpath($path) ?: $path;
        $gitHead = null;
        $headPath = rtrim($real, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'HEAD';
        if (is_file($headPath)) {
            $head = trim((string) @file_get_contents($headPath));
            $gitHead = $head;
            if (str_starts_with($head, 'ref: ')) {
                $refPath = rtrim($real, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.trim(mb_substr($head, 5));
                if (is_file($refPath)) {
                    $gitHead .= '|'.trim((string) @file_get_contents($refPath));
                }
            }
        }

        $structuralFiles = [
            'composer.json',
            'composer.lock',
            'package.json',
            'package-lock.json',
            'pnpm-lock.yaml',
            'yarn.lock',
            'tsconfig.json',
            'atlas-server/docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
            'atlas-server/docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md',
            'atlas-server/docs/engineering-knowledge-base/atlas-workspace-contract-orchestrator.md',
            'atlas-server/docs/engineering-knowledge-base/atlas-workspace-evolution-fabric.md',
        ];
        $structuralHashes = [];
        foreach ($structuralFiles as $file) {
            $filePath = rtrim($real, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file;
            if (is_file($filePath)) {
                $structuralHashes[$file] = hash_file('sha256', $filePath) ?: null;
            }
        }

        return MissionCanonicalHash::sha256([
            'realpath' => $real,
            'git_head' => $gitHead,
            'structural_hashes' => $structuralHashes,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashWithoutGeneratedAt(array $payload): string
    {
        unset($payload['generated_at'], $payload['runtime_hash']);

        return MissionCanonicalHash::sha256($payload);
    }
}
