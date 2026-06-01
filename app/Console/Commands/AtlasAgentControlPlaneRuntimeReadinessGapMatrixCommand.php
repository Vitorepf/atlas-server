<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAgentControlPlaneRuntimeReadinessGapMatrixService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Agent Control Plane Runtime Readiness Gap
 * Matrix v1 doc. With no args it renders the observational matrix (every Runtime
 * cell N/partial, as the audit states). Proves the doc's Closing Note gate is
 * live: the matrix never auto-promotes a Runtime cell to Y.
 *
 * @see docs/engineering-knowledge-base/self-construction/audits/atlas-agent-control-plane-runtime-readiness-gap-matrix-v1.md
 */
class AtlasAgentControlPlaneRuntimeReadinessGapMatrixCommand extends Command
{
    protected $signature = 'atlas:aaeos:agent-control-plane-runtime-readiness-gap-matrix {--json : Print machine-readable JSON}';

    protected $description = 'Render the observational Agent Control Plane runtime readiness gap matrix (Closing Note five-artifact gate enforced).';

    public function handle(AtlasAgentControlPlaneRuntimeReadinessGapMatrixService $service): int
    {
        try {
            // Safe default: no promotions supplied, so the matrix renders the
            // doc's observed state with zero Runtime=Y rows.
            $payload = $service->matrix();
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasAgentControlPlaneRuntimeReadinessGapMatrixService::SCHEMA_VERSION,
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
        $this->components->twoColumnDetail('block_count', (string) $payload['block_count']);
        $this->components->twoColumnDetail('runtime_y_count', (string) $payload['runtime_y_count']);
        $this->components->twoColumnDetail('all_runtime_cells_blocked', $payload['all_runtime_cells_blocked'] ? 'true' : 'false');
        $this->components->twoColumnDetail('next_required_slice', (string) $payload['current_next_required_slice']);

        return self::SUCCESS;
    }
}
