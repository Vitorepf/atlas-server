<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasQualitativeLevelsReadModel;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAiQualitativeLevelsCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:qualitative-levels
        {--hours=720 : Window size in hours}
        {--json : Print machine-readable JSON}';

    protected $description = 'Report Atlas AI qualitative level maturity without changing behavior.';

    public function handle(AtlasQualitativeLevelsReadModel $levels, KernelReplayReportInput $input): int
    {
        $hours = $input->hours($this->option('hours'));
        $report = $levels->report(now()->subHours($hours));
        $payload = [
            'status' => 'ok',
            'hours' => $hours,
            'qualitative_levels' => $report,
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Qualitative Levels</>', (string) $report['current_level']);
        $this->components->twoColumnDetail('Label', (string) $report['current_level_label']);
        $this->components->twoColumnDetail('Next level', (string) $report['next_level']);
        $this->components->twoColumnDetail('Evidence events', (string) data_get($report, 'evidence.evidence_ledger.event_count', 0));
        $this->components->twoColumnDetail('Read model only', YesNo::format((bool) data_get($report, 'rules.read_model_only')));

        $this->table(
            ['gate', 'status', 'reason'],
            collect((array) $report['gates'])
                ->map(fn (array $gate): array => [$gate['id'], $gate['status'], $gate['reason']])
                ->all(),
        );

        return self::SUCCESS;
    }
}
