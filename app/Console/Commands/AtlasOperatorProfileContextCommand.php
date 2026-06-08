<?php

namespace App\Console\Commands;

use App\Services\Ai\OperatorIntelligence\OperatorContextComposer;
use Illuminate\Console\Command;
use Throwable;

class AtlasOperatorProfileContextCommand extends Command
{
    protected $signature = 'atlas:operator-profile
        {action=context : context}
        {--operator= : Operator id. Defaults to config default.}
        {--flow= : Optional flow id.}
        {--provider-external : Compose provider-safe external context.}
        {--trace-id= : Optional trace id.}
        {--session-id= : Optional session id.}
        {--limit= : Max profile items to include.}
        {--no-record-usage : Do not record context_injected feedback.}
        {--json : Emit JSON.}';

    protected $description = 'Compose provider-safe Operator Intelligence context from active profile items.';

    public function handle(OperatorContextComposer $composer): int
    {
        try {
            $payload = match (strtolower(trim((string) $this->argument('action')))) {
                'context' => $composer->compose([
                    'operator_id' => $this->operatorId(),
                    'flow' => $this->stringOption('flow'),
                    'provider_external' => (bool) $this->option('provider-external'),
                    'trace_id' => $this->stringOption('trace-id'),
                    'session_id' => $this->stringOption('session-id'),
                    'limit' => $this->option('limit') !== null ? (int) $this->option('limit') : null,
                    'record_usage' => ! (bool) $this->option('no-record-usage'),
                ]),
                default => ['ok' => false, 'error' => 'unsupported_action', 'supported' => ['context']],
            };
        } catch (Throwable $e) {
            $payload = ['ok' => false, 'error' => 'exception', 'message' => $e->getMessage(), 'type' => $e::class];
        }

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        return ($payload['ok'] ?? true) === false ? self::FAILURE : self::SUCCESS;
    }

    private function operatorId(): string
    {
        return $this->stringOption('operator') ?? (string) config('atlas_operator_intelligence.default_operator_id', 'default');
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
