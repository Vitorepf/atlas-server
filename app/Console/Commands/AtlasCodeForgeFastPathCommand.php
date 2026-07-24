<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeForgeFastPathService;
use Illuminate\Console\Command;

/**
 * Atlas Code Forge Operator Fast Path v1 (CLI).
 *
 * Orquestra Obra → WorkItem → Spec/Plan/Tasks → Forge Live Execution → Checkpoint
 * reusando os controllers canonicos. Fail-closed sem obra.
 *
 * Doc: docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md
 */
final class AtlasCodeForgeFastPathCommand extends Command
{
    use ReadsNonEmptyStringOption;

    use EmitsCanonicalJson;

    protected $signature = 'atlas:code:forge-fast-path
        {--obra= : UUID da Obra existente (obrigatorio)}
        {--mode=execute_async : prepare_only | execute_async | execute_sync}
        {--intent= : Intent opcional caso Obra nao tenha goal/desired_outcome}
        {--operator-id= : Identificador do operador (default atlas-code-local-operator)}
        {--no-auto-work-item : Desabilita auto-criacao de WorkItem}
        {--no-auto-spec-plan : Desabilita auto-compilacao de Spec/Plan/Tasks}
        {--no-start-execution : Pula execution dispatch}
        {--create-checkpoint : Cria checkpoint apos execution}
        {--json : Imprime resultado em JSON canonico}
        {--strict : Exit non-zero quando status nao for passed/queued/prepared}';

    protected $description = 'Atlas Code Forge Operator Fast Path v1 — orquestra Obra → WorkItem → Spec/Plan/Tasks → Forge Live Execution.';

    public function handle(AtlasCodeForgeFastPathService $service): int
    {
        $obraId = $this->stringOption('obra');
        $project = $obraId !== null ? AtlasProject::query()->whereKey($obraId)->first() : null;

        $report = $service->run($project, [
            'obra_id' => $obraId,
            'mode' => $this->stringOption('mode') ?? AtlasCodeForgeFastPathService::MODE_EXECUTE_ASYNC,
            'intent' => $this->stringOption('intent'),
            'operator_id' => $this->stringOption('operator-id'),
            'auto_create_work_item' => ! (bool) $this->option('no-auto-work-item'),
            'auto_compile_spec_plan' => ! (bool) $this->option('no-auto-spec-plan'),
            'start_execution' => ! (bool) $this->option('no-start-execution'),
            'create_checkpoint' => (bool) $this->option('create-checkpoint'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));
        } else {
            $this->renderHuman($report);
        }

        return $this->resolveExit($report, (bool) $this->option('strict'));
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderHuman(array $report): void
    {
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Code Forge Fast Path</>', (string) $report['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $report['status']);
        $this->components->twoColumnDetail('Mode', (string) $report['mode']);
        $this->components->twoColumnDetail('Obra', (string) ($report['obra_id'] ?? '—'));
        $this->components->twoColumnDetail('WorkItem', (string) ($report['work_item_code'] ?? '—'));
        $this->components->twoColumnDetail('Spec/Plan', sprintf('%s · %s · %d tasks', $report['spec_hash'] ? substr((string) $report['spec_hash'], 0, 12) : '—', $report['plan_hash'] ? substr((string) $report['plan_hash'], 0, 12) : '—', (int) ($report['task_count'] ?? 0)));
        $this->components->twoColumnDetail('Execution', (string) ($report['execution_id'] ?? $report['history_id'] ?? '—'));
        $this->components->twoColumnDetail('Next action', (string) ($report['next_action'] ?? '—'));

        foreach ((array) ($report['stages'] ?? []) as $stage) {
            $name = (string) ($stage['name'] ?? 'stage');
            $status = (string) ($stage['status'] ?? 'unknown');
            $blocker = (string) ($stage['blocker'] ?? '-');
            $this->components->twoColumnDetail("· {$name}", "{$status}".($blocker !== '-' && $blocker !== '' ? " ({$blocker})" : ''));
        }

        $blockers = is_array($report['blockers'] ?? null) ? $report['blockers'] : [];
        if ($blockers !== []) {
            $this->newLine();
            $this->components->bulletList($blockers);
        }
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function resolveExit(array $report, bool $strict): int
    {
        if (! $strict) {
            return self::SUCCESS;
        }

        return in_array((string) ($report['status'] ?? ''), ['passed', 'queued', 'prepared'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }


}
