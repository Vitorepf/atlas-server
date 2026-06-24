<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Retry;

final class AtlasMaestroGiveBackReshapeStrategy
{
    /**
     * @param  array{
     *   allowed_files?:list<string>,
     *   forbidden_hits?:list<string>,
     *   scope_in?:list<string>,
     *   scope_in_mismatches?:list<string>,
     *   missing_symbol_traces?:list<array<string,mixed>>
     * }  $giveBackEvidence
     * @return ReshapeProposal|null
     */
    public function propose(array $giveBackEvidence): ?ReshapeProposal
    {
        $allowedFiles = $this->normalizePaths($giveBackEvidence['allowed_files'] ?? []);
        $forbiddenHits = $this->normalizePaths($giveBackEvidence['forbidden_hits'] ?? []);
        $scopeIn = $this->normalizePaths($giveBackEvidence['scope_in'] ?? []);
        $scopeMismatches = $this->normalizePaths($giveBackEvidence['scope_in_mismatches'] ?? []);
        $missingSymbolTraces = is_array($giveBackEvidence['missing_symbol_traces'] ?? null)
            ? $giveBackEvidence['missing_symbol_traces']
            : [];

        $domainEnvelope = $scopeIn !== [] ? $scopeIn : $allowedFiles;
        $anchor = $this->anchorFromMissingSymbolTrace($missingSymbolTraces, $domainEnvelope);

        if ($anchor === null) {
            return ReshapeProposal::empty();
        }

        $reshape = array_values(array_diff($allowedFiles, $forbiddenHits, $scopeMismatches));
        $reshape = $this->keepDomainEnvelope($reshape, $domainEnvelope);

        if (! in_array($anchor, $reshape, true)) {
            $reshape[] = $anchor;
        }

        $reshape = $this->keepDomainEnvelope($reshape, $domainEnvelope !== [] ? $domainEnvelope : [$anchor]);
        sort($reshape, SORT_STRING);

        return new ReshapeProposal(
            $reshape,
            [
                'dropped_forbidden_and_scope_mismatch_entries',
                'added_anchor_from_missing_symbol_trace',
                'kept_scope_inside_original_domain_envelope',
            ],
            'high',
        );
    }

    /**
     * @param  list<mixed>  $paths
     * @return list<string>
     */
    private function normalizePaths(array $paths): array
    {
        $normalized = array_values(array_filter(array_map(
            static fn (mixed $path): string => is_string($path) ? trim($path) : '',
            $paths,
        )));
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @param  list<array<string,mixed>>  $missingSymbolTraces
     * @param  list<string>  $domainEnvelope
     */
    private function anchorFromMissingSymbolTrace(array $missingSymbolTraces, array $domainEnvelope): ?string
    {
        foreach ($missingSymbolTraces as $trace) {
            $candidate = trim((string) ($trace['anchor_file'] ?? $trace['file'] ?? $trace['path'] ?? ''));
            if ($candidate === '') {
                continue;
            }

            if ($domainEnvelope === [] || in_array($candidate, $domainEnvelope, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $domainEnvelope
     * @return list<string>
     */
    private function keepDomainEnvelope(array $paths, array $domainEnvelope): array
    {
        if ($domainEnvelope === []) {
            return $paths;
        }

        return array_values(array_filter(
            $paths,
            static fn (string $path): bool => in_array($path, $domainEnvelope, true),
        ));
    }
}
