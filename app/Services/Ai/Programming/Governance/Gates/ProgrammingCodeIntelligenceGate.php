<?php

namespace App\Services\Ai\Programming\Governance\Gates;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Engineering\AtlasCodeIntelligenceAutomaticGateService;
use App\Services\Engineering\CodeGraph\CodeGraphGateContributor;
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
        private readonly ?AtlasCodeIntelligenceAutomaticGateService $automaticGate = null,
        private readonly ?CodeGraphGateContributor $codeGraph = null,
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
        $automaticGate = $this->automaticGate?->evaluate([
            'workspace' => $workItem->workspace ?: base_path(),
            'mode' => 'summary',
            'strict_freshness' => false,
            'auto_refresh' => in_array($status, ['empty', 'empty_index', 'not_migrated', 'summary_failed'], true) || $modules === 0,
            'run_context_type' => 'atlas_programming_work_item',
            'run_context_id' => (string) $workItem->id,
        ]);

        $workItem->forceFill([
            'code_intelligence_json' => [
                'recorded_at' => now()->toJSON(),
                'summary' => $summary,
                'automatic_gate' => $automaticGate,
            ],
        ])->save();

        if (is_array($automaticGate) && ($automaticGate['status'] ?? null) === 'blocked') {
            return ProgrammingGateOutcome::failed(
                'code_intelligence_automatic_gate_blocked',
                [
                    'summary_status' => $status,
                    'module_count' => $modules,
                    'blockers' => $automaticGate['blockers'] ?? [],
                    'auto_refresh_attempted' => (bool) data_get($automaticGate, 'refresh.attempted'),
                ],
            );
        }

        if (is_array($automaticGate) && data_get($automaticGate, 'refresh.attempted') && ($automaticGate['status'] ?? null) !== 'blocked') {
            $status = (string) data_get($automaticGate, 'summary.status', $status);
            $modules = (int) data_get($automaticGate, 'summary.module_count', $modules);
        }

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

        // AP-811 live integration: when the real code graph is activated it must be
        // load-bearing too — a stale/empty/drifted edge set blocks Dev/Forge exactly
        // like the symbol index. Flag-gated: with the flag OFF this is not even
        // consulted, so the gate behaves byte-identically to before (inert).
        if ((bool) config('atlas.code_graph.real_edges', false) && $this->codeGraph !== null) {
            $codeGraphSignal = $this->codeGraph->evaluate();

            if (($codeGraphSignal['status'] ?? null) === 'blocked') {
                return ProgrammingGateOutcome::failed(
                    'code_graph_blocked',
                    [
                        'summary_status' => $status,
                        'module_count' => $modules,
                        'code_graph_blockers' => $codeGraphSignal['blockers'] ?? [],
                    ],
                );
            }

            return ProgrammingGateOutcome::passed([
                'summary_status' => $status,
                'module_count' => $modules,
                'code_graph' => $codeGraphSignal,
            ]);
        }

        return ProgrammingGateOutcome::passed([
            'summary_status' => $status,
            'module_count' => $modules,
        ]);
    }
}
