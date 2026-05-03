<?php

namespace App\Console\Commands;

use App\Services\Engineering\EngineeringProjectBlueprintService;

class AtlasProjectBlueprintCreateCommand extends AtlasProjectBlueprintPrepareCommand
{
    protected $signature = 'atlas:project:blueprint:create {--project-id=} {--created-by=atlas_cli} {--json}';

    protected $description = 'Create a persisted draft project-level engineering blueprint.';

    public function handle(EngineeringProjectBlueprintService $blueprints): int
    {
        $project = $this->project();
        if (! $project) {
            return self::FAILURE;
        }

        return $this->render($blueprints->create($project, [
            'created_by' => (string) $this->option('created-by'),
        ]));
    }
}
