<?php

namespace App\Services\Ai\Programming\Governance\Gates;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Engineering\EngineeringDocumentationHealthService;
use Throwable;

/**
 * Reads docs-health and fails the gate when canonical documentation is missing,
 * oversized or violates the canonical module doc schema for areas the work item
 * touches.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
 */
class ProgrammingDocsHealthGate implements ProgrammingGateContract
{
    public function __construct(
        private readonly EngineeringDocumentationHealthService $docsHealth,
    ) {}

    public function name(): string
    {
        return 'docs-health';
    }

    public function evaluate(AtlasProgrammingWorkItem $workItem): ProgrammingGateOutcome
    {
        try {
            $report = $this->docsHealth->report();
        } catch (Throwable $e) {
            return ProgrammingGateOutcome::failed(
                'docs_health_report_failed:'.$e::class,
                ['exception_message' => $e->getMessage()],
            );
        }

        $status = (string) ($report['status'] ?? 'unknown');
        $missingRequired = (array) data_get($report, 'required_missing', []);
        $oversized = (array) data_get($report, 'oversized', []);

        if ($status === 'failed' || $missingRequired !== []) {
            return ProgrammingGateOutcome::failed(
                'docs_health_violations',
                [
                    'docs_health_status' => $status,
                    'required_missing_count' => count($missingRequired),
                    'oversized_count' => count($oversized),
                    'sample_required_missing' => array_slice(array_map(static fn ($d) => is_array($d) ? ($d['canonical_path'] ?? $d) : (string) $d, $missingRequired), 0, 5),
                ],
            );
        }

        return ProgrammingGateOutcome::passed([
            'docs_health_status' => $status,
            'oversized_count' => count($oversized),
        ]);
    }
}
