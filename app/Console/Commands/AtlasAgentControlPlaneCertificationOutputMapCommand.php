<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAgentControlPlaneCertificationOutputMapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Agent Control Plane Certification Output
 * Map v1 doc. With no args it self-describes the canonical field map and runs
 * a worked "tudo verde" evaluation, proving the all-green rule set and the
 * "Regras para IA" are live. It never authorizes runtime.
 *
 * @see docs/engineering-knowledge-base/self-construction/evidence-index/atlas-agent-control-plane-certification-output-map-v1.md
 */
class AtlasAgentControlPlaneCertificationOutputMapCommand extends Command
{
    protected $signature = 'atlas:aaeos:agent-control-plane-certification-output-map {--json : Print machine-readable JSON}';

    protected $description = 'Describe the Agent Control Plane certification output-map field rules and run a worked all-green evaluation (read-only, never authorizes runtime).';

    public function handle(AtlasAgentControlPlaneCertificationOutputMapService $service): int
    {
        try {
            $payload = $service->describe();
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasAgentControlPlaneCertificationOutputMapService::SCHEMA_VERSION,
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

        $sample = $payload['sample_evaluation'];
        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('mode', (string) $payload['mode']);
        $this->components->twoColumnDetail('commands_mapped', (string) count($payload['commands']));
        $this->components->twoColumnDetail('sample_command', (string) $sample['command']);
        $this->components->twoColumnDetail('sample_all_green', $sample['all_green'] ? 'true' : 'false');
        $this->components->twoColumnDetail('runtime_authorized', $payload['runtime_authorized'] ? 'true' : 'false');

        return self::SUCCESS;
    }
}
