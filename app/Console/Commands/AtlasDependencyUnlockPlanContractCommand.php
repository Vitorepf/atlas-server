<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDependencyUnlockPlanContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Self-Construction Dependency Unlock Plan CLI.
 *
 *   php artisan atlas:aaeos:dependency-unlock-plan-contract
 *     [--phase=preview|durable]            // current phase (default preview)
 *     [--packet=AIP-SPLIT-0002:AIP-SPLIT-0001,AIP-SPLIT-0000]
 *                                          // repeatable: id:dep1,dep2 (cold lane)
 *     [--hot-packet=AIP-HOT-0009:dep1]     // repeatable: a hot-lane packet
 *     [--available=AIP-SPLIT-0001]         // comma-separated durable-available dep ids
 *     [--json]
 *
 * Read-only, deterministic. Emits a unlock plan showing blocked packets, their
 * dependencies (available vs pending), what would become preview-assignable, and
 * which hot work stays withheld. It NEVER mutates queue state, marks
 * dependencies complete, persists completion or dispatches unlocked work.
 *
 * @see docs/engineering-knowledge-base/self-construction/dependency-unlock-plan-contract.md
 */
class AtlasDependencyUnlockPlanContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:dependency-unlock-plan-contract
        {--phase= : current phase (preview|durable); only preview yields candidates}
        {--packet=* : cold-lane packet as id:dep1,dep2 (repeatable)}
        {--hot-packet=* : hot-lane packet as id:dep1,dep2 (repeatable, always withheld)}
        {--available= : comma-separated dependency ids with durable completion evidence}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · read-only dependency unlock plan showing blocked packets, unlock candidates and withheld hot work without mutating the queue.';

    public function handle(AtlasDependencyUnlockPlanContractService $service): int
    {
        try {
            $phaseOpt = $this->option('phase');
            $phase = is_string($phaseOpt) && trim($phaseOpt) !== ''
                ? trim($phaseOpt)
                : AtlasDependencyUnlockPlanContractService::PHASE_PREVIEW;

            $packets = array_merge(
                $this->packets('packet', AtlasDependencyUnlockPlanContractService::LANE_COLD),
                $this->packets('hot-packet', AtlasDependencyUnlockPlanContractService::LANE_HOT),
            );

            // Safe default sample so a bare invocation still demonstrates the plan.
            if ($packets === []) {
                $packets = [
                    ['id' => 'AIP-SPLIT-LOCAL-0002', 'lane' => 'cold', 'dependencies' => ['AIP-SPLIT-LOCAL-0001']],
                    ['id' => 'AIP-SPLIT-LOCAL-0003', 'lane' => 'cold', 'dependencies' => ['AIP-SPLIT-LOCAL-0002']],
                    ['id' => 'AIP-HOT-LOCAL-0009', 'lane' => 'hot', 'dependencies' => ['AIP-SPLIT-LOCAL-0001']],
                ];
            }

            $available = $this->list('available');
            if ($available === [] && ! $this->hasPacketOptions()) {
                $available = ['AIP-SPLIT-LOCAL-0001'];
            }

            $input = [
                'phase' => $phase,
                'available' => $available,
                'packets' => $packets,
            ];

            $result = $service->plan($input);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'dependency_unlock_plan_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    private function hasPacketOptions(): bool
    {
        return $this->arrayOption('packet') !== [] || $this->arrayOption('hot-packet') !== [];
    }

    /**
     * Parse repeatable `id:dep1,dep2` packet options into the service shape.
     *
     * @return list<array{id: string, lane: string, dependencies: list<string>}>
     */
    private function packets(string $option, string $lane): array
    {
        $out = [];
        foreach ($this->arrayOption($option) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            $parts = explode(':', $token, 2);
            $id = trim($parts[0]);
            if ($id === '') {
                continue;
            }
            $deps = [];
            if (isset($parts[1]) && trim($parts[1]) !== '') {
                $deps = array_values(array_filter(
                    array_map('trim', explode(',', $parts[1])),
                    static fn ($v) => $v !== '',
                ));
            }
            $out[] = ['id' => $id, 'lane' => $lane, 'dependencies' => $deps];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function arrayOption(string $option): array
    {
        $raw = $this->option($option);
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($v): string => is_string($v) ? trim($v) : '', $raw),
            static fn (string $v) => $v !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function list(string $option): array
    {
        $raw = $this->option($option);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn ($v) => $v !== '',
        ));
    }
}
