<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Telemetry;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use Throwable;

final class AtlasLoopTelemetryFactExporter
{
    /** @var list<string> */
    private const FORBIDDEN_KEYS = ['score', 'rank', 'grade', 'quality', 'judgement'];

    /**
     * @param  array<string, mixed>  $fact
     */
    public function append(string $path, array $fact): bool
    {
        if ($path === '' || $this->containsForbiddenKeys($fact)) {
            return false;
        }

        try {
            (new JsonlReceiptStore($path))->append($fact);

            return true;
        } catch (Throwable) {
            return false; // fail-open by contract: telemetry export never breaks the caller
        }
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     */
    private function containsForbiddenKeys(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                return true;
            }

            if (is_array($value) && $this->containsForbiddenKeys($value)) {
                return true;
            }
        }

        return false;
    }
}
