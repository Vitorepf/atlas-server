<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalEvidenceReplayService;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasSelfConstructionFinalEvidenceReplayService::replay()} at the operator
 * surface: reads a self-construction final-evidence bundle JSON and emits the deterministic completion re-check
 * verdict (status passed/blocked, replay_green, violation_count, violations, expected_bundle_hash).
 *
 * Strictly read-only: the service runs in MODE read_only_final_evidence_replay with every *_allowed flag false,
 * so this command performs NO execution, dispatch, provider call, or token spend — it only replays the bundle
 * and reports whether the completion bundle is internally consistent and safe.
 */
final class AtlasLoopFinalEvidenceReplayCommand extends Command
{
    protected $signature = 'atlas:loop:final-evidence-replay {--bundle=} {--json}';

    protected $description = 'Read-only replay of a self-construction final-evidence bundle (completion re-check verdict).';

    public function handle(): int
    {
        $bundlePath = trim((string) $this->option('bundle'));
        if ($bundlePath === '' || ! is_file($bundlePath) || ! is_readable($bundlePath)) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'final-evidence-replay requires --bundle=<path to a readable bundle JSON>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $bundle = json_decode((string) file_get_contents($bundlePath), true);
        if (! is_array($bundle)) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'bundle file is not a JSON object',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $verdict = app(AtlasSelfConstructionFinalEvidenceReplayService::class)->replay($bundle);

        if ($this->option('json')) {
            $this->line((string) json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('status: '.$verdict['status'].'  replay_green: '.($verdict['replay_green'] ? 'yes' : 'no'));
            $this->line('violations: '.$verdict['violation_count']);
        }

        return self::SUCCESS;
    }
}
