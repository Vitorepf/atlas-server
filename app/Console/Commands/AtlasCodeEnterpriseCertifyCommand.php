<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasCodeEnterpriseCertificationService;
use Illuminate\Console\Command;

class AtlasCodeEnterpriseCertifyCommand extends Command
{
    protected $signature = 'atlas:code:enterprise-certify
        {--json : Imprime resultado em JSON canonico}
        {--strict : Exit non-zero quando atlas_code_enterprise_status nao for passed}
        {--keep-workspace : Mantem workspace temporario da certificacao para inspecao manual}';

    protected $description = 'Certifica o produto Atlas Code enterprise pesado: Obra, WorkItem, Forge Live, review, promotion, rollback, checkpoint e history.';

    public function handle(AtlasCodeEnterpriseCertificationService $service): int
    {
        $report = $service->certify([
            'keep_workspace' => (bool) $this->option('keep-workspace'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));
        } else {
            $this->renderHuman($report);
        }

        if (! (bool) $this->option('strict')) {
            return self::SUCCESS;
        }

        return ($report['atlas_code_enterprise_status'] ?? null) === 'passed'
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderHuman(array $report): void
    {
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Code Enterprise</>', (string) $report['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $report['atlas_code_enterprise_status']);
        $this->components->twoColumnDetail('Obra', (string) data_get($report, 'inputs.obra_id', '-'));
        $this->components->twoColumnDetail('External provider call', $report['external_provider_call'] ? 'yes' : 'no');

        foreach ((array) ($report['stages'] ?? []) as $stage) {
            if (! is_array($stage)) {
                continue;
            }
            $name = (string) ($stage['name'] ?? 'stage');
            $status = (string) ($stage['status'] ?? 'unknown');
            $blocker = (string) ($stage['blocker'] ?? '');
            $this->components->twoColumnDetail("· {$name}", $status.($blocker !== '' ? " ({$blocker})" : ''));
        }

        $blockers = (array) ($report['remaining_blockers'] ?? []);
        if ($blockers !== []) {
            $this->newLine();
            $this->components->bulletList($blockers);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
