<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Skills\AtlasSkillEvolutionRuntimeService;
use Illuminate\Console\Command;

final class AtlasSkillEvolutionCommand extends Command
{
    protected $signature = 'atlas:skills:evolve
        {action=propose : propose|refactor-plan}
        {--workspace= : Workspace path}
        {--objective= : Outcome objective or skill improvement goal}
        {--summary= : Outcome summary}
        {--domain=programming : Domain}
        {--flow-id=atlas_dev : Flow id}
        {--skill= : Skill name for refactor-plan}
        {--evidence=* : Evidence refs}
        {--json : Emit JSON}';

    protected $description = 'Propose or refactor Atlas skills from verified outcomes without auto-installing them.';

    public function handle(AtlasSkillEvolutionRuntimeService $runtime): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'propose' => $runtime->propose([
                'workspace' => $this->option('workspace'),
                'objective' => $this->option('objective'),
                'summary' => $this->option('summary'),
                'domain' => $this->option('domain'),
                'flow_id' => $this->option('flow-id'),
                'evidence_refs' => $this->option('evidence'),
            ]),
            'refactor-plan' => $runtime->refactorPlan([
                'workspace' => $this->option('workspace'),
                'skill' => $this->option('skill'),
            ]),
            default => [
                'schema_version' => 'atlas.skill_evolution.command_error.v1',
                'status' => 'blocked',
                'blockers' => [['id' => 'unknown_action', 'reason' => 'Use propose or refactor-plan.']],
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('Atlas Skill Evolution', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Schema', (string) ($payload['schema_version'] ?? 'unknown'));
            $this->components->twoColumnDetail('Hash', (string) ($payload['proposal_hash'] ?? $payload['refactor_plan_hash'] ?? '-'));
        }

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
