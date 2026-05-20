<?php

namespace App\Console\Commands;

use App\Services\Ai\StrategicReality\AtlasStrategicRealityRuntimeService;
use Illuminate\Console\Command;

class AtlasStrategicRealityCommand extends Command
{
    protected $signature = 'atlas:strategic-reality
        {action=control-plane : scan|decide|control-plane}
        {--question= : Strategic question or objective}
        {--domain= : Domain hint}
        {--evidence=* : Evidence refs}
        {--json : Emit JSON}';

    protected $description = 'Operate ASRE, the Atlas Strategic Reality Engine. Recommendation-only; no provider or external execution.';

    public function handle(AtlasStrategicRealityRuntimeService $runtime): int
    {
        $input = [
            'question' => (string) ($this->option('question') ?: 'Qual e a melhor proxima acao?'),
            'domain' => $this->option('domain'),
            'evidence_refs' => (array) $this->option('evidence'),
            'source' => 'atlas:strategic-reality',
        ];

        $payload = match ((string) $this->argument('action')) {
            'scan' => $runtime->scan($input),
            'decide' => $runtime->decide($input),
            default => $runtime->controlPlane(),
        };

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('ASRE action', (string) $this->argument('action'));
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        if (isset($payload['recommended_action'])) {
            $this->components->twoColumnDetail('Recommendation', (string) $payload['recommended_action']);
        }

        return self::SUCCESS;
    }
}
