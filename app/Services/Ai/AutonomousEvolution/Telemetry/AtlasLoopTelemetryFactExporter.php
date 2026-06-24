<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Telemetry;

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

        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return false;
        }

        $encoded = json_encode($fact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            return false;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return false;
            }

            $line = $encoded."\n";
            $written = fwrite($handle, $line);
            fflush($handle);

            return $written === strlen($line);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
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
