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
        // The docs-health ratchet: block only on NEW (blocking) violations, never
        // on the frozen legacy debt. enforcement.status is green|debt_holding|failed
        // and is 'failed' only when blocking_count > 0 (a regression). Falls back to
        // the legacy status mapping when the enforcement block is absent (e.g. mocked
        // reports in tests, or a report predating the ratchet).
        $enforcementStatus = (string) data_get(
            $report,
            'enforcement.status',
            $status === 'ok' ? 'green' : 'failed',
        );
        $missingRequired = (array) data_get($report, 'required_missing', []);
        $oversized = (array) data_get($report, 'oversized', []);

        if ($enforcementStatus === 'failed' || $missingRequired !== []) {
            return ProgrammingGateOutcome::failed(
                'docs_health_violations',
                [
                    'docs_health_status' => $status,
                    'enforcement_status' => $enforcementStatus,
                    'blocking_count' => (int) data_get($report, 'enforcement.blocking_count', 0),
                    'legacy_debt_count' => (int) data_get($report, 'enforcement.legacy_debt_count', 0),
                    'required_missing_count' => count($missingRequired),
                    'oversized_count' => count($oversized),
                    'sample_required_missing' => array_slice(array_map(static fn ($d) => is_array($d) ? ($d['canonical_path'] ?? $d) : (string) $d, $missingRequired), 0, 5),
                ],
            );
        }

        return ProgrammingGateOutcome::passed([
            'docs_health_status' => $status,
            'enforcement_status' => $enforcementStatus,
            'legacy_debt_count' => (int) data_get($report, 'enforcement.legacy_debt_count', 0),
            'oversized_count' => count($oversized),
        ]);
    }
}
