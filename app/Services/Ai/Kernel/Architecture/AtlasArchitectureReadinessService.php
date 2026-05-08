<?php

namespace App\Services\Ai\Kernel\Architecture;

use App\Services\Ai\AtlasProviderProjectionService;

final class AtlasArchitectureReadinessService
{
    public function __construct(
        private readonly AtlasAiArchitectureValidationService $validation,
        private readonly AtlasDocumentationSplitPlanService $splitPlan,
        private readonly AtlasProviderProjectionService $projection,
        private readonly AtlasArchitectureOperationsCatalog $operations,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function snapshot(array $options = []): array
    {
        $workspace = $this->workspace($options['workspace'] ?? null);
        $owner = $this->owner($options['owner'] ?? null);
        $architecture = $this->validation->payload();
        $splitPlan = $this->splitPlan->plan(array_filter([
            'owner' => $owner,
        ], fn (?string $value): bool => $value !== null));
        $projection = $this->projection->status('all', ['workspace' => $workspace], ['workspace' => $workspace]);

        $checks = [
            'architecture_validate' => [
                'ok' => ($architecture['status'] ?? null) === 'ok',
                'status' => (string) ($architecture['status'] ?? 'unknown'),
                'failed_static_scan_count' => (int) data_get($architecture, 'kernel.static_scan.summary.failed_count', 0),
            ],
            'documentation_health' => [
                'ok' => (bool) data_get($architecture, 'documentation.valid', false),
                'status' => (string) data_get($architecture, 'documentation.status', 'unknown'),
                'required_missing' => (int) data_get($architecture, 'documentation.summary.required_missing', 0),
                'split_required_count' => (int) ($splitPlan['split_required_count'] ?? 0),
                'total_split_required_count' => (int) ($splitPlan['total_split_required_count'] ?? 0),
            ],
            'provider_projection' => [
                'ok' => ($projection['status'] ?? null) === 'passed',
                'status' => (string) ($projection['status'] ?? 'unknown'),
            ],
            'architecture_operations' => [
                'ok' => true,
                'status' => 'published',
                'command_count' => (int) data_get($this->operations->summary(), 'command_count', 0),
            ],
        ];

        $ready = collect($checks)->every(fn (array $check): bool => (bool) ($check['ok'] ?? false));

        return [
            'schema_version' => 'atlas.architecture_readiness.v1',
            'status' => $ready ? 'ready' : 'attention',
            'ready' => $ready,
            'workspace' => $workspace,
            'owner' => $owner,
            'checks' => $checks,
            'docs_split_plan' => [
                'owner' => $splitPlan['filters']['owner'] ?? $owner,
                'status' => $splitPlan['status'] ?? 'unknown',
                'split_required_count' => $splitPlan['split_required_count'] ?? 0,
                'total_split_required_count' => $splitPlan['total_split_required_count'] ?? 0,
                'execution_order' => $splitPlan['execution_order'] ?? [],
                'first_doc' => data_get($splitPlan, 'docs.0'),
                'command' => $owner
                    ? 'php artisan atlas:ai:docs-split-plan --owner='.$owner.' --json'
                    : 'php artisan atlas:ai:docs-split-plan --json',
            ],
            'provider_projection' => [
                'status' => $projection['status'] ?? 'unknown',
                'targets' => $projection['targets'] ?? [],
                'recommended_command' => 'php artisan atlas:memory:projection status --target=all --workspace='.$workspace.' --json',
            ],
            'architecture_operations' => $this->readinessOperations(),
            'review_signal' => [
                'status' => $ready ? 'ready' : 'attention',
                'recommended_action' => $ready
                    ? 'continue_implementation_with_session_bootstrap_and_feature_placement'
                    : 'fix_failed_readiness_checks_before_expanding_architecture',
                'required_next_commands' => $this->nextCommands($checks, $owner, $workspace),
            ],
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readinessOperations(): array
    {
        $ids = [
            'architecture_readiness',
            'session_bootstrap',
            'feature_placement',
            'documentation_split_plan',
            'architecture_validate',
            'documentation_health',
            'provider_projection_status',
            'knowledge_sync',
            'code_intelligence_index',
        ];

        return $this->operations->summary(['id' => 'architecture_readiness']) + [
            'related_operation_ids' => $ids,
            'related_commands' => collect($this->operations->commands())
                ->filter(fn (array $command): bool => in_array((string) ($command['id'] ?? ''), $ids, true))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $checks
     * @return array<int,string>
     */
    private function nextCommands(array $checks, ?string $owner, string $workspace): array
    {
        $commands = [];

        if (! (bool) data_get($checks, 'architecture_validate.ok', false)) {
            $commands[] = 'php artisan atlas:ai:architecture-validate --json';
        }

        if (! (bool) data_get($checks, 'documentation_health.ok', false)) {
            $commands[] = 'atlas engineering knowledge docs-health --json';
        }

        $commands[] = $owner
            ? 'php artisan atlas:ai:docs-split-plan --owner='.$owner.' --json'
            : 'php artisan atlas:ai:docs-split-plan --json';

        if (! (bool) data_get($checks, 'provider_projection.ok', false)) {
            $commands[] = 'php artisan atlas:memory:projection status --target=all --workspace='.$workspace.' --json';
        }

        return array_values(array_unique($commands));
    }

    private function workspace(mixed $workspace): string
    {
        $workspace = trim((string) ($workspace ?: base_path()));

        return realpath($workspace) ?: $workspace;
    }

    private function owner(mixed $owner): ?string
    {
        $owner = trim((string) $owner);

        return $owner === '' ? null : $owner;
    }
}
