<?php

namespace App\Services\Ai\Hermes\Mesh;

use App\Services\Ai\Hermes\HermesAdapterReceipt;

/**
 * Pure, side-effect-free environment-health preflight for a critical mesh dispatch.
 *
 * Hermes is an EXECUTOR/transport only — Atlas (ATLS) is sovereign. Before a
 * critical Executive Mesh dispatch, a caller runs the read-only commands
 * (`hermes --version`, `hermes doctor`, `hermes status`) and hands the ALREADY
 * captured stdout to this parser. This component NEVER shells out, NEVER calls a
 * provider/model, and NEVER decides policy: it only parses the captured output
 * into governed EVIDENCE and ADVISES whether the environment looks healthy.
 *
 * It is fail-closed: empty or garbage input yields `healthy=false`,
 * `version=null` and `critical_dispatch_advised=false`. The receipt carries
 * `authority => 'atlas'` and `hermes_preflight_can_decide => false`, and only a
 * short, redacted signal vocabulary plus the detected version token reaches the
 * receipt — raw stdout (which may contain paths/secrets) is never echoed. Every
 * path returns a sealed `atlas.hermes.preflight_evidence.v1` receipt with a
 * deterministic `receipt_hash` appended last, so the Evidence Ledger can prove
 * the preflight assessment without trusting Hermes to narrate it.
 */
class HermesDoctorPreflight
{
    use HermesAdapterReceipt;

    /**
     * Lowercased substrings that mark the doctor output as unhealthy.
     *
     * @var array<int,string>
     */
    private const UNHEALTHY_MARKERS = ['error', 'missing', 'fail'];

    /**
     * Assess captured read-only Hermes command stdout into sealed evidence.
     *
     * @return array<string,mixed>
     */
    public function assess(string $versionOutput, string $doctorOutput, string $statusOutput): array
    {
        $version = $this->parseVersion($versionOutput);
        $doctorClean = $this->doctorClean($doctorOutput);
        $statusPresent = trim($statusOutput) !== '';

        // Healthy ONLY when a version was detected AND the doctor output carries
        // no unhealthy marker. Status presence is an advisory signal, not a gate.
        $healthy = $version !== null && $doctorClean;

        $signals = $this->signals($version, $doctorClean, $statusPresent);

        $receipt = [
            'schema_version' => 'atlas.hermes.preflight_evidence.v1',
            'component' => 'hermes_doctor_preflight',
            'authority' => 'atlas',
            'hermes_preflight_can_decide' => false,
            'version' => $version,
            'version_detected' => $version !== null,
            'doctor_clean' => $doctorClean,
            'status_present' => $statusPresent,
            'healthy' => $healthy,
            'critical_dispatch_advised' => $healthy,
            'signals' => $signals,
            'status' => $healthy ? 'preflight_healthy' : 'preflight_unhealthy',
        ];

        return $this->withReceiptHash($receipt);
    }

    /**
     * Extract a `vX.Y.Z` semantic version token from `hermes --version` stdout.
     * Returns the normalised `vX.Y.Z` token only — never the surrounding line.
     */
    private function parseVersion(string $versionOutput): ?string
    {
        if (preg_match('/\bv?(\d+\.\d+\.\d+)\b/', $versionOutput, $matches) === 1) {
            return 'v'.$matches[1];
        }

        return null;
    }

    /**
     * True when `hermes doctor` stdout contains no unhealthy marker. An empty
     * output is treated as NOT clean (fail-closed): absence of evidence is not
     * evidence of health.
     */
    private function doctorClean(string $doctorOutput): bool
    {
        $haystack = strtolower(trim($doctorOutput));
        if ($haystack === '') {
            return false;
        }

        foreach (self::UNHEALTHY_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build the redacted short-token signal list. Only fixed, path/secret-free
     * vocabulary is emitted — never any slice of the raw command output.
     *
     * @return array<int,string>
     */
    private function signals(?string $version, bool $doctorClean, bool $statusPresent): array
    {
        $signals = [];

        if ($version !== null) {
            $signals[] = 'version_detected';
        }

        if ($doctorClean) {
            $signals[] = 'doctor_clean';
        }

        if ($statusPresent) {
            $signals[] = 'status_present';
        }

        return $signals;
    }
}
