<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCollisionMatrixContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Collision Matrix CLI.
 *
 *   php artisan atlas:aaeos:collision-matrix-contract [--packets=/tmp/packets.json] [--json]
 *
 * Builds the read-only Collision Matrix over a list of packets (pairwise
 * comparisons, safe parallel groups, matrix hash). With no --packets it runs a
 * representative demo set. NEVER claims, reserves, dispatches or mutates state.
 *
 * @see docs/engineering-knowledge-base/self-construction/collision-matrix-contract.md
 */
final class AtlasCollisionMatrixContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:collision-matrix-contract
        {--packets= : path to a JSON file with a list of packet write scopes}
        {--json : machine-readable JSON output}';

    protected $description = 'Atlas AAEOS · build the read-only collision matrix proving which packets may be parallelized.';

    public function handle(AtlasCollisionMatrixContractService $service): int
    {
        try {
            $packets = $this->demoPackets();

            $packetsOpt = $this->option('packets');
            if (is_string($packetsOpt) && trim($packetsOpt) !== '') {
                $path = trim($packetsOpt);
                if (! is_file($path)) {
                    return $this->failEnvelope("packets file not found: {$path}");
                }
                $decoded = json_decode((string) file_get_contents($path), true);
                if (! is_array($decoded)) {
                    return $this->failEnvelope("packets file is not a JSON array: {$path}");
                }
                $packets = $decoded;
            }

            $result = $service->buildMatrix($packets);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failEnvelope($e->getMessage());
        }
    }

    /**
     * A small representative batch: two disjoint packets (parallel-safe) plus
     * one that overlaps the first on an allowed file (blocked).
     *
     * @return array<int, array<string,mixed>>
     */
    private function demoPackets(): array
    {
        return [
            ['id' => 'AIP-SPLIT-A', 'allowed' => ['app/Services/Foo.php'], 'withheld_hot' => false, 'depends_on' => []],
            ['id' => 'AIP-SPLIT-B', 'allowed' => ['app/Services/Bar.php'], 'withheld_hot' => false, 'depends_on' => []],
            ['id' => 'AIP-SPLIT-C', 'allowed' => ['app/Services/Foo.php'], 'withheld_hot' => false, 'depends_on' => []],
        ];
    }

    private function failEnvelope(string $message): int
    {
        $this->line((string) json_encode(
            ['ok' => false, 'error' => $message],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));

        return self::FAILURE;
    }
}
