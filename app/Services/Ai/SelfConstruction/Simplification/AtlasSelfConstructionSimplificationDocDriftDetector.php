<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure detector: after a simplification wave retires or merges organs, docs, memory
 * projections, and runbooks that still reference the old symbol are stale. Catches
 * exactly those references before they mislead a future implementer.
 *
 * A reference is stale if its `symbol` matches a `retired_organs` entry's `name` (the
 * organ is gone/merged/renamed) OR a `behavior_shifted_organs` entry's `name` (the organ
 * still exists but its documented behavior no longer matches reality).
 * Severity defaults to 'high' (docs pointing at a symbol that no longer exists) unless
 * the reference explicitly downgrades itself (e.g. a historical changelog entry), or is
 * marked `intentional_historical_note` — a deliberate record of the past that must not
 * be treated as something needing a doc sync.
 *
 * Pure / deterministic. No I/O — callers persist/act on the returned stale_refs.
 */
final class AtlasSelfConstructionSimplificationDocDriftDetector
{
    public const SCHEMA = 'atlas.self_construction.simplification.doc_drift_detector.v1';

    private const DEFAULT_SEVERITY = 'high';

    /**
     * @param  array{
     *   retired_organs?: list<array{name?: string, replacement?: string}>,
     *   behavior_shifted_organs?: list<array{name?: string, note?: string}>,
     *   references?: list<array{file?: string, symbol?: string, severity?: string, intentional_historical_note?: bool}>,
     * }  $facts
     * @return array{schema:string, docs_synced:bool, stale_refs:list<array<string,mixed>>}
     */
    public function detect(array $facts): array
    {
        $retiredOrgans = [];
        foreach ((array) ($facts['retired_organs'] ?? []) as $organ) {
            if (! is_array($organ)) {
                continue;
            }
            $name = trim((string) ($organ['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $retiredOrgans[$name] = (string) ($organ['replacement'] ?? '');
        }

        $behaviorShiftedOrgans = [];
        foreach ((array) ($facts['behavior_shifted_organs'] ?? []) as $organ) {
            if (! is_array($organ)) {
                continue;
            }
            $name = trim((string) ($organ['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $behaviorShiftedOrgans[$name] = (string) ($organ['note'] ?? '');
        }

        $staleRefs = [];
        foreach ((array) ($facts['references'] ?? []) as $reference) {
            if (! is_array($reference)) {
                continue;
            }
            $symbol = trim((string) ($reference['symbol'] ?? ''));
            $isRetired = $symbol !== '' && array_key_exists($symbol, $retiredOrgans);
            $isBehaviorShifted = $symbol !== '' && array_key_exists($symbol, $behaviorShiftedOrgans);
            if (! $isRetired && ! $isBehaviorShifted) {
                continue;
            }

            $file = (string) ($reference['file'] ?? '');
            $isHistorical = ($reference['intentional_historical_note'] ?? false) === true;
            $driftKind = $isRetired ? 'retired' : 'behavior_shift';
            $replacement = $isRetired ? $retiredOrgans[$symbol] : '';
            $note = $isBehaviorShifted ? $behaviorShiftedOrgans[$symbol] : '';

            $severity = trim((string) ($reference['severity'] ?? self::DEFAULT_SEVERITY));
            $severity = $severity === '' ? self::DEFAULT_SEVERITY : $severity;
            if ($isHistorical) {
                $severity = 'historical';
            }

            $staleRefs[] = [
                'file' => $file,
                'symbol' => $symbol,
                'replacement' => $replacement,
                'severity' => $severity,
                'required_update' => ! $isHistorical,
                'drift_kind' => $driftKind,
                'recommended_sync_action' => $this->recommendedSyncAction($isHistorical, $driftKind, $file, $symbol, $replacement, $note),
            ];
        }

        $hasHighSeverityStaleRef = array_values(array_filter(
            $staleRefs,
            static fn (array $ref): bool => $ref['severity'] === 'high',
        )) !== [];

        return [
            'schema' => self::SCHEMA,
            'docs_synced' => ! $hasHighSeverityStaleRef,
            'stale_refs' => $staleRefs,
        ];
    }

    private function recommendedSyncAction(
        bool $isHistorical,
        string $driftKind,
        string $file,
        string $symbol,
        string $replacement,
        string $note,
    ): string {
        if ($isHistorical) {
            return "No action: {$file} intentionally records the historical symbol {$symbol}.";
        }

        if ($driftKind === 'retired') {
            return "Update {$file}: replace {$symbol} with {$replacement}.";
        }

        $suffix = $note !== '' ? " ({$note})" : '';

        return "Update {$file}: re-verify {$symbol}'s documented behavior — it has shifted{$suffix}.";
    }
}
