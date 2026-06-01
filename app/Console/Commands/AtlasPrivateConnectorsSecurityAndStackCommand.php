<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasPrivateConnectorsSecurityAndStackService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the "Private Connectors Security And Stack"
 * doc. With no args it self-describes the 13 private connector types, the 10
 * security rules and the stack-adoption gate, and runs worked admission/stack
 * decisions proving the contract is live. It never opens a connector, grants
 * write, adopts a dependency, or authorizes runtime.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/private-connectors-security-and-stack.md
 */
class AtlasPrivateConnectorsSecurityAndStackCommand extends Command
{
    protected $signature = 'atlas:aaeos:private-connectors-security-and-stack {--json : Print machine-readable JSON}';

    protected $description = 'Describe the private connector security rules and stack-adoption gate, with worked admission decisions (read-only, never authorizes runtime).';

    public function handle(AtlasPrivateConnectorsSecurityAndStackService $service): int
    {
        try {
            $payload = $service->describe();
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasPrivateConnectorsSecurityAndStackService::SCHEMA_VERSION,
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

        $safeRead = $payload['sample_safe_read'];
        $deniedWrite = $payload['sample_denied_write_without_approval'];
        $stackNo = $payload['sample_stack_not_approved'];
        $stackYes = $payload['sample_stack_adoptable'];

        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('mode', (string) $payload['mode']);
        $this->components->twoColumnDetail('connector_type_count', (string) $payload['connector_type_count']);
        $this->components->twoColumnDetail('security_rule_count', (string) $payload['security_rule_count']);
        $this->components->twoColumnDetail('safe_read admitted', $safeRead['admitted'] ? 'true' : 'false');
        $this->components->twoColumnDetail('write_without_approval admitted', $deniedWrite['admitted'] ? 'true' : 'false');
        $this->components->twoColumnDetail('stack(missing leg) adoptable', $stackNo['adoptable'] ? 'true' : 'false');
        $this->components->twoColumnDetail('stack(all legs) adoptable', $stackYes['adoptable'] ? 'true' : 'false');
        $this->components->twoColumnDetail('runtime_authorized', $payload['runtime_authorized'] ? 'true' : 'false');

        return self::SUCCESS;
    }
}
