<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\Programming\AtlasForgeRuntimeCertificationService;
use Illuminate\Console\Command;
use App\Support\YesNo;

/**
 * Certifica o runtime real do Atlas Forge ponta-a-ponta.
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-runtime-certification-one-shot.md
 */
class AtlasForgeRuntimeCertifyCommand extends Command
{
    use ReadsNonEmptyStringOption;

    use EmitsCanonicalJson;

    protected $signature = 'atlas:forge:runtime-certify
        {--obra= : UUID de Obra real para capturar evidence vinculada (opcional)}
        {--json : Imprime resultado em JSON canonico}
        {--strict : Exit non-zero quando forge_core_status nao for passed}';

    protected $description = 'Prova replayable da cadeia Atlas Code -> Obra -> Forge Workspace -> programming.forge -> Decide -> Receipt -> Governance -> Evidence.';

    public function handle(AtlasForgeRuntimeCertificationService $service): int
    {
        $report = $service->certify([
            'obra_id' => $this->stringOption('obra'),
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
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Forge Runtime Certification</>', (string) $report['schema_version']);
        $this->components->twoColumnDetail('Forge core', (string) $report['forge_core_status']);
        $this->components->twoColumnDetail('External Rivals', (string) $report['external_rivals_status']);
        $this->components->twoColumnDetail('E2E command', (string) $report['e2e_command']);
        $this->components->twoColumnDetail('Obra provided', data_getYesNo::format($report, 'inputs.obra_provided'));

        $stages = is_array($report['stages'] ?? null) ? $report['stages'] : [];
        foreach ($stages as $stage) {
            $name = (string) ($stage['name'] ?? 'stage');
            $status = (string) ($stage['status'] ?? 'unknown');
            $blocker = (string) ($stage['blocker'] ?? '-');
            $this->components->twoColumnDetail("· {$name}", "{$status}".($blocker !== '-' ? " ({$blocker})" : ''));
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

        return ($report['forge_core_status'] ?? null) === 'passed'
            ? self::SUCCESS
            : self::FAILURE;
    }


}
