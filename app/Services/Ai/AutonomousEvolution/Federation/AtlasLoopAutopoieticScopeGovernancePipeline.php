<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Federation;

/**
 * AUTOPOIETIC SCOPE GOVERNANCE — the fail-closed gate that turns a scope-origination proposal (the shape
 * {@see \App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination\AtlasLoopScopeOriginationProposer}
 * proposes and {@see \App\Services\Ai\AutonomousEvolution\Autopoiesis\SelfExtension\AtlasLoopAutopoieticBootstrapper}
 * would materialize) into an ADMISSIBLE new scope — or refuses it with recorded reasons. Read-only: it decides,
 * it never writes/bootstraps/merges.
 *
 * HARD CONSTITUTIONAL RULES (fail-closed — any doubt ⇒ NOT admitted):
 *   - LOOP CORE IS OFF-LIMITS: a proposal whose root overlaps `app/Services/Ai/AutonomousEvolution/` is rejected
 *     with `ScopeOverlapsLoopCore` (the same boundary the bootstrapper guards) — a new scope can NEVER be born
 *     inside the loop's own organs.
 *   - NEVER-WEAKEN-WITHOUT-RECEIPT: requires_operator_receipt is ALWAYS true, and `admitted` is NEVER true
 *     unless the proposal carries an operator receipt — a new scope only crosses after the operator signs off.
 * Deterministic. NEW class only.
 */
final class AtlasLoopAutopoieticScopeGovernancePipeline
{
    public const SCHEMA = 'atlas.loop.autopoietic_scope_governance.v1';

    /** The loop's own organs — a new scope may never root here. */
    private const LOOP_CORE_ROOT = 'app/Services/Ai/AutonomousEvolution';

    public const REASON_LOOP_CORE_OVERLAP = 'ScopeOverlapsLoopCore';

    public const REASON_INCOMPLETE_DESCRIPTOR = 'IncompleteScopeDescriptor';

    public const REASON_OPERATOR_RECEIPT_REQUIRED = 'OperatorReceiptRequired';

    /**
     * @param  array<string,mixed>  $scopeProposal  {scope_id, namespace, operator_intent, root|roots, operator_receipt?}
     * @return array{schema:string, admitted:bool, blocking_reasons:list<string>, requires_operator_receipt:bool}
     */
    public function evaluate(array $scopeProposal): array
    {
        $reasons = [];

        $roots = $this->roots($scopeProposal);
        if ($roots === []) {
            $reasons[] = self::REASON_INCOMPLETE_DESCRIPTOR;
        }
        foreach ($roots as $root) {
            if ($this->overlapsLoopCore($root)) {
                $reasons[] = self::REASON_LOOP_CORE_OVERLAP;
                break;
            }
        }

        // A scope descriptor must be complete to be materializable (mirrors the bootstrapper's requirement).
        $scopeId = trim((string) ($scopeProposal['scope_id'] ?? ''));
        $namespace = trim((string) ($scopeProposal['namespace'] ?? ''));
        $operatorIntent = is_array($scopeProposal['operator_intent'] ?? null) ? $scopeProposal['operator_intent'] : [];
        if (($scopeId === '' || $namespace === '' || $operatorIntent === []) && ! in_array(self::REASON_INCOMPLETE_DESCRIPTOR, $reasons, true)) {
            $reasons[] = self::REASON_INCOMPLETE_DESCRIPTOR;
        }

        // NEVER-WEAKEN-WITHOUT-RECEIPT: a new scope can only be admitted with an operator receipt.
        if (! $this->hasOperatorReceipt($scopeProposal)) {
            $reasons[] = self::REASON_OPERATOR_RECEIPT_REQUIRED;
        }

        $reasons = array_values(array_unique($reasons));
        sort($reasons, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'admitted' => $reasons === [],
            'blocking_reasons' => $reasons,
            // ALWAYS true: a new scope is never auto-admissible without an operator receipt. This is the
            // invariant that makes `admitted` ⇒ a receipt was present.
            'requires_operator_receipt' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @return list<string>
     */
    private function roots(array $proposal): array
    {
        if (isset($proposal['roots']) && is_array($proposal['roots'])) {
            return array_values(array_filter(
                array_map(static fn ($r): string => trim((string) $r), $proposal['roots']),
                static fn (string $r): bool => $r !== '',
            ));
        }
        $root = trim((string) ($proposal['root'] ?? ''));

        return $root === '' ? [] : [$root];
    }

    private function overlapsLoopCore(string $root): bool
    {
        $normalized = rtrim(ltrim(trim(str_replace('\\', '/', $root)), '/'), '/');

        return $normalized === self::LOOP_CORE_ROOT
            || str_starts_with($normalized.'/', self::LOOP_CORE_ROOT.'/');
    }

    /**
     * @param  array<string,mixed>  $proposal
     */
    private function hasOperatorReceipt(array $proposal): bool
    {
        $receipt = $proposal['operator_receipt'] ?? null;
        if (is_string($receipt)) {
            return trim($receipt) !== '';
        }

        return is_array($receipt) && $receipt !== [];
    }
}
