<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodeMultiProjectClaudeOneShotPromptService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Atlas Code Multi-Project one-shot prompt
 * admission gate.
 *
 * With safe defaults (an empty plan against a project with no resolved workspace)
 * the gate must REJECT: an empty plan satisfies none of the ten "Critérios de
 * aceite". This proves the doc's admission contract is live — an unprepared plan
 * cannot be admitted.
 *
 * @see docs/engineering-knowledge-base/atlas-code-multi-project-claude-one-shot-prompt.md
 */
class AtlasCodeMultiProjectClaudeOneShotPromptCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-code-multi-project-claude-one-shot-prompt {--json : Print machine-readable JSON}';

    protected $description = 'Render the deterministic admit/reject gate for an external agent Atlas Code multi-project one-shot implementation plan.';

    public function handle(AtlasCodeMultiProjectClaudeOneShotPromptService $service): int
    {
        try {
            // Safe defaults: no active workspace, empty plan. The contract mandates rejection.
            $payload = $service->admitPlan([], []);
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasCodeMultiProjectClaudeOneShotPromptService::SCHEMA_VERSION,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('decision', (string) $payload['decision']);
        $this->components->twoColumnDetail('admitted', $payload['admitted'] ? 'true' : 'false');
        $this->components->twoColumnDetail('work_type', (string) $payload['work_type']);
        $this->components->twoColumnDetail('execution_allowed', $payload['execution_allowed'] ? 'true' : 'false');
        $this->components->twoColumnDetail('effective_risk', (string) $payload['effective_risk']);
        $this->components->twoColumnDetail(
            'acceptance_criteria',
            $payload['acceptance_ratio']['satisfied'] . '/' . $payload['acceptance_ratio']['total']
        );
        $this->components->twoColumnDetail('blocking_violations', (string) count($payload['blocking_violations']));
        $this->components->twoColumnDetail('required_next_action', (string) $payload['required_next_action']);

        return self::SUCCESS;
    }
}
