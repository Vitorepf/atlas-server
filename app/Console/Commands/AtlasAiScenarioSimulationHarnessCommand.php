<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiScenarioSimulationHarnessService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Atlas AI Scenario Simulation Harness doc.
 * With no args it grades an intentionally empty simulation request, proving the
 * harness defaults to `exploration` (never `prediction_grade`) and that a bare
 * request fails every documented gate. Demonstrates that simulation is an
 * audited rehearsal, not a prophecy.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-scenario-simulation-harness.md
 */
class AtlasAiScenarioSimulationHarnessCommand extends Command
{
    protected $signature = 'atlas:aaeos:scenario-simulation-harness {--json : Print machine-readable JSON}';

    protected $description = 'Grade a scenario-simulation request against the harness contracts (seed pack, runs, output contract, outcome tracking).';

    public function handle(AtlasAiScenarioSimulationHarnessService $service): int
    {
        try {
            // Safe default: empty request. The harness must refuse to call it
            // prediction-grade and must list every blocker.
            $payload = $service->assess([], [], [], [], 'medium');
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasAiScenarioSimulationHarnessService::SCHEMA_VERSION,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('grade', (string) $payload['grade']);
        $this->components->twoColumnDetail('is_prediction_grade', $payload['is_prediction_grade'] ? 'true' : 'false');
        $this->components->twoColumnDetail('must_write_evidence', $payload['evidence']['must_write_evidence'] ? 'true' : 'false');
        $this->components->twoColumnDetail('blockers', implode(', ', $payload['blockers']) ?: '(none)');

        return self::SUCCESS;
    }
}
