<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasObrasMetricsRisksAndExcellenceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Evaluates the Obras quality-claim gates (complete / Foundry / Sovereign),
 * the Excellence Criteria score and the risk/metric catalog for a given Obra
 * state. Default invocation uses an empty state, which proves the gates deny
 * every claim until evidence is supplied.
 *
 * @see docs/engineering-knowledge-base/obras/metrics-risks-and-excellence.md
 */
class AtlasObrasMetricsRisksAndExcellenceCommand extends Command
{
    protected $signature = 'atlas:aaeos:metrics-risks-and-excellence {--json}';

    protected $description = 'Evaluate Obras quality-claim gates, excellence score and risk register from a given Obra state.';

    public function handle(AtlasObrasMetricsRisksAndExcellenceService $service): int
    {
        try {
            $result = $service->evaluate([]);

            if ((bool) $this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('highest claimable maturity', (string) $result['highest_claimable_maturity']);
            $this->components->twoColumnDetail('excellence score', $result['excellence']['score_out_of_10'].'/10');
            $this->components->twoColumnDetail('state of the art', $result['excellence']['state_of_the_art'] ? 'yes' : 'no');

            foreach (['complete', 'foundry', 'sovereign'] as $claim) {
                $gate = $result['claims'][$claim];
                $this->components->twoColumnDetail(
                    "claim: {$claim}",
                    ($gate['can_claim'] ? 'ALLOWED' : 'DENIED')." ({$gate['satisfied_count']}/{$gate['total_conditions']})"
                );
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $envelope = [
                'schema_version' => 'atlas.obras.metrics_risks_and_excellence.v1',
                'error' => true,
                'message' => $e->getMessage(),
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }
    }
}
