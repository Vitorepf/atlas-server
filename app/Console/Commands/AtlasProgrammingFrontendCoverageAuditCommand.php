<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendCoverageAuditService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Impeccable Teardown Coverage Audit doc.
 * With no args it renders the canonical coverage matrix and runs the doc's
 * completeness decision over its own area set. Proves the doc's load-bearing
 * rules are live: the teardown is "complete" only when every area is covered,
 * and the matrix NEVER asserts Atlas runtime readiness.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-coverage-audit.md
 */
class AtlasProgrammingFrontendCoverageAuditCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-frontend-coverage-audit {--json : Print machine-readable JSON}';

    protected $description = 'Render the Impeccable teardown coverage matrix and evaluate documental completeness (never asserts runtime readiness).';

    public function handle(AtlasProgrammingFrontendCoverageAuditService $service): int
    {
        try {
            // Safe default: evaluate the canonical matrix against its own area
            // set, which the doc declares fully covered.
            $matrix = $service->matrix();
            $completeness = $service->evaluateCompleteness();
            $runtime = $service->assertRuntimeReadiness();

            $payload = [
                'ok' => true,
                'schema_version' => $matrix['schema_version'],
                'mode' => $matrix['mode'],
                'matrix' => $matrix,
                'completeness' => $completeness,
                'runtime_readiness' => $runtime,
                'flow' => $service->flow(),
            ];
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasProgrammingFrontendCoverageAuditService::SCHEMA_VERSION,
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
        $this->components->twoColumnDetail('external_subject', (string) $matrix['external_subject']);
        $this->components->twoColumnDetail('pinned_commit', (string) $matrix['pinned_commit']);
        $this->components->twoColumnDetail('area_count', (string) $matrix['area_count']);
        $this->components->twoColumnDetail('covered_count', (string) $matrix['covered_count']);
        $this->components->twoColumnDetail('coverage_complete', $completeness['complete'] ? 'true' : 'false');
        $this->components->twoColumnDetail('asserts_runtime_ready', $runtime['runtime_ready'] ? 'true' : 'false');

        return self::SUCCESS;
    }
}
