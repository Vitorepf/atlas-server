<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasPythonDataRetrievalRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasPythonDataRetrievalRuntimeCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:context:python-data
        {--workspace= : Workspace root}
        {--file=* : Relative files to analyze}
        {--decision-receipt-hash= : Required for execution}
        {--execute : Execute governed Python runtime}
        {--approved : Approval gate for execution}
        {--runtime-boundary-green=1 : Runtime boundary gate}
        {--max-files=40 : Max files}
        {--max-bytes-per-file=250000 : Max bytes per file}
        {--json : Emit canonical JSON}';

    protected $description = 'Run AUCRI APDR governed Python/data retrieval runtime contract and optional execution.';

    public function handle(AtlasPythonDataRetrievalRuntimeService $service): int
    {
        $payload = $service->run([
            'workspace' => (string) ($this->option('workspace') ?: base_path()),
            'files' => (array) $this->option('file'),
            'decision_receipt_hash' => (string) ($this->option('decision-receipt-hash') ?: ''),
            'execute' => (bool) $this->option('execute'),
            'approved' => (bool) $this->option('approved'),
            'runtime_boundary_green' => (string) $this->option('runtime-boundary-green') !== '0',
            'max_files' => (int) $this->option('max-files'),
            'max_bytes_per_file' => (int) $this->option('max-bytes-per-file'),
        ]);

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return in_array((string) ($payload['status'] ?? ''), ['blocked', 'failed'], true) ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Python Data Retrieval Runtime', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Files', (string) count((array) data_get($payload, 'request.files', [])));
        $this->components->twoColumnDetail('Gate', (string) data_get($payload, 'execution_receipt.gate.status', data_get($payload, 'request.status', 'unknown')));
        $this->components->twoColumnDetail('Runtime hash', (string) $payload['runtime_hash']);

        return in_array((string) ($payload['status'] ?? ''), ['blocked', 'failed'], true) ? self::FAILURE : self::SUCCESS;
    }
}
