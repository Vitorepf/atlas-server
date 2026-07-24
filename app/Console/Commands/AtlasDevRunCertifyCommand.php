<?php

namespace App\Console\Commands;

use App\Models\AtlasDevRunCertification;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevRunCertificationService;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasDevRunCertifyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:dev:run-certify
        {--run= : Dev run id}
        {--task= : Dev task id}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Emit the latest Atlas Dev run certification for a run/task.';

    public function handle(): int
    {
        if (! DatabaseTableAvailability::has('atlas_dev_run_certifications')) {
            $payload = $this->missingPayload('Atlas Dev run certification table is not migrated.');

            if ((bool) $this->option('json')) {
                $this->line($this->encode($payload));
            } else {
                $this->line('Atlas Dev Run Certification: blocked');
                $this->line('Reason: Atlas Dev run certification table is not migrated.');
            }

            return self::FAILURE;
        }

        $query = AtlasDevRunCertification::query()->latest('created_at');

        if (is_string($this->option('run')) && trim((string) $this->option('run')) !== '') {
            $query->where('run_id', trim((string) $this->option('run')));
        }

        if (is_string($this->option('task')) && trim((string) $this->option('task')) !== '') {
            $query->where('task_id', trim((string) $this->option('task')));
        }

        $certification = $query->first();
        $payload = $certification === null ? $this->missingPayload('No Atlas Dev run certification found for the requested run/task.') : [
            'schema_version' => 'atlas.dev.run_certify_command.v1',
            'status' => $certification->status,
            'run_id' => $certification->run_id,
            'task_id' => $certification->task_id,
            'summary' => $certification->summary,
            'checks' => $certification->checks,
            'blockers' => $certification->blockers,
            'certification_hash' => $certification->certification_hash,
            'source' => 'atlas_dev_run_certifications',
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line('Atlas Dev Run Certification: '.($payload['status'] ?? 'missing'));
            $this->line('Run: '.($payload['run_id'] ?? 'none'));
            $this->line('Task: '.($payload['task_id'] ?? 'none'));
        }

        return ($payload['status'] ?? DevRunCertificationService::STATUS_BLOCKED) === DevRunCertificationService::STATUS_READY
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function missingPayload(string $reason): array
    {
        return [
            'schema_version' => 'atlas.dev.run_certify_command.v1',
            'status' => DevRunCertificationService::STATUS_BLOCKED,
            'summary' => ['total' => 1, 'pass' => 0, 'fail' => 1],
            'checks' => [[
                'id' => 'run_certification_present',
                'status' => 'fail',
                'reason' => $reason,
            ]],
            'blockers' => [[
                'id' => 'run_certification_present',
                'status' => 'fail',
                'reason' => $reason,
            ]],
        ];
    }
}
