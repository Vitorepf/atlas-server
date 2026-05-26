<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\NamingPolicy\AtlasSelfConstructionNamingPolicyGate;
use Illuminate\Console\Command;

/**
 * Atlas Self-Construction OS — `status` sub-command.
 *
 * Gap4.F5 first slice. The mother command
 * `AtlasAiSelfConstructionCommand` is 13.790 lines / 1.5 MB. The catalog
 * blueprint (`atlas-self-construction-catalog.md` section 5) names
 * `status` as the lowest-risk extraction because it is read-only.
 *
 * This sub-command is a thin orchestrator over existing read-only
 * services:
 *
 *   - `AtlasSelfConstructionReadinessService::snapshot()` — subsystem
 *     readiness across the 290 services.
 *   - `AtlasSelfConstructionNamingPolicyGate::evaluate()` — naming policy
 *     baseline (existing violations + zero new).
 *
 * It does NOT replace the mother command; it ships ALONGSIDE so callers
 * have a focused, fast read-only entry without scanning the 13.790-line
 * mother. Coexistence is the deprecation step canon (mother stays
 * runtime-active until full refactor lands; new sub-commands extracted
 * one at a time, each tested in isolation).
 */
class AtlasAiSelfConstructionStatusCommand extends Command
{
    protected $signature = 'atlas:ai:self-construction:status
        {--json : Print machine-readable JSON}';

    protected $description = 'Atlas Self-Construction OS — read-only status snapshot (readiness + naming policy baseline).';

    public function handle(
        AtlasSelfConstructionReadinessService $readiness,
        AtlasSelfConstructionNamingPolicyGate $namingGate,
    ): int {
        $json = (bool) $this->option('json');

        $payload = $this->buildPayload($readiness, $namingGate);

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        // Status is `ok` when readiness reports valid AND no new naming
        // violations (existing grandfathered violations DO NOT block).
        return $payload['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{
     *   schema_version: string,
     *   status: string,
     *   readiness: array<string,mixed>,
     *   naming_policy: array<string,mixed>,
     *   command_source: string,
     *   docs_canon: array<int,string>
     * }
     */
    public function buildPayload(
        AtlasSelfConstructionReadinessService $readiness,
        AtlasSelfConstructionNamingPolicyGate $namingGate,
    ): array {
        $readinessSnapshot = $this->safeSnapshot(fn (): array => $readiness->snapshot());
        $namingSnapshot = $namingGate->evaluate([]);

        $readinessOk = ($readinessSnapshot['valid'] ?? false) === true
            || ($readinessSnapshot['status'] ?? 'unknown') === 'ok';
        $namingOk = ($namingSnapshot['status'] ?? 'failed') === 'ok';

        return [
            'schema_version' => 'atlas.self_construction.status_snapshot.v1',
            'status' => ($readinessOk && $namingOk) ? 'ok' : 'failed',
            'readiness' => $readinessSnapshot,
            'naming_policy' => $namingSnapshot,
            'command_source' => 'atlas:ai:self-construction:status (Gap4.F5 sub-command extraction)',
            'docs_canon' => [
                'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                'docs/engineering-knowledge-base/atlas-self-construction-catalog.md',
            ],
        ];
    }

    /**
     * @param  callable(): array<string,mixed>  $producer
     * @return array<string,mixed>
     */
    private function safeSnapshot(callable $producer): array
    {
        try {
            return $producer();
        } catch (\Throwable $e) {
            return [
                'status' => 'unreachable',
                'detail' => $e::class.': '.$e->getMessage(),
                'note' => 'Snapshot unreachable in this environment (likely missing DB tables). Service exists; runtime probe failed.',
            ];
        }
    }
}
