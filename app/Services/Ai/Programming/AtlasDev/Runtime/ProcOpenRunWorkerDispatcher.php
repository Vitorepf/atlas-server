<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Runtime;

use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use RuntimeException;

final class ProcOpenRunWorkerDispatcher implements RunWorkerDispatcher
{
    public function __construct(private readonly ReceiptStorage $storage) {}

    public function dispatch(string $runId, string $taskContractHash, ?string $expectedCompactSddHash = null): ?int
    {
        $runDir = $this->storage->ensureRunDirectory($runId);
        $log = $runDir.DIRECTORY_SEPARATOR.'run_worker.log';

        $command = [
            PHP_BINARY,
            base_path('artisan'),
            'atlas:dev:run-worker',
            $runId,
            '--task-contract-hash='.$taskContractHash,
        ];
        if ($expectedCompactSddHash !== null && $expectedCompactSddHash !== '') {
            $command[] = '--expected-compact-sdd-hash='.$expectedCompactSddHash;
        }

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'],
            ],
            $pipes,
            base_path(),
        );

        if (! is_resource($process)) {
            throw new RuntimeException('run worker proc_open failed');
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $status = proc_get_status($process);
        $pid = isset($status['pid']) ? (int) $status['pid'] : 0;

        return $pid > 0 ? $pid : null;
    }
}
