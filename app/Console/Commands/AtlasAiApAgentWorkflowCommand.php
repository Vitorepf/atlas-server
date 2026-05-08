<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowRegistry;
use Illuminate\Console\Command;

class AtlasAiApAgentWorkflowCommand extends Command
{
    protected $signature = 'atlas:ai:ap-agent-workflow
        {--json : Print machine-readable JSON}';

    protected $description = 'Show the canonical read-only AP agent workflow registry.';

    public function handle(AtlasApAgentWorkflowRegistry $registry): int
    {
        $payload = [
            'status' => 'ok',
            'ap_agent_workflow' => $registry->workflow(),
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $workflow = $payload['ap_agent_workflow'];

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AP Agent Workflow</>', (string) $workflow['status']);
        $this->components->twoColumnDetail('Workflow', (string) $workflow['workflow_id']);
        $this->components->twoColumnDetail('Primary steps', (string) $workflow['step_count']);
        $this->components->twoColumnDetail('Post-completion steps', (string) count((array) $workflow['post_completion_review_chain']));
        $this->components->twoColumnDetail('Next action', (string) $workflow['next_action']);

        $this->table(
            ['ap', 'component', 'purpose'],
            collect($workflow['steps'])
                ->merge((array) $workflow['post_completion_review_chain'])
                ->map(fn (array $step): array => [
                    $step['ap'],
                    $step['component'],
                    $step['purpose'],
                ])
                ->all(),
        );

        return self::SUCCESS;
    }
}
