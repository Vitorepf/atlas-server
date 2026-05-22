<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\VerifiedContextExecution\AtlasVerifiedContextExecutionLoopService;
use Illuminate\Console\Command;

final class AtlasVerifiedContextExecutionLoopCommand extends Command
{
    protected $signature = 'atlas:verified-context-execution
        {action=certify : certify|shadow}
        {--flow-id=atlas_dev : Flow id}
        {--domain=programming : Domain}
        {--provider=gpt : Provider}
        {--risk=medium : Risk level}
        {--workspace=atlas : Workspace label}
        {--changed-file=* : Changed file path}
        {--allowed-file=* : Allowed file pattern}
        {--forbidden-file=* : Forbidden file pattern}
        {--evidence-ref=* : Evidence ref}
        {--command= : Failed/planned command}
        {--exit-code= : Exit code for failure capsule}
        {--stdout= : Stdout excerpt}
        {--stderr= : Stderr excerpt}
        {--failing-test= : Failing test name}
        {--task-type= : Token economy task type}
        {--available-gb=12 : Available/reclaimable RAM in GB}
        {--total-gb=48 : Total physical RAM in GB}
        {--swap-gb=0 : Swap used in GB}
        {--cpu-load=0.2 : CPU load 0..1}
        {--power-state=plugged : plugged|battery_low}
        {--operator-floor-gb=3 : Minimum RAM GB reserved for operator}
        {--requested-ram-gb=0 : Requested Atlas RAM GB, 0 lets runtime choose}
        {--repeated-tokens=0 : Repeated context tokens}
        {--json : Emit canonical JSON}';

    protected $description = 'Operate AVCEL verified context execution loop in read-only shadow/certify mode.';

    public function handle(AtlasVerifiedContextExecutionLoopService $service): int
    {
        $input = [
            'flow_id' => (string) $this->option('flow-id'),
            'domain' => (string) $this->option('domain'),
            'provider' => (string) $this->option('provider'),
            'risk_level' => (string) $this->option('risk'),
            'workspace' => (string) $this->option('workspace'),
            'changed_files' => (array) $this->option('changed-file'),
            'allowed_files' => (array) $this->option('allowed-file'),
            'forbidden_files' => (array) $this->option('forbidden-file'),
            'evidence_refs' => (array) $this->option('evidence-ref'),
            'command' => (string) ($this->option('command') ?? ''),
            'exit_code' => $this->option('exit-code') === null ? null : (int) $this->option('exit-code'),
            'stdout' => (string) ($this->option('stdout') ?? ''),
            'stderr' => (string) ($this->option('stderr') ?? ''),
            'failing_test' => (string) ($this->option('failing-test') ?? ''),
            'task_type' => (string) ($this->option('task-type') ?? ''),
            'memory_available_bytes' => (int) round(((float) $this->option('available-gb')) * 1073741824),
            'memory_total_bytes' => (int) round(((float) $this->option('total-gb')) * 1073741824),
            'swap_used_bytes' => (int) round(((float) $this->option('swap-gb')) * 1073741824),
            'cpu_load' => (float) $this->option('cpu-load'),
            'power_state' => (string) $this->option('power-state'),
            'operator_resource_floor_gb' => (float) $this->option('operator-floor-gb'),
            'requested_ram_gb' => (float) $this->option('requested-ram-gb'),
            'repeated_tokens' => (int) $this->option('repeated-tokens'),
        ];

        $payload = match ((string) $this->argument('action')) {
            'shadow' => $service->shadow($input),
            default => $service->certify($input),
        };

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return ($payload['status'] ?? null) === AtlasVerifiedContextExecutionLoopService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('AVCEL action', (string) $this->argument('action'));
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hash', (string) ($payload['certification_hash'] ?? $payload['avcel_hash'] ?? 'missing'));

        return ($payload['status'] ?? null) === AtlasVerifiedContextExecutionLoopService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
    }
}
