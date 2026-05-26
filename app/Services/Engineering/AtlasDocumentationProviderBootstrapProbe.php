<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class AtlasDocumentationProviderBootstrapProbe
{
    public function __construct(
        private readonly ConsoleKernel $console,
    ) {}

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
        try {
            $output = new BufferedOutput;
            $exitCode = $this->console->call($command, $arguments, $output);
            $rawOutput = trim($output->fetch());
            $payload = $rawOutput === '' ? [] : json_decode($rawOutput, true, flags: JSON_THROW_ON_ERROR);
            $payloadStatus = (string) data_get($payload, 'status', 'unknown');
            $status = $exitCode === 0 && in_array($payloadStatus, ['ready', 'ok'], true)
                ? 'ready'
                : ($exitCode === 0 ? 'review' : 'blocked');

            return [
                'command' => $this->formatProbeCommand($command, $arguments),
                'status' => $status,
                'exit_code' => $exitCode,
                'payload_status' => $payloadStatus,
                'schema_version' => data_get($payload, 'schema_version'),
            ];
        } catch (Throwable $exception) {
            return [
                'command' => $this->formatProbeCommand($command, $arguments),
                'status' => 'blocked',
                'exit_code' => null,
                'payload_status' => null,
                'error_class' => $exception::class,
                'error_message' => $exception->getMessage(),
            ];
        }
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
