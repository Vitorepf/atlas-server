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
        'AWCO' => 'Atlas Workspace Contract Orchestrator',
        'AWEF' => 'Atlas Workspace Evolution Fabric',
    ];

    public function __construct(
        private readonly AtlasCodeWorkspaceProfileService $profiles,
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
        $contracts = $this->contracts($workspaceReport, $artifacts);
        $evolution = $this->evolution($profile, $twin);
        $checks = $this->checks($workspaceReport, $twin, $continuity, $artifacts, $contracts, $evolution);
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
            'awco' => $contracts,
            'awef' => $evolution,
            'checks' => $checks,
            'claim_policy' => [
                'read_only' => true,
                'invokes_provider' => false,
                'transfers_raw_cross_workspace' => false,
                'raw_conversation_used_as_prompt' => false,
                'planned_runtime_not_full_enforcement' => true,
            ],
        ];

        $payload['runtime_hash'] = $this->hashWithoutGeneratedAt($payload);

        return $payload;
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

        $payload = [
            'schema_version' => 'atlas.workspace_twin.v1',
            'status' => $exists ? 'ready' : 'limited',
            'workspace_id' => (string) $profile['slug'],
            'genome' => [
                'stack' => $stack,
                'docs_status' => (string) ($profile['docs_status'] ?? 'unknown'),
                'production_status' => (string) ($profile['production_status'] ?? 'unknown'),
                'risk_floor' => (string) data_get($profile, 'safety.risk_floor', $profile['default_risk'] ?? 'medium'),
            ],
            'living_code_map' => [
                'root_exists' => $exists,
                'owner_docs' => $docs,
                'critical_areas' => array_values((array) ($profile['critical_areas'] ?? [])),
            ],
            'test_command_intelligence' => [
                'commands' => array_values((array) ($profile['test_commands'] ?? [])),
                'has_focused_entrypoint' => (array) ($profile['test_commands'] ?? []) !== [],
            ],
            'command_registry' => $commands,
            'risk_fragility_map' => $this->riskMap($profile),
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

        return [
            'schema_version' => 'atlas.workspace_contract_orchestrator.v1',
            'status' => $blocked === [] ? 'ready' : 'blocked',
            'certifications' => $certifications,
            'blocked_count' => count($blocked),
            'execution_readiness_status' => $blocked === [] ? 'ready' : 'blocked',
            'contract_hash' => MissionCanonicalHash::sha256($certifications),
        ];
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

        return [
            'schema_version' => 'atlas.workspace_evolution_fabric.v1',
            'status' => $profile === null ? 'blocked' : 'ready',
            'workspace_id' => $profile['slug'] ?? null,
            'privacy_preserving_transfer' => true,
            'patterns' => $patterns,
            'failure_signature_bank' => [
                [
                    'signature_id' => 'stale_workspace_context',
                    'avoidance_policy' => ['refresh_awis', 'rebuild_twin', 'regenerate_artifacts'],
                    'confidence' => 0.8,
                ],
            ],
            'evolution_hash' => MissionCanonicalHash::sha256($patterns),
        ];
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
            $this->check('awco_artifacts_certified', data_get($sections, '4.execution_readiness_status') === 'ready', 'critical'),
            $this->check('awef_privacy_preserving_transfer', data_get($sections, '5.privacy_preserving_transfer') === true, 'critical'),
        ];

        return $checks;
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
        $head = null;
        $headPath = rtrim($real, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'HEAD';
        if (is_file($headPath)) {
            $head = trim((string) @file_get_contents($headPath));
        }

        return hash('sha256', $real.'|'.$head);
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
