<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\AiValueNormalizer;

final readonly class ProviderBoundRedactionDriftWatchdogCheck implements AtlasWatchdogCheck
{
    public const SCHEMA_VERSION = 'atlas.provider_bound_redaction_drift.v1';

    public const CHECK_ID = 'maxm06.provider_bound_redaction_drift';

    public const SAMPLE_LIMIT = 200;

    public const REASON_ATLAS_MEMORY_ENTRIES_MISSING = 'atlas_memory_entries_missing';

    public const REASON_NO_PROVIDER_BOUND_REDACTION_DRIFT = 'no_provider_bound_redaction_drift';
    public const FIELD_SCHEMA = 'schema';
    public const FIELD_REASON = 'reason';
    public const FIELD_MEMORY_REF = 'memory_ref';
    public const FIELD_SIGNALS = 'signals';
    public const FIELD_VERIFIED_BY = 'verified_by';
    public const FIELD_CHECKED = 'checked';
    public const FIELD_DRIFT_COUNT = 'drift_count';
    public const FIELD_DRIFT = 'drift';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_CODE = 'code';
    public const FIELD_REDACTION_STATUS = 'redaction_status';
    public const FIELD_ATLAS_MEMORY_ENTRIES = 'atlas_memory_entries';
    public const FIELD_REDACTED = 'redacted';
    public const FIELD_PROVIDER_BOUND_REDACTION_DRIFT = 'provider_bound_redaction_drift';
    public const FIELD_SHA256 = 'sha256';
    public const FIELD_NONE = 'none';
    public const FIELD_PROVIDER_BODY_CONTAINS_RAW_BODY = 'provider_body_contains_raw_body';
    public const FIELD_PROVIDER_SUMMARY_CONTAINS_RAW_SUMMARY = 'provider_summary_contains_raw_summary';
    public const FIELD_PRIVACY_PROVIDER_BODY_VERIFIED = 'privacy.provider_body_verified';
    public const FIELD_PROVIDER_PROJECTION_PROVIDER_BODY_VERIFIED = 'provider_projection.provider_body_verified';
    public const FIELD_PROVIDER_BOUND_REDACTION_STATUS_DRIFT_DETECTED_ = 'Provider-bound redaction status drift detected.';


    public function __construct(private AtlasMemoryPrivacyService $privacy) {}

    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function run(): AtlasWatchdogCheckResult
    {
        if (! DatabaseTableAvailability::has(self::FIELD_ATLAS_MEMORY_ENTRIES)) {
            return AtlasWatchdogCheckResult::skipped([
                self::FIELD_SCHEMA => self::SCHEMA_VERSION,
                self::FIELD_REASON => self::REASON_ATLAS_MEMORY_ENTRIES_MISSING,
            ]);
        }

        $drift = [];
        AtlasMemoryEntry::query()
            ->where(self::FIELD_REDACTION_STATUS, self::FIELD_REDACTED)
            ->limit(self::SAMPLE_LIMIT)
            ->get()
            ->each(function (AtlasMemoryEntry $entry) use (&$drift): void {
                $signals = $this->driftSignals($entry);
                if ($signals !== []) {
                    $drift[] = [
                        self::FIELD_MEMORY_REF => $this->memoryRef($entry),
                        self::FIELD_SIGNALS => $signals,
                        self::FIELD_VERIFIED_BY => data_get($entry->metadata, self::FIELD_PRIVACY_PROVIDER_BODY_VERIFIED) === true
                            ? self::FIELD_PRIVACY_PROVIDER_BODY_VERIFIED
                            : (data_get($entry->metadata, self::FIELD_PROVIDER_PROJECTION_PROVIDER_BODY_VERIFIED) === true
                                ? self::FIELD_PROVIDER_PROJECTION_PROVIDER_BODY_VERIFIED
                                : self::FIELD_NONE),
                    ];
                }
            });

        $evidence = [
            self::FIELD_SCHEMA => self::SCHEMA_VERSION,
            self::FIELD_CHECKED => min(self::SAMPLE_LIMIT, AtlasMemoryEntry::query()->where(self::FIELD_REDACTION_STATUS, self::FIELD_REDACTED)->count()),
            self::FIELD_DRIFT_COUNT => count($drift),
            self::FIELD_DRIFT => $drift,
        ];

        if ($drift !== []) {
            return AtlasWatchdogCheckResult::alert($evidence, [
                self::FIELD_CODE => self::FIELD_PROVIDER_BOUND_REDACTION_DRIFT,
                self::FIELD_MESSAGE => self::FIELD_PROVIDER_BOUND_REDACTION_STATUS_DRIFT_DETECTED_,
            ]);
        }

        return AtlasWatchdogCheckResult::ok($evidence + [self::FIELD_REASON => self::REASON_NO_PROVIDER_BOUND_REDACTION_DRIFT]);
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
            $signals[] = self::FIELD_PROVIDER_BODY_CONTAINS_RAW_BODY;
        }
        if ($summary !== '' && $providerSummary !== '' && str_contains($providerSummary, $summary)) {
            $signals[] = self::FIELD_PROVIDER_SUMMARY_CONTAINS_RAW_SUMMARY;
        }

        return $signals;
    }

    private function memoryRef(AtlasMemoryEntry $entry): string
    {
        $hash = AiValueNormalizer::trimmedStringOrNull($entry->content_hash ?? null) ?? '';
        if ($hash === '') {
            $hash = hash(self::FIELD_SHA256, AiValueNormalizer::trimmedScalarStringOrNull($entry->id ?? null) ?? '');
        }

        return 'memory:'.substr($hash, 0, 16);
    }
}
