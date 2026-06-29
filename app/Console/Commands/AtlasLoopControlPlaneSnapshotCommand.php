<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3CrossScopeTransferProbe;
use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3GraderPromotionGate;
use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3MetaLeverRecommender;
use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3MetaStrategyDistiller;
use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3SelfConstructionControlPlane;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopV3SelfConstructionControlPlane::snapshot()} at the operator surface from a
 * SUPPLIED input (candidate_graders / lever_readings / campaign_ids / transfer_jobs), emitting the composed
 * control-plane snapshot — promotable graders, lever recommendation, distilled strategy, ready transfers and
 * the consolidated ready_actions — as deterministic facts. Read-only: the input is supplied; it spawns nothing.
 *
 * --input accepts inline JSON or a path to a JSON file.
 */
final class AtlasLoopControlPlaneSnapshotCommand extends Command
{
    /** Optional injected strategy-outcome ledger (duck-typed read/outcomes); default = no-op. */
    private const LEDGER_BINDING = 'atlas.loop.v3.strategy_ledger';

    protected $signature = 'atlas:loop:control-plane-snapshot {--input=} {--json}';

    protected $description = 'Read-only V3 self-construction control-plane snapshot from a supplied input.';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'input_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }
        if (! is_file($input)) {
            $this->line((string) json_encode(['status' => 'input_not_found', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $payload = json_decode((string) file_get_contents($input), true);
        if (! is_array($payload)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $this->line((string) json_encode(
            $this->controlPlane()->snapshot($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }

    private function controlPlane(): AtlasLoopV3SelfConstructionControlPlane
    {
        $app = $this->getLaravel();
        $ledger = $app->bound(self::LEDGER_BINDING)
            ? $app->make(self::LEDGER_BINDING)
            : new class
            {
                /** @return list<array<string,mixed>> */
                public function read(string $campaignId): array
                {
                    return [];
                }
            };

        return new AtlasLoopV3SelfConstructionControlPlane(
            $app->make(AtlasLoopV3GraderPromotionGate::class),
            $app->make(AtlasLoopV3MetaLeverRecommender::class),
            new AtlasLoopV3MetaStrategyDistiller($ledger),
            $app->make(AtlasLoopV3CrossScopeTransferProbe::class),
        );
    }
}
