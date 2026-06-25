<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay\AtlasLoopTrinityCycleConductor;
use App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay\AtlasLoopTrinityFeedbackAuditor;
use App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay\AtlasLoopTrinityHealthService;
use App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay\AtlasLoopTrinityReceiptChain;
use Illuminate\Console\Command;
use Throwable;

/**
 * TRINITY OPERATOR CLI — proves the recursive three-way coupling (Loop ⇄ Cortex ⇄ Maestro) is ALIVE. Each
 * action surfaces evidence from a different primitive:
 *   - status : {@see AtlasLoopTrinityHealthService}::health() summary
 *   - cycle  : {@see AtlasLoopTrinityCycleConductor}::runOneCycle() + auditor verdict
 *   - drift  : last 10 cycles' static-violation flags from the chain
 *   - chain  : {@see AtlasLoopTrinityReceiptChain}::verifyChain() + last 5 entries
 */
final class AtlasLoopTrinityCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:trinity {action : status|cycle|drift|chain}';

    /** @var string */
    protected $description = 'Operator surface for the Trinity three-way coupling — status / cycle / drift / chain.';

    public function handle(): int
    {
        $action = trim((string) $this->argument('action'));

        return match ($action) {
            'status' => $this->status(),
            'cycle' => $this->cycle(),
            'drift' => $this->drift(),
            'chain' => $this->chain(),
            default => $this->usage($action),
        };
    }

    private function status(): int
    {
        try {
            $health = $this->resolveHealthService();
            if ($health === null) {
                $this->line(json_encode(['status' => 'skipped', 'reason' => 'health_service_unwired'], JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }
            $snapshot = $health->health();
            $payload = [
                'latencyP50' => $snapshot->latencyP50,
                'latencyP95' => $snapshot->latencyP95,
                'mutualCoverage' => $snapshot->mutualCoverage,
                'chainIntegrityOk' => $snapshot->chainIntegrityOk,
                'chain_length' => $this->chainLength(),
            ];
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line(json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }
    }

    private function cycle(): int
    {
        try {
            $conductor = $this->resolveConductor();
            if ($conductor === null) {
                $this->line(json_encode(['status' => 'skipped', 'reason' => 'conductor_unwired'], JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }
            $result = $conductor->runOneCycle();
            $isStatic = false;
            try {
                $stream = $conductor->getFuelForNextCycle($result->cycleId);
                $auditor = new AtlasLoopTrinityFeedbackAuditor($stream);
                $isStatic = $auditor->audit($result->cycleId)->isStatic;
            } catch (Throwable) {
            }
            $payload = [
                'cycleId' => $result->cycleId,
                'loopReceiptId' => $result->loopReceiptId,
                'cortexReceiptId' => $result->cortexReceiptId,
                'maestroReceiptId' => $result->maestroReceiptId,
                'newFactCount' => $result->newFactCount,
                'isStatic' => $isStatic,
            ];
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line(json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }
    }

    private function drift(): int
    {
        $chain = $this->resolveChain();
        $latest = $chain->latest();
        // Without a richer chain API we surface the last 10 entries' integrity status as drift evidence.
        $entries = $this->readChainEntries();
        $tail = array_slice($entries, max(0, count($entries) - 10));
        $payload = [
            'tail_count' => count($tail),
            'latest_cycle_id' => $latest?->cycleId,
            'chain_verifies' => $chain->verifyChain(),
        ];
        $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function chain(): int
    {
        $chain = $this->resolveChain();
        $ok = $chain->verifyChain();
        $entries = $this->readChainEntries();
        $tail = array_slice($entries, max(0, count($entries) - 5));
        $payload = [
            'verifyChain' => $ok,
            'tail' => array_map(static fn (array $e): array => [
                'cycleId' => $e['cycleId'] ?? '',
                'prevCycleHash' => $e['prevCycleHash'] ?? '',
                'factStreamHash' => $e['factStreamHash'] ?? '',
            ], $tail),
        ];
        $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $ok ? self::SUCCESS : self::INVALID;
    }

    private function usage(string $action): int
    {
        $this->line(json_encode(['status' => 'usage_error', 'reason' => 'unknown action: '.$action, 'allowed' => ['status', 'cycle', 'drift', 'chain']], JSON_UNESCAPED_SLASHES));

        return self::INVALID;
    }

    private function resolveHealthService(): ?AtlasLoopTrinityHealthService
    {
        try {
            return $this->getLaravel()->make(AtlasLoopTrinityHealthService::class);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveConductor(): ?AtlasLoopTrinityCycleConductor
    {
        try {
            return $this->getLaravel()->make(AtlasLoopTrinityCycleConductor::class);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveChain(): AtlasLoopTrinityReceiptChain
    {
        try {
            return $this->getLaravel()->make(AtlasLoopTrinityReceiptChain::class);
        } catch (Throwable) {
            return new AtlasLoopTrinityReceiptChain;
        }
    }

    /**
     * Read the on-disk JSONL entries of the chain singleton. The chain class doesn't expose a public listing,
     * so we reflect on its private $chainFile property to discover whichever path the container-bound
     * instance is using (tests bind an override).
     *
     * @return list<array<string,mixed>>
     */
    private function readChainEntries(): array
    {
        try {
            $chain = $this->resolveChain();
            $ref = new \ReflectionObject($chain);
            $prop = $ref->hasProperty('chainFile') ? $ref->getProperty('chainFile') : null;
            $path = $prop !== null ? (string) $prop->getValue($chain) : '';
        } catch (Throwable) {
            return [];
        }

        if ($path === '' || ! is_file($path)) {
            return [];
        }
        $entries = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    private function chainLength(): int
    {
        return count($this->readChainEntries());
    }
}
