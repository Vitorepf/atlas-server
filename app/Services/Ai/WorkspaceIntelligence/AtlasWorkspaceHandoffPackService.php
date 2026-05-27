<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * AWIS · Workspace Handoff Pack.
 *
 * Produces the provider/subagent-safe projection that Dev, Forge and worker
 * handoffs should receive instead of raw workspace state or full conversations.
 */
final class AtlasWorkspaceHandoffPackService implements \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\AwisHandoffPackPort
{
    public const SCHEMA_VERSION = 'atlas.workspace_handoff_pack.v1';

    public function __construct(
        private readonly AtlasWorkspaceIntelligenceRuntimeService $runtime,
        private readonly AtlasWorkspaceConversationFusionService $conversationFusion,
    ) {}

    /**
     * @param  array<int,string>  $threadIds
     * @return array<string,mixed>
     */
    public function build(?string $workspace = null, string $task = '', string $consumer = 'atlas_dev', array $threadIds = []): array
    {
        $consumer = $this->normaliseConsumer($consumer);
        $report = $this->runtime->certify(workspace: $workspace, task: $task, conversationTexts: []);
        $workspaceReport = (array) ($report['workspace'] ?? []);
        $status = (string) ($report['status'] ?? 'blocked');
        if ($status === 'blocked') {
            return $this->blocked($workspaceReport, ['workspace_intelligence_not_ready']);
        }

        $artifacts = collect((array) data_get($report, 'awaf.artifacts', []))
            ->filter(fn ($artifact): bool => is_array($artifact))
            ->keyBy(fn (array $artifact): string => (string) ($artifact['artifact_type'] ?? 'unknown'));
        $required = $this->requiredArtifactTypes($consumer);
        $missing = array_values(array_filter(
            $required,
            fn (string $type): bool => ! $artifacts->has($type),
        ));

        $fusion = null;
        if ($threadIds !== []) {
            $fusion = $this->conversationFusion->build(
                workspace: (string) ($workspaceReport['workspace_id'] ?? $workspace),
                limit: count($threadIds),
                threadIds: $threadIds,
                persist: false,
            );
            if (($fusion['status'] ?? null) === 'blocked') {
                return $this->blocked($workspaceReport, (array) ($fusion['blockers'] ?? ['conversation_fusion_blocked']), [
                    'conversation_fusion' => $this->safeFusionProjection($fusion),
                ]);
            }
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now()->toISOString(),
            'status' => $missing === [] ? 'ready' : 'blocked',
            'consumer' => $consumer,
            'workspace' => [
                'workspace_id' => (string) ($workspaceReport['workspace_id'] ?? ''),
                'workspace_name' => (string) ($workspaceReport['workspace_name'] ?? ''),
                'workspace_hash' => $workspaceReport['workspace_hash'] ?? null,
                'readiness_status' => (string) ($workspaceReport['readiness_status'] ?? 'unknown'),
                'memory_scope' => 'workspace',
            ],
            'task' => [
                'input_hash' => hash('sha256', trim($task)),
                'excerpt' => mb_substr(trim((string) preg_replace('/\s+/', ' ', $task)), 0, 220),
                'scope' => 'workspace_scoped',
            ],
            'required_artifacts' => $required,
            'missing_artifacts' => $missing,
            'context_units' => $this->contextUnits($artifacts, $required),
            'execution_contract' => [
                'provider_safe' => true,
                'raw_conversation_included' => false,
                'workspace_isolation_required' => true,
                'cross_workspace_memory_allowed' => false,
                'mutative_execution_requires_awis_gate' => true,
                'allowed_consumers' => ['atlas_dev', 'atlas_forge', 'subagent_projection', 'reviewer'],
            ],
            'scope_guard' => [
                'allowed_scope' => 'current_workspace_only',
                'risk_floor' => data_get($report, 'awtr.genome.risk_floor', 'medium'),
                'sensitive_areas' => array_values((array) data_get($report, 'awtr.risk_fragility_map.sensitive_areas', [])),
                'owner_docs' => array_values((array) data_get($report, 'awtr.living_code_map.owner_docs', [])),
            ],
            'test_contract' => [
                'focused_tests' => array_values((array) data_get($report, 'workspace_focus_map.focused_commands', data_get($report, 'awtr.test_command_intelligence.commands', []))),
                'fallback_tests' => array_values((array) data_get($report, 'awtr.genome.test_families', [])),
                'skip_reason' => data_get($report, 'awaf.artifacts.4.body.skip_reason'),
            ],
            'next_session_brain' => [
                'schema_version' => (string) data_get($report, 'workspace_next_session_brain.schema_version', 'atlas.awis.workspace_next_session_brain.v1'),
                'status' => (string) data_get($report, 'workspace_next_session_brain.status', 'blocked'),
                'brain_hash' => data_get($report, 'workspace_next_session_brain.brain_hash'),
                'readiness_score' => data_get($report, 'workspace_next_session_brain.readiness_score'),
                'load_order' => array_values((array) data_get($report, 'workspace_next_session_brain.resume_packet.load_order', [])),
                'focused_repositories' => array_values((array) data_get($report, 'workspace_next_session_brain.resume_packet.focused_repositories', [])),
                'execution_priority' => array_values((array) data_get($report, 'workspace_next_session_brain.execution_priority', [])),
                'context_loading_plan' => (array) data_get($report, 'workspace_next_session_brain.context_loading_plan', []),
                'raw_content_returned' => false,
            ],
            'live_execution_memory' => $this->safeLiveExecutionMemoryProjection((array) data_get($report, 'workspace_live_execution_memory', [])),
            'conversation_fusion' => $this->safeFusionProjection($fusion),
            'claim_policy' => [
                'read_only' => true,
                'invokes_provider' => false,
                'spends_tokens' => false,
                'safe_for_provider_prompt' => true,
                'raw_conversation_returned' => false,
                'full_message_content_returned' => false,
                'next_session_brain_provider_safe' => data_get($report, 'workspace_next_session_brain.source_policy.raw_file_content_returned') === false
                    && data_get($report, 'workspace_next_session_brain.source_policy.raw_conversation_returned') === false
                    && data_get($report, 'workspace_next_session_brain.context_loading_plan.provider_policy.raw_manifest_returned') === false
                    && data_get($report, 'workspace_next_session_brain.context_loading_plan.provider_policy.script_bodies_returned') === false
                    && data_get($report, 'workspace_next_session_brain.context_loading_plan.provider_policy.absolute_workspace_path_returned') === false,
                'live_execution_memory_provider_safe' => data_get($report, 'workspace_live_execution_memory.source_policy.raw_file_content_returned') === false
                    && data_get($report, 'workspace_live_execution_memory.source_policy.raw_diff_returned') === false
                    && data_get($report, 'workspace_live_execution_memory.source_policy.raw_conversation_returned') === false
                    && data_get($report, 'workspace_live_execution_memory.source_policy.absolute_workspace_path_returned') === false,
            ],
        ];
        $payload['handoff_hash'] = $this->hashWithoutGeneratedAt($payload);

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function requiredArtifactTypes(string $consumer): array
    {
        return match ($consumer) {
            'atlas_forge' => ['workspace_brief', 'task_packet', 'context_pack', 'execution_plan', 'test_plan', 'risk_sheet', 'handoff_packet', 'workspace_runbook'],
            'subagent_projection' => ['workspace_brief', 'task_packet', 'context_pack', 'test_plan', 'risk_sheet', 'handoff_packet'],
            'reviewer' => ['workspace_brief', 'task_packet', 'risk_sheet', 'test_plan', 'outcome_record'],
            default => ['workspace_brief', 'task_packet', 'context_pack', 'test_plan', 'risk_sheet', 'handoff_packet'],
        };
    }

    /**
     * @param  Collection<string,array<string,mixed>>  $artifacts
     * @param  list<string>  $required
     * @return list<array<string,mixed>>
     */
    private function contextUnits($artifacts, array $required): array
    {
        return array_values(array_filter(array_map(function (string $type) use ($artifacts): ?array {
            $artifact = $artifacts->get($type);
            if (! is_array($artifact)) {
                return null;
            }

            return [
                'artifact_type' => $type,
                'artifact_hash' => (string) ($artifact['artifact_hash'] ?? ''),
                'status' => (string) ($artifact['status'] ?? 'unknown'),
                'body' => (array) ($artifact['body'] ?? []),
            ];
        }, $required)));
    }

    /**
     * @param  array<string,mixed>  $memory
     * @return array<string,mixed>|null
     */
    private function safeLiveExecutionMemoryProjection(array $memory): ?array
    {
        if ($memory === []) {
            return null;
        }

        return [
            'schema_version' => (string) ($memory['schema_version'] ?? 'atlas.awis.workspace_live_execution_memory.v1'),
            'status' => (string) ($memory['status'] ?? 'blocked'),
            'workspace_id' => $memory['workspace_id'] ?? null,
            'live_memory_hash' => $memory['live_memory_hash'] ?? null,
            'startup_packet' => [
                'load_first' => array_values((array) data_get($memory, 'startup_packet.load_first', [])),
                'use_as_summary' => array_values((array) data_get($memory, 'startup_packet.use_as_summary', [])),
                'validate_before_trust' => array_values((array) data_get($memory, 'startup_packet.validate_before_trust', [])),
                'avoid' => array_values((array) data_get($memory, 'startup_packet.avoid', [])),
                'human_boundary' => array_values((array) data_get($memory, 'startup_packet.human_boundary', [])),
            ],
            'workspace_learning' => [
                'repositories' => array_values((array) data_get($memory, 'workspace_learning.repositories', [])),
                'focused_repositories' => array_values((array) data_get($memory, 'workspace_learning.focused_repositories', [])),
                'focused_areas' => array_values((array) data_get($memory, 'workspace_learning.focused_areas', [])),
                'focused_commands' => array_values((array) data_get($memory, 'workspace_learning.focused_commands', [])),
                'canonical_source_count' => (int) data_get($memory, 'workspace_learning.canonical_source_count', 0),
            ],
            'automation_loop' => [
                'before_send' => array_values((array) data_get($memory, 'automation_loop.before_send', [])),
                'after_success' => array_values((array) data_get($memory, 'automation_loop.after_success', [])),
                'after_failure' => array_values((array) data_get($memory, 'automation_loop.after_failure', [])),
                'on_drift' => array_values((array) data_get($memory, 'automation_loop.on_drift', [])),
            ],
            'promotion_rules' => [
                'promote_to_gold' => array_values((array) data_get($memory, 'promotion_rules.promote_to_gold', [])),
                'preserve_as_artifact' => array_values((array) data_get($memory, 'promotion_rules.preserve_as_artifact', [])),
                'revalidate' => array_values((array) data_get($memory, 'promotion_rules.revalidate', [])),
                'demote' => array_values((array) data_get($memory, 'promotion_rules.demote', [])),
            ],
            'persistence_contract' => (array) data_get($memory, 'persistence_contract', []),
            'cache_keys' => (array) data_get($memory, 'cache_keys', []),
            'source_policy' => [
                'raw_file_content_returned' => false,
                'raw_diff_returned' => false,
                'raw_conversation_returned' => false,
                'absolute_workspace_path_returned' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $fusion
     * @return array<string,mixed>|null
     */
    private function safeFusionProjection(?array $fusion): ?array
    {
        if ($fusion === null) {
            return null;
        }

        return [
            'schema_version' => (string) ($fusion['schema_version'] ?? AtlasWorkspaceConversationFusionService::SCHEMA_VERSION),
            'status' => (string) ($fusion['status'] ?? 'unknown'),
            'workspace_id' => $fusion['workspace_id'] ?? null,
            'summary' => (array) ($fusion['summary'] ?? []),
            'fusion_pack_hash' => data_get($fusion, 'fusion_pack.fusion_pack_hash'),
            'decision_ledger' => (array) data_get($fusion, 'fusion_pack.decision_ledger', []),
            'blocker_ledger' => (array) data_get($fusion, 'fusion_pack.blocker_ledger', []),
            'risk_ledger' => (array) data_get($fusion, 'fusion_pack.risk_ledger', []),
            'raw_conversation_returned' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $workspaceReport
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(array $workspaceReport, array $blockers, array $extra = []): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now()->toISOString(),
            'status' => 'blocked',
            'workspace' => [
                'workspace_id' => $workspaceReport['workspace_id'] ?? null,
                'workspace_name' => $workspaceReport['workspace_name'] ?? null,
                'workspace_hash' => $workspaceReport['workspace_hash'] ?? null,
                'readiness_status' => $workspaceReport['readiness_status'] ?? 'blocked',
            ],
            'blockers' => array_values(array_unique($blockers)),
            'claim_policy' => [
                'read_only' => true,
                'invokes_provider' => false,
                'spends_tokens' => false,
                'safe_for_provider_prompt' => false,
                'raw_conversation_returned' => false,
            ],
        ] + $extra;
        $payload['handoff_hash'] = $this->hashWithoutGeneratedAt($payload);

        return $payload;
    }

    private function normaliseConsumer(string $consumer): string
    {
        $consumer = trim(strtolower($consumer));

        return in_array($consumer, ['atlas_dev', 'atlas_forge', 'subagent_projection', 'reviewer'], true)
            ? $consumer
            : 'atlas_dev';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashWithoutGeneratedAt(array $payload): string
    {
        unset($payload['generated_at'], $payload['handoff_hash']);

        return MissionCanonicalHash::sha256($payload);
    }
}
