<?php

namespace App\Services\Ai\Hermes;

use App\Models\HermesCapabilityCandidate;
use App\Models\HermesCapabilityManifest;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

/**
 * Sovereign registry for the Hermes capability manifest.
 *
 * The probe PRODUCES an `atlas.hermes.capability_manifest.v1` document; this
 * registry persists it, versions it, DIFFs it against the previously recorded
 * manifest and emits governed CapabilityCandidates for everything Hermes newly
 * exposes. ATLS stays the capability authority: nothing recorded here is ever
 * enabled or promoted automatically — every candidate lands quarantined and a
 * sealed `atlas.hermes.capability_registry_receipt.v1` proves what happened.
 */
class HermesCapabilityRegistry
{
    use HermesAdapterReceipt;

    private const ALWAYS_QUARANTINE = ['mcp_server', 'hook', 'delegation'];

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function record(array $manifest, array $context = []): array
    {
        $hermesVersion = $this->string($manifest['hermes_version'] ?? null, 190);
        $manifestHash = $this->string($manifest['manifest_hash'] ?? null, 64);
        $probeStatus = $this->probeStatus($manifest['probe_status'] ?? null);

        $receipt = [
            'schema_version' => 'atlas.hermes.capability_registry_receipt.v1',
            'adapter' => 'hermes_capability_registry',
            'capability_authority' => 'atlas',
            'enabled_now' => false,
            'policy' => $this->policy($context),
            'manifest_version' => 0,
            'manifest_hash' => $manifestHash,
            'previous_manifest_hash' => null,
            'hermes_version' => $hermesVersion,
            'added_count' => 0,
            'removed_count' => 0,
            'changed_count' => 0,
            'candidate_count' => 0,
            'persisted_count' => 0,
            'duplicate_count' => 0,
            'skipped_count' => 0,
            'persisted_candidate_ids' => [],
            'removed_capabilities' => [],
            'status' => 'capability_registry_unavailable',
        ];

        if (! DatabaseTableAvailability::all(['hermes_capability_manifests', 'hermes_capability_candidates'])) {
            return $this->withReceiptHash($receipt);
        }

        $previous = $this->latestManifestModel();
        $previousManifest = $previous instanceof HermesCapabilityManifest && is_array($previous->manifest_json)
            ? $previous->manifest_json
            : [];

        // Content-addressed identity: an already-recorded manifest_hash is the
        // exact same probed surface. No new manifest row, no version bump, no
        // candidates — just acknowledge it was already governed.
        if ($manifestHash !== null && $this->manifestHashRecorded($manifestHash)) {
            $diff = $this->diff($previousManifest, $manifest);

            $receipt['manifest_version'] = $previous instanceof HermesCapabilityManifest
                ? (int) $previous->manifest_version
                : $this->latestVersion();
            $receipt['previous_manifest_hash'] = $previous instanceof HermesCapabilityManifest
                ? $previous->manifest_hash
                : null;
            $receipt['added_count'] = count($diff['added']);
            $receipt['removed_count'] = count($diff['removed']);
            $receipt['changed_count'] = count($diff['changed']);
            $receipt['candidate_count'] = count($diff['added']) + count($diff['changed']);
            $receipt['removed_capabilities'] = $this->removedCapabilities($diff['removed']);
            $receipt['status'] = 'no_change';

            return $this->withReceiptHash($receipt);
        }

        $manifestVersion = (int) ($this->latestVersion() + 1);
        $manifest['manifest_version'] = $manifestVersion;

        $diff = $this->diff($previousManifest, $manifest);

        $receipt['manifest_version'] = $manifestVersion;
        $receipt['previous_manifest_hash'] = $previous instanceof HermesCapabilityManifest
            ? $previous->manifest_hash
            : null;
        $receipt['added_count'] = count($diff['added']);
        $receipt['removed_count'] = count($diff['removed']);
        $receipt['changed_count'] = count($diff['changed']);
        $receipt['candidate_count'] = count($diff['added']) + count($diff['changed']);
        $receipt['removed_capabilities'] = $this->removedCapabilities($diff['removed']);

        HermesCapabilityManifest::query()->create([
            'manifest_hash' => $manifestHash ?: $this->fallbackManifestHash($manifest),
            'hermes_version' => $hermesVersion,
            'manifest_version' => $manifestVersion,
            'probe_status' => $probeStatus,
            'manifest_json' => $manifest,
            'diff_json' => $diff,
            'receipt_hash' => null,
            'probed_at' => $this->probedAt($manifest['probed_at'] ?? null),
        ]);

        $persisted = $this->persistCandidates($diff, $manifest);

        $receipt['persisted_count'] = $persisted['persisted_count'];
        $receipt['duplicate_count'] = $persisted['duplicate_count'];
        $receipt['skipped_count'] = $persisted['skipped_count'];
        $receipt['persisted_candidate_ids'] = $persisted['persisted_candidate_ids'];
        $receipt['status'] = $this->status($diff, $persisted);

        return $this->withReceiptHash($receipt);
    }

