<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Provider-safe normalized fact adapter: turns raw static-analyzer findings into deterministic
 * compression facts WITHOUT calling any external tool — the caller supplies findings already
 * collected elsewhere, and this class only classifies/normalizes them.
 *
 * Recognized finding kinds:
 *   unused_symbol     — supports deletion, never blocks.
 *   unreachable_path   — supports deletion, never blocks.
 *   dependency_cycle   — BLOCKS deletion eligibility until the cycle is resolved; a symbol tangled
 *                        in a cycle cannot be safely removed in isolation.
 *   type_error         — BLOCKS deletion eligibility until resolved; a type error means static
 *                        analysis itself cannot be trusted for this symbol.
 *   anything else      — fail-closed: unrecognized finding kinds also BLOCK (never silently
 *                        treated as safe-to-delete).
 *
 * OUTPUT: { schema, normalized_findings: list<{finding_id, symbol, kind, deletion_eligible,
 *   blocking, reason}>, compression_eligible: bool, blocking_findings: list<string> }
 * compression_eligible is true ONLY when zero findings are blocking.
 *
 * Pure: no I/O, no provider calls, no external tool invocation.
 */
final class AtlasExternalBrainStaticAnalysisBridge
{
    public const SCHEMA = 'atlas.external_brain.static_analysis_bridge.v1';

    public const KIND_UNUSED_SYMBOL = 'unused_symbol';

    public const KIND_UNREACHABLE_PATH = 'unreachable_path';

    public const KIND_DEPENDENCY_CYCLE = 'dependency_cycle';

    public const KIND_TYPE_ERROR = 'type_error';

    private const NON_BLOCKING_KINDS = [self::KIND_UNUSED_SYMBOL, self::KIND_UNREACHABLE_PATH];

    private const BLOCKING_KINDS = [self::KIND_DEPENDENCY_CYCLE, self::KIND_TYPE_ERROR];

    /**
     * @param  array{findings?: list<array<string,mixed>>}  $input
     * @return array{schema:string, normalized_findings:list<array<string,mixed>>, compression_eligible:bool, blocking_findings:list<string>}
     */
    public function normalize(array $input): array
    {
        $findings = is_array($input['findings'] ?? null) ? $input['findings'] : [];

        $normalized = [];
        $blockingFindings = [];

        foreach ($findings as $finding) {
            if (! is_array($finding) || ! isset($finding['finding_id'])) {
                continue;
            }

            $findingId = (string) $finding['finding_id'];
            $symbol = trim((string) ($finding['symbol'] ?? ''));
            $kind = strtolower(trim((string) ($finding['kind'] ?? '')));

            if (in_array($kind, self::NON_BLOCKING_KINDS, true)) {
                $deletionEligible = true;
                $blocking = false;
                $reason = 'kind_'.$kind.'_supports_deletion';
            } elseif (in_array($kind, self::BLOCKING_KINDS, true)) {
                $deletionEligible = false;
                $blocking = true;
                $reason = 'kind_'.$kind.'_blocks_deletion_until_resolved';
            } else {
                $deletionEligible = false;
                $blocking = true;
                $reason = 'unrecognized_finding_kind: '.($kind === '' ? '(empty)' : $kind);
            }

            $normalized[] = [
                'finding_id' => $findingId,
                'symbol' => $symbol,
                'kind' => $kind,
                'deletion_eligible' => $deletionEligible,
                'blocking' => $blocking,
                'reason' => $reason,
            ];

            if ($blocking) {
                $blockingFindings[] = $findingId;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'normalized_findings' => $normalized,
            'compression_eligible' => $blockingFindings === [],
            'blocking_findings' => $blockingFindings,
        ];
    }
}
