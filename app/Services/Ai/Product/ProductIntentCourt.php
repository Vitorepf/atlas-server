<?php

declare(strict_types=1);

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;

final class ProductIntentCourt
{
    public function __construct(
        private readonly ?AtlasProductTruthCompilerService $compiler = null,
        private readonly ?AtlasEvidenceLedger $ledger = null,
    ) {}

    public function adjudicate(ProductIntentCase $case): ProductIntentVerdict
    {
        $d = $case->data;
        $truth = ($this->compiler ?? new AtlasProductTruthCompilerService)->compile([
            'human_request' => $d['human_request'],
            'route' => $d['mode'],
        ]);
        $blocking = [];
        foreach (['problem', 'user', 'value'] as $key) {
            if (($d[$key] ?? null) === null) $blocking[] = $key.'_missing';
        }
        if (($d['metric'] ?? null) === null) $blocking[] = 'metric_missing';
        if (($d['observation_window'] ?? null) === null) $blocking[] = 'observation_window_missing';
        if ($d['source_refs'] === []) $blocking[] = 'source_provenance_missing';
        if ($d['falsifiers'] === []) $blocking[] = 'falsifier_missing';
        if ($d['acceptance'] === []) $blocking[] = 'acceptance_missing';
        if (($truth['status'] ?? null) !== 'ready') $blocking[] = 'product_truth_not_ready';
        if (array_intersect(array_map('strval', $d['constraints']), array_map('strval', $d['non_goals'])) !== []) {
            $blocking[] = 'contradictory_constraints';
        }

        $risk = (int) substr((string) $d['risk_class'], 1);
        if ($risk >= 3 && (($d['world_snapshot_hash'] ?? null) === null || ($d['world_snapshot_status'] ?? null) !== 'fresh')) {
            $blocking[] = $d['world_snapshot_hash'] === null ? 'world_snapshot_missing' : 'world_snapshot_stale';
        }
        foreach ($d['side_effects'] as $sideEffect) {
            if (is_array($sideEffect) && trim((string) ($sideEffect['containment'] ?? '')) === '') {
                $blocking[] = 'unbounded_side_effect';
            }
        }

        $status = 'admitted';
        if (in_array('unbounded_side_effect', $blocking, true)) {
            $status = 'refused';
        } elseif (in_array('world_snapshot_missing', $blocking, true) || in_array('world_snapshot_stale', $blocking, true)) {
            $status = 'held';
        } elseif ($blocking !== []) {
            $status = 'revise';
        }

        $hashPayload = $d;
        unset($hashPayload['mode']);
        $hashPayload['status'] = $status;
        $hashPayload['blocking_reasons'] = array_values(array_unique($blocking));
        $hashPayload['product_truth_hash'] = $truth['truth_hash'] ?? null;
        $intentHash = MissionCanonicalHash::sha256($hashPayload);

        $verdict = new ProductIntentVerdict(
            $status, (string) ($d['problem'] ?? ''), (string) ($d['user'] ?? ''), (string) ($d['value'] ?? ''),
            (string) ($d['metric'] ?? ''), (string) ($d['observation_window'] ?? ''), $d['source_refs'], $d['constraints'],
            $d['non_goals'], $d['hypotheses'], $d['uncertainties'], $d['alternatives'], $d['falsifiers'],
            $d['side_effects'], $d['acceptance'], $d['release_policy'], $d['outcome_policy'], $d['world_snapshot_hash'],
            array_values(array_unique($blocking)), $intentHash, $truth,
        );
        if ($status === 'admitted') {
            $this->recordFrozenUnit($verdict);
        }

        return $verdict;
    }

    private function recordFrozenUnit(ProductIntentVerdict $verdict): void
    {
        $ledger = $this->ledger;
        if (! $ledger instanceof AtlasEvidenceLedger) return;
        $eventId = 'unit-frozen-'.substr($verdict->intentHash, 0, 20);
        if ($ledger->eventById($eventId) !== null) return;
        $ledger->record(LedgerEventType::UnitFrozen, [
            'event_name' => 'unit.frozen', 'intent_hash' => $verdict->intentHash,
            'world_snapshot_hash' => $verdict->worldSnapshotHash, 'status' => $verdict->status,
        ], [
            'event_id' => $eventId, 'correlation_id' => $verdict->intentHash,
            'scope_type' => 'product_intent', 'scope_id' => $verdict->intentHash,
            'emitter_stage' => 'atlas.product.intent_court',
        ]);
    }
}
