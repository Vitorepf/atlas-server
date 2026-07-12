<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Quality;

use App\Models\AiRunOutcome;
use App\Services\Ai\Compounding\AtlasCompoundingOutcomeEvaluator;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;

/**
 * Materializes the ledger-derived temporal projection into the existing
 * compounding read model. It never turns a temporal observation into a claim.
 */
final class QualityFoundryTemporalProjectionMaterializer
{
    public function __construct(
        private readonly QualityFoundryTemporalProjectionRebuilder $rebuilder,
        private readonly AtlasCompoundingOutcomeEvaluator $evaluator,
    ) {}

    /** @return array<string,mixed> */
    public function materialize(AtlasEvidenceLedger $ledger, string $deliveryId): array
    {
        $projection = $this->rebuilder->rebuildFromLedger($ledger, $deliveryId);
        $canonicalOutcome = $projection['outcome'] ?? null;
        if (! is_array($canonicalOutcome) || trim((string) ($canonicalOutcome['run_id'] ?? '')) === '') {
            return [
                'status' => 'blocked',
                'reason' => 'temporal_outcome_missing',
                'projection' => $projection,
            ];
        }

        $eventRefs = [];
        foreach ((array) ($projection['windows'] ?? []) as $window) {
            foreach ((array) ($window['observations'] ?? []) as $observation) {
                $ref = trim((string) ($observation['event_hash'] ?? ''));
                if ($ref !== '') {
                    $eventRefs[] = $ref;
                }
            }
        }
        $eventRefs = array_values(array_unique($eventRefs));
        $status = 'temporal_'.$projection['temporal_state'];
        $input = [
            'schema_version' => 'atlas.ai.compounding.temporal_projection.v1',
            'run_id' => (string) $canonicalOutcome['run_id'],
            'flow_id' => 'engineering_kernel_temporal',
            'outcome_status' => $status,
            'flow_quality' => 0,
            'retrieval_quality' => 0,
            'execution_quality' => 0,
            'evidence_quality' => 0,
            'learning_required' => true,
            'evidence_refs' => $eventRefs,
            'payload' => [
                'source' => 'atlas.quality_foundry.temporal_projection',
                'delivery_id' => $deliveryId,
                'canonical_outcome_hash' => $canonicalOutcome['outcome_hash'] ?? null,
                'projection' => $projection,
            ],
        ];
        /** @var AiRunOutcome $materialized */
        $materialized = $this->evaluator->evaluate($input);

        return [
            'status' => 'materialized',
            'run_outcome_id' => (string) $materialized->getKey(),
            'outcome_hash' => $materialized->outcome_hash,
            'temporal_state' => $projection['temporal_state'],
            'claim_eligible' => false,
            'learning_required' => true,
            'projection' => $projection,
        ];
    }
}
