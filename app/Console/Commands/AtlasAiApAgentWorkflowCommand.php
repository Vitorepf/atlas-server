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
        $this->components->twoColumnDetail('Default post-completion table', 'AP-206..'.(string) data_get($workflow, 'summary.post_completion_review_chain.human_display_until_ap'));
        $this->components->twoColumnDetail('Extended steps hidden', (string) data_get($workflow, 'summary.post_completion_review_chain.hidden_in_default_human_output_count'));
        $this->components->twoColumnDetail('Next action', (string) $workflow['next_action']);

        $displayUntil = (string) data_get($workflow, 'summary.post_completion_review_chain.human_display_until_ap', 'AP-228');
        $displayUntilNumber = (int) preg_replace('/\D+/', '', $displayUntil);
        $visiblePostCompletionSteps = collect($workflow['post_completion_review_chain'])
            ->filter(fn (array $step): bool => (int) preg_replace('/\D+/', '', (string) $step['ap']) <= $displayUntilNumber);

        $this->table(
            ['ap', 'component', 'purpose'],
            collect($workflow['steps'])
                ->merge($visiblePostCompletionSteps)
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
