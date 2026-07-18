<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Support\AiValueNormalizer;

use App\Services\Ai\AcosMax\AtlasLocalModelIntegrityService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use Carbon\CarbonImmutable;

/**
 * ELEV-19 — Watchdog: alerta quando um artefato local pinado mudou de hash
 * (`mismatched`) ou sumiu (`missing`). Artefato `unpinned` fica em advisory
 * dentro do evidence, mas não gera alerta (pin ausente ≠ pin quebrado).
 */
final readonly class LocalModelIntegrityWatchdogCheck implements AtlasWatchdogCheck
{
    public const SCHEMA_VERSION = 'atlas.acos.local_model_integrity_watchdog.v1';

    public const CHECK_ID = 'elev-19.local_model_integrity';
    public const FIELD_ARTIFACTS = 'artifacts';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_MODEL_ID = 'model_id';
    public const FIELD_TOTAL = 'total';
    public const FIELD_STATUS = 'status';
    public const FIELD_SCHEMA_VERSION = 'schema_version';

    public function __construct(
        private AtlasLocalModelIntegrityService $service,
        private ?CarbonImmutable $now = null,
    ) {}

    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $now = $this->now ?? CarbonImmutable::now('UTC');
        $report = $this->service->verifyAll();

        $evidence = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_GENERATED_AT => $now->toIso8601String(),
            self::FIELD_TOTAL => $report[self::FIELD_TOTAL],
            AtlasLocalModelIntegrityService::FIELD_VERIFIED => $report[AtlasLocalModelIntegrityService::FIELD_VERIFIED],
            AtlasLocalModelIntegrityService::FIELD_MISMATCHED => $report[AtlasLocalModelIntegrityService::FIELD_MISMATCHED],
            AtlasLocalModelIntegrityService::FIELD_MISSING => $report[AtlasLocalModelIntegrityService::FIELD_MISSING],
            AtlasLocalModelIntegrityService::FIELD_UNPINNED => $report[AtlasLocalModelIntegrityService::FIELD_UNPINNED],
            self::FIELD_ARTIFACTS => $report[self::FIELD_ARTIFACTS],
        ];

        if ($report[AtlasLocalModelIntegrityService::FIELD_MISMATCHED] > 0 || $report[AtlasLocalModelIntegrityService::FIELD_MISSING] > 0) {
            $offenders = array_values(array_filter(
                $report[self::FIELD_ARTIFACTS],
                static fn (array $row): bool => in_array(
                    AiValueNormalizer::trimmedScalarStringOrNull($row[self::FIELD_STATUS] ?? null) ?? '',
                    [AtlasLocalModelIntegrityService::STATUS_MISMATCHED, AtlasLocalModelIntegrityService::STATUS_MISSING],
                    true,
                ),
            ));

            return AtlasWatchdogCheckResult::alert($evidence, [
                'code' => $report[AtlasLocalModelIntegrityService::FIELD_MISMATCHED] > 0 ? 'local_model_hash_mismatch' : 'local_model_missing',
                'message' => 'Local model artifact integrity broken vs manifest pin.',
                self::FIELD_ARTIFACTS => array_map(static fn (array $row): string => AiValueNormalizer::trimmedScalarStringOrNull($row[self::FIELD_MODEL_ID] ?? null) ?? '', $offenders),
            ]);
        }

        return AtlasWatchdogCheckResult::ok($evidence);
    }
}
