<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSingleSessionInstructionPacketContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Single Session Instruction Packet Contract
 * doc. With no args it self-describes the required-field contract and the
 * Non-Goal stop conditions, then runs a worked accept + reject pair to prove
 * the validator is live. It never dispatches, claims, merges or authorizes
 * runtime.
 *
 * @see docs/engineering-knowledge-base/self-construction/single-session-instruction-packet-contract.md
 */
class AtlasSingleSessionInstructionPacketContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:single-session-instruction-packet-contract {--json : Print machine-readable JSON}';

    protected $description = 'Validate single-session instruction / Codex start packets against the contract (read-only, never dispatches or authorizes runtime).';

    public function handle(AtlasSingleSessionInstructionPacketContractService $service): int
    {
        try {
            $payload = $service->describe();
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasSingleSessionInstructionPacketContractService::SCHEMA_VERSION,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
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
        $reject = $payload['sample_reject'];

        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('mode', (string) $payload['mode']);
        $this->components->twoColumnDetail('instruction_required_fields', (string) $payload['instruction_required_field_count']);
        $this->components->twoColumnDetail('codex_start_required_fields', (string) $payload['codex_start_required_field_count']);
        $this->components->twoColumnDetail('prohibition_count', (string) $payload['prohibition_count']);
        $this->components->twoColumnDetail('sample_accept_verdict', (string) $accept['verdict']);
        $this->components->twoColumnDetail('sample_reject_verdict', (string) $reject['verdict']);
        $this->components->twoColumnDetail('sample_reject_reasons', (string) count($reject['reasons']));
        $this->components->twoColumnDetail('dispatch_remains_separate', $payload['dispatch_remains_separate'] ? 'true' : 'false');
        $this->components->twoColumnDetail('runtime_authorized', $payload['runtime_authorized'] ? 'true' : 'false');

        return self::SUCCESS;
    }
}
