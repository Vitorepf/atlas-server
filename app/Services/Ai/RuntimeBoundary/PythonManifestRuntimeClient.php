<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary;

use App\Services\Ai\Support\JsonFileStore;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

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

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>|null
     */
    public function runResident(
        array $manifest,
        string $socketPath,
        string $manifestPath,
        bool $autoStart = true,
        int $connectTimeoutMs = 200,
        int $startupTimeoutMs = 1000,
        int $idleTimeoutSeconds = 300,
    ): ?array {
        if (! $this->residentAvailable() || trim($socketPath) === '') {
            return null;
        }

        File::ensureDirectoryExists(dirname($socketPath));
        File::ensureDirectoryExists(dirname($manifestPath));

        $stream = $this->connectResident($socketPath, $connectTimeoutMs);
        if (! is_resource($stream) && $autoStart) {
            $this->startResident($socketPath, $manifestPath, $idleTimeoutSeconds);
            $stream = $this->waitForResident($socketPath, $connectTimeoutMs, $startupTimeoutMs);
        }

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $payload = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (! is_string($payload) || @fwrite($stream, $payload."\n") === false) {
                return null;
            }

            stream_set_timeout($stream, max(1, $this->timeoutSeconds));
            $line = fgets($stream);
            if (! is_string($line) || trim($line) === '') {
                return null;
            }

            $decoded = json_decode($line, true);
            if (! is_array($decoded) || ($decoded['ok'] ?? false) !== true || ! is_array($decoded['result'] ?? null)) {
                return null;
            }

            /** @var array<string,mixed> $result */
            $result = $decoded['result'];

            return $result;
        } catch (Throwable) {
            return null;
        } finally {
            @fclose($stream);
        }
    }

    public function residentAvailable(): bool
    {
        return $this->available() && File::exists($this->daemonEntrypoint());
    }

    private function venvPython(): string
    {
        // Override por env para runtimes cross-plataforma: no container Linux
        // (atlas-backend) o bind-mount traz o venv do macOS do host, cujo
        // binário não executa no Linux — available()=false e o context pack
        // degradava para lexical. ATLAS_PYTHON_VENV_ROOT aponta para um venv
        // NATIVO do ambiente (ex.: /opt/atlas-python no container). Sem a env
        // (host/dev), mantém a convenção `<runtime>/.venv/bin/python`.
        $override = trim((string) env('ATLAS_PYTHON_VENV_ROOT', ''));
        if ($override !== '') {
            return rtrim($override, '/').'/'.basename($this->runtimeRoot).'/.venv/bin/python';
        }

        return base_path($this->runtimeRoot.'/.venv/bin/python');
    }

    private function entrypoint(): string
    {
        return base_path($this->runtimeRoot.'/main.py');
    }

    private function daemonEntrypoint(): string
    {
        return base_path($this->runtimeRoot.'/daemon.py');
    }

    /**
     * @return resource|null
     */
    private function connectResident(string $socketPath, int $timeoutMs)
    {
        $timeout = max(0.001, $timeoutMs / 1000);
        $stream = @stream_socket_client('unix://'.$socketPath, $errno, $errstr, $timeout);

        return is_resource($stream) ? $stream : null;
    }

    private function startResident(string $socketPath, string $manifestPath, int $idleTimeoutSeconds): void
    {
        $command = sprintf(
            'nohup %s %s --socket %s --manifest-path %s --idle-timeout %d >/dev/null 2>&1 &',
            escapeshellarg($this->venvPython()),
            escapeshellarg($this->daemonEntrypoint()),
            escapeshellarg($socketPath),
            escapeshellarg($manifestPath),
            max(1, $idleTimeoutSeconds),
        );

        try {
            (new Process(['/bin/sh', '-lc', $command], base_path($this->runtimeRoot)))->run();
        } catch (Throwable) {
            // Resident mode is an optimization only; callers fall back to spawn.
        }
    }

    /**
     * @return resource|null
     */
    private function waitForResident(string $socketPath, int $connectTimeoutMs, int $startupTimeoutMs)
    {
        $deadline = microtime(true) + (max(0, $startupTimeoutMs) / 1000);
        do {
            $stream = $this->connectResident($socketPath, $connectTimeoutMs);
            if (is_resource($stream)) {
                return $stream;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        return null;
    }
}
