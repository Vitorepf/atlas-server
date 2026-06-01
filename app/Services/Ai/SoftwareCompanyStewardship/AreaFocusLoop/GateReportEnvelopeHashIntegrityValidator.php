<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Fail-closed integrity check for a gate report envelope.
 *
 * Mirrors the stamp site (AreaFocusGateEvaluatorService::finalize), where the
 * envelope is sealed with
 *   report_hash = 'sha256:'.MissionCanonicalHash::sha256($payload)
 * computed over the payload BEFORE report_hash and the volatile generated_at
 * are added. Verification therefore recomputes that digest over the envelope
 * minus report_hash and generated_at, then proves equality with hash_equals.
 *
 * A report is trusted only when it carries a non-empty stamped hash AND the
 * recomputed digest equals it. Absence of the stamp is never trusted.
 */
final class GateReportEnvelopeHashIntegrityValidator
{
    private const SCHEMA_VERSION = 'atlas.aaeos.gate_report_hash_integrity.v1';

    private const HASH_PREFIX = 'sha256:';

    private const VERDICT_VERIFIED = 'verified';

    private const VERDICT_UNHASHED = 'unhashed';

    private const VERDICT_TAMPERED = 'tampered';

    /**
     * @param  array<string,mixed>  $report
     * @return array{
     *     schema_version: string,
     *     trusted: bool,
     *     verdict: string,
     *     stamped_hash: ?string,
     *     recomputed_hash: ?string,
     *     reason: ?string
     * }
     */
    public function validate(array $report): array
    {
        $stampedHash = $this->stampedHash($report);
        $recomputedHash = self::HASH_PREFIX.MissionCanonicalHash::sha256($this->identityEnvelope($report));

        if ($stampedHash === null) {
            return $this->result(
                trusted: false,
                verdict: self::VERDICT_UNHASHED,
                stampedHash: null,
                recomputedHash: $recomputedHash,
                reason: 'report_hash absent; integrity stamp required before trust',
            );
        }

        if (! hash_equals($recomputedHash, $stampedHash)) {
            return $this->result(
                trusted: false,
                verdict: self::VERDICT_TAMPERED,
                stampedHash: $stampedHash,
                recomputedHash: $recomputedHash,
                reason: 'recomputed envelope digest does not match stamped report_hash',
            );
        }

        return $this->result(
            trusted: true,
            verdict: self::VERDICT_VERIFIED,
            stampedHash: $stampedHash,
            recomputedHash: $recomputedHash,
            reason: null,
        );
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function stampedHash(array $report): ?string
    {
        $stamped = $report['report_hash'] ?? null;

        if (! is_string($stamped) || $stamped === '') {
            return null;
        }

        return $stamped;
    }

    /**
     * Envelope reduced to the fields that participate in the identity digest:
     * everything except the stamp itself and the volatile generated_at.
     *
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function identityEnvelope(array $report): array
    {
        unset($report['report_hash'], $report['generated_at']);

        return $report;
    }

    /**
     * @return array{
     *     schema_version: string,
     *     trusted: bool,
     *     verdict: string,
     *     stamped_hash: ?string,
     *     recomputed_hash: ?string,
     *     reason: ?string
     * }
     */
    private function result(
        bool $trusted,
        string $verdict,
        ?string $stampedHash,
        ?string $recomputedHash,
        ?string $reason,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'trusted' => $trusted,
            'verdict' => $verdict,
            'stamped_hash' => $stampedHash,
            'recomputed_hash' => $recomputedHash,
            'reason' => $reason,
        ];
    }
}
