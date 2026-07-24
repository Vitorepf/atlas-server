<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Memory\AtlasMemoryRecallConcentrationV2Reader;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * ASI-12 — read-only per-actor concentration reader (v2).
 *
 * `atlas:memory:concentration-v2 --json` reports the volume-normalized
 * per-actor concentration index. v1 aggregate (`AtlasMemoryRecallConcentrationDemotion`)
 * keeps its own byte-identical output — this is a dual-read series, not a
 * substitution.
 */
final class AtlasMemoryConcentrationV2Command extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:memory:concentration-v2
        {--window-days=45 : recall window (days)}
        {--min-recalls=100 : minimum total recalls for signal}
        {--min-actor-recalls=10 : minimum per-actor recalls to qualify}
        {--json : print machine-readable JSON}';

    protected $description = 'ASI-12 v2 multi-actor recall concentration (informative; v1 unchanged).';

    public function handle(AtlasMemoryRecallConcentrationV2Reader $reader): int
    {
        $result = $reader->read([
            'window_days' => (int) $this->option('window-days'),
            'min_recalls' => (int) $this->option('min-recalls'),
            'min_actor_recalls' => (int) $this->option('min-actor-recalls'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'concentration-v2 status=%s total=%d actors=%d top_share_global=%.4f top_share_max_per_actor=%.4f',
            (string) ($result['status'] ?? 'unknown'),
            (int) ($result['total_usages'] ?? 0),
            is_array($result['actors'] ?? null) ? count($result['actors']) : 0,
            (float) ($result['aggregated']['top_share_global'] ?? 0.0),
            (float) ($result['aggregated']['top_share_max_per_actor'] ?? 0.0),
        ));

        return self::SUCCESS;
    }
}
