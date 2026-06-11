<?php

namespace App\Services\Ai\Mission;

use App\Models\AiMission;
use App\Models\AiObjective;
use App\Models\AiWorkOrder;
use App\Services\Ai\Mission\Support\MissionSuccessCriteriaNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WorkOrderFactoryService
{
    public function __construct(private readonly MissionLifecycleService $lifecycle) {}

    /**
     * @return Collection<int,AiWorkOrder>
     */
    public function plan(AiMission $mission): Collection
    {
        $objectives = $mission->objectives()->orderBy('priority')->get();
        if ($objectives->isEmpty()) {
            return collect();
        }

        $existing = $mission->workOrders()->get()->keyBy('objective_id');
        $created = collect();

        foreach ($objectives as $objective) {
            if ($existing->has($objective->id)) {
                $created->push($existing->get($objective->id));

                continue;
            }

            $workOrder = $this->createForObjective($mission, $objective);
            $created->push($workOrder);
        }

        return $created;
    }

    public function createForObjective(AiMission $mission, AiObjective $objective): AiWorkOrder
    {
        $payload = [
            'schema_version' => 'atlas.ai.work_order.v1',
            'mission_id' => $mission->id,
            'objective_id' => $objective->id,
            'title' => 'Execute objective: '.Str::limit($objective->title, 160, ''),
            'instructions' => $this->buildInstructions($objective),
            'expected_artifacts' => $this->expectedArtifacts($objective),
            'expected_tests' => $this->expectedTests($objective),
            'risk_notes' => $this->riskNotes($mission),
            'rollback_plan' => null,
            'domain_runtime' => $mission->primary_domain,
            'flow_profile' => null,
            'status' => 'ready',
        ];

        $receiptHash = MissionCanonicalHash::sha256([
            'mission_id' => $payload['mission_id'],
            'objective_id' => $payload['objective_id'],
            'title' => $payload['title'],
            'instructions' => $payload['instructions'],
            'expected_artifacts' => $payload['expected_artifacts'],
            'expected_tests' => $payload['expected_tests'],
            'risk_notes' => $payload['risk_notes'],
        ]);

        $workOrder = AiWorkOrder::query()->create(array_merge($payload, [
            'uuid' => (string) Str::uuid(),
            'evidence_refs' => [],
            'receipt_hash' => $receiptHash,
        ]));

        $this->lifecycle->recordEvent(
            $mission,
            'work_order.created',
            'system',
            [
                'work_order_id' => $workOrder->id,
                'objective_id' => $objective->id,
                'receipt_hash' => $receiptHash,
            ],
            receiptHash: $receiptHash,
        );

        return $workOrder;
    }

    private function buildInstructions(AiObjective $objective): string
    {
        $criteria = collect(MissionSuccessCriteriaNormalizer::descriptions($objective->success_criteria))
            ->map(static fn (string $line) => '- '.$line)
            ->implode("\n");

        return trim(
            "Objective: {$objective->title}\n".
            ($objective->description ? "Context: {$objective->description}\n" : '').
            "Success criteria:\n".$criteria
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function expectedArtifacts(AiObjective $objective): array
    {
        return [
            [
                'kind' => 'execution_summary',
                'description' => 'Summary of work performed for: '.$objective->title,
                'required' => true,
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function expectedTests(AiObjective $objective): array
    {
        return [
            [
                'kind' => 'validation_check',
                'description' => 'Validate success criteria for: '.$objective->title,
                'required' => true,
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function riskNotes(AiMission $mission): array
    {
        return [
            [
                'category' => 'risk_level',
                'value' => (string) $mission->risk_level,
            ],
        ];
    }
}
