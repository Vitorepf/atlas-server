<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active;

use Closure;

/**
 * CORTEX COUNTERFACTUAL PROBE — answers "what breaks if symbol X is removed?" by composing the
 * {@see AtlasCortexHypotheticalChangeWalker} with a REMOVAL edit and post-filtering its propagation FACTs down
 * to the HARD breaks: call_site_dangling, interface_contract_violated, downstream_required_field_missing. Each
 * surviving FACT carries caller_file:line, the broken_contract, and a purely DESCRIPTIVE minimum_repair_hint
 * (remove_call | replace_with_no_op | inline_constant) — never a score, never a suggested patch.
 *
 * Honest by construction: it refuses on an unresolved target (returns a single UNRESOLVED_TARGET fact, never a
 * silent empty list), surfaces the walker's UNKNOWN_REGION blind spots, and is idempotent/pure.
 */
final class AtlasCortexCounterfactualProbe
{
    public const SCHEMA = 'atlas.cortex.active.counterfactual_probe.v1';

    public const UNRESOLVED_TARGET = 'UNRESOLVED_TARGET';

    public const UNKNOWN_REGION = 'UNKNOWN_REGION';

    /** Walker propagation_reason ⇒ [broken_contract, minimum_repair_hint]. Reasons absent here are SOFT (excluded). */
    private const HARD_MAP = [
        'call_site_dangling' => ['call_site_dangling', 'remove_call'],
        'interface_contract_violated' => ['interface_contract_violated', 'replace_with_no_op'],
        'downstream_required_field_missing' => ['downstream_required_field_missing', 'inline_constant'],
        // the in-tree walker's REMOVAL vocabulary, mapped to the hard-break contract categories:
        'symbol_removed' => ['call_site_dangling', 'remove_call'],
        'signature_arity_changed' => ['interface_contract_violated', 'replace_with_no_op'],
        'return_type_changed' => ['downstream_required_field_missing', 'inline_constant'],
    ];

    /**
     * @param  object  $walker     anything exposing walk(string $target, array|string $edit): array
     * @param  Closure(string):bool|null  $isResolved  whether the target is a known symbol (default: non-empty)
     */
    public function __construct(
        private readonly object $walker,
        private readonly ?Closure $isResolved = null,
    ) {
    }

    /**
     * @param  array<string,mixed>|string  $removalEdit
     * @return list<array<string,mixed>>
     */
    public function probe(string $target, array|string $removalEdit = ['kind' => 'removal']): array
    {
        $resolver = $this->isResolved ?? static fn (string $t): bool => trim($t) !== '';
        if (! $resolver($target)) {
            return [['schema' => self::SCHEMA, 'fact' => self::UNRESOLVED_TARGET, 'target' => $target]];
        }

        $out = [];
        foreach ((array) $this->walker->walk($target, $removalEdit) as $fact) {
            if (! is_array($fact)) {
                continue;
            }
            $reason = (string) ($fact['propagation_reason'] ?? '');
            $callerFile = (string) ($fact['caller_file'] ?? '');

            // Blind spot — surface it so the operator sees the unindexed region (never silently dropped).
            if ($reason === 'unindexed_region' || $callerFile === self::UNKNOWN_REGION || $reason === self::UNKNOWN_REGION) {
                $out[] = ['schema' => self::SCHEMA, 'fact' => self::UNKNOWN_REGION, 'target' => $target, 'caller_file' => $callerFile];

                continue;
            }

            $mapped = self::HARD_MAP[$reason] ?? null;
            if ($mapped === null) {
                continue; // soft warning ⇒ excluded
            }

            $line = (int) ($fact['caller_line'] ?? 0);
            $out[] = [
                'schema' => self::SCHEMA,
                'target' => $target,
                'caller_file' => $callerFile,
                'caller_line' => $line,
                'caller' => $callerFile.':'.$line,
                'broken_contract' => $mapped[0],
                'minimum_repair_hint' => $mapped[1],
            ];
        }

        return $out;
    }
}
