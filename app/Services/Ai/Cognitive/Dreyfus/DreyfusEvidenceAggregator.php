<?php

namespace App\Services\Ai\Cognitive\Dreyfus;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;
use App\Services\Ai\Support\DatabaseTableAvailability;

class DreyfusEvidenceAggregator
{
    public function __construct(private readonly KernelSloProbe $slo) {}

    /**
     * @return array<string,mixed>
     */
    public function aggregate(string $knowledgeNodeId, string $domain, int $hours = 720): array
    {
        return $this->slo->measure('cognitive.dreyfus.aggregate', function () use ($knowledgeNodeId, $domain, $hours): array {
            if (! DatabaseTableAvailability::has('atlas_ledger_events')) {
                return $this->empty($knowledgeNodeId, $domain, 'ledger_missing');
            }

            $events = AtlasLedgerEvent::query()
                ->where('occurred_at', '>=', now()->subHours(max(1, $hours)))
                ->latest('occurred_at')
                ->limit(500)
                ->get()
                ->filter(fn (AtlasLedgerEvent $event): bool => $this->matches($event, $knowledgeNodeId, $domain))
                ->take(200)
                ->values();

            if ($events->isEmpty()) {
                return $this->empty($knowledgeNodeId, $domain, 'no_evidence');
            }

            $successes = $events->filter(fn (AtlasLedgerEvent $event): bool => in_array($event->event_type, [
                'OPERATION_COMPLETED',
                'GATE_PASSED',
                'EVIDENCE_PACKED',
                'DREYFUS_LEVEL_DELTA_RECORDED',
            ], true))->count();
            $failures = $events->filter(fn (AtlasLedgerEvent $event): bool => in_array($event->event_type, [
                'OPERATION_FAILED',
                'GATE_BLOCKED',
                'OPERATION_BLOCKED',
            ], true))->count();
            $reviewed = $events->filter(fn (AtlasLedgerEvent $event): bool => (bool) data_get($event->payload, 'mastery_evidence.reviewed', false))->count();
            $transfer = $events->filter(fn (AtlasLedgerEvent $event): bool => (bool) data_get($event->payload, 'mastery_evidence.transfer_proof', false))->count();

            $score = ($successes * 8) + ($reviewed * 12) + ($transfer * 20) - ($failures * 10);
            $level = match (true) {
                $score >= 80 && $transfer >= 2 => 5,
                $score >= 55 && $transfer >= 1 => 4,
                $score >= 30 => 3,
                $score >= 10 => 2,
                default => 1,
            };

            return [
                'schema_version' => 'atlas.cognitive.dreyfus_evidence_aggregate.v1',
                'status' => 'ok',
                'knowledge_node_id' => $knowledgeNodeId,
                'domain' => $domain,
                'current_level' => $level,
                'confidence' => min(0.95, round(0.35 + (min(20, $events->count()) * 0.03) + ($reviewed * 0.04) + ($transfer * 0.05), 2)),
                'signals' => [
                    'event_count' => $events->count(),
                    'successes' => $successes,
                    'failures' => $failures,
                    'reviewed_mastery_evidence' => $reviewed,
                    'transfer_proofs' => $transfer,
                    'score' => $score,
                ],
                'evidence_refs' => $events->take(12)
                    ->map(fn (AtlasLedgerEvent $event): string => $event->event_type.':'.$event->id)
                    ->values()
                    ->all(),
            ];
        }, [
            'domain' => $domain,
            'knowledge_node_id' => $knowledgeNodeId,
            'hours' => $hours,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function empty(string $knowledgeNodeId, string $domain, string $reason): array
    {
        return [
            'schema_version' => 'atlas.cognitive.dreyfus_evidence_aggregate.v1',
            'status' => $reason,
            'knowledge_node_id' => $knowledgeNodeId,
            'domain' => $domain,
            'current_level' => 1,
            'confidence' => 0.95,
            'signals' => ['event_count' => 0],
            'evidence_refs' => [],
        ];
    }

    private function matches(AtlasLedgerEvent $event, string $knowledgeNodeId, string $domain): bool
    {
        return data_get($event->payload, 'domain') === $domain
            || data_get($event->payload, 'knowledge_node_id') === $knowledgeNodeId
            || data_get($event->payload, 'dreyfus.knowledge_node_id') === $knowledgeNodeId
            || data_get($event->payload, 'dreyfus.resolution.knowledge_node_id') === $knowledgeNodeId;
    }
}
