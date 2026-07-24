<?php

namespace App\Console\Commands;

use App\Services\Ai\Router\AtlasAiHyperflowCertificationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAiHyperflowCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:hyperflow
        {action=certify : certify}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run Atlas Hyperflow backend certification gates without calling external providers.';

    public function handle(AtlasAiHyperflowCertificationService $certification): int
    {
        $action = strtolower(trim((string) $this->argument('action')));

        $payload = match ($action) {
            'certify', 'certification' => [
                'schema_version' => 'atlas.ai.hyperflow_command.v1',
                'action' => 'certify',
                'certification' => $certification->certify(),
                'writes' => false,
            ],
            default => [
                'schema_version' => 'atlas.ai.hyperflow_command.v1',
                'action' => $action,
                'status' => 'failed',
                'error' => 'unsupported_action',
                'supported_actions' => ['certify'],
                'writes' => false,
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $this->exitCodeFor($action, $payload);
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Hyperflow</>', $action);
        if (in_array($action, ['certify', 'certification'], true)) {
            $this->components->twoColumnDetail('Certification', (string) data_get($payload, 'certification.status', 'unknown'));
            $this->components->twoColumnDetail('Failed checks', (string) data_get($payload, 'certification.summary.failed', 0));
            foreach ((array) data_get($payload, 'certification.remaining_blockers', []) as $blocker) {
                $this->warn((string) $blocker);
            }
        } else {
            $this->error('Unsupported action: '.$action);
        }

        return $this->exitCodeFor($action, $payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCodeFor(string $action, array $payload): int
    {
        return match ($action) {
            'certify', 'certification' => data_get($payload, 'certification.status') === 'passed' ? self::SUCCESS : self::FAILURE,
            default => self::FAILURE,
        };
    }
}
