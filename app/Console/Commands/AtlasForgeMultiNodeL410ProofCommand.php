<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Services\Ai\Programming\AtlasForgeMultiNodeL410ProofService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Support\YesNo;

/**
 * L4-10: Forge multi-node proof report.
 */
final class AtlasForgeMultiNodeL410ProofCommand extends Command
{
    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:forge:l4-10-proof
        {--evidence= : JSON receipt from a real 6-10 node Forge/Obra run}
        {--hours=24 : Morning digest window used to prove L4-6 is locally delivered}
        {--write-report : Persist the proof/autopsy JSON}
        {--report-path= : Explicit report path when --write-report is used}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless L4-10 is certified by real evidence}';

    protected $description = 'Plans the L4-10 multi-node Forge Obra and certifies only explicit real kill/resume evidence.';

    public function handle(AtlasForgeMultiNodeL410ProofService $service): int
    {
        $report = $service->report([
            'evidence_path' => $this->stringOption('evidence'),
            'hours' => $this->intOption('hours') ?? 24,
        ]);

        if ((bool) $this->option('write-report')) {
            $reportPath = $this->stringOption('report-path') ?? storage_path('app/atlas/evidence/fable-l4-10-proof.json');
            File::ensureDirectoryExists(dirname($reportPath));
            File::put($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $report['written_report_path'] = $reportPath;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderHuman($report);
        }

        if ((bool) $this->option('strict') && ($report['certified'] ?? false) !== true) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderHuman(array $report): void
    {
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Forge L4-10 Proof</>', (string) ($report['schema_version'] ?? 'unknown'));
        $this->components->twoColumnDetail('Status', (string) ($report['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Certified', ($report['certified'] ?? false) === YesNo::format(true));
        $this->components->twoColumnDetail('Planned nodes', (string) data_get($report, 'planned_obra.work_node_count', 0));
        $this->components->twoColumnDetail('Recommended agents', (string) data_get($report, 'planned_obra.schedule.recommended_agent_count', 0));
        $this->components->twoColumnDetail('Digest command', data_get($report, 'delivered_item.local_digest_command_available') ? 'available' : 'missing');
        $this->components->twoColumnDetail('Provider dispatch now', data_getYesNo::format($report, 'claim_policy.provider_dispatches_now'));

        $blockers = array_values((array) ($report['blockers'] ?? []));
        if ($blockers !== []) {
            $this->newLine();
            $this->components->bulletList($blockers);
        }
    }

    private function intOption(string $key): ?int
    {
        $value = $this->option($key);
        if (is_int($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return ctype_digit($value) ? (int) $value : null;
    }

}