    /**
     * @param  array<string,mixed>  $previous
     * @param  array<string,mixed>  $current
     * @return array{added:array<int,array<string,mixed>>,removed:array<int,array<string,mixed>>,changed:array<int,array<string,mixed>>}
     */
    public function diff(array $previous, array $current): array
    {
        $previousEntries = $this->entriesById($previous);
        $currentEntries = $this->entriesById($current);

        $added = [];
        $removed = [];
        $changed = [];

        foreach ($currentEntries as $id => $entry) {
            if (! array_key_exists($id, $previousEntries)) {
                $added[] = $entry;

                continue;
            }

            if ($this->entryChanged($previousEntries[$id], $entry)) {
                $changed[] = $entry;
            }
        }

        foreach ($previousEntries as $id => $entry) {
            if (! array_key_exists($id, $currentEntries)) {
                $removed[] = $entry;
            }
        }

        return [
            'added' => array_values($added),
            'removed' => array_values($removed),
            'changed' => array_values($changed),
        ];
    }

    /**
     * @param  array{added:array<int,array<string,mixed>>,removed:array<int,array<string,mixed>>,changed:array<int,array<string,mixed>>}  $diff
     * @param  array<string,mixed>  $manifest
     * @return array{persisted_count:int,duplicate_count:int,skipped_count:int,persisted_candidate_ids:array<int,string>,duplicate_candidate_ids:array<int,string>}
     */
    public function persistCandidates(array $diff, array $manifest): array
    {
        $result = [
            'persisted_count' => 0,
            'duplicate_count' => 0,
            'skipped_count' => 0,
            'persisted_candidate_ids' => [],
            'duplicate_candidate_ids' => [],
        ];

        if (! DatabaseTableAvailability::has('hermes_capability_candidates')) {
            return $result;
        }

        $entries = array_merge(
            array_values($diff['added'] ?? []),
            array_values($diff['changed'] ?? []),
        );

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $class = $this->string($entry['capability_class'] ?? null, 40);
            $key = $this->string($entry['capability_key'] ?? null, 190);
            if ($class === null || $key === null) {
                $result['skipped_count']++;

                continue;
            }

            $detail = is_array($entry['detail'] ?? null) ? $entry['detail'] : [];
            $capabilityHash = $this->capabilityHash($class, $key, $detail);

            $existing = $this->duplicateCandidate($capabilityHash);
            if ($existing instanceof HermesCapabilityCandidate) {
                $result['duplicate_count']++;
                $result['duplicate_candidate_ids'][] = $existing->id;

                continue;
            }

            $riskLevel = $this->riskClassFor($class);

            $row = HermesCapabilityCandidate::query()->create([
                'capability_hash' => $capabilityHash,
                'capability_class' => $class,
                'capability_key' => $key,
                'status' => 'persisted_for_review',
                'gate_status' => 'quarantined_for_atlas_capability_review',
                'risk_level' => $riskLevel,
                'enabled' => false,
                'review_required' => true,
                'payload_json' => $this->payload($class, $key, $detail, $riskLevel),
                'evidence_refs_json' => $this->evidence($manifest),
                'gate_json' => $this->gate($class, $riskLevel),
                'reviewed_at' => null,
                'expires_at' => now()->addDays(90),
            ]);

            $result['persisted_count']++;
            $result['persisted_candidate_ids'][] = $row->id;
        }

