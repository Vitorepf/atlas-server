<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary;

use App\Services\Ai\Support\JsonFileStore;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

final class PythonManifestRuntimeClient
{
    public function __construct(
        private readonly string $runtimeRoot,
        private readonly string $manifestPrefix,
        private readonly string $unavailableMessage,
        private readonly string $failureLabel,
        private readonly int $timeoutSeconds = 120,
    ) {}

    public function available(): bool
    {
        return File::exists($this->venvPython()) && File::exists($this->entrypoint());
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    public function run(array $manifest): array
    {
        if (! $this->available()) {
            throw new RuntimeException($this->unavailableMessage);
        }

        $manifestPath = JsonFileStore::writeTemporary(
            storage_path('app'),
            $this->manifestPrefix,
            $manifest,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        try {
            $process = new Process(
                [$this->venvPython(), $this->entrypoint(), $manifestPath],
                base_path($this->runtimeRoot),
            );
            $process->setTimeout($this->timeoutSeconds);
            $process->run();
        } finally {
            JsonFileStore::deleteQuietly($manifestPath);
        }

        $payload = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($payload) || ($payload['ok'] ?? false) !== true) {
            $detail = $process->getErrorOutput() !== '' ? $process->getErrorOutput() : $process->getOutput();

            throw new RuntimeException($this->failureLabel.' runtime failed: '.substr($detail, 0, 500));
        }

        $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];

        /** @var array<string,mixed> $result */
        return $result;
    }

    private function venvPython(): string
    {
        return base_path($this->runtimeRoot.'/.venv/bin/python');
    }

    private function entrypoint(): string
    {
        return base_path($this->runtimeRoot.'/main.py');
    }
}
