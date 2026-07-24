<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\Programming\AtlasForgeLiveExecutionService;
use Illuminate\Console\Command;
use App\Support\YesNo;

/**
 * Atlas Forge Live Execution E2E v1.
 *
 * Executa o caminho real ponta-a-ponta: sandbox -> patch fixture -> patch
 * verifier -> teste local -> stage receipts -> repair plan -> Evidence Ledger
 * -> rollback. Sem provider externo.
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md
 */
class AtlasForgeLiveExecuteCommand extends Command
{
    use ReadsNonEmptyStringOption;

    use EmitsCanonicalJson;

    protected $signature = 'atlas:forge:live-execute
        {--obra= : UUID de Obra obrigatorio para Forge (sem Obra o comando falha fechado)}
        {--simulate-failure : Forca cenario de falha controlada para validar o repair loop}
        {--json : Imprime resultado em JSON canonico}
        {--strict : Exit non-zero quando forge_live_execution_status nao for passed}';

    protected $description = 'Prova executavel da cadeia Forge real: sandbox, patch fixture, harness fixture, repair loop, Evidence Ledger. Falha fechado sem --obra.';

    public function handle(AtlasForgeLiveExecutionService $service): int
    {
        $report = $service->execute([
            'obra_id' => $this->stringOption('obra'),
            'simulate_test_failure' => (bool) $this->option('simulate-failure'),
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
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Forge Live Execution</>', (string) $report['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $report['forge_live_execution_status']);
        $this->components->twoColumnDetail('E2E command', (string) $report['e2e_command']);
        $this->components->twoColumnDetail('External provider call', YesNo::format($report['external_provider_call']));
        $this->components->twoColumnDetail('Sandbox mode', (string) data_get($report, 'sandbox.mode', '-'));
        $this->components->twoColumnDetail('Sandbox provisioning', (string) data_get($report, 'sandbox.provisioning_mode', '-'));
        $this->components->twoColumnDetail('Ledger events', (string) count((array) ($report['ledger_event_ids'] ?? [])));

        $stages = is_array($report['stages'] ?? null) ? $report['stages'] : [];
        foreach ($stages as $stage) {
            $name = (string) ($stage['name'] ?? 'stage');
            $status = (string) ($stage['status'] ?? 'unknown');
            $blocker = (string) ($stage['blocker'] ?? '-');
            $this->components->twoColumnDetail("· {$name}", "{$status}".($blocker !== '-' && $blocker !== '' ? " ({$blocker})" : ''));
        }

        $blockers = is_array($report['remaining_blockers'] ?? null) ? $report['remaining_blockers'] : [];
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

        return ($report['forge_live_execution_status'] ?? null) === 'passed'
            ? self::SUCCESS
            : self::FAILURE;
    }


}
