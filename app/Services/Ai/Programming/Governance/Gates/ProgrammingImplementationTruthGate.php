<?php

namespace App\Services\Ai\Programming\Governance\Gates;

use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * R4 enforcement — blocks when any governance doc OVER-CLAIMS its
 * implementation_state versus the code intelligence index (claimed rank >
 * computed rank). This is the gate that makes "claim de pronto sem evidence e
 * falso completo" enforceable in the programming flow instead of advisory.
 *
 * Fail-open when judgement is impossible: if the code index is not populated
 * (fresh / test environments) the gate passes, because every evidence ref would
 * MISS and produce false over-claims. Under-claim is never blocked.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
 */
class ProgrammingImplementationTruthGate implements ProgrammingGateContract
{
    public function __construct(
        private readonly AtlasAaeosImplementationTruthService $truth,
    ) {}

    public function name(): string
    {
        return 'implementation-truth';
    }

    public function evaluate(AtlasProgrammingWorkItem $workItem): ProgrammingGateOutcome
    {
        if (! Schema::hasTable('atlas_engineering_code_symbols')
            || AtlasEngineeringCodeSymbol::query()->limit(1)->count() === 0) {
            return ProgrammingGateOutcome::passed(['implementation_truth' => 'skipped_no_code_index']);
        }

        try {
            $ledger = $this->truth->ledger();
        } catch (Throwable $e) {
            return ProgrammingGateOutcome::passed([
                'implementation_truth' => 'skipped_error',
                'exception' => $e::class,
            ]);
        }

        $driftCount = (int) data_get($ledger, 'summary.drift_count', 0);
        if ($driftCount > 0) {
            $overClaims = collect((array) ($ledger['capabilities'] ?? []))
                ->filter(fn (array $row): bool => ($row['drift'] ?? false) === true)
                ->map(fn (array $row): array => [
                    'capability_id' => $row['capability_id'] ?? null,
                    'claimed_state' => $row['claimed_state'] ?? null,
                    'computed_state' => $row['computed_state'] ?? null,
                    'unmet_evidence' => $row['unmet_evidence'] ?? [],
                ])
                ->values()
                ->all();

            return ProgrammingGateOutcome::failed(
                'implementation_state_over_claim',
                [
                    'drift_count' => $driftCount,
                    'evaluated' => (int) data_get($ledger, 'summary.evaluated', 0),
                    'over_claims' => array_slice($overClaims, 0, 10),
                ],
            );
        }

        return ProgrammingGateOutcome::passed([
            'implementation_truth' => 'no_over_claim',
            'evaluated' => (int) data_get($ledger, 'summary.evaluated', 0),
        ]);
    }
}
