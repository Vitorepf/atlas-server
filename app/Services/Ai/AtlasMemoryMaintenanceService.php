<?php

namespace App\Services\Ai;

use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringKnowledgeBaseService;

class AtlasMemoryMaintenanceService
{
    public function __construct(
        private readonly EngineeringKnowledgeBaseService $knowledge,
        private readonly EngineeringCodeIntelligenceService $code,
        private readonly AtlasProviderProjectionService $projection,
        private readonly AtlasProviderProjectionAuditService $audits,
        private readonly AtlasOpenBrainMcpService $mcp,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function run(array $options = []): array
    {
        $workspace = $this->workspace($options['workspace'] ?? null);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $sync = (bool) ($options['sync'] ?? true);
        $indexCode = (bool) ($options['index_code'] ?? true);
        $prune = (bool) ($options['prune'] ?? true);
        $applyProjection = (bool) ($options['apply_projection'] ?? false);
        $confirm = (bool) ($options['confirm'] ?? false);
        $includeDriftAudit = (bool) ($options['include_drift_audit'] ?? false);
        $initiator = $this->initiator($options['initiator'] ?? 'system');
        $context = ['workspace' => $workspace];

        $payload = [
            'ok' => true,
            'status' => 'running',
            'workspace' => $workspace,
            'dry_run' => $dryRun,
            'prune' => $prune,
            'writes' => [
                'knowledge_sync' => ! $dryRun && $sync,
                'code_index' => ! $dryRun && $indexCode,
                'provider_projection_apply' => $applyProjection && ! $dryRun,
            ],
            'stages' => [],
            'generated_at' => now()->toJSON(),
        ];

        if ($sync) {
            $payload['stages']['knowledge_sync'] = $this->knowledge->sync([
                'dry_run' => $dryRun,
                'prune' => $prune,
            ]);
            $payload['ok'] = $payload['ok'] && (bool) data_get($payload, 'stages.knowledge_sync.ok', false);
        } else {
            $payload['stages']['knowledge_sync'] = ['ok' => true, 'status' => 'skipped'];
        }

        if ($indexCode) {
            $payload['stages']['code_index'] = $this->code->index([
                'workspace' => $workspace,
                'dry_run' => $dryRun,
                'prune' => $prune,
            ]);
            $payload['ok'] = $payload['ok'] && (bool) data_get($payload, 'stages.code_index.ok', false);
        } else {
            $payload['stages']['code_index'] = ['ok' => true, 'status' => 'skipped'];
        }

        $payload['stages']['provider_projection_status'] = $this->projection->status('all', $context, $context);

        if ($applyProjection) {
            $payload['stages']['provider_projection_apply'] = $this->applyProjectionIfNeeded(
                $context,
                $payload['stages']['provider_projection_status'],
                $dryRun,
                $confirm,
                $initiator,
            );
            $payload['ok'] = $payload['ok'] && (bool) data_get($payload, 'stages.provider_projection_apply.ok', true);
            $payload['stages']['provider_projection_status'] = $this->projection->status('all', $context, $context);
        }

        $payload['stages']['mcp_health'] = $this->mcpHealth($workspace, $includeDriftAudit);
        $payload['status'] = $this->finalStatus($payload, $dryRun);
        $payload['ok'] = $payload['ok'] && in_array($payload['status'], ['ready', 'dry_run_ready'], true);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $status
     * @return array<string,mixed>
     */
    private function applyProjectionIfNeeded(
        array $context,
        array $status,
        bool $dryRun,
        bool $confirm,
        string $initiator,
    ): array {
        if ($dryRun) {
            return ['ok' => true, 'status' => 'skipped_dry_run'];
        }

        if (($status['status'] ?? null) === 'passed') {
            return ['ok' => true, 'status' => 'skipped_already_passed'];
        }

        if (! $confirm) {
            return [
                'ok' => false,
                'status' => 'confirmation_required',
                'message' => 'Use confirm=true para aplicar provider projections pela rotina de manutencao.',
            ];
        }

        $result = $this->projection->applyReviewed('all', $context, $context) + [
            'confirmed' => true,
            'confirmation_mode' => $initiator === 'api' ? 'api_confirm' : 'flag',
        ];
        $audit = $this->audits->recordApply($result, $context, [
            'initiator' => $initiator,
            'confirmation_mode' => $initiator === 'api' ? 'api_confirm' : 'flag',
            'target' => 'all',
            'source' => 'atlas:memory:maintain',
        ]);

        return $result + [
            'audit' => $audit ? $this->audits->payload($audit) : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function mcpHealth(string $workspace, bool $includeDriftAudit): array
    {
        $response = $this->mcp->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 'maintenance-health',
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_memory_maintenance_status',
                'arguments' => [
                    'workspace' => $workspace,
                    'include_drift_audit' => $includeDriftAudit,
                ],
            ],
        ]);

        $structured = data_get($response, 'result.structuredContent');

        return is_array($structured)
            ? $structured
            : ['ok' => false, 'overall_status' => 'mcp_health_unavailable', 'response' => $response];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function finalStatus(array $payload, bool $dryRun): string
    {
        if (! (bool) ($payload['ok'] ?? false)) {
            return 'failed';
        }

        $healthStatus = (string) data_get($payload, 'stages.mcp_health.overall_status', 'unknown');
        if ($dryRun && $healthStatus === 'ready') {
            return 'dry_run_ready';
        }

        return $healthStatus;
    }

    private function workspace(mixed $workspace): string
    {
        $workspace = trim((string) ($workspace ?: config('atlas.ai.workdir') ?: base_path()));

        return realpath($workspace) ?: $workspace;
    }

    private function initiator(mixed $value): string
    {
        return in_array($value, ['api', 'cli', 'system'], true) ? (string) $value : 'system';
    }
}
