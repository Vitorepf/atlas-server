<?php

namespace App\Console\Commands;

use App\Models\AtlasProject;
use App\Services\Engineering\EngineeringProjectBlueprintService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasProjectBlueprintPrepareCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:project:blueprint:prepare {--project-id=} {--json}';

    protected $description = 'Prepare a project-level engineering blueprint draft without persisting it.';

    public function handle(EngineeringProjectBlueprintService $blueprints): int
    {
        $project = $this->project();
        if (! $project) {
            return self::FAILURE;
        }

        return $this->render($blueprints->prepare($project));
    }

    protected function project(): ?AtlasProject
    {
        $projectId = trim((string) $this->option('project-id'));
        if ($projectId === '') {
            $this->error('--project-id e obrigatorio.');

            return null;
        }

        $project = AtlasProject::query()->find($projectId);
        if (! $project) {
            $this->error("Projeto nao encontrado: {$projectId}");
        }

        return $project;
    }

    protected function render(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->components->twoColumnDetail('Project blueprint', (string) data_get($payload, 'validation.status', 'unknown'));
            foreach ((array) data_get($payload, 'validation.errors', []) as $error) {
                $this->warn((string) ($error['message'] ?? 'Coverage incompleto.'));
            }
        }

        return data_get($payload, 'validation.blocking') ? self::FAILURE : self::SUCCESS;
    }
}
