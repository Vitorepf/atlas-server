<?php

namespace App\Console\Commands;

use App\Services\Engineering\EngineeringProjectBlueprintService;

class AtlasProjectBlueprintValidateCommand extends AtlasProjectBlueprintPrepareCommand
{
    protected $signature = 'atlas:project:blueprint:validate {--project-id=} {--blueprint-version=} {--json}';

    protected $description = 'Validate project-level engineering blueprint coverage.';

    public function handle(EngineeringProjectBlueprintService $blueprints): int
    {
        $project = $this->project();
        if (! $project) {
            return self::FAILURE;
        }

        return $this->render($blueprints->validateProject($project, [
            'version' => $this->option('blueprint-version'),
        ]));
    }
}
