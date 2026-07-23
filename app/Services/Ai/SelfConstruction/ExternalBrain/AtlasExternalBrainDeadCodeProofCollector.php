<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Fail-closed proof gate for codebase-compression dead-code deletion: a symbol is never marked
 * dead_code_confirmed on the strength of a single grep. Confirmation requires ALL of: search
 * evidence was actually collected, no route reference, no command reference, no consumer
 * reference, and a replacement proof (what closes the gap this symbol leaves). Any ambiguous
 * search result or dynamically-referenced symbol (call_user_func, variable method/class name,
 * reflection) returns needs_manual_or_runtime_probe instead of a confirmed deletion — grep
 * cannot prove the absence of a reference it cannot see.
 *
 * Input shape:
 *   { candidate: {
 *       symbol?:                     string,
 *       search_evidence?:            string,  // non-empty proof a search was actually run
 *       route_reference_found?:      bool,
 *       command_reference_found?:    bool,
 *       consumer_reference_found?:   bool,
 *       dynamic_reference_suspected?: bool,    // call_user_func / variable method / reflection
 *       search_ambiguous?:           bool,     // search matched but could not disambiguate
 *       replacement_proof?:          string,
 *   } }
 *
 * Pure: no I/O, no provider calls, deterministic — callers supply the search/reference facts.
 */
final class AtlasExternalBrainDeadCodeProofCollector
{
    public const SCHEMA = 'atlas.self_construction.external_brain.dead_code_proof_collector.v1';

    /**
     * @param  array{candidate?: array<string,mixed>}  $facts
     * @return array<string,mixed>
     */
    public function collect(array $facts): array
    {
        $candidate = is_array($facts['candidate'] ?? null) ? $facts['candidate'] : [];

        $symbol = trim((string) ($candidate['symbol'] ?? ''));
        $searchEvidence = trim((string) ($candidate['search_evidence'] ?? ''));
        $routeFound = (bool) ($candidate['route_reference_found'] ?? false);
        $commandFound = (bool) ($candidate['command_reference_found'] ?? false);
        $consumerFound = (bool) ($candidate['consumer_reference_found'] ?? false);
        $dynamicSuspected = (bool) ($candidate['dynamic_reference_suspected'] ?? false);
        $searchAmbiguous = (bool) ($candidate['search_ambiguous'] ?? false);
        $replacementProof = trim((string) ($candidate['replacement_proof'] ?? ''));

        $missingProofs = [];
        if ($searchEvidence === '') {
            $missingProofs[] = 'missing_search_evidence';
        }
        if ($routeFound) {
            $missingProofs[] = 'route_reference_present';
        }
        if ($commandFound) {
            $missingProofs[] = 'command_reference_present';
        }
        if ($consumerFound) {
            $missingProofs[] = 'consumer_reference_present';
        }
        if ($replacementProof === '') {
            $missingProofs[] = 'missing_replacement_proof';
        }

        // A static search can never prove the absence of a dynamic call site, and an ambiguous
        // match means the search itself did not disambiguate — both fail closed to a probe
        // requirement rather than a confirmed (or falsely denied) deletion.
        if ($dynamicSuspected || $searchAmbiguous) {
            return [
                'schema' => self::SCHEMA,
                'symbol' => $symbol,
                'dead_code_confirmed' => false,
                'needs_manual_or_runtime_probe' => true,
                'missing_proofs' => $missingProofs,
                'reason' => $dynamicSuspected
                    ? 'dynamic_reference_suspected: static search cannot prove absence of a call_user_func/variable-method/reflection reference'
                    : 'search_ambiguous: static search matched but could not disambiguate real usage from noise',
            ];
        }

        $confirmed = $missingProofs === [];

        return [
            'schema' => self::SCHEMA,
            'symbol' => $symbol,
            'dead_code_confirmed' => $confirmed,
            'needs_manual_or_runtime_probe' => false,
            'missing_proofs' => $missingProofs,
            'reason' => $confirmed ? 'all_required_proofs_present' : 'missing_required_proof',
        ];
    }
}
