<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure detector: after a simplification wave retires or merges organs, docs, memory
 * projections, and runbooks that still reference the old symbol are stale. Catches
 * exactly those references before they mislead a future implementer.
 *
 * A reference is stale only if its `symbol` matches a `retired_organs` entry's `name`.
 * Severity defaults to 'high' (docs pointing at a symbol that no longer exists) unless
 * the reference explicitly downgrades itself (e.g. a historical changelog entry).
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
     *   references?: list<array{file?: string, symbol?: string, severity?: string}>,
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

        $staleRefs = [];
        foreach ((array) ($facts['references'] ?? []) as $reference) {
            if (! is_array($reference)) {
                continue;
            }
            $symbol = trim((string) ($reference['symbol'] ?? ''));
            if ($symbol === '' || ! array_key_exists($symbol, $retiredOrgans)) {
                continue;
            }

            $severity = trim((string) ($reference['severity'] ?? self::DEFAULT_SEVERITY));
            $severity = $severity === '' ? self::DEFAULT_SEVERITY : $severity;

            $staleRefs[] = [
                'file' => (string) ($reference['file'] ?? ''),
                'symbol' => $symbol,
                'replacement' => $retiredOrgans[$symbol],
                'severity' => $severity,
                'required_update' => true,
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
}
