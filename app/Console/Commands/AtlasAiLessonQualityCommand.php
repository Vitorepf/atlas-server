<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Compounding\AtlasLessonQualityService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasAiLessonQualityCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:lesson-quality
        {--min-cases= : Minimum measured cases per lesson-quality group}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless at least one group is measured}';

    protected $description = 'Measure lesson quality by memory_type, flow_id, and scope without writing learning state.';

    public function handle(AtlasLessonQualityService $service): int
    {
        $report = $service->report(minCases: $this->intOption('min-cases'));

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));
        } else {
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Lesson Quality</>', (string) ($report['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Measure', (string) ($report['measure_id'] ?? AtlasLessonQualityService::MEASURE_ID));
            $this->components->twoColumnDetail('Candidates', (string) data_get($report, 'totals.candidate_count', 0));
            $this->components->twoColumnDetail('Measured cases', (string) data_get($report, 'totals.measured_count', 0));
            $this->components->twoColumnDetail('Groups', (string) count((array) ($report['groups'] ?? [])));
        }

        if ((bool) $this->option('strict') && ($report['status'] ?? null) !== 'ok') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function intOption(string $key): ?int
    {
        $value = $this->option($key);
        if (! is_numeric($value)) {
            return null;
        }

        return max(1, (int) $value);
    }
}
