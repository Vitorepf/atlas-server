<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\RuntimeEfficiency\AtlasLocalVerificationEngineService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasLocalVerificationEngineCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:local-verification:run
        {--flow-id=atlas_dev : Flow id}
        {--risk=medium : Risk level}
        {--changed-file=* : Changed file}
        {--allowed-file=* : Allowed file/prefix/pattern}
        {--forbidden-file=* : Forbidden file/prefix/pattern}
        {--command= : Failed command}
        {--exit-code= : Exit code}
        {--stderr= : Stderr excerpt}
        {--stdout= : Stdout excerpt}
        {--failing-test= : Failing test}
        {--resource-mode=normal : Resource mode}
        {--heavy-jobs : Allow heavy local jobs}
        {--json : Emit canonical JSON}';

    protected $description = 'Run ALVE local verification shadow: diff scope, test impact and failure capsule. No commands executed.';

    public function handle(AtlasLocalVerificationEngineService $service): int
    {
        $exitCode = $this->option('exit-code');
        $payload = $service->run([
            'flow_id' => (string) $this->option('flow-id'),
            'risk_level' => (string) $this->option('risk'),
            'changed_files' => (array) $this->option('changed-file'),
            'allowed_files' => (array) $this->option('allowed-file'),
            'forbidden_files' => (array) $this->option('forbidden-file'),
            'command' => (string) ($this->option('command') ?: ''),
            'exit_code' => $exitCode === null ? null : (int) $exitCode,
            'stderr' => (string) ($this->option('stderr') ?: ''),
            'stdout' => (string) ($this->option('stdout') ?: ''),
            'failing_test' => (string) ($this->option('failing-test') ?: ''),
            'resource_policy' => [
                'mode' => (string) $this->option('resource-mode'),
                'heavy_jobs_allowed' => (bool) $this->option('heavy-jobs'),
            ],
        ]);

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return ($payload['status'] ?? null) === AtlasLocalVerificationEngineService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('ALVE', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Hash', (string) ($payload['local_verification_hash'] ?? 'missing'));

        return ($payload['status'] ?? null) === AtlasLocalVerificationEngineService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
    }
}
