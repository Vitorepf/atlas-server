<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Services\Ai\Instrumentation\AtlasProviderProjectionAuditService;
use App\Services\Ai\Instrumentation\AtlasProviderProjectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AtlasMemoryProjectionCommand extends Command
{
    protected $signature = 'atlas:memory:projection
        {action=preview : preview, review, apply, write, inspect, status, adopt, audit-summary or audit-purge}
        {--target=claude : claude, agents or all}
        {--workspace= : Workspace root where projection files live}
        {--project-id= : Project UUID}
        {--task-id= : Task UUID}
        {--run-id= : Engineering run UUID}
        {--max-lines= : Maximum lines per generated file}
        {--memory-limit= : Maximum provider-safe memories to project}
        {--audit-days= : Days to include in audit summary}
        {--retention-days= : Purge audit rows older than this many days}
        {--initiator= : Audit initiator filter: api, cli or system}
        {--status= : Audit status filter}
        {--ok= : Audit ok filter: true or false}
        {--force : Overwrite unmanaged or manually edited projection files}
        {--yes : Confirm apply without interactive prompt}
        {--json : Print machine-readable JSON}';

    protected $description = 'Generate provider-safe Atlas memory projection files such as CLAUDE.md and AGENTS.md.';

    public function handle(AtlasProviderProjectionService $projection, AtlasProviderProjectionAuditService $audits): int
    {
        $action = strtolower(trim((string) $this->argument('action')));
        $action = $action === 'diff' ? 'review' : $action;
        if (! in_array($action, ['preview', 'review', 'apply', 'write', 'inspect', 'status', 'adopt', 'audit-summary', 'audit-purge'], true)) {
            $this->error("Acao invalida para atlas:memory:projection: {$action}");

            return self::FAILURE;
        }

        $payload = match ($action) {
            'audit-summary' => $audits->summary($this->auditFilters(), $this->integerOption('audit-days', 30, 1, 365)),
            'audit-purge' => $this->auditPurge($audits),
            'status' => $projection->status((string) $this->option('target'), $this->projectionContext(), $this->optionsPayload()),
            'review' => $projection->review((string) $this->option('target'), $this->projectionContext(), $this->optionsPayload()),
            'apply' => $this->applyReviewed($projection, $audits),
            default => [
                'projections' => collect($projection->targets((string) $this->option('target')))
                    ->map(fn (string $target): array => $this->runTarget($projection, $action, $target))
                    ->values()
                    ->all(),
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $action === 'apply' && ! (bool) ($payload['ok'] ?? false)
                ? self::FAILURE
                : self::SUCCESS;
        }

        if ($action === 'apply') {
            return $this->renderApply($payload);
        }

        if ($action === 'audit-summary') {
            return $this->renderAuditSummary($payload);
        }

        if ($action === 'audit-purge') {
            return $this->renderAuditPurge($payload);
        }

        return $this->renderHuman($action, $payload);
    }

    private function runTarget(AtlasProviderProjectionService $projection, string $action, string $target): array
    {
        return match ($action) {
            'write' => $projection->write($target, $this->projectionContext(), $this->optionsPayload()),
            'inspect' => $projection->inspect($target, $this->projectionContext(), $this->optionsPayload()),
            'adopt' => $projection->adopt($target, $this->projectionContext(), $this->optionsPayload()),
            default => $projection->generate($target, $this->projectionContext(), $this->optionsPayload()),
        };
    }

    private function auditPurge(AtlasProviderProjectionAuditService $audits): array
    {
        $filters = $this->auditFilters();
        $retentionDays = $this->integerOption('retention-days', 90, 1, 3650);
        if (! (bool) $this->option('yes')) {
            return $audits->purge($filters, $retentionDays, true);
        }

        $dryRun = $audits->purge($filters, $retentionDays, true);

        return $audits->purge([
            ...$filters,
            'confirmation_fingerprint' => $dryRun['confirmation_fingerprint'] ?? null,
        ], $retentionDays, false);
    }

    private function renderHuman(string $action, array $payload): int
    {
        if (in_array($action, ['status', 'review'], true)) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Provider Projection</>', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Workspace', (string) ($payload['workspace'] ?? '-'));
            $this->components->twoColumnDetail('Resumo', (string) ($payload['detail'] ?? '-'));
        }

        $rows = [];
        foreach ((array) ($payload['projections'] ?? []) as $projection) {
            if ($action === 'preview') {
                $this->line((string) ($projection['content'] ?? ''));
                $this->newLine();
            }

            if ($action === 'review' && (string) ($projection['diff'] ?? '') !== '') {
                $this->line((string) $projection['diff']);
                $this->newLine();
            }

            $rows[] = [
                $projection['target'] ?? '-',
                $projection['path'] ?? '-',
                (string) ($projection['line_count'] ?? $projection['proposed_line_count'] ?? '-'),
                (string) ($projection['memory_count'] ?? '-'),
                $this->status($projection),
            ];
        }

        $this->table(['target', 'path', 'lines', 'memories', 'status'], $rows);

        $actions = (array) ($payload['next_actions'] ?? []);
        if ($actions !== []) {
            $this->newLine();
            $this->line('Proximas acoes:');
            foreach ($actions as $nextAction) {
                $this->line('  - '.$nextAction);
            }
        }

        return self::SUCCESS;
    }

    private function applyReviewed(AtlasProviderProjectionService $projection, AtlasProviderProjectionAuditService $audits): array
    {
        $review = $projection->review((string) $this->option('target'), $this->projectionContext(), $this->optionsPayload());

        if ((bool) $this->option('json') && ! (bool) $this->option('yes')) {
            return [
                'ok' => false,
                'status' => 'confirmation_required',
                'error' => 'confirmation_required',
                'message' => 'Use --yes para aplicar uma review de provider projection via JSON.',
                'review' => $review,
            ];
        }

        if (! (bool) $this->option('json') && ! (bool) $this->option('yes')) {
            $this->renderHuman('review', $review);
            if (! $this->confirm('Aplicar as mudancas revisadas de provider projection?', false)) {
                return [
                    'ok' => false,
                    'status' => 'cancelled',
                    'cancelled' => true,
                    'message' => 'Aplicacao cancelada pelo operador.',
                    'review' => $review,
                ];
            }
        }

        $result = $projection->applyReviewed((string) $this->option('target'), $this->projectionContext(), $this->optionsPayload()) + [
            'confirmed' => true,
            'confirmation_mode' => (bool) $this->option('yes') ? 'flag' : 'interactive',
        ];
        $audit = $audits->recordApply($result, $this->projectionContext(), [
            'initiator' => 'cli',
            'confirmation_mode' => (bool) $this->option('yes') ? 'flag' : 'interactive',
            'target' => (string) $this->option('target'),
        ]);

        return $result + [
            'audit' => $audit ? $audits->payload($audit) : null,
        ];
    }

    private function renderApply(array $payload): int
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Provider Projection Apply</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Workspace', (string) ($payload['workspace'] ?? '-'));
        $this->components->twoColumnDetail('Resumo', (string) ($payload['detail'] ?? $payload['message'] ?? '-'));

        $rows = collect((array) ($payload['applied'] ?? []))
            ->map(fn (array $result): array => [
                $result['target'] ?? '-',
                $result['path'] ?? '-',
                $result['change_type'] ?? '-',
                (bool) ($result['written'] ?? false) ? 'written' : (string) ($result['error'] ?? 'not_written'),
            ])
            ->values()
            ->all();

        if ($rows !== []) {
            $this->newLine();
            $this->table(['target', 'path', 'change', 'status'], $rows);
        }

        $blocked = (array) ($payload['blocked'] ?? []);
        if ($blocked !== []) {
            $this->newLine();
            $this->warn('Itens bloqueados para revisao manual:');
            foreach ($blocked as $item) {
                $this->line('  - '.($item['target'] ?? '-').': '.($item['reason'] ?? $item['change_type'] ?? 'manual_drift'));
            }
        }

        return (bool) ($payload['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    private function renderAuditSummary(array $payload): int
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Provider Projection Audit</>', 'summary');
        $this->components->twoColumnDetail('Periodo', (string) ($payload['period_days'] ?? '-').' dias');
        $this->components->twoColumnDetail('Total', (string) ($payload['total'] ?? 0));
        $this->components->twoColumnDetail('Aplicados', (string) ($payload['applied'] ?? 0));
        $this->components->twoColumnDetail('Bloqueados', (string) ($payload['blocked'] ?? 0));

        $rows = collect((array) ($payload['by_target'] ?? []))
            ->map(fn (array $row): array => [
                $row['value'] ?? '-',
                (string) ($row['total'] ?? 0),
                (string) ($row['applied'] ?? 0),
                (string) ($row['blocked'] ?? 0),
            ])
            ->values()
            ->all();

        if ($rows !== []) {
            $this->newLine();
            $this->table(['target', 'total', 'applied', 'blocked'], $rows);
        }

        return self::SUCCESS;
    }

    private function renderAuditPurge(array $payload): int
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Provider Projection Audit</>', 'purge');
        $this->components->twoColumnDetail('Dry run', (bool) ($payload['dry_run'] ?? true) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Retencao', (string) ($payload['older_than_days'] ?? '-').' dias');
        $this->components->twoColumnDetail('Encontrados', (string) ($payload['matched'] ?? 0));
        $this->components->twoColumnDetail('Removidos', (string) ($payload['deleted'] ?? 0));

        return self::SUCCESS;
    }

    private function status(array $projection): string
    {
        if (isset($projection['change_type'])) {
            return (bool) ($projection['changed'] ?? false) ? (string) $projection['change_type'] : 'ok';
        }

        if (($projection['exists'] ?? true) === false) {
            return 'missing';
        }

        if (($projection['managed'] ?? true) === false) {
            return 'unmanaged';
        }

        if (isset($projection['manual_drift'])) {
            return ($projection['manual_drift'] ?? false)
                ? 'manual_drift'
                : (($projection['stale'] ?? false) ? 'stale' : 'ok');
        }

        if (isset($projection['written'])) {
            return ($projection['written'] ?? false) ? 'written' : (string) ($projection['error'] ?? 'not_written');
        }

        return 'preview';
    }

    /**
     * @return array<string,mixed>
     */
    private function projectionContext(): array
    {
        return [
            'workspace' => $this->stringOption('workspace'),
            'project_id' => $this->stringOption('project-id'),
            'task_id' => $this->stringOption('task-id'),
            'engineering_run_id' => $this->stringOption('run-id'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function optionsPayload(): array
    {
        return array_filter([
            'workspace' => $this->stringOption('workspace'),
            'max_lines' => $this->stringOption('max-lines'),
            'memory_limit' => $this->stringOption('memory-limit'),
            'force' => (bool) $this->option('force'),
        ], fn (mixed $value): bool => $value !== null);
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? Str::of($value)->trim()->value() : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function auditFilters(): array
    {
        return array_filter([
            'workspace' => $this->stringOption('workspace'),
            'target' => $this->input->hasParameterOption('--target') ? $this->stringOption('target') : 'all',
            'initiator' => $this->stringOption('initiator'),
            'status' => $this->stringOption('status'),
            'ok' => $this->booleanOption('ok'),
        ], fn (mixed $value): bool => $value !== null);
    }

    private function integerOption(string $key, int $default, int $min, int $max): int
    {
        $value = $this->option($key);
        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    private function booleanOption(string $key): ?bool
    {
        $value = $this->option($key);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => null,
        };
    }
}
