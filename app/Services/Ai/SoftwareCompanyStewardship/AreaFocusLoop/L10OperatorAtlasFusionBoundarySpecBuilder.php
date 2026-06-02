<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S161 - L10 R4 operator<->Atlas fusion boundary spec builder.
 *
 * Design-only builder. It defines the reversible cognitive-extension
 * boundary for operator-Atlas fusion. The R4 safety gate is load-bearing:
 * fusion amplifies operator judgement but NEVER replaces it; the operator
 * stays the irreducible source of engineering ends, every extension is
 * auditable and reversible, and the operator can always detach and override.
 *
 * This class is pure: it computes a boundary specification from the inputs
 * and never couples to any runtime, never activates a session, never
 * performs I/O.
 */
final class L10OperatorAtlasFusionBoundarySpecBuilder
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.fusion_boundary_spec.v1';

    private const BASELINE_AUDIT_CHANNELS = [
        'operator_override',
        'reversible_detach',
        'sovereignty_of_ends',
    ];

    /**
     * @param array<string, mixed> $q1Evidence  operator-amplification (L9 Q1) precursor evidence
     * @param array<string, mixed> $sovereignty value/ends sovereignty evidence required before any fusion claim
     *
     * @return array{
     *     schema_version: string,
     *     fusion_boundary_id: string,
     *     reversible: bool,
     *     operator_agency_preserved: bool,
     *     audit_channels: list<string>,
     *     runtime_coupling: bool,
     *     blockers: list<string>
     * }
     */
    public function build(array $q1Evidence, array $sovereignty): array
    {
        $blockers = [];

        if (! $this->hasSovereignty($sovereignty)) {
            $blockers[] = 'sovereignty_evidence_missing';
        }

        if ($this->replacesOperatorAgency($q1Evidence, $sovereignty)) {
            $blockers[] = 'replacement_of_operator_agency_rejected';
        }

        if (! $this->hasQ1Precursor($q1Evidence)) {
            $blockers[] = 'q1_amplification_precursor_missing';
        }

        $boundaryIntact = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'fusion_boundary_id' => $this->boundaryId($sovereignty),
            'reversible' => $boundaryIntact,
            'operator_agency_preserved' => $boundaryIntact,
            'audit_channels' => $this->auditChannels($sovereignty),
            // Design-only spec: it never couples to a runtime or activates a session.
            'runtime_coupling' => false,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param array<string, mixed> $sovereignty
     */
    private function hasSovereignty(array $sovereignty): bool
    {
        return ($sovereignty['ends_owned_by_operator'] ?? false) === true
            || ($sovereignty['sovereignty_locked'] ?? false) === true;
    }

    /**
     * The R4 invariant: fusion amplifies, never replaces. A request whose
     * intent is to make the system the source of ends, or to remove the
     * operator's ability to detach/override, replaces operator agency and
     * is rejected.
     *
     * @param array<string, mixed> $q1Evidence
     * @param array<string, mixed> $sovereignty
     */
    private function replacesOperatorAgency(array $q1Evidence, array $sovereignty): bool
    {
        if (($q1Evidence['replaces_operator_agency'] ?? false) === true) {
            return true;
        }

        if (($q1Evidence['mode'] ?? 'amplify') === 'replace') {
            return true;
        }

        if (($q1Evidence['operator_detach_available'] ?? true) === false) {
            return true;
        }

        if (($q1Evidence['operator_override_available'] ?? true) === false) {
            return true;
        }

        return ($sovereignty['system_as_source_of_ends'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $q1Evidence
     */
    private function hasQ1Precursor(array $q1Evidence): bool
    {
        return ($q1Evidence['q1_certified'] ?? false) === true
            || ($q1Evidence['operator_amplification_proven'] ?? false) === true;
    }

    /**
     * Deterministic boundary id derived from the sovereignty fingerprint so
     * identical input yields an identical id without any randomness or clock.
     *
     * @param array<string, mixed> $sovereignty
     */
    private function boundaryId(array $sovereignty): string
    {
        $operator = $sovereignty['operator_id'] ?? 'operator';
        $fingerprint = is_scalar($operator) ? (string) $operator : 'operator';

        return 'fusion_boundary:' . $fingerprint;
    }

    /**
     * Audit channels always include the three load-bearing reversibility
     * surfaces, plus any extra operator-declared channels, de-duplicated and
     * re-keyed as a clean list<string> (no int-key coercion leaks through).
     *
     * @param array<string, mixed> $sovereignty
     *
     * @return list<string>
     */
    private function auditChannels(array $sovereignty): array
    {
        $channels = self::BASELINE_AUDIT_CHANNELS;

        $extra = $sovereignty['audit_channels'] ?? [];
        if (is_array($extra)) {
            foreach ($extra as $channel) {
                if (! is_string($channel)) {
                    continue;
                }

                $channel = trim($channel);
                if ($channel !== '' && ! in_array($channel, $channels, true)) {
                    $channels[] = $channel;
                }
            }
        }

        return array_values($channels);
    }
}
