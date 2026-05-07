<?php

namespace App\Console\Commands;

use App\Services\Ai\Domain\LearningPlanService;
use Illuminate\Console\Command;

class AtlasStudyCommand extends Command
{
    protected $signature = 'atlas:study
        {topic : Topic or skill to study}
        {--objective= : Learning objective}
        {--target-level=can_apply_with_evidence : Target level}
        {--current-level=auto : Operator current level hint}
        {--dreyfus-stage=auto : Explicit Dreyfus stage 1..5 or auto}
        {--flow=learning.practice : Learning flow}
        {--json : Print machine-readable JSON}';

    protected $description = 'Generate a governed Atlas study packet with Dreyfus Dynamic Pedagogy.';

    public function handle(LearningPlanService $learning): int
    {
        $topic = trim((string) $this->argument('topic'));
        $flow = trim((string) $this->option('flow')) ?: 'learning.practice';
        $packet = $learning->packet($flow, [
            'objective' => trim((string) ($this->option('objective') ?: 'Study '.$topic.' with calibrated practice.')),
            'topic' => $topic,
            'current_level' => trim((string) $this->option('current-level')),
            'target_level' => trim((string) $this->option('target-level')),
            'dreyfus_stage_target' => trim((string) $this->option('dreyfus-stage')),
            'surface_id' => 'atlas_study_cli',
        ]);

        $payload = [
            'schema_version' => 'atlas.study.cli.v1',
            'status' => 'ok',
            'flow' => $flow,
            'topic' => $topic,
            'dreyfus' => $packet['dreyfus'] ?? [],
            'packet' => $packet,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Study', (string) data_get($payload, 'dreyfus.resolution.pedagogy_mode_resolved', 'unknown'));
        $this->components->twoColumnDetail('Topic', $topic);
        $this->components->twoColumnDetail('Dreyfus stage', (string) data_get($payload, 'dreyfus.resolution.dreyfus_stage_resolved', 'unknown'));
        $this->components->twoColumnDetail('Confidence', (string) data_get($payload, 'dreyfus.resolution.confidence', 'unknown'));
        $this->line('Rules: '.implode(', ', (array) data_get($payload, 'dreyfus.prompt_policy.rules', [])));

        return self::SUCCESS;
    }
}
