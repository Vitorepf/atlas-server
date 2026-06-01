<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasBuildGraphService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Atlas Self-Construction Build Graph doc.
 * With no args it renders the dependency-ordering law snapshot: the 11-stage
 * core chain, the L5 blocking rule (which caps self-programming when its
 * prerequisites are unbuilt), the 7-key Build Graph Packet, and the drift
 * signal for off-graph capabilities. Proves the law never lets a downstream
 * capability outrun its foundations.
 *
 * @see docs/engineering-knowledge-base/self-construction/build-graph.md
 */
class AtlasBuildGraphCommand extends Command
{
    protected $signature = 'atlas:aaeos:build-graph {--json : Print machine-readable JSON}';

    protected $description = 'Render the Self-Construction Build Graph law (core chain, L5 blocking rule, packet schema, drift signal).';

    public function handle(AtlasBuildGraphService $service): int
    {
        try {
            // Safe default: nothing built yet, so the blocking rule is in force.
            $payload = $service->snapshot();
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasBuildGraphService::SCHEMA_VERSION,
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
        $this->components->twoColumnDetail('core_chain_length', (string) $payload['core_chain_length']);
        $this->components->twoColumnDetail('blocking_rule_level', 'L'.(string) $payload['blocking_rule_level']);
        $this->components->twoColumnDetail('default_self_programming_capped', $payload['default_self_programming_capped'] ? 'true' : 'false');
        $this->components->twoColumnDetail('default_self_programming_effective_level', 'L'.(string) $payload['default_self_programming_effective_level']);
        $this->components->twoColumnDetail('empty_packet_valid', $payload['empty_packet_valid'] ? 'true' : 'false');
        $this->components->twoColumnDetail('off_graph_capability_drifts', $payload['off_graph_capability_drifts'] ? 'true' : 'false');

        return self::SUCCESS;
    }
}
