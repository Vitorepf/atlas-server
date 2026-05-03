<?php

namespace App\Console\Commands;

use App\Models\AtlasProject;
use App\Services\Engineering\EngineeringProjectBlueprintService;
use App\Services\Engineering\EngineeringTaskGenerationService;
use Illuminate\Console\Command;

class AtlasProjectTasksGenerateCommand extends Command
{
    protected $signature = 'atlas:project:tasks:generate {--project-id=} {--from-blueprint=} {--force} {--json}';

    protected $description = 'Generate engineering tasks from a frozen project blueprint.';

    public function handle(EngineeringProjectBlueprintService $blueprints, EngineeringTaskGenerationService $tasks): int
    {
        $projectId = trim((string) $this->option('project-id'));
        $project = $projectId !== '' ? AtlasProject::query()->find($projectId) : null;
        if (! $project) {
            $this->error('--project-id precisa apontar para um projeto existente.');

            return self::FAILURE;
        }

        $record = $blueprints->latest($project, 'frozen');
        $version = $this->option('from-blueprint');
        if (is_numeric($version)) {
            $record = $project->engineeringProjectBlueprints()
                ->where('version', (int) $version)
                ->where('status', 'frozen')
                ->first();
        }
        if (! $record) {
            $this->error('Blueprint congelado nao encontrado.');

            return self::FAILURE;
        }

        $payload = $tasks->generate($record, ['force' => (bool) $this->option('force')]);
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Tasks criadas', (string) $payload['created_count']);
            $this->components->twoColumnDetail('Tasks atualizadas', (string) $payload['updated_count']);
            $this->components->twoColumnDetail('Tasks preservadas', (string) $payload['skipped_count']);
        }

        return self::SUCCESS;
    }
}
