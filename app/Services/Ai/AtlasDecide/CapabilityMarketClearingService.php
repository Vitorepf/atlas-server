<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;

final class CapabilityMarketClearingService
{
    public function __construct(private readonly ?AtlasEvidenceLedger $ledger = null) {}

    /** @param list<array<string,mixed>> $routes */
    public function clear(CapabilityMarketRequest $request, array $routes): CapabilityMarketDecision
    {
        $eligible = []; $rejected = [];
        foreach ($routes as $route) {
            $id = (string) ($route['id'] ?? '');
            $reason = $this->ineligibleReason($request, $route);
            if ($id === '' || $reason !== null) { if ($id !== '') $rejected[$id] = $reason ?? 'route_invalid'; continue; }
            $eligible[$id] = $route;
        }
        $risk = $request->data['risk_class'];
        if (in_array($risk, ['R4','R5'], true)) {
            foreach ($eligible as $id => $route) if (count(array_unique((array) $route['verifier_families'])) < 2) { $rejected[$id] = 'verifier_diversity_insufficient'; unset($eligible[$id]); }
        }
        uasort($eligible, static function (array $a, array $b): int {
            foreach ([['quality_status', ['proven' => 0, 'calibrated' => 1]], ['estimated_time_ms', null], ['estimated_cost', null], ['id', null]] as [$field, $order]) {
                if ($field === 'quality_status') { $cmp = ($order[$a[$field]] ?? 9) <=> ($order[$b[$field]] ?? 9); } else { $cmp = $a[$field] <=> $b[$field]; }
                if ($cmp !== 0) return $cmp;
            }
            return 0;
        });
        $selected = array_key_first($eligible);
        $candidateSet = $request->data['topology'] === 'candidate_set' ? array_keys($eligible) : ($selected === null ? [] : [$selected]);
        $hash = hash('sha256', json_encode(['request' => $request->requestHash, 'selected' => $selected, 'candidate_set' => $candidateSet, 'rejected' => $rejected], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $decision = new CapabilityMarketDecision($selected, $candidateSet, $rejected, $hash);
        $this->record($request, $decision);

        return $decision;
    }

    /** @param array<string,mixed> $route */
    private function ineligibleReason(CapabilityMarketRequest $request, array $route): ?string
    {
        if (($route['allowed'] ?? false) !== true || ($route['authority_status'] ?? null) !== 'active') return 'authority_or_route_disallowed';
        if (($route['available'] ?? false) !== true) return 'route_unavailable';
        if (array_diff((array) $request->data['required_capabilities'], (array) ($route['capabilities'] ?? [])) !== []) return 'capability_missing';
        if (! in_array($route['quality_status'] ?? null, ['proven', 'calibrated'], true)) return 'quality_evidence_unknown';
        return null;
    }

    private function record(CapabilityMarketRequest $request, CapabilityMarketDecision $decision): void
    {
        $ledger = $this->ledger;
        if ($ledger === null && function_exists('app')) {
            try { $ledger = app()->bound(AtlasEvidenceLedger::class) ? app(AtlasEvidenceLedger::class) : null; } catch (\Throwable) { $ledger = null; }
        }
        if (! $ledger instanceof AtlasEvidenceLedger) return;
        $eventId = 'market-'.substr($decision->decisionHash, 0, 25);
        if ($ledger->eventById($eventId) !== null) return;
        $ledger->record(LedgerEventType::DecisionIssued, [
            'event_name' => 'capability.market.cleared', 'request_hash' => $request->requestHash,
            'decision_hash' => $decision->decisionHash, 'selected_route' => $decision->selectedRoute,
            'candidate_set' => $decision->candidateSet, 'rejected' => $decision->rejected, 'claim_eligible' => false,
        ], ['event_id' => $eventId, 'correlation_id' => $request->requestHash, 'scope_type' => 'capability_market',
            'scope_id' => $request->requestHash, 'emitter_stage' => 'atlas.decide.capability_market']);
    }
}
