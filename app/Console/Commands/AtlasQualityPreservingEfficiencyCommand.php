<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\RuntimeEfficiency\AtlasQualityPreservingEfficiencySystemService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasQualityPreservingEfficiencyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:efficiency
        {action=certify : certify|shadow|resources}
        {--prompt= : Prompt/objective for shadow}
        {--flow-id=atlas_dev : Flow id}
        {--domain=programming : Domain}
        {--provider=gpt : Provider}
        {--risk=medium : Risk level}
        {--available-gb=12 : Available/reclaimable RAM in GB}
        {--total-gb=48 : Total physical RAM in GB}
        {--swap-gb=0 : Swap used in GB}
        {--cpu-load=0.2 : CPU load 0..1}
        {--power-state=plugged : plugged|battery_low}
        {--operator-floor-gb=3 : Minimum RAM GB reserved for operator}
        {--requested-ram-gb=0 : Requested Atlas RAM GB, 0 lets runtime choose}
        {--repeated-tokens=0 : Repeated context tokens}
        {--json : Emit canonical JSON}';

    protected $description = 'Operate AQPES quality-preserving efficiency certification, shadow, and resource policy. No providers, no writes.';

    public function handle(AtlasQualityPreservingEfficiencySystemService $service): int
    {
        $input = [
            'prompt' => $this->option('prompt'),
            'flow_id' => (string) $this->option('flow-id'),
            'domain' => (string) $this->option('domain'),
            'provider' => (string) $this->option('provider'),
            'risk_level' => (string) $this->option('risk'),
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
            'resources' => $service->resourcePolicy($input),
            default => $service->certify($input),
        };

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return ($payload['status'] ?? null) === AtlasQualityPreservingEfficiencySystemService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('AQPES action', (string) $this->argument('action'));
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'ready'));
        $this->components->twoColumnDetail('Hash', (string) ($payload['certification_hash'] ?? $payload['shadow_hash'] ?? $payload['resource_policy_hash'] ?? 'missing'));

        return ($payload['status'] ?? null) === AtlasQualityPreservingEfficiencySystemService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
    }
}
