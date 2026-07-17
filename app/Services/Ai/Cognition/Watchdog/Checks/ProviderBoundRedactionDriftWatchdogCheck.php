<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasMemoryPrivacyService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\AiValueNormalizer;

final readonly class ProviderBoundRedactionDriftWatchdogCheck implements AtlasWatchdogCheck
{
    public const SCHEMA_VERSION = 'atlas.provider_bound_redaction_drift.v1';

    public const CHECK_ID = 'maxm06.provider_bound_redaction_drift';

    public const SAMPLE_LIMIT = 200;

    public function __construct(private AtlasMemoryPrivacyService $privacy) {}

    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function run(): AtlasWatchdogCheckResult
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return AtlasWatchdogCheckResult::skipped([
                'schema' => self::SCHEMA_VERSION,
                'reason' => 'atlas_memory_entries_missing',
            ]);
        }

        $drift = [];
        AtlasMemoryEntry::query()
            ->where('redaction_status', 'redacted')
            ->limit(self::SAMPLE_LIMIT)
            ->get()
            ->each(function (AtlasMemoryEntry $entry) use (&$drift): void {
                $signals = $this->driftSignals($entry);
                if ($signals !== []) {
                    $drift[] = [
                        'memory_ref' => $this->memoryRef($entry),
                        'signals' => $signals,
                        'verified_by' => data_get($entry->metadata, 'privacy.provider_body_verified') === true
                            ? 'privacy.provider_body_verified'
                            : (data_get($entry->metadata, 'provider_projection.provider_body_verified') === true
                                ? 'provider_projection.provider_body_verified'
                                : 'none'),
                    ];
                }
            });

        $evidence = [
            'schema' => self::SCHEMA_VERSION,
            'checked' => min(self::SAMPLE_LIMIT, AtlasMemoryEntry::query()->where('redaction_status', 'redacted')->count()),
            'drift_count' => count($drift),
            'drift' => $drift,
        ];

        if ($drift !== []) {
            return AtlasWatchdogCheckResult::alert($evidence, [
                'code' => 'provider_bound_redaction_drift',
                'message' => 'Provider-bound redaction status drift detected.',
            ]);
        }

        return AtlasWatchdogCheckResult::ok($evidence + ['reason' => 'no_provider_bound_redaction_drift']);
    }

    /**
     * @return list<string>
     */
    private function driftSignals(AtlasMemoryEntry $entry): array
    {
        $signals = [];
        $body = AiValueNormalizer::trimmedStringOrNull($entry->body) ?? '';
        $summary = AiValueNormalizer::trimmedStringOrNull($entry->summary ?? null) ?? '';
        $providerBody = $this->privacy->providerBody($entry);
        $providerSummary = AiValueNormalizer::trimmedStringOrNull($this->privacy->providerSummary($entry) ?? null) ?? '';

        if ($body !== '' && $providerBody !== '' && str_contains($providerBody, $body)) {
            $signals[] = 'provider_body_contains_raw_body';
        }
        if ($summary !== '' && $providerSummary !== '' && str_contains($providerSummary, $summary)) {
            $signals[] = 'provider_summary_contains_raw_summary';
        }

        return $signals;
    }

    private function memoryRef(AtlasMemoryEntry $entry): string
    {
        $hash = AiValueNormalizer::trimmedStringOrNull($entry->content_hash ?? null) ?? '';
        if ($hash === '') {
            $hash = hash('sha256', (string) $entry->id);
        }

        return 'memory:'.substr($hash, 0, 16);
    }
}
