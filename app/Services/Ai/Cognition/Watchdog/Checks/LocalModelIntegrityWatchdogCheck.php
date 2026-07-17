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
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $now->toIso8601String(),
            'total' => $report['total'],
            'verified' => $report['verified'],
            'mismatched' => $report['mismatched'],
            'missing' => $report['missing'],
            'unpinned' => $report['unpinned'],
            'artifacts' => $report['artifacts'],
        ];

        if ($report['mismatched'] > 0 || $report['missing'] > 0) {
            $offenders = array_values(array_filter(
                $report['artifacts'],
                static fn (array $row): bool => in_array(
                    AiValueNormalizer::trimmedScalarStringOrNull($row['status'] ?? null) ?? '',
                    [AtlasLocalModelIntegrityService::STATUS_MISMATCHED, AtlasLocalModelIntegrityService::STATUS_MISSING],
                    true,
                ),
            ));

            return AtlasWatchdogCheckResult::alert($evidence, [
                'code' => $report['mismatched'] > 0 ? 'local_model_hash_mismatch' : 'local_model_missing',
                'message' => 'Local model artifact integrity broken vs manifest pin.',
                'artifacts' => array_map(static fn (array $row): string => AiValueNormalizer::trimmedScalarStringOrNull($row['model_id'] ?? null) ?? '', $offenders),
            ]);
        }

        return AtlasWatchdogCheckResult::ok($evidence);
    }
}
