<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasSwarmProductionResolverService;
use Illuminate\Console\Command;

/**
 * Per-arm subprocess CLI invoked by AtlasSwarmParallelDispatchService's
 * default commandBuilder. Receives a JSON-encoded arm + context, invokes
 * the F2 Production Resolver, and emits the canonical outcome JSON on
 * stdout — exactly the contract `AtlasSwarmParallelDispatchService`
 * expects when parsing each subprocess's output.
 *
 * Stdout JSON shape:
 *   {"result":"success|failure|timeout","latency_ms":int,
 *    "quality_score":float|null,"output":"..."}
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-swarm-parallel-dispatch.md
 */
class AtlasSwarmExecuteArmCommand extends Command
{
    protected $signature = 'atlas:swarm:execute-arm
        {--arm-json= : JSON-encoded arm record}
        {--context-json= : JSON-encoded context record}';

    protected $description = 'Execute one swarm arm via the Production Resolver and emit canonical outcome JSON on stdout.';

    public function handle(AtlasSwarmProductionResolverService $resolver): int
    {
        $arm = $this->decode((string) $this->option('arm-json'));
        $context = $this->decode((string) $this->option('context-json'));

        if ($arm === null) {
            $this->emit([
                'result' => 'failure',
                'latency_ms' => 0,
                'quality_score' => null,
                'output' => 'invalid_arm_json',
            ]);

            return self::SUCCESS; // exit 0 so the parent reads the JSON outcome
        }

        try {
            $outcome = $resolver->resolve($arm, $context ?? []);
        } catch (\Throwable $e) {
            $outcome = [
                'result' => 'failure',
                'latency_ms' => 0,
                'quality_score' => null,
                'output' => 'execute_arm_error: '.substr($e->getMessage(), 0, 120),
            ];
        }

        $this->emit($outcome);

        return self::SUCCESS;
    }

    private function decode(string $json): ?array
    {
        if ($json === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function emit(array $payload): void
    {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
