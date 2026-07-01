<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Retry;

final class AtlasMaestroGiveBackReshapeStrategy
{
    // AC2: give_back root causes, classified into rationale as 'root_cause:<name>' — first
    // match wins. ReshapeProposal (out of scope here) has no dedicated field for this, so it
    // rides in the existing rationale list as a structured token instead of a new property.
    private const ROOT_CAUSE_FORBIDDEN_TARGET = 'forbidden_target';
    private const ROOT_CAUSE_CONTRADICTION = 'contradiction';
    private const ROOT_CAUSE_DUPLICATE_CAPABILITY = 'duplicate_capability';
    private const ROOT_CAUSE_SCOPE_MISSING = 'scope_missing';
    private const ROOT_CAUSE_WEAK_ACCEPTANCE = 'weak_acceptance';

    /**
     * @param  array{
     *   allowed_files?:list<string>,
     *   forbidden_hits?:list<string>,
     *   scope_in?:list<string>,
     *   scope_in_mismatches?:list<string>,
     *   missing_symbol_traces?:list<array<string,mixed>>,
     *   contradiction_evidence?:bool,
     *   duplicate_capability_evidence?:bool
     * }  $giveBackEvidence
     * @return ReshapeProposal|null
     */
    public function propose(array $giveBackEvidence): ?ReshapeProposal
    {
        $allowedFiles = $this->normalizePaths($giveBackEvidence['allowed_files'] ?? []);
        $forbiddenHits = $this->normalizePaths($giveBackEvidence['forbidden_hits'] ?? []);
        $petreoFiles = $this->normalizePaths($giveBackEvidence['petreo_files'] ?? []);
        $scopeIn = $this->normalizePaths($giveBackEvidence['scope_in'] ?? []);
        $scopeMismatches = $this->normalizePaths($giveBackEvidence['scope_in_mismatches'] ?? []);
        $missingSymbolTraces = is_array($giveBackEvidence['missing_symbol_traces'] ?? null)
            ? $giveBackEvidence['missing_symbol_traces']
            : [];
        $contradictionEvidence = (bool) ($giveBackEvidence['contradiction_evidence'] ?? false);
        $duplicateCapabilityEvidence = (bool) ($giveBackEvidence['duplicate_capability_evidence'] ?? false);

        $domainEnvelope = $scopeIn !== [] ? $scopeIn : $allowedFiles;
        // AC4: search for an anchor with no envelope restriction — a candidate outside the
        // domain envelope is still real evidence (scope_missing), not the same as no evidence
        // at all (weak_acceptance), and the two must not be conflated into one blind refusal.
        $anchor = $this->anchorFromMissingSymbolTrace($missingSymbolTraces, []);

        if ($anchor === null) {
            return new ReshapeProposal([], [
                'no_anchor_evidence',
                'root_cause:'.self::ROOT_CAUSE_WEAK_ACCEPTANCE,
                'min_missing_evidence:missing_symbol_traces_with_anchor_file',
            ], 'none', true);
        }

        if (str_contains($anchor, '..')) {
            return new ReshapeProposal([], [
                'parent_traversal_rejected',
                'root_cause:'.self::ROOT_CAUSE_FORBIDDEN_TARGET,
                'quarantine_recommended',
            ], 'none', true);
        }

        if (in_array($anchor, $forbiddenHits, true) || in_array($anchor, $petreoFiles, true)) {
            return new ReshapeProposal([], [
                'forbidden_or_petreo_anchor_rejected',
                'root_cause:'.self::ROOT_CAUSE_FORBIDDEN_TARGET,
                'quarantine_recommended',
            ], 'none', true);
        }

        if ($contradictionEvidence) {
            return new ReshapeProposal([], [
                'acceptance_contradicts_objective_or_scope',
                'root_cause:'.self::ROOT_CAUSE_CONTRADICTION,
                'acceptance_repair_recommended:clarify_acceptance_criteria_to_remove_contradiction',
                'min_missing_evidence:clarified_acceptance_criteria_without_contradiction',
            ], 'none', true);
        }

        if ($duplicateCapabilityEvidence) {
            return new ReshapeProposal([], [
                'capability_already_implemented_elsewhere',
                'root_cause:'.self::ROOT_CAUSE_DUPLICATE_CAPABILITY,
                'quarantine_recommended',
                'min_missing_evidence:confirmation_this_capability_is_not_already_delivered',
            ], 'none', true);
        }

        // AC2: the anchor is real evidence, but the original allowed_files never covered it —
        // this give_back's root cause is a scope that was too narrow, not weak evidence.
        $scopeMissing = ! in_array($anchor, $allowedFiles, true);

        $effectiveEnvelope = $domainEnvelope;
        if (! in_array($anchor, $effectiveEnvelope, true)) {
            $effectiveEnvelope[] = $anchor;
        }

        $reshape = array_values(array_diff($allowedFiles, $forbiddenHits, $petreoFiles, $scopeMismatches));
        $reshape = $this->keepDomainEnvelope($reshape, $effectiveEnvelope);

        if (! in_array($anchor, $reshape, true)) {
            $reshape[] = $anchor;
        }
        sort($reshape, SORT_STRING);

        // Require at least one impl and one test file in the result.
        $hasImpl = false;
        $hasTest = false;
        foreach ($reshape as $f) {
            if (str_contains($f, '/tests/') || str_contains($f, '/Tests/') || str_ends_with($f, 'Test.php')) {
                $hasTest = true;
            } else {
                $hasImpl = true;
            }
        }
        if (! $hasImpl || ! $hasTest) {
            return new ReshapeProposal([], [
                'impl_test_pair_incomplete',
                'root_cause:'.($scopeMissing ? self::ROOT_CAUSE_SCOPE_MISSING : self::ROOT_CAUSE_WEAK_ACCEPTANCE),
                'min_missing_evidence:impl_and_test_file_pair_for_anchor',
            ], 'none', true);
        }

        $rationale = [
            'dropped_forbidden_and_scope_mismatch_entries',
            'added_anchor_from_missing_symbol_trace',
            'kept_scope_inside_original_domain_envelope',
        ];
        if ($scopeMissing) {
            $rationale[] = 'root_cause:'.self::ROOT_CAUSE_SCOPE_MISSING;
            $rationale[] = 'acceptance_repair_recommended:widen_allowed_files_to_include_anchor';
        }

        return new ReshapeProposal($reshape, $rationale, 'high');
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
