<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMultiSiteWiringPlanner;
use Closure;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopMultiSiteWiringPlanner::plan()} at the operator surface: emits the loop's
 * multi-site wiring audit — per primitive × intended consumer, whether the consumer source already references
 * the primitive (status wired) or not yet (status intended) — as deterministic facts. Pure (grep-of-record
 * only), read-only. Flag-gated default-OFF ⇒ empty. --primitives overrides the resolver; default = the real
 * cross-leverage registry.
 *
 * --primitives accepts inline JSON or a path to a JSON file (a list of primitive descriptors).
 */
final class AtlasLoopWiringPlanCommand extends Command
{
    protected $signature = 'atlas:loop:wiring-plan {--primitives=} {--json}';

    protected $description = 'Read-only multi-site wiring audit (per primitive+consumer: wired vs intended).';

    public function handle(): int
    {
        $resolver = null;
        $primitivesOption = $this->option('primitives');
        if ($primitivesOption !== null && trim((string) $primitivesOption) !== '') {
            $raw = is_file((string) $primitivesOption) ? (string) file_get_contents((string) $primitivesOption) : (string) $primitivesOption;
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                $this->line((string) json_encode(['status' => 'invalid_json', 'primitives' => (string) $primitivesOption], JSON_UNESCAPED_SLASHES));

                return self::INVALID;
            }
            $list = array_values(array_filter($decoded, 'is_array'));
            $resolver = static fn (): array => $list;
        }

        $records = (new AtlasLoopMultiSiteWiringPlanner(null, $resolver instanceof Closure ? $resolver : null, null))->plan();

        $this->line((string) json_encode([
            'schema' => 'atlas.loop.multi_site_wiring_plan.v1',
            'count' => count($records),
            'records' => $records,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
