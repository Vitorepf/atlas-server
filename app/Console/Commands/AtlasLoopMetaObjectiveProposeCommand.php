<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Antifragile\AtlasLoopMetaObjectiveProposer;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Console\Command;
use Throwable;

/**
 * Arms the dormant {@see AtlasLoopMetaObjectiveProposer::propose()} at the operator surface: gathers the
 * capability-Δ attribution-by-shape prior (from the serving-store) and the pétreo forbidden-self-targets (from
 * {@see AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS}), runs propose(), and emits its deterministic
 * meta-objective proposals. Read-only: it PROPOSES only — it NEVER enqueues, commits, or grants its own
 * go-ahead. The attribution prior comes from an injectable seam (best-effort serving-store read by default).
 */
final class AtlasLoopMetaObjectiveProposeCommand extends Command
{
    /** Container key for an injected attribution-by-shape prior (test/integration seam): list<array>|callable():list<array>. */
    private const ATTRIBUTION_BINDING = 'atlas.loop.meta_objective.attribution_by_shape';

    protected $signature = 'atlas:loop:meta-objective-propose {--json}';

    protected $description = 'Read-only meta-objective proposals from the credited capability-Δ shapes (never enqueues).';

    public function handle(AtlasLoopMetaObjectiveProposer $proposer): int
    {
        $proposals = $proposer->propose($this->attributionByShape(), AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS);

        $this->line((string) json_encode($proposals, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function attributionByShape(): array
    {
        $app = $this->getLaravel();
        if ($app->bound(self::ATTRIBUTION_BINDING)) {
            $bound = $app->make(self::ATTRIBUTION_BINDING);
            if (is_callable($bound)) {
                $bound = $bound();
            }
            if (is_array($bound)) {
                return array_values(array_filter($bound, 'is_array'));
            }
        }

        return $this->fromServingStore();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fromServingStore(): array
    {
        try {
            $byShape = [];
            foreach (['resolved', 'released'] as $status) {
                foreach (AtlasTaskServingStack::queueRepo()->list(['status' => $status]) as $row) {
                    foreach ((array) (data_get($row, 'capability_attribution.by_shape') ?? []) as $entry) {
                        if (is_array($entry)) {
                            $byShape[] = $entry;
                        }
                    }
                }
            }

            return $byShape;
        } catch (Throwable) {
            return [];
        }
    }
}
