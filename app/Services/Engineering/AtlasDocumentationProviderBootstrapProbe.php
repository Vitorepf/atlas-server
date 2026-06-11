<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use JsonException;
use Symfony\Component\Process\Process;
use Throwable;

class AtlasDocumentationProviderBootstrapProbe
{
    private const PROBE_MEMORY_LIMIT = '1024M';

    private const PROBE_TIMEOUT_SECONDS = 180;

    /**
     * @return array<string,mixed>
     */
    public function report(string $task, string $feature, string $workspace): array
    {
        $sessionBootstrap = $this->probeArtisanJson('atlas:ai:session-bootstrap', [
            '--task' => $task === '' ? '<task>' : $task,
            '--workspace' => $workspace === '' ? base_path() : $workspace,
            '--strict' => true,
            '--json' => true,
        ]);

        $featurePlacement = $feature === ''
            ? [
                'command' => 'php artisan atlas:ai:place-feature "<feature>" --strict --json',
                'status' => 'review',
                'exit_code' => null,
                'payload_status' => null,
                'reason' => 'feature is empty; placement was not executed',
            ]
            : $this->probeArtisanJson('atlas:ai:place-feature', [
                'feature' => $feature,
                '--strict' => true,
                '--json' => true,
            ]);

        $statuses = [
            (string) $sessionBootstrap['status'],
            (string) $featurePlacement['status'],
        ];

        $status = in_array('blocked', $statuses, true)
            ? 'blocked'
            : (in_array('review', $statuses, true) ? 'review' : 'ready');

        return [
            'status' => $status,
            'session_bootstrap' => $sessionBootstrap,
            'feature_placement' => $featurePlacement,
            'fail_closed' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function probeArtisanJson(string $command, array $arguments): array
    {
        $argv = $this->probeArgv($command, $arguments);
        $run = $this->runProbeProcess($argv);
        $rawOutput = trim($run['stdout']);

        try {
            if ($rawOutput === '' && (int) $run['exit_code'] !== 0) {
                return $this->blockedProcessReport($command, $arguments, $run, 'empty_probe_output', 'probe returned no JSON output');
            }

            $payload = $rawOutput === '' ? [] : json_decode($rawOutput, true, flags: JSON_THROW_ON_ERROR);
            $exitCode = $run['exit_code'];
            $payloadStatus = (string) data_get($payload, 'status', 'unknown');
            $payloadGateStatus = data_get($payload, 'gate_status');
            $status = $exitCode === 0 && in_array($payloadStatus, ['ready', 'ok'], true)
                ? 'ready'
                : ($exitCode === 0 ? 'review' : 'blocked');

            $report = [
                'command' => $this->formatProbeCommand($command, $arguments),
                'status' => $status,
                'exit_code' => $exitCode,
                'payload_status' => $payloadStatus,
                'payload_gate_status' => is_scalar($payloadGateStatus) ? (string) $payloadGateStatus : null,
                'schema_version' => data_get($payload, 'schema_version'),
                'process_isolated' => true,
                'memory_limit' => self::PROBE_MEMORY_LIMIT,
                'timeout_seconds' => self::PROBE_TIMEOUT_SECONDS,
                'blocked_reason' => $this->payloadBlockedReason($payload),
                'blocked_when' => $this->payloadBlockedWhen($payload),
            ];
            if ($run['timed_out']) {
                $report['failure_reason'] = 'probe_timed_out';
            }

            return $report;
        } catch (JsonException $exception) {
            return $this->blockedProcessReport($command, $arguments, $run, 'invalid_json', $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->blockedProcessReport($command, $arguments, $run, 'probe_exception', $exception->getMessage(), $exception::class);
        }
    }

    /**
     * @param  array<int,string>  $argv
     * @return array{exit_code:int, stdout:string, stderr:string, timed_out:bool}
     */
    protected function runProbeProcess(array $argv): array
    {
        try {
            $process = new Process($argv, base_path(), timeout: self::PROBE_TIMEOUT_SECONDS);
            $process->run();

            return [
                'exit_code' => $process->getExitCode() ?? 1,
                'stdout' => $process->getOutput(),
                'stderr' => $process->getErrorOutput(),
                'timed_out' => false,
            ];
        } catch (Throwable $exception) {
            return [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => $exception->getMessage(),
                'timed_out' => str_contains(strtolower($exception->getMessage()), 'timed out'),
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<int,string>
     */
    private function probeArgv(string $command, array $arguments): array
    {
        $argv = [
            PHP_BINARY,
            '-d',
            'memory_limit='.self::PROBE_MEMORY_LIMIT,
            base_path('artisan'),
            $command,
        ];

        foreach ($arguments as $key => $value) {
            if ($value === false || $value === null) {
                continue;
            }
            if (is_string($key) && str_starts_with($key, '--')) {
                if ($value === true) {
                    $argv[] = $key;
                } else {
                    $argv[] = $key.'='.(string) $value;
                }
                continue;
            }
            if (is_string($value) && trim($value) !== '') {
                $argv[] = $value;
            }
        }

        return $argv;
    }

    /**
     * @param  array{exit_code:int, stdout:string, stderr:string, timed_out:bool}  $run
     * @return array<string,mixed>
     */
    private function blockedProcessReport(
        string $command,
        array $arguments,
        array $run,
        string $failureReason,
        string $message,
        ?string $errorClass = null,
    ): array {
        $stderr = (string) ($run['stderr'] ?? '');
        $stdout = (string) ($run['stdout'] ?? '');
        $classifiedReason = $this->classifyProbeFailure($failureReason, $stderr.$stdout.$message);

        return [
            'command' => $this->formatProbeCommand($command, $arguments),
            'status' => 'blocked',
            'exit_code' => (int) ($run['exit_code'] ?? 1),
            'payload_status' => null,
            'schema_version' => null,
            'process_isolated' => true,
            'memory_limit' => self::PROBE_MEMORY_LIMIT,
            'timeout_seconds' => self::PROBE_TIMEOUT_SECONDS,
            'failure_reason' => $classifiedReason,
            'error_class' => $errorClass,
            'error_message' => $this->sanitizeProbeText($message),
            'stderr_hash' => $stderr === '' ? null : hash('sha256', $stderr),
            'stdout_hash' => $stdout === '' ? null : hash('sha256', $stdout),
            'stderr_excerpt' => $this->sanitizeProbeText($stderr),
            'stdout_excerpt' => $this->sanitizeProbeText($stdout),
        ];
    }

    private function classifyProbeFailure(string $fallback, string $text): string
    {
        $lower = strtolower($text);
        if (str_contains($lower, 'allowed memory size') || str_contains($lower, 'memory exhausted')) {
            return 'memory_limit_exhausted';
        }
        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return 'probe_timed_out';
        }

        return $fallback;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function payloadBlockedReason(array $payload): ?string
    {
        foreach ([
            'session_gate.reason',
            'reason',
            'error',
            'message',
        ] as $key) {
            $value = data_get($payload, $key);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return mb_substr(trim((string) $value), 0, 200);
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,string>
     */
    private function payloadBlockedWhen(array $payload): array
    {
        $values = data_get($payload, 'session_gate.blocked_when', data_get($payload, 'blocked_when', []));
        if (! is_array($values)) {
            return [];
        }

        return array_slice(array_values(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $values,
        ), static fn (string $value): bool => $value !== '')), 0, 8);
    }

    private function sanitizeProbeText(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $text = preg_replace('#/Users/[^\\s:]+#', '<local-path>', $text) ?? $text;
        $text = preg_replace('#/private/[^\\s:]+#', '<local-path>', $text) ?? $text;
        $text = preg_replace('/\\s+/', ' ', $text) ?? $text;

        return mb_substr($text, 0, 240);
    }

    /**
     * @param  array<string,mixed>  $arguments
     */
    private function formatProbeCommand(string $command, array $arguments): string
    {
        if ($command === 'atlas:ai:session-bootstrap') {
            return 'php artisan atlas:ai:session-bootstrap --task="'.(string) ($arguments['--task'] ?? '<task>').'" --strict --json';
        }

        if ($command === 'atlas:ai:place-feature') {
            return 'php artisan atlas:ai:place-feature "'.(string) ($arguments['feature'] ?? '<feature>').'" --strict --json';
        }

        return 'php artisan '.$command;
    }
}
