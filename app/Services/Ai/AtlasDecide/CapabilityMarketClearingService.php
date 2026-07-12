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
        ksort($rejected);
        $risk = (string) $request->data['risk_class'];
        if (in_array($risk, ['R4','R5'], true)) {
            foreach ($eligible as $id => $route) {
                if (count(array_unique((array) $route['verifier_families'])) < 2) {
                    $rejected[$id] = 'verifier_diversity_insufficient';
                    unset($eligible[$id]);
                }
            }
        }
        ksort($rejected);
        uasort($eligible, static function (array $a, array $b): int {
            foreach (['quality_status', 'quality_score', 'diversity_score', 'estimated_time_ms', 'estimated_cost', 'id'] as $field) {
                if ($field === 'quality_status') {
                    $cmp = (['proven' => 0, 'calibrated' => 1][$a[$field]] ?? 9)
                        <=> (['proven' => 0, 'calibrated' => 1][$b[$field]] ?? 9);
                } elseif ($field === 'id') {
                    $cmp = strcmp((string) $a[$field], (string) $b[$field]);
                } else {
                    $cmp = ((float) ($b[$field] ?? 0)) <=> ((float) ($a[$field] ?? 0));
                    if (in_array($field, ['estimated_time_ms', 'estimated_cost'], true)) {
                        $cmp = ((float) ($a[$field] ?? INF)) <=> ((float) ($b[$field] ?? INF));
                    }
                }
                if ($cmp !== 0) return $cmp;
            }
            return 0;
        });
        $selected = array_key_first($eligible);
        $candidateSet = $request->data['topology'] === 'candidate_set' ? array_keys($eligible) : ($selected === null ? [] : [$selected]);
        $availability = [];
        foreach ($routes as $route) {
            $id = (string) ($route['id'] ?? '');
            if ($id !== '') $availability[$id] = ($route['available'] ?? false) === true;
        }
        ksort($availability);
        $selectedRoute = $selected === null ? [] : (array) ($eligible[$selected] ?? []);
        $evidenceRefs = array_values(array_filter(array_map('strval', (array) ($selectedRoute['evidence_refs'] ?? [
            'quality:'.(string) ($selectedRoute['quality_hash'] ?? ''),
        ])), static fn (string $ref): bool => $ref !== ''));
        $routeProof = [];
        foreach ($eligible as $id => $route) {
            $routeProof[] = [
                'id' => $id,
                'quality_hash' => (string) $route['quality_hash'],
                'provider_version' => (string) $route['provider_version'],
                'verifier_families' => array_values(array_map('strval', (array) $route['verifier_families'])),
                'estimated_time_ms' => (float) $route['estimated_time_ms'],
                'estimated_cost' => (float) $route['estimated_cost'],
            ];
        }
        $hash = hash('sha256', json_encode([
            'request' => $request->requestHash, 'selected' => $selected, 'candidate_set' => $candidateSet,
            'rejected' => $rejected, 'availability' => $availability, 'route_proof' => $routeProof,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $decision = new CapabilityMarketDecision(
            selectedRoute: $selected,
            candidateSet: $candidateSet,
            rejected: $rejected,
            decisionHash: $hash,
            evidenceRefs: $evidenceRefs,
            availability: $availability,
            estimatedTimeMs: $selectedRoute === [] ? null : (int) $selectedRoute['estimated_time_ms'],
            estimatedCost: $selectedRoute === [] ? null : (float) $selectedRoute['estimated_cost'],
            explorationRef: isset($request->data['experiment_ref']) ? (string) $request->data['experiment_ref'] : null,
        );
        $this->record($request, $decision);

        return $decision;
    }

    /** @param array<string,mixed> $route */
    private function ineligibleReason(CapabilityMarketRequest $request, array $route): ?string
    {
        if (($route['allowed'] ?? false) !== true || ($route['authority_status'] ?? null) !== 'active') return 'authority_or_route_disallowed';
        if (($route['available'] ?? false) !== true) return 'route_unavailable';
        if (array_diff((array) $request->data['required_capabilities'], (array) ($route['capabilities'] ?? [])) !== []) return 'capability_missing';
        foreach (['order_hash', 'snapshot_hash', 'authority_hash'] as $binding) {
            if (! is_string($route[$binding] ?? null) || ! hash_equals((string) $request->data[$binding], $route[$binding])) {
                return 'request_binding_mismatch';
            }
        }
        if (isset($route['supported_risk_classes'])
            && ! in_array($request->data['risk_class'], (array) $route['supported_risk_classes'], true)) {
            return 'risk_fit_insufficient';
        }
        if (! in_array($route['quality_status'] ?? null, ['proven', 'calibrated'], true)) return 'quality_evidence_unknown';
        if (preg_match('/^[a-f0-9]{64}$/', (string) ($route['quality_hash'] ?? '')) !== 1) return 'quality_evidence_invalid';
        if (trim((string) ($route['provider_version'] ?? '')) === '') return 'quality_evidence_invalid';
        if (! is_array($route['quality_evidence'] ?? null) || trim((string) ($route['quality_evidence']['observation_window'] ?? '')) === '') {
            return 'quality_evidence_incomplete';
        }
        if (! is_numeric($route['estimated_time_ms'] ?? null) || ! is_numeric($route['estimated_cost'] ?? null)) return 'route_estimate_invalid';
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
            'candidate_set' => $decision->candidateSet, 'rejected' => $decision->rejected,
            'evidence_refs' => $decision->evidenceRefs, 'availability' => $decision->availability,
            'estimated_time_ms' => $decision->estimatedTimeMs, 'estimated_cost' => $decision->estimatedCost,
            'exploration_ref' => $decision->explorationRef, 'claim_eligible' => false,
        ], ['event_id' => $eventId, 'correlation_id' => $request->requestHash, 'scope_type' => 'capability_market',
            'scope_id' => $request->requestHash, 'emitter_stage' => 'atlas.decide.capability_market']);
    }
}
