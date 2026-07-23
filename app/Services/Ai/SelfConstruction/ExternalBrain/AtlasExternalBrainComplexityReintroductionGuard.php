<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * A simplification engine is useless if a later autonomous task quietly recreates the exact
 * complexity it removed. This admission gate compares a candidate task's pattern against the
 * history of recently removed duplicate, indirection, and public-surface patterns; a match with
 * no new justifying evidence is REJECTED as a reintroduction. A candidate that either matches
 * nothing in the removal history, or matches but carries genuine new evidence for why the
 * pattern is needed again, is admitted — reintroduction is never banned outright, only banned
 * without proof.
 *
 * Input contract:
 *   removed_patterns: list<array{pattern?: string, kind?: string}>
 *   candidate:         array{pattern?: string, kind?: string, new_evidence?: string}
 *
 * A candidate matches a removed pattern only when BOTH `pattern` and `kind` are identical —
 * the same string used for a different complexity kind is not a reintroduction.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainComplexityReintroductionGuard
{
    public const SCHEMA = 'atlas.external_brain.complexity_reintroduction_guard.v1';

    public const DECISION_ADMIT  = 'admit';
    public const DECISION_REJECT = 'reject';

    public const REASON_REINTRODUCES_WITHOUT_EVIDENCE = 'reintroduces_removed_pattern_without_new_evidence';
    public const REASON_ADMITTED_WITH_NEW_EVIDENCE     = 'admitted_with_new_evidence';
    public const REASON_NEW_CAPABILITY                 = 'new_capability_no_reintroduction';

    /**
     * @param  array{removed_patterns?: list<array<string,mixed>>, candidate?: array<string,mixed>}  $facts
     * @return array{schema:string, decision:string, reason:string, matched_removed_pattern:?string}
     */
    public function evaluate(array $facts): array
    {
        $removedPatterns = is_array($facts['removed_patterns'] ?? null) ? $facts['removed_patterns'] : [];
        $candidate = is_array($facts['candidate'] ?? null) ? $facts['candidate'] : [];

        $pattern     = trim((string) ($candidate['pattern'] ?? ''));
        $kind        = trim((string) ($candidate['kind'] ?? ''));
        $newEvidence = trim((string) ($candidate['new_evidence'] ?? ''));

        $matched = null;
        foreach ($removedPatterns as $removed) {
            if (! is_array($removed)) {
                continue;
            }
            $removedPattern = trim((string) ($removed['pattern'] ?? ''));
            $removedKind    = trim((string) ($removed['kind'] ?? ''));

            if ($removedPattern !== '' && $removedPattern === $pattern && $removedKind === $kind) {
                $matched = $removedPattern;

                break;
            }
        }

        if ($matched !== null && $newEvidence === '') {
            return [
                'schema'                   => self::SCHEMA,
                'decision'                 => self::DECISION_REJECT,
                'reason'                   => self::REASON_REINTRODUCES_WITHOUT_EVIDENCE,
                'matched_removed_pattern'  => $matched,
            ];
        }

        return [
            'schema'                   => self::SCHEMA,
            'decision'                 => self::DECISION_ADMIT,
            'reason'                   => $matched !== null ? self::REASON_ADMITTED_WITH_NEW_EVIDENCE : self::REASON_NEW_CAPABILITY,
            'matched_removed_pattern'  => $matched,
        ];
    }
}
