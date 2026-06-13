<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AobgSemanticRetrievalLiftService;
use Illuminate\Console\Command;

/**
 * L4-11: measured AOBG semantic retrieval activation decision.
 */
final class AtlasAobgSemanticRetrievalLiftCommand extends Command
{
    protected $signature = 'atlas:aobg:semantic-lift
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless measured lift recommends enabling semantic retrieval}';

    protected $description = 'Measure AOBG semantic retrieval lift over 10 provider-safe context queries.';

    public function handle(AobgSemanticRetrievalLiftService $service): int
    {
        $report = $service->report();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderHuman($report);
        }

        if ((bool) $this->option('strict') && data_get($report, 'decision.should_enable') !== true) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderHuman(array $report): void
    {
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>AOBG Semantic Retrieval Lift</>', (string) ($report['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Measured cases', (string) data_get($report, 'measurement.measured_case_count', 0));
        $this->components->twoColumnDetail('Average lift', (string) data_get($report, 'measurement.average_lift', 0));
        $this->components->twoColumnDetail('Positive cases', (string) data_get($report, 'measurement.positive_lift_case_count', 0));
        $this->components->twoColumnDetail('Decision', (string) data_get($report, 'decision.decision', 'unknown'));
        $this->components->twoColumnDetail('Provider calls', data_get($report, 'claim_policy.provider_calls_made') ? 'yes' : 'no');
    }
}
