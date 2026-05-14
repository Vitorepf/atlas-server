<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

/**
 * Atlas Rivals Invalid Battery Triage Registry v1.
 *
 * Per-fingerprint registry that tracks which historical invalid Rivals
 * batteries have been triaged. Triage is now scoped to the canonical
 * fingerprint `(suite, preset, atlas_model, baseline_model, atlas_workspace_hash,
 * baseline_workspace_hash, case_ids, gate_profile, test_command)` so a
 * triaged opus+quick run does NOT keep blocking a fresh sonnet+quick run.
 *
 * Storage is JSON on local disk under
 * `storage/app/rivals-forge-triage/<fingerprint_prefix>.json` — read-write
 * with file locks. The registry never dispatches providers and never mutates
 * git state.
 *
 * Schema: atlas.programming.rivals_invalid_battery_triage_registry.v1
 */
class AtlasRivalsInvalidBatteryTriageRegistry
{
    public const SCHEMA_VERSION = 'atlas.programming.rivals_invalid_battery_triage_registry.v1';

    public const STATUS_PENDING_TRIAGE = 'pending_triage';

    public const STATUS_TRIAGED_QUARANTINED = 'triaged_quarantined';

    public function rootDirectory(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'rivals-forge-triage');
    }

    /**
     * Record an invalid-battery occurrence for the given fingerprint. If a
     * pending-triage record already exists for that fingerprint, this
     * updates the latest_seen_at timestamp instead of creating a duplicate.
     *
     * @param  array<string,mixed>  $context
     */
    public function recordInvalidBattery(string $fingerprint, array $context = []): void
    {
        $this->ensureRootDir();
        $existing = $this->loadEntry($fingerprint);
        $now = now()->toJSON();

        if ($existing !== null) {
            $existing['latest_seen_at'] = $now;
            $existing['occurrence_count'] = (int) ($existing['occurrence_count'] ?? 1) + 1;
            $this->writeEntry($fingerprint, $existing);

            return;
        }

        $this->writeEntry($fingerprint, [
            'schema_version' => self::SCHEMA_VERSION,
            'fingerprint' => $fingerprint,
            'status' => self::STATUS_PENDING_TRIAGE,
            'first_seen_at' => $now,
            'latest_seen_at' => $now,
            'triaged_at' => null,
            'triaged_by' => null,
            'reason' => null,
            'context' => $context,
            'occurrence_count' => 1,
        ]);
    }

    /**
     * Mark a fingerprint as triaged_quarantined so the next runbook/run does
     * NOT block on the historical occurrence.
     */
    public function triage(string $fingerprint, string $reason, ?string $operator = null): array
    {
        $this->ensureRootDir();
        $existing = $this->loadEntry($fingerprint);
        $now = now()->toJSON();

        if ($existing === null) {
            // Allow triage of a fingerprint not yet recorded — covers the case
            // where the operator wants to pre-clear a known invalid run.
            $existing = [
                'schema_version' => self::SCHEMA_VERSION,
                'fingerprint' => $fingerprint,
                'first_seen_at' => $now,
                'latest_seen_at' => $now,
                'occurrence_count' => 0,
            ];
        }

        $existing['status'] = self::STATUS_TRIAGED_QUARANTINED;
        $existing['triaged_at'] = $now;
        $existing['triaged_by'] = $operator;
        $existing['reason'] = $reason;

        $this->writeEntry($fingerprint, $existing);

        return $existing;
    }

    /**
     * True iff the fingerprint has a known invalid battery that has NOT yet
     * been triaged. The runner uses this to refuse provider dispatch.
     */
    public function requiresTriage(string $fingerprint): bool
    {
        $entry = $this->loadEntry($fingerprint);
        if ($entry === null) {
            return false;
        }

        return ($entry['status'] ?? null) === self::STATUS_PENDING_TRIAGE;
    }

    public function resolutionCommand(string $fingerprint): string
    {
        return sprintf(
            'php artisan atlas:engineering:benchmark:rivals triage-invalid-battery --fingerprint=%s --confirm-invalid-battery-quarantine --reason="<why this is quarantined>"',
            $fingerprint,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function loadEntry(string $fingerprint): ?array
    {
        $path = $this->entryPath($fingerprint);
        if (! is_file($path)) {
            return null;
        }
        $contents = @file_get_contents($path);
        if (! is_string($contents) || trim($contents) === '') {
            return null;
        }
        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function writeEntry(string $fingerprint, array $entry): void
    {
        $path = $this->entryPath($fingerprint);
        $blob = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $tmp = $path.'.tmp';
        @file_put_contents($tmp, $blob);
        @rename($tmp, $path);
    }

    private function entryPath(string $fingerprint): string
    {
        if (preg_match('/^[a-f0-9]{32,}$/', $fingerprint) !== 1) {
            throw new \RuntimeException('rivals_triage_registry:invalid_fingerprint:'.$fingerprint);
        }
        $prefix = substr($fingerprint, 0, 16);

        return $this->rootDirectory().DIRECTORY_SEPARATOR.$prefix.'.json';
    }

    private function ensureRootDir(): void
    {
        $dir = $this->rootDirectory();
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    /**
     * Returns all registry entries (used for diagnostics and for the
     * `atlas rivals triage-invalid-battery` listing payload).
     *
     * @return list<array<string,mixed>>
     */
    public function listEntries(): array
    {
        $dir = $this->rootDirectory();
        if (! is_dir($dir)) {
            return [];
        }
        $files = glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [];
        $entries = [];
        foreach ($files as $file) {
            $contents = @file_get_contents($file);
            if (! is_string($contents) || trim($contents) === '') {
                continue;
            }
            $decoded = json_decode($contents, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }
}
