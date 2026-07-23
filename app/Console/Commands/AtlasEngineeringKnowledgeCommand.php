<?php

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDocsAuthorityGraphService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspacePathResolverService;
use App\Services\Engineering\AtlasCodeIntelligenceAutomaticGateService;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringContextIntelligenceInput;
use App\Services\Engineering\EngineeringDocumentationHealthService;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AtlasEngineeringKnowledgeCommand extends Command
{
    use EmitsCanonicalJson;

    private ?EngineeringContextIntelligenceInput $contextInput = null;

    protected $signature = 'atlas:engineering:knowledge
        {action=status : status, sync, list, show, context, docs-health, docs-health-baseline, index-code, code-gate, audit-code, code-readiness, code-status, modules, symbols or show-module}
        {item? : Knowledge item slug/id or code module slug/id}
        {--category= : Filter by category}
        {--status= : active, draft, archived or deprecated}
        {--layer= : Filter code modules by layer}
        {--module= : Filter symbols by code module slug or id}
        {--symbol-type= : Filter symbols by type}
        {--language= : Filter symbols by language}
        {--docs-status= : Filter code modules/symbols by documentation status}
        {--q= : Search title, slug, summary or canonical path}
        {--limit=50 : Maximum items}
        {--workspace= : Workspace path to scan for code intelligence}
        {--run-context-type= : Optional Atlas Tool Runtime context type for code intelligence evidence}
        {--run-context-id= : Optional Atlas Tool Runtime context id for code intelligence evidence}
        {--dry-run : Preview sync without writing}
        {--prune : Archive canonical records whose markdown no longer exists}
        {--summary-only : For JSON index-code output, omit large module and symbol previews while preserving persisted indexing}
        {--auto-refresh : For code-gate, refresh index-code automatically when safe and necessary}
        {--max-age-minutes=1440 : For code-gate, maximum accepted index age}
        {--strict : For code-gate, return failure unless the gate is ready}
        {--enforce : For docs-health, exit non-zero only on blocking (non-baseline) violations}
        {--freeze : For docs-health-baseline, (re)write the baseline lockfile from current violations}
        {--json : Print machine-readable JSON}';

    protected $description = 'Sync and inspect the Atlas Engineering Knowledge Base.';

    public function handle(
        EngineeringKnowledgeBaseService $knowledge,
        EngineeringCodeIntelligenceService $code,
        EngineeringDocumentationHealthService $documentationHealth,
        EngineeringContextIntelligenceInput $input,
        AtlasCodeIntelligenceAutomaticGateService $codeGate,
        AtlasWorkspaceIntelligenceExecutionGateService $workspaceGate,
        AtlasWorkspacePathResolverService $workspacePaths,
    ): int {
        $this->contextInput = $input;

        $action = (string) $this->argument('action');

        return match ($action) {
            'sync' => $this->renderSync($knowledge),
            'list' => $this->renderList($knowledge),
            'show' => $this->renderShow($knowledge),
            'context' => $this->renderContext($knowledge),
            'docs-health' => $this->renderDocsHealth($documentationHealth),
            'docs-health-baseline' => $this->renderDocsHealthBaseline($documentationHealth),
            'index-code' => $this->renderCodeIndex($code, $workspaceGate, $workspacePaths),
            'code-gate' => $this->renderCodeGate($codeGate),
            'audit-code' => $this->renderCodeAudit($code),
            'code-readiness' => $this->renderCodeReadiness($code),
            'code-status' => $this->renderCodeStatus($code),
            'modules' => $this->renderModules($code),
            'symbols' => $this->renderSymbols($code),
            'show-module' => $this->renderShowModule($code),
            'status' => $this->renderStatus($knowledge),
            default => $this->invalidAction($action),
        };
    }

    private function renderSync(EngineeringKnowledgeBaseService $knowledge): int
    {
        $payload = $knowledge->sync([
            'dry_run' => (bool) $this->option('dry-run'),
            'prune' => (bool) $this->option('prune'),
        ]);

        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($payload));

            return $payload['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $summary = $payload['summary'];
        $this->components->twoColumnDetail('docs', (string) $payload['docs_root']);
        $this->components->twoColumnDetail('created', (string) ($summary['created'] ?? 0));
        $this->components->twoColumnDetail('updated', (string) ($summary['updated'] ?? 0));
        $this->components->twoColumnDetail('unchanged', (string) ($summary['unchanged'] ?? 0));
        $this->components->twoColumnDetail('archived', (string) ($summary['archived'] ?? 0));
        $this->components->twoColumnDetail('failed', (string) ($summary['failed'] ?? 0));

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderStatus(EngineeringKnowledgeBaseService $knowledge): int
    {
        $payload = ['summary' => $knowledge->summary()];
        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($payload));

            return self::SUCCESS;
        }

        $summary = $payload['summary'];
        $this->components->twoColumnDetail('status', (string) ($summary['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('docs', (string) ($summary['docs_root'] ?? '-'));
        $this->components->twoColumnDetail('items', (string) ($summary['active'] ?? 0).'/'.(string) ($summary['total'] ?? 0));
        $this->components->twoColumnDetail('last indexed', (string) ($summary['last_indexed_at'] ?? '-'));

        return self::SUCCESS;
    }

    private function renderList(EngineeringKnowledgeBaseService $knowledge): int
    {
        $payload = $knowledge->catalog($this->filters(), $this->contextInput()->knowledgeLimit($this->option('limit')));
        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($payload));

            return self::SUCCESS;
        }

        $this->table(
            ['slug', 'category', 'priority', 'status', 'summary'],
            collect($payload['items'])->map(fn (array $item): array => [
                $item['slug'],
                $item['category'],
                $item['priority'],
                $item['status'],
                Str::limit((string) ($item['summary'] ?? ''), 90),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function renderShow(EngineeringKnowledgeBaseService $knowledge): int
    {
        $item = (string) $this->argument('item');
        if (trim($item) === '') {
            $this->error('Informe o slug ou id do item.');

            return self::FAILURE;
        }

        $payload = $knowledge->find($item);
        if ($payload === null) {
            $this->error('Knowledge item nao encontrado.');

            return self::FAILURE;
        }

        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject(['knowledge_item' => $payload]));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('slug', (string) $payload['slug']);
        $this->components->twoColumnDetail('category', (string) $payload['category']);
        $this->components->twoColumnDetail('path', (string) $payload['canonical_path']);
        $this->line((string) ($payload['summary'] ?? ''));

        return self::SUCCESS;
    }

    private function renderContext(EngineeringKnowledgeBaseService $knowledge): int
    {
        $payload = [
            'knowledge_refs' => $knowledge->contextRefs([
                'category' => $this->stringOption('category'),
                'tags' => array_filter([
                    $this->option('category') ?: null,
                    $this->option('q') ?: null,
                ]),
            ], $this->contextInput()->knowledgeLimit($this->option('limit'))),
        ];

        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($payload));

            return self::SUCCESS;
        }

        $this->table(
            ['slug', 'category', 'reason', 'summary'],
            collect($payload['knowledge_refs'])->map(fn (array $item): array => [
                $item['slug'],
                $item['category'],
                $item['reason'],
                Str::limit((string) ($item['summary'] ?? ''), 90),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function renderDocsHealth(EngineeringDocumentationHealthService $documentationHealth): int
    {
        $payload = $documentationHealth->report();
        $enforce = (bool) $this->option('enforce');
        $enforcementStatus = (string) data_get(
            $payload,
            'enforcement.status',
            ($payload['status'] ?? null) === 'ok' ? 'green' : 'failed',
        );
        // Legacy exit: any violation fails. Enforce exit (the ratchet): only NEW
        // (blocking) violations fail; frozen legacy debt does not.
        $exit = $enforce
            ? ($enforcementStatus === 'failed' ? self::FAILURE : self::SUCCESS)
            : (($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE);

        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($payload));

            return $exit;
        }

        $summary = $payload['summary'];
        $this->components->twoColumnDetail('status', (string) $payload['status']);
        $this->components->twoColumnDetail('enforcement', $enforcementStatus.($enforce ? ' (enforced)' : ''));
        $this->components->twoColumnDetail('blocking (new)', (string) data_get($payload, 'enforcement.blocking_count', 0));
        $this->components->twoColumnDetail('legacy debt (frozen)', (string) data_get($payload, 'enforcement.legacy_debt_count', 0));
        $this->components->twoColumnDetail('docs', (string) ($summary['docs_root'] ?? '-'));
        $this->components->twoColumnDetail('docs count', (string) ($summary['doc_count'] ?? 0));
        $this->components->twoColumnDetail('required missing', (string) ($summary['required_missing_count'] ?? 0));
        $this->components->twoColumnDetail('oversized', (string) ($summary['oversized_count'] ?? 0));
        $this->components->twoColumnDetail('frontmatter violations', (string) ($summary['frontmatter_violation_count'] ?? 0));
        $this->components->twoColumnDetail('canonical coverage violations', (string) ($summary['canonical_module_coverage_violation_count'] ?? 0));
        $this->components->twoColumnDetail('canonical module violations', (string) ($summary['canonical_module_violation_count'] ?? 0));
        $this->components->twoColumnDetail('warnings (non-blocking)', (string) ($summary['warning_count'] ?? 0));

        if (($payload['oversized_docs'] ?? []) !== []) {
            $this->table(
                ['path', 'lines', 'limit', 'status'],
                collect($payload['oversized_docs'])->take(10)->map(fn (array $doc): array => [
                    $doc['path'],
                    $doc['line_count'],
                    $doc['limit'],
                    $doc['status'],
                ])->all(),
            );
        }

        if (($payload['blocking'] ?? []) !== []) {
            $this->newLine();
            $this->warn('Blocking (new, not in baseline) — these fail --enforce:');
            $this->table(
                ['#', 'violation'],
                collect($payload['blocking'])->take(15)->values()->map(fn (string $v, int $i): array => [
                    $i + 1,
                    Str::limit($v, 110),
                ])->all(),
            );
        }

        if (($payload['warnings'] ?? []) !== []) {
            $this->table(
                ['rule', 'path'],
                collect($payload['warnings'])->take(15)->map(fn (array $warning): array => [
                    $warning['rule'],
                    $warning['path'],
                ])->all(),
            );
        }

        return $exit;
    }

    private function renderDocsHealthBaseline(EngineeringDocumentationHealthService $documentationHealth): int
    {
        if (! (bool) $this->option('freeze')) {
            $report = $documentationHealth->report();
            $payload = [
                'schema_version' => 'atlas.docs_health.baseline.status.v1',
                'baseline_present' => $documentationHealth->baselineExists(),
                'baseline_path' => $documentationHealth->relativeBaselinePath(),
                'enforcement' => $report['enforcement'] ?? [],
                'hint' => 'Run with --freeze to (re)write the baseline lockfile from current violations.',
            ];

            if ($this->json()) {
                $this->line($this->encodeOrEmptyObject($payload));

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('baseline', $payload['baseline_present'] ? 'present' : 'absent');
            $this->components->twoColumnDetail('path', (string) $payload['baseline_path']);
            $this->components->twoColumnDetail('blocking (new)', (string) data_get($payload, 'enforcement.blocking_count', 0));
            $this->components->twoColumnDetail('legacy debt (frozen)', (string) data_get($payload, 'enforcement.legacy_debt_count', 0));
            $this->warn('Nothing frozen. Run with --freeze to write the lockfile.');

            return self::SUCCESS;
        }

        $payload = $documentationHealth->freezeBaseline();
        $payload['baseline_path'] = $documentationHealth->relativeBaselinePath();

        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($payload));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('frozen violations', (string) ($payload['violation_count'] ?? 0));
        $this->components->twoColumnDetail('path', (string) $payload['baseline_path']);
        $this->components->twoColumnDetail('ratchet', (string) ($payload['ratchet'] ?? '-'));
        $this->info('Baseline frozen. docs-health --enforce now blocks only regressions (new violations).');

        return self::SUCCESS;
    }

    private function renderCodeIndex(
        EngineeringCodeIntelligenceService $code,
        AtlasWorkspaceIntelligenceExecutionGateService $workspaceGate,
        AtlasWorkspacePathResolverService $workspacePaths,
    ): int {
        $workspaceResolution = $workspacePaths->resolveForExecution($this->stringOption('workspace'));
        if (($workspaceResolution['status'] ?? null) !== 'ready') {
            $payload = [
                'schema_version' => 'atlas.engineering.code_index.awis_gate.v1',
                'ok' => false,
                'status' => 'blocked',
                'error' => 'workspace_required_for_index_code',
                'message' => 'index-code exige --workspace resolvivel para um Workspace AWIS registrado.',
                'awis_execution_gate' => $workspaceGate->gate(
                    workspace: '__missing_index_code_workspace__',
                    mode: 'index-code',
                    task: 'engineering knowledge index-code',
                ),
            ];

            if ($this->json()) {
                $this->line($this->encodeOrEmptyObject($payload));

                return self::FAILURE;
            }

            $this->error((string) $payload['message']);

            return self::FAILURE;
        }

        $gate = $workspaceGate->gate(
            workspace: (string) $workspaceResolution['workspace_slug'],
            mode: 'index-code',
            task: 'engineering knowledge index-code',
        );
        if (($gate['allowed'] ?? false) !== true) {
            $payload = [
                'schema_version' => 'atlas.engineering.code_index.awis_gate.v1',
                'ok' => false,
                'status' => 'blocked',
                'error' => 'awis_execution_gate_blocked',
                'awis_execution_gate' => $gate,
            ];

            if ($this->json()) {
                $this->line($this->encodeOrEmptyObject($payload));

                return self::FAILURE;
            }

            $this->error('AWIS bloqueou index-code: workspace nao esta pronto para execucao mutativa.');

            return self::FAILURE;
        }

        $payload = $code->index([
            'workspace' => (string) $workspaceResolution['workspace_path'],
            'dry_run' => (bool) $this->option('dry-run'),
            'prune' => (bool) $this->option('prune'),
            'run_context_type' => $this->stringOption('run-context-type'),
            'run_context_id' => $this->stringOption('run-context-id'),
        ]);
        $payload['awis_execution_gate'] = $gate;

        // R1: keep the docs authority graph (atlas:docs:locate) fresh whenever the
        // code index is rebuilt. Best-effort — a graph failure must never fail
        // index-code (the code index is the primary deliverable here).
        if (($payload['ok'] ?? false) === true && ! (bool) $this->option('dry-run')) {
            try {
                $payload['docs_authority_graph'] = app(AtlasDocsAuthorityGraphService::class)->build();
            } catch (\Throwable $e) {
                $payload['docs_authority_graph'] = ['error' => $e::class, 'message' => $e->getMessage()];
            }
        }

        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($this->summaryOnly() ? $this->compactCodeIndexPayload($payload) : $payload));

            return $payload['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $summary = $payload['summary'];
        $this->components->twoColumnDetail('workspace', (string) $payload['workspace']);
        $this->components->twoColumnDetail('modules', (string) ($summary['module_count'] ?? 0));
        $this->components->twoColumnDetail('symbols', (string) ($summary['symbol_count'] ?? 0));
        $this->components->twoColumnDetail('routes', (string) ($summary['route_count'] ?? 0));
        $this->components->twoColumnDetail('commands', (string) ($summary['command_count'] ?? 0));
        $this->components->twoColumnDetail('migrations', (string) ($summary['migration_count'] ?? 0));
        $this->components->twoColumnDetail('tests', (string) ($summary['test_count'] ?? 0));
        $this->components->twoColumnDetail('doc links', (string) ($summary['doc_link_count'] ?? 0));

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderCodeGate(AtlasCodeIntelligenceAutomaticGateService $codeGate): int
    {
        $payload = $codeGate->evaluate([
            'workspace' => $this->stringOption('workspace'),
            'mode' => 'readiness',
            'strict_freshness' => true,
            'max_age_minutes' => $this->option('max-age-minutes'),
            'auto_refresh' => (bool) $this->option('auto-refresh'),
            'run_context_type' => $this->stringOption('run-context-type') ?: 'engineering_knowledge_code_gate',
            'run_context_id' => $this->stringOption('run-context-id'),
        ]);

        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($payload));

            return $this->codeGateExitCode($payload);
        }

        $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('workspace', (string) ($payload['workspace'] ?? '-'));
        $this->components->twoColumnDetail('modules', (string) data_get($payload, 'summary.module_count', 0));
        $this->components->twoColumnDetail('symbols', (string) data_get($payload, 'summary.symbol_count', 0));
        $this->components->twoColumnDetail('drift', (string) data_get($payload, 'metrics.drift_total', 0));
        $this->components->twoColumnDetail('cache hit rate', (string) data_get($payload, 'metrics.cache_hit_rate', '-'));
        $this->components->twoColumnDetail('consumers', (string) data_get($payload, 'metrics.consumer_count', 0));
        $this->components->twoColumnDetail('auto refresh', data_get($payload, 'refresh.attempted') ? 'attempted' : 'not attempted');
        $this->components->twoColumnDetail('hash', (string) ($payload['gate_hash'] ?? '-'));

        return $this->codeGateExitCode($payload);
    }

    private function renderCodeStatus(EngineeringCodeIntelligenceService $code): int
    {
        $payload = ['summary' => $code->summary([
            'workspace' => $this->stringOption('workspace'),
        ])];
        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($payload));

            return self::SUCCESS;
        }

        $summary = $payload['summary'];
        $this->components->twoColumnDetail('status', (string) ($summary['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('modules', (string) ($summary['module_count'] ?? 0));
        $this->components->twoColumnDetail('symbols', (string) ($summary['symbol_count'] ?? 0));
        $this->components->twoColumnDetail('routes', (string) ($summary['route_count'] ?? 0));
        $this->components->twoColumnDetail('commands', (string) ($summary['command_count'] ?? 0));
        $this->components->twoColumnDetail('doc links', (string) ($summary['doc_link_count'] ?? 0));
        $this->components->twoColumnDetail('last indexed', (string) ($summary['last_indexed_at'] ?? '-'));

        return self::SUCCESS;
    }

    private function renderCodeAudit(EngineeringCodeIntelligenceService $code): int
    {
        $payload = $code->audit([
            'workspace' => $this->stringOption('workspace'),
            'limit' => $this->contextInput()->codeLimit($this->option('limit')),
            'run_context_type' => $this->stringOption('run-context-type'),
            'run_context_id' => $this->stringOption('run-context-id'),
        ]);

        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($payload));

            return self::SUCCESS;
        }

        $summary = $payload['summary'];
        $drift = $summary['drift'] ?? [];
        $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('workspace', (string) ($payload['workspace'] ?? '-'));
        $this->components->twoColumnDetail('total drift', (string) ($drift['total'] ?? 0));
        $this->components->twoColumnDetail('modules changed', (string) data_get($drift, 'modules.changed', 0));
        $this->components->twoColumnDetail('modules missing', (string) data_get($drift, 'modules.missing_in_index', 0));
        $this->components->twoColumnDetail('modules removed', (string) data_get($drift, 'modules.removed_from_workspace', 0));
        $this->components->twoColumnDetail('symbols added', (string) data_get($drift, 'symbols.added', 0));
        $this->components->twoColumnDetail('symbols removed', (string) data_get($drift, 'symbols.removed', 0));
        $this->components->twoColumnDetail('stale doc links', (string) data_get($drift, 'doc_links.stale_target_hashes', 0));

        return self::SUCCESS;
    }

    private function renderCodeReadiness(EngineeringCodeIntelligenceService $code): int
    {
        $payload = $code->readiness([
            'workspace' => $this->stringOption('workspace'),
            'limit' => $this->contextInput()->codeLimit($this->option('limit')),
            'run_context_type' => $this->stringOption('run-context-type'),
            'run_context_id' => $this->stringOption('run-context-id'),
        ]);

        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($payload));

            return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $summary = $payload['summary'] ?? [];
        $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('modules', (string) ($summary['module_count'] ?? 0));
        $this->components->twoColumnDetail('symbols', (string) ($summary['symbol_count'] ?? 0));
        $this->components->twoColumnDetail('doc links', (string) ($summary['doc_link_count'] ?? 0));
        $this->components->twoColumnDetail('drift', (string) ($summary['drift_total'] ?? 0));
        $this->components->twoColumnDetail('warnings', (string) ($summary['warnings'] ?? 0));

        return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
    }

    private function renderModules(EngineeringCodeIntelligenceService $code): int
    {
        $payload = $code->catalog($this->codeModuleFilters(), $this->contextInput()->codeLimit($this->option('limit')));
        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($payload));

            return self::SUCCESS;
        }

        $this->table(
            ['slug', 'layer', 'docs', 'files', 'symbols', 'routes', 'cmds', 'tests'],
            collect($payload['modules'])->map(fn (array $module): array => [
                $module['slug'],
                $module['layer'],
                $module['docs_status'],
                $module['file_count'],
                $module['symbol_count'],
                $module['route_count'],
                $module['command_count'],
                $module['test_count'],
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function renderSymbols(EngineeringCodeIntelligenceService $code): int
    {
        $payload = $code->symbols($this->codeSymbolFilters(), $this->contextInput()->codeLimit($this->option('limit')));
        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($payload));

            return self::SUCCESS;
        }

        $this->table(
            ['type', 'module', 'name', 'path', 'line', 'docs'],
            collect($payload['symbols'])->map(fn (array $symbol): array => [
                $symbol['symbol_type'],
                $symbol['module_slug'] ?? '-',
                Str::limit((string) $symbol['symbol_name'], 48),
                Str::limit((string) $symbol['file_path'], 52),
                $symbol['line_start'] ?? '-',
                $symbol['docs_status'],
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function renderShowModule(EngineeringCodeIntelligenceService $code): int
    {
        $module = (string) ($this->argument('item') ?: $this->option('module') ?: '');
        if (trim($module) === '') {
            $this->error('Informe o slug ou id do modulo.');

            return self::FAILURE;
        }

        $payload = $code->module($module);
        if ($payload === null) {
            $this->error('Modulo de code intelligence nao encontrado.');

            return self::FAILURE;
        }

        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($payload));

            return self::SUCCESS;
        }

        $modulePayload = $payload['module'];
        $this->components->twoColumnDetail('slug', (string) $modulePayload['slug']);
        $this->components->twoColumnDetail('layer', (string) $modulePayload['layer']);
        $this->components->twoColumnDetail('root', (string) ($modulePayload['root_path'] ?? '-'));
        $this->components->twoColumnDetail('docs', (string) ($modulePayload['docs_status'] ?? '-'));
        $this->components->twoColumnDetail('symbols', (string) count($payload['symbols']));
        $this->components->twoColumnDetail('doc links', (string) count($payload['doc_links']));

        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Acao invalida para atlas:engineering:knowledge: {$action}");

        return self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function filters(): array
    {
        return [
            'category' => $this->stringOption('category'),
            'status' => $this->stringOption('status'),
            'q' => $this->stringOption('q'),
            'include_archived' => $this->stringOption('status') === 'archived',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function codeModuleFilters(): array
    {
        return [
            'layer' => $this->stringOption('layer'),
            'docs_status' => $this->stringOption('docs-status'),
            'q' => $this->stringOption('q'),
            'include_archived' => $this->stringOption('status') === 'archived',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function codeSymbolFilters(): array
    {
        return [
            'module' => $this->stringOption('module'),
            'symbol_type' => $this->stringOption('symbol-type'),
            'language' => $this->stringOption('language'),
            'docs_status' => $this->stringOption('docs-status'),
            'q' => $this->stringOption('q'),
            'include_archived' => $this->stringOption('status') === 'archived',
        ];
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function json(): bool
    {
        return (bool) $this->option('json');
    }

    private function summaryOnly(): bool
    {
        return (bool) $this->option('summary-only');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function codeGateExitCode(array $payload): int
    {
        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function compactCodeIndexPayload(array $payload): array
    {
        unset($payload['modules'], $payload['symbols_preview']);
        $payload['summary_only'] = true;

        return $payload;
    }

    private function contextInput(): EngineeringContextIntelligenceInput
    {
        return $this->contextInput ?? app(EngineeringContextIntelligenceInput::class);
    }

}
