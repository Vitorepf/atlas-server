<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactIntelligenceRepository;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceConversationFusionService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceExecutionBoundaryAuditService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceHandoffPackService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceRuntimeService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceSnapshotRepository;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceRuntimeProjectionRepository;
use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class AtlasWorkspaceIntelligenceCommand extends Command
{
    protected $signature = 'atlas:workspace-intelligence
        {action=certify : certify|show|twin|artifacts|contracts|evolution|artifact-intelligence|conversation-fusion|handoff-pack|boundary-audit|gate|register|list}
        {--workspace= : Workspace slug, defaults to configured Atlas workspace}
        {--path= : Workspace root path for register action}
        {--name= : Human workspace name for register action}
        {--kind=product : Workspace kind for register action}
        {--production-status=development : development|staging|production}
        {--docs-status=unknown : complete|incomplete|unknown}
        {--default-risk=medium : low|medium|high|critical}
        {--test-command=* : Test command allowed for this workspace}
        {--critical-area=* : Critical path/area for this workspace}
        {--mode=conversation : Execution mode for gate action: conversation|dev|forge|patch|test|index-code}
        {--task= : Task used to generate task/context artifacts}
        {--conversation=* : Optional long conversation text or excerpt; stored as hash/excerpt only}
        {--thread=* : Optional AI thread id for conversation-fusion}
        {--limit=12 : Max AI threads for conversation-fusion}
        {--consumer=atlas_dev : Handoff consumer: atlas_dev|atlas_forge|subagent_projection|reviewer}
        {--persist : Persist the generated runtime snapshot}
        {--json : Print machine-readable JSON}
        {--strict : Exit non-zero unless status === ready}';

    protected $description = 'Builds and certifies the AWIS family runtime envelope (AWIS/AWTR/ACIOS/AWAF/AWAIR/AWCO/AWEF).';

    public function handle(
        AtlasWorkspaceIntelligenceRuntimeService $runtime,
        AtlasWorkspaceIntelligenceExecutionGateService $gate,
        AtlasWorkspaceIntelligenceSnapshotRepository $snapshots,
        AtlasWorkspaceArtifactIntelligenceRepository $artifactIntelligence,
        AtlasWorkspaceRuntimeProjectionRepository $projections,
        AtlasWorkspaceExecutionBoundaryAuditService $boundaryAudit,
        AtlasWorkspaceConversationFusionService $conversationFusion,
        AtlasWorkspaceHandoffPackService $handoffPack,
        AtlasCodeWorkspaceProfileService $profiles,
    ): int {
        $action = (string) $this->argument('action');
        if ($action === 'register') {
            $payload = $this->registerWorkspace($profiles);
            $this->outputPayload($payload);

            return ($payload['error'] ?? null) === null ? self::SUCCESS : self::FAILURE;
        }
        if ($action === 'list') {
            $payload = [
                'schema_version' => AtlasCodeWorkspaceProfileService::SCHEMA_VERSION,
                'status' => 'ready',
                'data' => $profiles->listProfiles(),
                'meta' => [
                    'total' => count($profiles->listProfiles()),
                    'default_slug' => $profiles->defaultSlug(),
                ],
            ];
            $this->outputPayload($payload);

            return self::SUCCESS;
        }

        $report = $runtime->certify(
            workspace: $this->stringOption('workspace'),
            task: $this->stringOption('task') ?? '',
            conversationTexts: $this->conversationOption(),
        );
        $snapshot = null;
        if ((bool) $this->option('persist')) {
            $snapshot = $snapshots->persist($report);
            $artifactGraph = $artifactIntelligence->persist($report);
            $projectionIds = $projections->persist($report);
            $report['persisted_snapshot_id'] = $snapshot?->id;
            $report['persisted_artifact_graph_id'] = $artifactGraph?->id;
            $report['persisted_projection_ids'] = $projectionIds;
        }

        $payload = match ($action) {
            'show', 'certify' => $report,
            'twin' => $runtime->twin($this->stringOption('workspace')),
            'artifacts' => $report['awaf'] ?? [],
            'contracts' => $runtime->contractOrchestration(
                workspace: $this->stringOption('workspace'),
                task: $this->stringOption('task') ?? '',
            ),
            'evolution' => $runtime->evolutionFabric($this->stringOption('workspace')),
            'artifact-intelligence' => $report['awair'] ?? [],
            'conversation-fusion', 'fusion', 'merge-conversations' => $conversationFusion->build(
                workspace: $this->stringOption('workspace'),
                limit: (int) ($this->option('limit') ?: 12),
                threadIds: $this->stringListOption('thread'),
                persist: (bool) $this->option('persist'),
            ),
            'handoff-pack', 'handoff', 'provider-handoff' => $handoffPack->build(
                workspace: $this->stringOption('workspace'),
                task: $this->stringOption('task') ?? '',
                consumer: $this->stringOption('consumer') ?? 'atlas_dev',
                threadIds: $this->stringListOption('thread'),
            ),
            'boundary-audit', 'boundaries', 'execution-boundaries' => $boundaryAudit->audit(),
            'gate' => $gate->gate(
                workspace: $this->stringOption('workspace'),
                mode: $this->stringOption('mode') ?? 'conversation',
                task: $this->stringOption('task') ?? '',
                conversationTexts: $this->conversationOption(),
            ),
            default => [
                'schema_version' => AtlasWorkspaceIntelligenceRuntimeService::SCHEMA_VERSION,
                'status' => 'blocked',
                'error' => 'unknown_action',
                'allowed_actions' => ['certify', 'show', 'twin', 'artifacts', 'contracts', 'evolution', 'artifact-intelligence', 'conversation-fusion', 'handoff-pack', 'boundary-audit', 'gate', 'register', 'list'],
            ],
        };

        $this->outputPayload($payload);

        if (($payload['error'] ?? null) === 'unknown_action') {
            return self::FAILURE;
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? $report['status'] ?? null) !== 'ready') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function registerWorkspace(AtlasCodeWorkspaceProfileService $profiles): array
    {
        $workspace = $this->stringOption('workspace');
        if ($workspace === null) {
            return [
                'schema_version' => AtlasCodeWorkspaceProfileService::SCHEMA_VERSION,
                'status' => 'blocked',
                'error' => 'workspace_slug_required',
            ];
        }

        try {
            $profile = $profiles->upsertPersistedProfile([
                'slug' => $workspace,
                'name' => $this->stringOption('name') ?? $workspace,
                'kind' => $this->stringOption('kind') ?? 'product',
                'workspace_path' => $this->stringOption('path') ?? '',
                'repo_root' => $this->stringOption('path') ?? '',
                'production_status' => $this->stringOption('production-status') ?? 'development',
                'docs_status' => $this->stringOption('docs-status') ?? 'unknown',
                'default_risk' => $this->stringOption('default-risk') ?? 'medium',
                'test_commands' => $this->stringListOption('test-command'),
                'critical_areas' => $this->stringListOption('critical-area'),
                'source' => 'operator_cli',
                'status' => 'active',
            ]);
        } catch (InvalidArgumentException $exception) {
            return [
                'schema_version' => AtlasCodeWorkspaceProfileService::SCHEMA_VERSION,
                'status' => 'blocked',
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'schema_version' => AtlasCodeWorkspaceProfileService::SCHEMA_VERSION,
            'status' => 'ready',
            'workspace' => $profile,
            'meta' => [
                'persisted' => true,
                'execution_allowed' => (bool) data_get($profile, 'safety.execution_allowed', false),
                'execution_blocked_reason' => data_get($profile, 'safety.execution_blocked_reason'),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function outputPayload(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }

        $this->renderHuman($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderHuman(array $payload): void
    {
        $this->components->twoColumnDetail(
            '<fg=bright-blue;options=bold>Atlas Workspace Intelligence</>',
            (string) ($payload['schema_version'] ?? '-'),
        );
        $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? '-'));
        $this->components->twoColumnDetail('workspace', (string) data_get($payload, 'workspace.workspace_id', data_get($payload, 'workspace_id', '-')));
        $this->components->twoColumnDetail('hash', (string) ($payload['runtime_hash'] ?? $payload['artifact_fabric_hash'] ?? '-'));
        if (isset($payload['persisted_snapshot_id'])) {
            $this->components->twoColumnDetail('snapshot', (string) $payload['persisted_snapshot_id']);
        }

        $checks = is_array($payload['checks'] ?? null) ? $payload['checks'] : [];
        foreach ($checks as $check) {
            if (! is_array($check)) {
                continue;
            }
            $this->components->twoColumnDetail(
                '· '.(string) ($check['id'] ?? 'unknown'),
                (string) ($check['status'] ?? 'unknown'),
            );
        }
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<int,string>
     */
    private function conversationOption(): array
    {
        $items = [];
        foreach ((array) $this->option('conversation') as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return $items;
    }

    /**
     * @return array<int,string>
     */
    private function stringListOption(string $key): array
    {
        $items = [];
        foreach ((array) $this->option($key) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return array_values(array_unique($items));
    }
}
