<?php

namespace App\Console\Commands;

use App\Services\Engineering\EngineeringProjectBlueprintService;
use Illuminate\Validation\ValidationException;

class AtlasProjectBlueprintFreezeCommand extends AtlasProjectBlueprintPrepareCommand
{
    protected $signature = 'atlas:project:blueprint:freeze
        {--project-id=}
        {--blueprint-version=}
        {--exception-reason= : Human exception reason when coverage is incomplete}
        {--approved-by=atlas_cli}
        {--json}';

    protected $description = 'Freeze a project-level engineering blueprint after coverage validation.';

    public function handle(EngineeringProjectBlueprintService $blueprints): int
    {
        $project = $this->project();
        if (! $project) {
            return self::FAILURE;
        }

        try {
            return $this->render($blueprints->freeze($project, [
                'version' => $this->option('blueprint-version'),
                'exception_reason' => $this->option('exception-reason'),
                'approved_by' => $this->option('approved-by'),
            ]));
        } catch (ValidationException $exception) {
            $payload = [
                'status' => 'failed',
                'errors' => $exception->errors(),
            ];
            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                foreach ($exception->errors() as $messages) {
                    foreach ((array) $messages as $message) {
                        $this->error((string) $message);
                    }
                }
            }

            return self::FAILURE;
        }
    }
}
