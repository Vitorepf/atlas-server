<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Compounding\AtlasLearningRecallUseLiftService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasAiLessonTypeYieldCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:lesson-type-yield
        {--min-cases= : Minimum cases per lesson type; hard-floored at 8}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless at least one type is measured}';

    protected $description = 'Measure A/B lift by lesson memory_type with the MAXJ-05 n>=8 floor.';

    public function handle(AtlasLearningRecallUseLiftService $service): int
    {
        $report = $service->lessonTypeYieldReport(minCases: $this->intOption('min-cases'));

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));
        } else {
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Lesson Type Yield</>', (string) ($report['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Measure', (string) ($report['measure_id'] ?? AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_MEASURE_ID));
            $this->components->twoColumnDetail('Types', (string) data_get($report, 'totals.memory_type_count', 0));
            $this->components->twoColumnDetail('Measured types', (string) data_get($report, 'totals.measured_type_count', 0));
            $this->components->twoColumnDetail('Denominator min', (string) ($report['denominator_min'] ?? AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_DENOMINATOR_MIN));
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
