<?php

namespace App\Console\Commands;

use App\Services\Ai\IntelligenceFactory\AtlasIntelligenceFactoryRuntimeService;
use Illuminate\Console\Command;

class AtlasIntelligenceFactoryCommand extends Command
{
    protected $signature = 'atlas:intelligence-factory
        {action=control-plane : gap|decide|simulate|register-capability|certify-capability|marketplace|control-plane}
        {--objective= : Objective/prompt to inspect}
        {--capability-id= : Capability id}
        {--capability-key= : Capability key}
        {--name= : Capability name}
        {--domain= : Domain}
        {--flow-id= : Flow id}
        {--evidence=* : Evidence refs}
        {--json : Emit JSON}';

    protected $description = 'Operate Atlas Intelligence Factory OS local runtime.';

    public function handle(AtlasIntelligenceFactoryRuntimeService $runtime): int
    {
        $input = [
            'objective' => (string) ($this->option('objective') ?: 'Atlas Intelligence Factory CLI objective'),
            'capability_key' => $this->option('capability-key'),
            'name' => $this->option('name'),
            'domain' => $this->option('domain'),
            'flow_id' => $this->option('flow-id'),
            'evidence_refs' => (array) $this->option('evidence'),
            'source' => 'atlas:intelligence-factory',
        ];

        $payload = match ((string) $this->argument('action')) {
            'gap' => $runtime->detectGap($input),
            'decide' => $runtime->decide($input),
            'simulate' => $runtime->simulate($input),
            'register-capability' => $runtime->registerCapability($input),
            'certify-capability' => $runtime->certifyCapability((string) $this->option('capability-id')),
            'marketplace' => ['schema_version' => 'atlas.intelligence_factory.marketplace.v1', 'items' => $runtime->marketplace()],
            default => $runtime->controlPlane(),
        };

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('Action', (string) $this->argument('action'));
            $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'ready'));
            if (isset($payload['decision'])) {
                $this->components->twoColumnDetail('Decision', (string) $payload['decision']);
            }
        }

        return self::SUCCESS;
    }
}
