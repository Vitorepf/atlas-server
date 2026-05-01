<?php

namespace App\Services\Ai\Cli;

use Symfony\Component\Process\Process;

class AtlasTerminalNotifier
{
    public function __construct(
        private readonly bool $enabled = true,
        private readonly int $minDurationMs = 30_000,
    ) {}

    public function notify(string $title, string $body, int $durationMs = 0, ?string $sound = null): bool
    {
        if (! $this->enabled) {
            return false;
        }
        if ($durationMs > 0 && $durationMs < $this->minDurationMs) {
            return false;
        }
        if (PHP_OS_FAMILY !== 'Darwin') {
            return false;
        }

        $script = $this->buildOsascript($title, $body, $sound);

        try {
            $process = new Process(['osascript', '-e', $script]);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildOsascript(string $title, string $body, ?string $sound): string
    {
        $titleSafe = $this->escape($title);
        $bodySafe = $this->escape($body);
        $line = sprintf(
            'display notification "%s" with title "%s"',
            $bodySafe,
            $titleSafe,
        );
        if (is_string($sound) && $sound !== '') {
            $line .= ' sound name "'.$this->escape($sound).'"';
        }

        return $line;
    }

    private function escape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('"', '\\"', $value);
        $value = str_replace(["\r", "\n"], ' ', $value);

        return $value;
    }
}
