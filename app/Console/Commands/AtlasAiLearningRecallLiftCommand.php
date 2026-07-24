<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Compounding\AtlasLearningRecallUseLiftService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasAiLearningRecallLiftCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:learning-recall-lift
        {--min-cases= : Minimum A/B cases per arm}
        {--min-passing-use= : Minimum passing tasks that used recalled memory}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless live DoD is met}';

    protected $description = 'Measure learn→recall→use lift from existing compounding/RAG feedback rows.';

    public function handle(AtlasLearningRecallUseLiftService $service): int
    {
        $report = $service->report(
            minCases: $this->intOption('min-cases'),
            minPassingUse: $this->intOption('min-passing-use'),
        );

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));
        } else {
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Learning Recall Use Lift</>', (string) ($report['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Active memories', (string) data_get($report, 'measurement.active_compounding_memory_count', 0));
            $this->components->twoColumnDetail('Feedback events', (string) data_get($report, 'measurement.feedback_event_count', 0));
            $this->components->twoColumnDetail('With recall', (string) data_get($report, 'measurement.with_recalled_memory.case_count', 0));
            $this->components->twoColumnDetail('Without recall', (string) data_get($report, 'measurement.without_recalled_memory.case_count', 0));
            $this->components->twoColumnDetail('Passed-rate lift', (string) data_get($report, 'measurement.passed_rate_lift', 0));
            $this->components->twoColumnDetail('Completion claim', data_get($report, 'claim_policy.completion_claim_allowed') ? 'allowed' : 'blocked');
        }

        if ((bool) $this->option('strict') && data_get($report, 'measurement.live_dod_met') !== true) {
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
