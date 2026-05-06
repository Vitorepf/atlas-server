<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasArchitectureOperationsCatalog
{
    /**
     * @param  array<int,array<string,mixed>>|null  $commandsOverride
     */
    public function __construct(
        private readonly ?array $commandsOverride = null,
    ) {}

    public function sectionKey(): string
    {
        return 'arquitetura_mae';
    }

    /**
     * @return array<int,array{id:string,command:string,description:string,surface:string,kind:string,output:string}>
     */
    public function commands(): array
    {
        if ($this->commandsOverride !== null) {
            return $this->commandsOverride;
        }

        return [
            [
                'id' => 'architecture_operations',
                'command' => 'atlas ai architecture-operations --json',
                'description' => 'Lista o catalogo canonico de comandos da arquitetura mae consumido por CLI help, Observability e Open Brain MCP.',
                'surface' => 'cli',
                'kind' => 'catalog',
                'output' => 'json',
            ],
            [
                'id' => 'architecture_validate',
                'command' => 'atlas ai architecture-validate',
                'description' => 'Valida contratos executaveis da arquitetura mae: capabilities, domains, adapters, providers, SLOs e APs estaticos.',
                'surface' => 'cli',
                'kind' => 'validation',
                'output' => 'human_json',
            ],
            [
                'id' => 'kernel_slo_report',
                'command' => 'atlas ai slo --hours=24 --json',
                'description' => 'Audita SLOs do kernel por janela usando o Evidence Ledger e review_signal canonico.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'kernel_pipeline_report',
                'command' => 'atlas ai kernel-pipeline-report --hours=24 --json',
                'description' => 'Mostra aceite/rejeicao do Kernel Pipeline por surface, flow, input mode e contract source.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'repair_report',
                'command' => 'atlas ai repair-report --hours=24 --json',
                'description' => 'Resume Repair Loop por strategy, failure domain, status e recommended_action.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'provider_performance_report',
                'command' => 'atlas ai provider-performance --hours=24 --json',
                'description' => 'Projeta performance empirica de providers a partir do Ledger para apoiar Atlas Decide e Self-Improvement.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'decision_receipt_report',
                'command' => 'atlas ai decision-receipt-report --envelope=<id> --json',
                'description' => 'Audita replay de DecisionReceipt por envelope, verificando receipt_hash, chain_hash e review_signal.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'ledger_replay',
                'command' => 'atlas ledger replay --envelope=<id> --json',
                'description' => 'Reproduz a timeline append-only do Evidence Ledger para um envelope, usando o mesmo report canonico de atlas ai ledger.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'ledger_projection_worker',
                'command' => 'atlas ai ledger-project --limit=500 --json',
                'description' => 'Projeta eventos append-only do Evidence Ledger para read models operacionais como ai_traces, atlas_engineering_runs e atlas_tool_runs.',
                'surface' => 'cli',
                'kind' => 'maintenance',
                'output' => 'json',
            ],
            [
                'id' => 'self_improvement_schedule_report',
                'command' => 'atlas ai self-improvement-schedule-report --hours=24 --json',
                'description' => 'Audita schedule replay do Self-Improvement, proposals emitidas e refs do Inbox.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'inbox_action_report',
                'command' => 'atlas ai inbox-action-report --hours=24 --json',
                'description' => 'Audita acoes humanas do Inbox, incluindo review_patch, refs de diff e gaps que o Curator deve aprender.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
        ];
    }

    /**
     * @param  array{id?:string|null,kind?:string|null}  $filters
     * @return array{schema_version:string,section:string,command_count:int,operation_ids:array<int,string>,filters:array<string,string>,commands:array<int,array<string,mixed>>}
     */
    public function summary(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $commands = array_values(array_filter(
            $this->commands(),
            fn (array $command): bool => $this->matchesFilters($command, $filters),
        ));

        return [
            'schema_version' => 'atlas.architecture_operations.v1',
            'section' => $this->sectionKey(),
            'command_count' => count($commands),
            'operation_ids' => array_values(array_filter(array_map(
                fn (array $command): ?string => is_string($command['id'] ?? null) ? $command['id'] : null,
                $commands,
            ))),
            'filters' => $filters,
            'commands' => $commands,
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,string>
     */
    private function normalizeFilters(array $filters): array
    {
        return array_filter([
            'id' => is_string($filters['id'] ?? null) && $filters['id'] !== '' ? $filters['id'] : null,
            'kind' => is_string($filters['kind'] ?? null) && $filters['kind'] !== '' ? $filters['kind'] : null,
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * @param  array<string,mixed>  $command
     * @param  array<string,string>  $filters
     */
    private function matchesFilters(array $command, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            if (($command[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }
}