        return $result;
    }

    /**
     * Read model for the capability-aware builder: the most recent manifest_json.
     *
     * @return array<string,mixed>|null
     */
    public function latestManifest(): ?array
    {
        if (! DatabaseTableAvailability::has('hermes_capability_manifests')) {
            return null;
        }

        $manifest = $this->latestManifestModel();

        return $manifest instanceof HermesCapabilityManifest && is_array($manifest->manifest_json)
            ? $manifest->manifest_json
            : null;
    }

    private function latestManifestModel(): ?HermesCapabilityManifest
    {
        return HermesCapabilityManifest::query()
            ->orderByDesc('manifest_version')
            ->orderByDesc('created_at')
            ->first();
    }

    private function latestVersion(): int
    {
        $latest = HermesCapabilityManifest::query()->max('manifest_version');

        return is_numeric($latest) ? (int) $latest : 0;
    }

    private function manifestHashRecorded(string $manifestHash): bool
    {
        return HermesCapabilityManifest::query()
            ->where('manifest_hash', $manifestHash)
            ->exists();
    }

    /**
     * @param  array{added:array<int,array<string,mixed>>,removed:array<int,array<string,mixed>>,changed:array<int,array<string,mixed>>}  $diff
     * @param  array{persisted_count:int,duplicate_count:int,skipped_count:int,persisted_candidate_ids:array<int,string>,duplicate_candidate_ids:array<int,string>}  $persisted
     */
    private function status(array $diff, array $persisted): string
    {
        $changeCount = count($diff['added']) + count($diff['removed']) + count($diff['changed']);
        if ($changeCount === 0) {
            return 'no_change';
        }

        if ($persisted['persisted_count'] > 0) {
            return 'persisted_for_review';
        }

        if ($persisted['duplicate_count'] > 0) {
            return 'deduplicated';
        }

        return 'no_change';
    }

    /**
     * @param  array<int,array<string,mixed>>  $removed
     * @return array<int,array<string,string>>
     */
    private function removedCapabilities(array $removed): array
    {
        return collect($removed)
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->map(fn (array $entry): array => [
                'class' => $this->string($entry['capability_class'] ?? null, 40) ?? '',
                'key' => $this->string($entry['capability_key'] ?? null, 190) ?? '',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,array<string,mixed>>
     */
    private function entriesById(array $manifest): array
    {
        $entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];
        $byId = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = $this->string($entry['id'] ?? null, 255);
            if ($id === null) {
                $class = $this->string($entry['capability_class'] ?? null, 40);
                $key = $this->string($entry['capability_key'] ?? null, 190);
                if ($class === null || $key === null) {
                    continue;
                }
                $id = $class.':'.$key;
            }

            $byId[$id] = $entry;
        }

        return $byId;
    }

    /**
     * @param  array<string,mixed>  $previous
     * @param  array<string,mixed>  $current
     */
    private function entryChanged(array $previous, array $current): bool
    {
        return $this->canonicalDetail($previous['detail'] ?? null) !== $this->canonicalDetail($current['detail'] ?? null)
            || (bool) ($previous['supported'] ?? false) !== (bool) ($current['supported'] ?? false)
            || ($this->string($previous['hermes_token'] ?? null, 190)) !== ($this->string($current['hermes_token'] ?? null, 190));
    }

    private function canonicalDetail(mixed $detail): string
    {
        $detail = is_array($detail) ? $detail : [];
        $this->recursiveKsort($detail);

        return json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<mixed>  $value
     */
    private function recursiveKsort(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->recursiveKsort($item);
            }
        }
        unset($item);

        ksort($value);
    }

    /**
     * @param  array<string,mixed>  $detail
     * @return array<string,mixed>
     */
    private function payload(string $class, string $key, array $detail, string $riskLevel): array
    {
        return [
            'schema_version' => 'atlas.hermes.capability_candidate.v1',
            'capability_class' => $class,
            'capability_key' => $key,
            'detail' => $detail,
            'gate_status' => 'quarantined_for_atlas_capability_review',
            'enabled_now' => false,
            'enablement_requires_atlas_capability_gate' => true,
            'risk_level' => $riskLevel,
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<int,array<string,mixed>>
     */
    private function evidence(array $manifest): array
    {
        return [
            [
                'kind' => 'hermes_capability_manifest',
                'manifest_hash' => $this->string($manifest['manifest_hash'] ?? null, 64),
                'manifest_version' => (int) ($manifest['manifest_version'] ?? 0),
                'probed_at' => $this->string($manifest['probed_at'] ?? null, 64),
                'enabled_now' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function gate(string $class, string $riskLevel): array
    {
        return [
            'gate' => 'AtlasCapabilityGate',
            'capability_authority' => 'atlas',
            'enabled_now' => false,
            'enablement_requires_atlas_capability_gate' => true,
            'review_required' => true,
            'risk_level' => $riskLevel,
            'always_quarantine' => in_array($class, self::ALWAYS_QUARANTINE, true),
            'danger_requires_operator_authority' => $riskLevel === 'high',
        ];
    }

    private function duplicateCandidate(string $capabilityHash): ?HermesCapabilityCandidate
    {
        return HermesCapabilityCandidate::query()
            ->where('capability_hash', $capabilityHash)
            ->first();
    }

    /**
     * @param  array<string,mixed>  $detail
     */
    private function capabilityHash(string $class, string $key, array $detail): string
    {
        return hash('sha256', $class.'|'.$key.'|'.$this->canonicalDetail($detail));
    }

    private function riskClassFor(string $class): string
    {
        return match ($class) {
            'mcp_server', 'hook', 'delegation', 'provider' => 'high',
            'toolset', 'skill', 'bundle' => 'medium',
            default => 'low',
        };
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function policy(array $context): string
    {
        $policy = $this->string($context['policy'] ?? null, 60);

        return $policy ?? 'atlas_capability_registry';
    }

    private function probeStatus(mixed $value): string
    {
        $status = $this->string($value, 20) ?: 'degraded';

        return in_array($status, ['ok', 'degraded', 'binary_offline'], true) ? $status : 'degraded';
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function fallbackManifestHash(array $manifest): string
    {
        return hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function probedAt(mixed $value): ?string
    {
        return $this->string($value, 64);
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
