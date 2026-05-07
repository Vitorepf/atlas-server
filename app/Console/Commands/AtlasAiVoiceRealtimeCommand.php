<?php

namespace App\Console\Commands;

use App\Services\Ai\Voice\AtlasVoiceRealtimeService;
use Illuminate\Console\Command;

class AtlasAiVoiceRealtimeCommand extends Command
{
    protected $signature = 'atlas:ai:voice
        {action=contract : Action to inspect: contract, bootstrap or health}
        {--runtime=livekit_agents_sdk : Runtime id for contract inspection}
        {--base-url= : Kernel base URL for bootstrap manifests}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect the Atlas AI Voice Realtime surface contract.';

    public function handle(AtlasVoiceRealtimeService $voice): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'contract' => $voice->runtimeContract(['runtime' => (string) $this->option('runtime')]),
            'bootstrap' => $voice->runtimeBootstrapManifest([
                'runtime' => (string) $this->option('runtime'),
                'base_url' => (string) ($this->option('base-url') ?: config('app.url')),
            ]),
            'health' => $voice->health(['runtime' => (string) $this->option('runtime')]),
            default => [
                'schema_version' => 'atlas.voice_realtime.command.v1',
                'status' => 'invalid_action',
                'allowed_actions' => ['contract', 'bootstrap', 'health'],
                'received_action' => $action,
            ],
        };

        $exitCode = $payload['status'] === 'invalid_action' ? self::FAILURE : self::SUCCESS;

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exitCode;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Voice Realtime</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Schema', (string) ($payload['schema_version'] ?? 'unknown'));
        $this->components->twoColumnDetail('Surface', (string) ($payload['surface_id'] ?? 'voice_realtime'));
        $this->components->twoColumnDetail('Runtime', (string) ($payload['runtime_id'] ?? $this->option('runtime')));
        $this->components->twoColumnDetail('Mobile first', data_get($payload, 'mobile_first', false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Kernel decides', data_get($payload, 'kernel_is_decision_authority', true) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Internal auth', (string) data_get($payload, 'auth_contract.internal_api.middleware', 'atlas.token'));
        $this->components->twoColumnDetail('Mobile auth', (string) data_get($payload, 'auth_contract.mobile_api.middleware', 'atlas.mobile.bearer'));
        $this->components->twoColumnDetail('Raw audio persistence', data_get($payload, 'contract.raw_audio_persistence_allowed', false) ? 'allowed' : 'forbidden');

        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.runtime_bootstrap.v1') {
            $this->components->twoColumnDetail('Entrypoint', (string) data_get($payload, 'entrypoint.module').'::'.(string) data_get($payload, 'entrypoint.factory'));
            $this->components->twoColumnDetail('Kernel', (string) data_get($payload, 'kernel.base_url'));
            $this->components->twoColumnDetail('Contract hash', (string) data_get($payload, 'contract_hash'));
        }

        if (($payload['required_callbacks'] ?? []) !== []) {
            $this->table(
                ['callback', 'internal endpoint', 'mobile endpoint'],
                collect($payload['required_callbacks'])
                    ->map(fn (string $endpoint, string $callback): array => [
                        $callback,
                        $endpoint,
                        (string) data_get($payload, "mobile_required_callbacks.{$callback}", ''),
                    ])
                    ->values()
                    ->all(),
            );
        }

        if ($exitCode !== self::SUCCESS) {
            $this->error('Invalid action. Use contract, bootstrap or health.');
        }

        return $exitCode;
    }
}
