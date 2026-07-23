<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Services\Ai\Support\AiValueNormalizer;

final class AtlasDebugRootCauseService
{
    public const SERVICE_VERSION = 'atlas.aaeos.debug.root_cause.v1';

    public const STATUS_ANALYZED = 'analyzed';

    public const STATUS_NO_DATA = 'no_data';

    public const STATUS_UNKNOWN = 'unknown';
    public const FIELD_CONTEXT = 'context';
    public const FIELD_ROOT_CAUSE = 'root_cause';
    public const FIELD_STATUS = 'status';
    public const FIELD_SUSPECTED_CAUSE = 'suspected_cause';
    public const FIELD_VERSION = 'version';


    public function getVersion(): string
    {
        return self::SERVICE_VERSION;
    }

    public function analyzeRootCause(array $context = []): array
    {
        return [
            self::FIELD_VERSION => self::SERVICE_VERSION,
            self::FIELD_STATUS => self::STATUS_ANALYZED,
            self::FIELD_CONTEXT => $context,
            self::FIELD_ROOT_CAUSE => $this->determineRootCause($context),
        ];
    }

    private function determineRootCause(array $context): string
    {
        if ($context === []) {
            return self::STATUS_NO_DATA;
        }

        return AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_SUSPECTED_CAUSE] ?? null) ?? self::STATUS_UNKNOWN;
    }
}
