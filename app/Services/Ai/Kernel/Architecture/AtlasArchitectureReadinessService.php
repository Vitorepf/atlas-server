<?php

namespace App\Services\Ai\Kernel\Architecture;

use App\Services\Ai\AtlasProviderProjectionService;
use Illuminate\Support\Facades\File;

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
            'summary' => [
                'ready_for_implementation' => $ready,
                'failed_static_scan_count' => (int) data_get($checks, 'architecture_validate.failed_static_scan_count', 0),
                'split_required_count' => (int) data_get($checks, 'documentation_health.split_required_count', 0),
                'total_split_required_count' => (int) data_get($checks, 'documentation_health.total_split_required_count', 0),
                'provider_projection_status' => (string) data_get($checks, 'provider_projection.status', 'unknown'),
                'message' => $ready
                    ? 'Architecture gates are green; continue through session-bootstrap, feature-placement and focused validation.'
                    : 'Fix failed readiness checks before expanding the mother architecture.',
            ],
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
            'coverage_boundary' => $this->implementedVsScaffoldCoverageBoundary(),
            'safe_next_blocks' => $this->implementedVsScaffoldSafeNextBlocks(),
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
    private function implementedVsScaffoldCoverageBoundary(): array
    {
        return [
            'schema_version' => 'atlas.implemented_vs_scaffold.coverage_boundary.v1',
            'source_doc' => 'docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md',
            'status' => File::exists($this->implementedVsScaffoldMatrixPath()) ? 'available' : 'missing_source_doc',
            'authority' => 'diagnostic_read_model_only',
            'rule' => 'do_not_treat_matrix_as_backlog_parallel',
            'covered_areas' => [
                'kernel',
                'domains',
                'memory',
                'evidence',
                'curator',
                'providers',
                'surfaces',
                'mcp',
                'mobile',
                'engineering',
                'tools',
                'semantic_layer',
                'product_substrate',
            ],
        ];
    }

    /**
     * @return array<int,array{order:int,block:string,why_safe:string,dod_minimum:string}>
     */
    private function implementedVsScaffoldSafeNextBlocks(): array
    {
        $path = $this->implementedVsScaffoldMatrixPath();
        if (! File::exists($path)) {
            return [];
        }

        $body = File::get($path);
        $start = strpos($body, '## Safe Next Blocks');
        if ($start === false) {
            return [];
        }

        $section = substr($body, $start);
        $next = strpos($section, "\n## ", 1);
        if ($next !== false) {
            $section = substr($section, 0, $next);
        }

        return collect(preg_split('/\R/', $section) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => preg_match('/^\|\s*\d+\s*\|/', $line) === 1)
            ->map(function (string $line): ?array {
                $columns = array_map('trim', explode('|', trim($line, '|')));
                if (count($columns) < 4) {
                    return null;
                }

                return [
                    'order' => (int) $columns[0],
                    'block' => $columns[1],
                    'why_safe' => $columns[2],
                    'dod_minimum' => $columns[3],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function implementedVsScaffoldMatrixPath(): string
    {
        return base_path('docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md');
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
            'runtime_language_boundary',
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
            'owner_layer_operations' => [
                'runtime' => $this->operations->summary(['owner_layer' => 'runtime']),
            ],
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
