<?php

namespace App\Services\Tools;

use App\Models\AtlasToolFinding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AtlasToolFindingWaiverService
{
    /**
     * @param  array<string,mixed>  $attributes
     */
    public function waive(AtlasToolFinding $finding, array $attributes): AtlasToolFinding
    {
        $reason = trim((string) ($attributes['reason'] ?? 'operator_waived_tool_finding'));
        $ttlHours = $this->ttlHours($attributes['ttl_hours'] ?? null);
        $waivedAt = now();
        $waivedUntil = $ttlHours !== null ? $waivedAt->copy()->addHours($ttlHours) : null;
        $metadata = (array) ($finding->metadata_json ?? []);
        $history = (array) data_get($metadata, 'waiver_history', []);
        $currentWaiver = (array) data_get($metadata, 'waiver', []);

        if ($currentWaiver !== []) {
            $history[] = $currentWaiver;
        }

        $waiver = [
            'id' => (string) Str::uuid(),
            'reason' => mb_substr($reason !== '' ? $reason : 'operator_waived_tool_finding', 0, 500),
            'waived_by' => mb_substr((string) ($attributes['waived_by'] ?? 'atlas_operator'), 0, 120),
            'waived_at' => $waivedAt->toISOString(),
            'waived_until' => $waivedUntil?->toISOString(),
            'source' => mb_substr((string) ($attributes['source'] ?? 'atlas_tool_runtime'), 0, 120),
        ];

        $finding->forceFill([
            'status' => 'waived',
            'waiver_id' => $waiver['id'],
            'metadata_json' => [
                ...$metadata,
                'waiver' => $waiver,
                'waiver_history' => $history,
            ],
        ])->save();

        return $finding->refresh();
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function revoke(AtlasToolFinding $finding, array $attributes = []): AtlasToolFinding
    {
        $metadata = (array) ($finding->metadata_json ?? []);
        $history = (array) data_get($metadata, 'waiver_history', []);
        $waiver = (array) data_get($metadata, 'waiver', []);

        if ($waiver !== []) {
            $history[] = [
                ...$waiver,
                'revoked_at' => now()->toISOString(),
                'revoked_by' => mb_substr((string) ($attributes['revoked_by'] ?? 'atlas_operator'), 0, 120),
                'revocation_reason' => mb_substr((string) ($attributes['reason'] ?? 'operator_revoked_tool_finding_waiver'), 0, 500),
            ];
        }

        unset($metadata['waiver']);

        $finding->forceFill([
            'status' => 'open',
            'waiver_id' => null,
            'metadata_json' => [
                ...$metadata,
                'waiver_history' => $history,
            ],
        ])->save();

        return $finding->refresh();
    }

    public function isWaived(AtlasToolFinding $finding): bool
    {
        if ($finding->status !== 'waived' || ! is_string($finding->waiver_id) || $finding->waiver_id === '') {
            return false;
        }

        $waivedUntil = data_get($finding->metadata_json, 'waiver.waived_until');
        if (! is_string($waivedUntil) || trim($waivedUntil) === '') {
            return true;
        }

        try {
            return Carbon::parse($waivedUntil)->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    private function ttlHours(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return max(1, min(8760, (int) $value));
    }
}
