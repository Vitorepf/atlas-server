<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSystemGraphCapabilitiesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runtime surface for the system-graph `capabilities` gate. Runs a small sample
 * catalog of harnesses through the policy gate and prints the authorized subset
 * plus every denial reason — the doc's "Saida: capabilities autorizadas".
 *
 * @see docs/engineering-knowledge-base/system-graph/capabilities.md
 */
class AtlasSystemGraphCapabilitiesCommand extends Command
{
    protected $signature = 'atlas:aaeos:system-graph-capabilities {--json : Print machine-readable JSON}';

    protected $description = 'Authorize a capability catalog against a policy (system-graph capabilities gate): classify by risk + evidence and emit the authorized set.';

    public function handle(AtlasSystemGraphCapabilitiesService $capabilities): int
    {
        try {
            // Safe default sample: two policy-granted harnesses (one needs evidence,
            // one does not), one high-risk capability missing its evidence gate, and
            // one capability the policy never granted.
            $catalog = [
                ['id' => 'programming_harness', 'kind' => 'harness', 'risk' => 'medium'],
                ['id' => 'frontend_design_harness', 'kind' => 'harness', 'risk' => 'high', 'has_evidence' => true],
                ['id' => 'shell_exec_harness', 'kind' => 'harness', 'risk' => 'critical', 'has_evidence' => false],
                ['id' => 'unsanctioned_harness', 'kind' => 'harness', 'risk' => 'low'],
            ];
            $policy = [
                'allowed_capabilities' => [
                    'programming_harness',
                    'frontend_design_harness',
                    'shell_exec_harness',
                ],
            ];

            $result = $capabilities->authorizeCatalog($catalog, $policy);

            return $this->emit($result);
        } catch (Throwable $e) {
            return $this->emit([
                'schema' => AtlasSystemGraphCapabilitiesService::SCHEMA_VERSION,
                'error' => true,
                'message' => $e->getMessage(),
            ], self::FAILURE);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit = self::SUCCESS): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $exit;
        }

        foreach ($payload as $key => $value) {
            $this->components->twoColumnDetail((string) $key, is_scalar($value) ? (string) $value : json_encode($value));
        }

        return $exit;
    }
}
