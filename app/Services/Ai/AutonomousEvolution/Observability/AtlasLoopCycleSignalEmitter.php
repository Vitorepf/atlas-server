<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Observability;

use Illuminate\Support\Facades\Log;
use Throwable;

final class AtlasLoopCycleSignalEmitter
{
    public const SCHEMA_VERSION = 'atlas.loop.cycle_signal.v1';

    public const STAGE_DECISION = 'decision';

    public const STAGE_LEVERAGE = 'leverage';

    public const STAGE_PROJECTION = 'projection';

    public const STAGE_CERTIFICATION = 'certification';

    public const STAGE_MERGE = 'merge';

    public const STAGE_LEARNING = 'learning';

    /**
     * @param  array<string,mixed>  $payload
     */
    public function emit(string $stage, string $campaignId, string $cycleId, array $payload): void
    {
        try {
            $signal = [
                'schema_version' => self::SCHEMA_VERSION,
                'emitted_at' => gmdate('c'),
                'stage' => $stage,
                'campaign_id' => $campaignId,
                'cycle_id' => $cycleId,
                'payload' => $this->filterPayloadProviderSafe($payload),
            ];

            $encoded = json_encode($signal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (! is_string($encoded)) {
                throw new \RuntimeException('signal_encode_failed');
            }

            $path = $this->sinkPath();
            $directory = dirname($path);
            if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
                throw new \RuntimeException('signal_sink_directory_unavailable');
            }

            $handle = @fopen($path, 'ab');
            if (! is_resource($handle)) {
                throw new \RuntimeException('signal_sink_open_failed');
            }

            try {
                if (! @flock($handle, LOCK_EX)) {
                    throw new \RuntimeException('signal_sink_lock_failed');
                }
                if (@fwrite($handle, $encoded."\n") === false) {
                    throw new \RuntimeException('signal_sink_write_failed');
                }
                @fflush($handle);
            } finally {
                @flock($handle, LOCK_UN);
                @fclose($handle);
            }
        } catch (Throwable $e) {
            try {
                Log::channel('atlas-loop')->warning('Atlas loop cycle signal emit failed.', [
                    'stage' => $stage,
                    'campaign_id' => $campaignId,
                    'cycle_id' => $cycleId,
                    'reason' => $e->getMessage(),
                ]);
            } catch (Throwable) {
                // fail-open by contract: telemetry may never break the loop
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function filterPayloadProviderSafe(array $payload): array
    {
        return $this->canonicalizeAssoc($this->filterAssoc($payload));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function filterAssoc(array $payload): array
    {
        $filtered = [];
        $dropped = [];

        foreach ($payload as $key => $value) {
            $key = (string) $key;
            if (is_scalar($value) || $value === null) {
                $filtered[$key] = $value;

                continue;
            }
            if (is_array($value)) {
                $filtered[$key] = $this->filterArray($value);

                continue;
            }

            $dropped[] = $key;
        }

        if ($dropped !== []) {
            sort($dropped);
            $filtered['_dropped_non_scalar_keys'] = $dropped;
        }

        return $filtered;
    }

    /**
     * @param  array<int|string,mixed>  $payload
     * @return array<int|string,mixed>
     */
    private function filterArray(array $payload): array
    {
        if (array_is_list($payload)) {
            $filtered = [];
            foreach ($payload as $value) {
                if (is_scalar($value) || $value === null) {
                    $filtered[] = $value;

                    continue;
                }
                if (is_array($value)) {
                    $filtered[] = $this->filterArray($value);
                }
            }

            return $filtered;
        }

        return $this->filterAssoc($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function canonicalizeAssoc(array $payload): array
    {
        $dropped = $payload['_dropped_non_scalar_keys'] ?? null;
        unset($payload['_dropped_non_scalar_keys']);
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalizeArray($value);
            }
        }

        if (is_array($dropped) && $dropped !== []) {
            sort($dropped);
            $payload['_dropped_non_scalar_keys'] = array_values($dropped);
        }

        return $payload;
    }

    /**
     * @param  array<int|string,mixed>  $payload
     * @return array<int|string,mixed>
     */
    private function canonicalizeArray(array $payload): array
    {
        if (array_is_list($payload)) {
            foreach ($payload as $index => $value) {
                if (is_array($value)) {
                    $payload[$index] = $this->canonicalizeArray($value);
                }
            }

            return $payload;
        }

        /** @var array<string,mixed> $payload */
        return $this->canonicalizeAssoc($payload);
    }

    private function sinkPath(): string
    {
        return storage_path('app/atlas-loop/signals/'.gmdate('Y-m-d').'.jsonl');
    }
}
