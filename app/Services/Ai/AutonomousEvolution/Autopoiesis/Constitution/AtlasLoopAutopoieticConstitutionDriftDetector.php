<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution;

use Throwable;

/**
 * AUTOPOIETIC CONSTITUTION DRIFT DETECTOR — answers ONE fact-shaped question: did anything edit the
 * constitutional registry source between two anchored points in time WITHOUT a corresponding operator receipt?
 *
 * It computes the current registry fingerprint (delegated to {@see AtlasLoopAutopoieticConstitutionRegistry::fingerprint()},
 * the p1 primitive), compares it to the last_known_fingerprint persisted at
 * `storage/app/atlas/autopoiesis/constitution_fingerprint.json`, and emits a {@see DriftReport} with both
 * fingerprints + the last operator receipt id + a diff_summary.
 *
 * PÉTREO INVARIANTS:
 *   - READ-ONLY: detect() NEVER writes the new fingerprint, NEVER auto-heals, NEVER calls a provider.
 *   - FACT-ONLY: drifted is binary (`current != last_known`); no score, no percentage.
 *   - Missing fingerprint file ⇒ drifted=false, last_known_fingerprint=null (no panic over a fresh install —
 *     operator bootstraps the anchor by writing the file once).
 */
final class AtlasLoopAutopoieticConstitutionDriftDetector
{
    public function __construct(
        private readonly AtlasLoopAutopoieticConstitutionRegistry $registry,
        private readonly ?string $fingerprintFile = null,
    ) {
    }

    public function detect(): DriftReport
    {
        $current = $this->registry->fingerprint();
        $anchor = $this->readAnchor();

        $lastKnown = null;
        if ($anchor !== null) {
            $fp = (string) ($anchor['fingerprint'] ?? '');
            $lastKnown = $fp === '' ? null : $fp;
        }
        $lastReceiptId = $anchor === null ? null : (isset($anchor['last_operator_receipt_id']) ? (string) $anchor['last_operator_receipt_id'] : null);
        $lastVerifiedAt = $anchor === null ? null : (isset($anchor['last_verified_at']) ? (string) $anchor['last_verified_at'] : null);

        if ($lastKnown === null || $lastKnown === $current) {
            return new DriftReport(false, $current, $lastKnown, $lastReceiptId, $lastVerifiedAt, []);
        }

        return new DriftReport(true, $current, $lastKnown, $lastReceiptId, $lastVerifiedAt, [
            'kind' => 'fingerprint_divergence',
            'current_fingerprint' => $current,
            'last_known_fingerprint' => $lastKnown,
        ]);
    }

    private function fingerprintPath(): string
    {
        if ($this->fingerprintFile !== null && $this->fingerprintFile !== '') {
            return $this->fingerprintFile;
        }
        $base = function_exists('storage_path')
            ? storage_path('app/atlas/autopoiesis')
            : sys_get_temp_dir().'/atlas/autopoiesis';

        return rtrim($base, '/').'/constitution_fingerprint.json';
    }

    /**
     * Read the on-disk anchor, or null when the file is absent / unreadable / non-JSON. NEVER writes; even
     * malformed content produces null (read-only invariant).
     *
     * @return array<string,mixed>|null
     */
    private function readAnchor(): ?array
    {
        $path = $this->fingerprintPath();
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        try {
            $raw = (string) file_get_contents($path);
        } catch (Throwable) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
