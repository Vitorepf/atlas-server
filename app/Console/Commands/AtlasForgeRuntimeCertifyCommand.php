<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasForgeRuntimeCertificationService;
use Illuminate\Console\Command;

/**
 * Certifica o runtime real do Atlas Forge ponta-a-ponta.
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-runtime-certification-one-shot.md
 */
class AtlasForgeRuntimeCertifyCommand extends Command
{
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
        $this->components->twoColumnDetail('Obra provided', data_get($report, 'inputs.obra_provided') ? 'yes' : 'no');

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

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
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
