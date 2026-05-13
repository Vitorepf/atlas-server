<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasStructureMotherAuditReadModel;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Console\Command;

class AtlasAiStructureMotherAuditCommand extends Command
{
    protected $signature = 'atlas:ai:structure-mother-audit
        {--hours=720 : Window size in hours}
        {--workspace= : Workspace path}
        {--json : Print machine-readable JSON}';

    protected $description = 'Audit the eight Atlas AI structure-mother modules without mutating runtime, memory or provider state.';

    public function handle(AtlasStructureMotherAuditReadModel $audit, KernelReplayReportInput $input): int
    {
        $hours = $input->hours($this->option('hours'));
        $payload = [
            'status' => 'ok',
            'structure_mother_audit' => $audit->report([
                'hours' => $hours,
                'workspace' => $this->option('workspace'),
            ]),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $report = $payload['structure_mother_audit'];
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Structure Mother Audit</>', (string) $report['status']);
        $this->components->twoColumnDetail('Complete', ((bool) $report['complete']) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Ready modules', (string) data_get($report, 'summary.ready_count', 0));
        $this->components->twoColumnDetail('Attention modules', (string) data_get($report, 'summary.attention_count', 0));
        $this->components->twoColumnDetail('Blocked modules', (string) data_get($report, 'summary.blocked_count', 0));
        $this->components->twoColumnDetail('Qualitative level', (string) data_get($report, 'summary.qualitative_level', 'unknown'));
        $this->components->twoColumnDetail('Completion gate', (string) data_get($report, 'completion_gate.status', 'unknown'));

        $this->table(
            ['module', 'implementation', 'operational', 'blockers'],
            collect((array) ($report['modules'] ?? []))
                ->map(fn (array $module): array => [
                    $module['label'] ?? $module['id'] ?? '-',
                    $module['implementation_status'] ?? $module['status'] ?? 'unknown',
                    $module['operational_status'] ?? 'unknown',
                    implode(', ', (array) ($module['operational_blockers'] ?? $module['blockers'] ?? [])) ?: '-',
                ])
                ->all(),
        );

        return self::SUCCESS;
    }
}
