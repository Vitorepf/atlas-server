<?php

namespace App\Services\Ai\Programming\Governance\Gates;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use Throwable;

/**
 * Validates that a feature placement decision exists for the work item.
 * Delegates to the existing AtlasFeaturePlacementService when needed.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md (Contrato 1)
 */
class ProgrammingPlacementGate implements ProgrammingGateContract
{
    public function __construct(
        private readonly AtlasFeaturePlacementService $placement,
    ) {}

    public function name(): string
    {
        return 'feature-placement';
    }

    public function evaluate(AtlasProgrammingWorkItem $workItem): ProgrammingGateOutcome
    {
        $existing = (array) ($workItem->placement_json ?? []);
        if ($existing !== [] && ! empty($existing['placement'] ?? null)) {
            return ProgrammingGateOutcome::passed([
                'source' => 'cached_on_work_item',
                'placement_layer' => data_get($existing, 'placement.layer'),
                'placement_domain' => data_get($existing, 'placement.domain'),
            ]);
        }

        try {
            $payload = $this->placement->place($workItem->intent_text, []);
            $workItem->forceFill(['placement_json' => $payload])->save();

            return ProgrammingGateOutcome::passed([
                'source' => 'placed_now',
                'placement_layer' => data_get($payload, 'placement.layer'),
                'placement_domain' => data_get($payload, 'placement.domain'),
                'status' => $payload['status'] ?? null,
            ]);
        } catch (Throwable $e) {
            return ProgrammingGateOutcome::failed(
                'placement_service_failed:'.$e::class,
                ['exception_message' => $e->getMessage()],
            );
        }
    }
}
