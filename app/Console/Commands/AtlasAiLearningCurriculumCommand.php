<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Compounding\AtlasLearningCurriculumService;
use Illuminate\Console\Command;

final class AtlasAiLearningCurriculumCommand extends Command
{
    protected $signature = 'atlas:ai:learning-curriculum
        {--min-cases= : Minimum lesson-type cases; hard-floored by MAXJ-05}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless at least one category is measured}';

    protected $description = 'MAXJ-06 — read-only learning curriculum from lesson-type yield and causal denominators.';

    public function handle(AtlasLearningCurriculumService $service): int
    {
        $report = $service->report($this->intOption('min-cases'));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        } else {
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Learning Curriculum</>', (string) ($report['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Measured categories', (string) data_get($report, 'totals.measured_categories', 0));
            $this->components->twoColumnDetail('Mode', (string) data_get($report, 'global_prior.mode', 'neutral'));
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
