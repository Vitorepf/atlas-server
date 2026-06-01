<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAgenticEngineeringOsRuntimeGapMatrixService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the AAEOS Runtime Gap Matrix doc. With no args it
 * renders the canonical snapshot with derived counters. Proves the doc's rule is
 * live: with no evidence supplied, no in-scope row is "ready" and the loop sees
 * the real high-impact backlog (partial_runtime + spec_runtime_gap only).
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md
 */
class AtlasAgenticEngineeringOsRuntimeGapMatrixCommand extends Command
{
    protected $signature = 'atlas:aaeos:agentic-engineering-os-runtime-gap-matrix {--json : Print machine-readable JSON}';

    protected $description = 'Render the canonical AAEOS runtime gap matrix (ready requires solid_runtime + evidence; backlog = high-impact gaps only).';

    public function handle(AtlasAgenticEngineeringOsRuntimeGapMatrixService $service): int
    {
        try {
            $payload = $service->matrix();
        } catch (Throwable $e) {
            $error = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasAgenticEngineeringOsRuntimeGapMatrixService::SCHEMA_VERSION,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
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
        $this->components->twoColumnDetail('row_count', (string) $payload['row_count']);
        $this->components->twoColumnDetail('solid_count', (string) $payload['solid_count']);
        $this->components->twoColumnDetail('partial_count', (string) $payload['partial_count']);
        $this->components->twoColumnDetail('out_of_scope_count', (string) $payload['out_of_scope_count']);
        $this->components->twoColumnDetail('backlog_count', (string) $payload['backlog_count']);
        $this->components->twoColumnDetail('ready_count (no evidence supplied)', (string) $payload['ready_count']);

        return self::SUCCESS;
    }
}
