<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAgentControlPlaneSafetyInvariantsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Agent Control Plane Safety Invariants v1
 * doc. With no args it self-describes the 16 hard-law invariants and runs a
 * worked all-green evaluation, proving the stop-condition contract is live.
 * It never authorizes runtime.
 *
 * @see docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-safety-invariants-v1.md
 */
class AtlasAgentControlPlaneSafetyInvariantsCommand extends Command
{
    protected $signature = 'atlas:aaeos:agent-control-plane-safety-invariants {--json : Print machine-readable JSON}';

    protected $description = 'Describe the 16 Agent Control Plane safety invariants and run a worked all-green evaluation (read-only, never authorizes runtime).';

    public function handle(AtlasAgentControlPlaneSafetyInvariantsService $service): int
    {
        try {
            $payload = $service->describe();
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasAgentControlPlaneSafetyInvariantsService::SCHEMA_VERSION,
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

        $sample = $payload['sample_all_green'];
        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('mode', (string) $payload['mode']);
        $this->components->twoColumnDetail('invariant_count', (string) $payload['invariant_count']);
        $this->components->twoColumnDetail('sample_posture', (string) $sample['posture']);
        $this->components->twoColumnDetail('sample_ships', $sample['ships'] ? 'true' : 'false');
        $this->components->twoColumnDetail('sample_violations', (string) $sample['violation_count']);
        $this->components->twoColumnDetail('runtime_authorized', $payload['runtime_authorized'] ? 'true' : 'false');

        return self::SUCCESS;
    }
}
