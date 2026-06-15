<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopFeatureObjectiveBuilder;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Console\Command;

/**
 * Lever 3 — feature lane entry seam. The operator specifies WHAT (a feature + its frozen acceptance
 * test, in natural terms); the loop implements the HOW autonomously through the ADEP grind
 * (iterate-to-green on the spec test, then cert via the gate + diff_earned + adversarial panel). This
 * is the Atlas thesis applied to features: a human states the goal, the loop builds it — no human in
 * the implementation loop. (Fully-autonomous feature DISCOVERY — the loop choosing what to build — is
 * a separate, carefully-gated follow-on; this safe seam proves the lane end-to-end first.)
 */
final class AtlasLoopSeedFeatureCommand extends Command
{
    protected $signature = 'atlas:loop:seed-feature
        {--campaign-id= : The running campaign to enqueue the feature into (required)}
        {--name= : Short feature name (required)}
        {--spec= : Natural-language description of the behavior to build (required)}
        {--test= : Repo-relative path to the FROZEN acceptance test that pins the feature (required, must currently be RED)}
        {--files=* : Repo-relative implementation file(s) the provider may edit/CREATE (required, >=1)}
        {--provider= : Pin a provider (default: loop default / routing)}
        {--priority=120 : Queue priority}
        {--json : Print the canonical JSON result}';

    protected $description = 'Seed a FEATURE task: the loop autonomously implements the described behavior until the frozen acceptance test (RED now) goes GREEN (diff_earned), via the ADEP grind.';

    public function handle(AtlasLoopStore $store): int
    {
        $campaignId = trim((string) $this->option('campaign-id'));
        $name = trim((string) $this->option('name'));
        $spec = trim((string) $this->option('spec'));
        $test = trim((string) $this->option('test'));
        $files = array_values(array_filter(array_map('trim', (array) $this->option('files'))));
        $provider = trim((string) ($this->option('provider') ?: ''));

        $missing = [];
        foreach (['campaign-id' => $campaignId, 'name' => $name, 'spec' => $spec, 'test' => $test] as $k => $v) {
            if ($v === '') {
                $missing[] = $k;
            }
        }
        if ($files === []) {
            $missing[] = 'files';
        }
        if ($missing !== []) {
            $this->error('Missing required option(s): '.implode(', ', $missing));

            return self::INVALID;
        }

        $campaign = AtlasLoopCampaign::query()->find($campaignId);
        if (! $campaign instanceof AtlasLoopCampaign) {
            $this->error('Campaign not found: '.$campaignId);

            return self::FAILURE;
        }

        $built = (new AtlasLoopFeatureObjectiveBuilder())->build($name, $spec, $test, $files, $provider !== '' ? $provider : null);

        $task = $store->enqueueTask(
            (string) $campaign->id,
            $built['objective'],
            $built['payload'],
            'operator_seed:feature',
            $files[0],
            max(1, (int) $this->option('priority')),
            false, // framework/multi-file feature, not a self-contained plain-php grind
            $built['acceptance_hash'],
        );

        $out = [
            'schema_version' => 'atlas.loop.seed_feature.v1',
            'enqueued' => $task !== null,
            'task_id' => $task?->id,
            'campaign_id' => (string) $campaign->id,
            'feature' => $name,
            'acceptance_test' => $test,
            'allowed_files' => $files,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info($task !== null
                ? "Feature '{$name}' enqueued (task {$task->id}); the loop will implement it until {$test} goes GREEN."
                : "Feature '{$name}' was NOT enqueued (dedupe or race).");
        }

        return $task !== null ? self::SUCCESS : self::FAILURE;
    }
}
