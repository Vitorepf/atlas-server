<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\AtomicWriter;
use InvalidArgumentException;

/**
 * Build campaign manifests for a mode that span stack/risk/duration over rotating,
 * private unit sets. Units must be disjoint across campaigns (no unit proves capability
 * twice). The emitted shape is exactly what WorldTrialReadiness consumes; this class
 * only assembles manifests — it never issues a claim. Pure over arrays.
 */
final class CampaignManifest
{
    public const SCHEMA = 'atlas.rivals2.campaign_manifest.v1';

    /**
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    public function campaign(array $spec): array
    {
        $unitIds = array_values(array_unique(array_map('strval', (array) ($spec['unit_ids'] ?? []))));
        $critical = [];
        foreach ((array) ($spec['critical_dimensions'] ?? []) as $dimension) {
            $critical[(string) $dimension] = true;
        }

        return [
            'id' => implode('|', [
                (string) ($spec['mode'] ?? 'mode'),
                (string) ($spec['stack'] ?? 'stack'),
                (string) ($spec['risk'] ?? 'R0'),
                (string) ($spec['duration'] ?? 'duration'),
            ]),
            'mode' => (string) ($spec['mode'] ?? ''),
            'stack' => (string) ($spec['stack'] ?? ''),
            'risk' => (string) ($spec['risk'] ?? ''),
            'duration' => (string) ($spec['duration'] ?? ''),
            'unit_ids' => $unitIds,
            'distinct_units' => count($unitIds),
            'power' => (float) ($spec['power'] ?? 0.0),
            'outcome_days' => (int) ($spec['outcome_days'] ?? 0),
            'outcome_windows' => is_array($spec['outcome_windows'] ?? null) ? $spec['outcome_windows'] : [],
            // Missing provenance is not a real campaign. Unknown defaults to
            // synthetic so a partial manifest can never become claim-eligible.
            'synthetic' => (bool) ($spec['synthetic'] ?? true),
            'execution_source' => (string) ($spec['execution_source'] ?? 'hermetic_fixture'),
            'real_execution' => (bool) ($spec['real_execution'] ?? false),
            'preregistration_hash' => $spec['preregistration_hash'] ?? null,
            'native_receipt_hash' => $spec['native_receipt_hash'] ?? null,
            'evidence_pack_hash' => $spec['evidence_pack_hash'] ?? null,
            'outcome_receipt_hash' => $spec['outcome_receipt_hash'] ?? null,
            'contamination_free' => (bool) ($spec['contamination_free'] ?? false),
            'itt_complete' => (bool) ($spec['itt_complete'] ?? false),
            'critical_dimensions' => $critical,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $specs
     * @param  array<string,mixed>  $overrides  required_exposure, required_outcome_days, required_critical_dimensions
     * @return array<string,mixed> a WorldTrialReadiness-ready manifest
     */
    public function assemble(string $mode, array $specs, array $overrides = []): array
    {
        $campaigns = [];
        $seenUnits = [];
        foreach ($specs as $spec) {
            $campaign = $this->campaign(['mode' => $mode] + $spec);
            foreach ($campaign['unit_ids'] as $unit) {
                if (isset($seenUnits[$unit])) {
                    throw new InvalidArgumentException('campaign_unit_overlap:'.$unit);
                }
                $seenUnits[$unit] = true;
            }
            $campaigns[] = $campaign;
        }

        return [
            'schema_version' => self::SCHEMA,
            'mode' => $mode,
            'campaigns' => $campaigns,
            'required_exposure' => (int) ($overrides['required_exposure'] ?? 1),
            'required_outcome_days' => (int) ($overrides['required_outcome_days'] ?? 30),
            'required_critical_dimensions' => array_values(array_map(
                'strval',
                (array) ($overrides['required_critical_dimensions'] ?? []),
            )),
        ];
    }

    /**
     * Read a canonical campaign artifact without normalizing or filling facts.
     * A malformed, foreign or schema-less file is not a trial manifest.
     *
     * @return array<string,mixed>
     */
    public function readFile(string $path): array
    {
        $safePath = RunPaths::assertImportSource($path);
        try {
            $manifest = json_decode((string) file_get_contents($safePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('rivals_campaign_manifest_json_invalid', 0, $e);
        }
        if (! is_array($manifest) || ($manifest['schema_version'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('rivals_campaign_manifest_schema_invalid');
        }
        $artifactHash = (string) ($manifest['artifact_hash'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $artifactHash) !== 1) {
            throw new InvalidArgumentException('rivals_campaign_manifest_artifact_hash_missing');
        }
        unset($manifest['artifact_hash']);
        if (! hash_equals($artifactHash, $this->hash($manifest))) {
            throw new InvalidArgumentException('rivals_campaign_manifest_artifact_hash_mismatch');
        }
        $manifest['artifact_hash'] = $artifactHash;

        return $manifest;
    }

    /**
     * Persist an immutable campaign artifact. This stores intent/provenance
     * only; it never upgrades synthetic campaigns or invents outcomes.
     *
     * @param array<string,mixed> $manifest
     */
    public function persist(string $campaignId, array $manifest): string
    {
        if (($manifest['schema_version'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('rivals_campaign_manifest_schema_invalid');
        }
        $payload = $manifest;
        unset($payload['artifact_hash']);
        $payload['campaign_id'] = $campaignId;
        $payload['artifact_hash'] = $this->hash($payload);
        $path = RunPaths::campaignManifestPath($campaignId);
        if (is_file($path)) {
            $existing = $this->readFile($path);
            if (($existing['artifact_hash'] ?? null) !== $payload['artifact_hash']) {
                throw new InvalidArgumentException('rivals_campaign_manifest_immutable');
            }

            return $path;
        }
        AtomicWriter::write($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $path;
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize($payload), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
