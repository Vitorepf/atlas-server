<?php

namespace App\Services\Ai\Programming\Governance\Gates;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Throwable;

/**
 * Validates Code Intelligence freshness for the work item before structural work.
 * Reads summary() and audit() from the existing EngineeringCodeIntelligenceService.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md (Contrato 4)
 * @see docs/engineering-knowledge-base/code-intelligence.md
 */
class ProgrammingCodeIntelligenceGate implements ProgrammingGateContract
{
    public function __construct(
        private readonly EngineeringCodeIntelligenceService $codeIntelligence,
    ) {}

    public function name(): string
    {
        return 'code-intelligence-context';
    }

    public function evaluate(AtlasProgrammingWorkItem $workItem): ProgrammingGateOutcome
    {
        try {
            $summary = $this->codeIntelligence->summary();
        } catch (Throwable $e) {
            return ProgrammingGateOutcome::failed(
                'code_intelligence_summary_failed:'.$e::class,
                ['exception_message' => $e->getMessage()],
            );
        }

        $status = (string) ($summary['status'] ?? 'unknown');
        // Canonical field is `module_count`; legacy stub callers may emit `totals.modules`.
        $modules = (int) ($summary['module_count']
            ?? data_get($summary, 'totals.modules', data_get($summary, 'modules', 0)));

        $workItem->forceFill([
            'code_intelligence_json' => [
                'recorded_at' => now()->toJSON(),
                'summary' => $summary,
            ],
        ])->save();

        if (in_array($status, ['empty', 'empty_index', 'not_migrated'], true) || $modules === 0) {
            return ProgrammingGateOutcome::failed(
                'code_intelligence_empty_index',
                ['summary_status' => $status, 'module_count' => $modules],
            );
        }

        if (in_array($status, ['drift_detected', 'stale'], true)) {
            return ProgrammingGateOutcome::failed(
                'code_intelligence_drift_detected',
                ['summary_status' => $status, 'module_count' => $modules],
            );
        }

        return ProgrammingGateOutcome::passed([
            'summary_status' => $status,
            'module_count' => $modules,
        ]);
    }
}
