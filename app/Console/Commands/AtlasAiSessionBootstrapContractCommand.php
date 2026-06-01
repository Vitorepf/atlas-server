<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiSessionBootstrapContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Atlas Self-Construction AI Session Bootstrap
 * Contract doc. With no args it self-describes the payload schema, the three
 * bootstrap modes, the Non-Goal stop conditions and the documented stop-condition
 * set, then runs a worked accept + blocked pair to prove the validator is live.
 * It never dispatches, claims, writes the ledger or authorizes execution.
 *
 * @see docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md
 */
class AtlasAiSessionBootstrapContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:ai-session-bootstrap-contract {--json : Print machine-readable JSON}';

    protected $description = 'Validate AI session bootstrap payloads against the contract (read-only, never dispatches, claims, writes the ledger or authorizes execution).';

    public function handle(AtlasAiSessionBootstrapContractService $service): int
    {
        try {
            $payload = $service->describe();
        } catch (Throwable $e) {
            $envelope = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasAiSessionBootstrapContractService::SCHEMA_VERSION,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $accept = $payload['sample_accept'];
        $blocked = $payload['sample_blocked'];

        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('mode', (string) $payload['mode']);
        $this->components->twoColumnDetail('bootstrap_modes', implode(', ', $payload['bootstrap_modes']));
        $this->components->twoColumnDetail('payload_required_fields', (string) $payload['payload_required_field_count']);
        $this->components->twoColumnDetail('non_goal_count', (string) $payload['non_goal_count']);
        $this->components->twoColumnDetail('stop_condition_count', (string) $payload['stop_condition_count']);
        $this->components->twoColumnDetail('sample_accept_verdict', (string) $accept['verdict']);
        $this->components->twoColumnDetail('sample_blocked_verdict', (string) $blocked['verdict']);
        $this->components->twoColumnDetail('sample_blocked_reasons', (string) count($blocked['reasons']));
        $this->components->twoColumnDetail('adapter_may_widen_scope', $payload['adapter_may_widen_scope_invariant'] ? 'true' : 'false');
        $this->components->twoColumnDetail('execution_allowed', $payload['execution_allowed'] ? 'true' : 'false');
        $this->components->twoColumnDetail('ledger_write_allowed', $payload['ledger_write_allowed'] ? 'true' : 'false');

        return self::SUCCESS;
    }
}
