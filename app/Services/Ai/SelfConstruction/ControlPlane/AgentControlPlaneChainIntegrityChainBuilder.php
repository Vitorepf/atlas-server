<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\ChainIntegrity\AgentControlPlaneDeepChainCatalog;

/**
 * CHAIN-CONSTRUCTION concern, extracted from the god-class
 * {@see AgentControlPlaneChainIntegrityAuditService}.
 *
 * Owns the deterministic, no-I/O chain construction primitives: canonicalDeepChain,
 * deepChainEntry, stripDispatchPrefix, cliBaseForSlice and buildShallowChain.
 *
 * Four of the methods are thin object-oriented seams over the existing
 * AgentControlPlaneDeepChainCatalog::static() projections; the fifth (cliBaseForSlice)
 * owns a 4-line string transformation so the audit service stays focused on the
 * higher-level chain audit, and buildShallowChain owns the canonical-quintet filter.
 */
final class AgentControlPlaneChainIntegrityChainBuilder
{
    /**
     * @return list<array<string, string>>
     */
    public function canonicalDeepChain(): array
    {
        return AgentControlPlaneDeepChainCatalog::canonicalDeepChain();
    }

    /**
     * @return array{clean:bool, duplicate_count:int, blockers:list<string>, entries:list<array<string,string>>}
     */
    public function canonicalDeepChainAudit(): array
    {
        $chain = $this->canonicalDeepChain();
        $audit = AgentControlPlaneDeepChainCatalog::audit($chain);

        // Extend audit with duplicate invoker_class detection (the catalog only
        // checks slice_key and method_prefix duplicates natively).
        $extraBlockers = $this->detectDuplicateInvokerClasses($chain);

        $allBlockers = array_merge($audit['blockers'], $extraBlockers);
        $duplicateCount = count(array_filter($allBlockers, static fn (string $b): bool => str_starts_with($b, 'duplicate_')));

        return [
            'clean' => $allBlockers === [],
            'duplicate_count' => $duplicateCount,
            'blockers' => $allBlockers,
            'entries' => $chain,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function deepChainEntry(string $sliceKey, string $methodPrefix, string $invokerClass, string $prepareMethod, string $docBullet): array
    {
        return AgentControlPlaneDeepChainCatalog::deepChainEntry($sliceKey, $methodPrefix, $invokerClass, $prepareMethod, $docBullet);
    }

    public function stripDispatchPrefix(string $sliceKey): string
    {
        return AgentControlPlaneDeepChainCatalog::stripDispatchPrefix($sliceKey);
    }

    public function cliBaseForSlice(string $sliceKey): string
    {
        if ($sliceKey === '') {
            return '';
        }

        return 'agent-'.str_replace('_', '-', $sliceKey);
    }

    /**
     * Detect duplicate invoker_class entries in a chain.
     *
     * @param  list<array<string,string>>  $chain
     * @return list<string>  list of duplicate_invoker_class:<class> blockers
     */
    public function detectDuplicateInvokerClasses(array $chain): array
    {
        $seen = [];
        $blockers = [];
        foreach ($chain as $entry) {
            $invokerClass = (string) ($entry['invoker_class'] ?? '');
            if ($invokerClass !== '' && isset($seen[$invokerClass])) {
                $blockers[] = 'duplicate_invoker_class:'.$invokerClass;
            }
            if ($invokerClass !== '') {
                $seen[$invokerClass] = true;
            }
        }

        return $blockers;
    }

    /**
     * @param  list<string>  $currentCapability
     * @return list<string>
     */
    public function buildShallowChain(array $currentCapability): array
    {
        $suffixes = ['_contract', '_preflight', '_implementation_packet', '_invoker_service', '_status_projection'];
        $candidates = [];
        foreach ($currentCapability as $capability) {
            if (! is_string($capability)) {
                continue;
            }
            if (! str_starts_with($capability, 'automatic_dispatch_scheduler_one_shot_tick_')) {
                continue;
            }
            foreach ($suffixes as $suffix) {
                if (str_ends_with($capability, $suffix)) {
                    $candidate = substr($capability, 0, -strlen($suffix));
                    if ($candidate !== '') {
                        $candidates[$candidate] = true;
                    }
                    break;
                }
            }
        }

        // A slice family is only canonical when *every* one of its five
        // quintet members is registered in the capability list. This avoids
        // false positives when a slice key legitimately ends in `_contract`
        // (e.g. `post_start_receipt_contract`) and would otherwise be split
        // into a shorter family without the full quintet.
        $shallow = [];
        foreach (array_keys($candidates) as $candidate) {
            $allFive = true;
            foreach ($suffixes as $suffix) {
                if (! in_array($candidate.$suffix, $currentCapability, true)) {
                    $allFive = false;
                    break;
                }
            }
            if ($allFive) {
                $shallow[] = $candidate;
            }
        }

        return array_values(array_unique($shallow));
    }
}