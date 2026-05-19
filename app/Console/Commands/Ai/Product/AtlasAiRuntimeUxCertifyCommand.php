<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasAiRuntimeUxCertificationService;
use Illuminate\Console\Command;

/**
 * Atlas AI · Runtime UX Certify CLI.
 *
 * Read-only: file-inspection + 1 runtime smoke (uxBundle()). Não invoca
 * provider, não roda rivals, não destrava nada.
 */
class AtlasAiRuntimeUxCertifyCommand extends Command
{
    protected $signature = 'atlas:ai:runtime-ux-certify
        {--json : Print the canonical JSON envelope}
        {--strict : Exit non-zero unless status === ready}';

    protected $description = 'Certifies the Atlas AI Runtime UX layer (Mobile + Desktop status pill + view-model wiring).';

    public function handle(AtlasAiRuntimeUxCertificationService $service): int
    {
        $report = $service->certify();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
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
        $this->components->twoColumnDetail(
            '<fg=bright-blue;options=bold>Atlas AI · Runtime UX Certification</>',
            (string) $report['schema_version'],
        );
        $this->components->twoColumnDetail('status', (string) $report['status']);
        $this->components->twoColumnDetail('certification_hash', (string) $report['certification_hash']);

        foreach ($report['checks'] as $check) {
            $id = (string) ($check['id'] ?? 'unknown');
            $status = (string) ($check['status'] ?? 'unknown');
            $severity = (string) ($check['severity'] ?? 'critical');
            $colour = $status === 'passed' ? 'fg=green' : ($severity === 'warn' ? 'fg=yellow' : 'fg=red');
            $this->components->twoColumnDetail("· {$id}", "<{$colour}>{$status}</> ({$severity})");
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

        return ($report['status'] ?? null) === 'ready' ? self::SUCCESS : self::FAILURE;
    }
}
