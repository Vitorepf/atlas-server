<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodeMultiProjectClaudeGoalPromptService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Atlas Code Multi-Project `/goal` acceptance gate.
 *
 * With safe defaults (an empty agent report) the gate must REJECT: an empty
 * report has no changed files, no validations, no risks and no next steps, so it
 * fails every "Regras para IA" rule and the full Definition of done. This proves
 * the doc's contract is live — a dishonest "done" with no evidence cannot pass.
 *
 * @see docs/engineering-knowledge-base/atlas-code-multi-project-claude-goal-prompt.md
 */
class AtlasCodeMultiProjectClaudeGoalPromptCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-code-multi-project-claude-goal-prompt {--json : Print machine-readable JSON}';

    protected $description = 'Render the deterministic accept/reject gate for an external agent Atlas Code multi-project /goal closing report.';

    public function handle(AtlasCodeMultiProjectClaudeGoalPromptService $service): int
    {
        try {
            // Safe default: an empty final report. The contract mandates rejection.
            $payload = $service->evaluateGoalReport([]);
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasCodeMultiProjectClaudeGoalPromptService::SCHEMA_VERSION,
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
        $this->components->twoColumnDetail('accepted', $payload['accepted'] ? 'true' : 'false');
        $this->components->twoColumnDetail(
            'definition_of_done',
            $payload['definition_of_done_ratio']['satisfied'] . '/' . $payload['definition_of_done_ratio']['total']
        );
        $this->components->twoColumnDetail('rejection_rules_failed', (string) count($payload['rejection_rules_failed']));
        $this->components->twoColumnDetail('blocking_violations', (string) count($payload['blocking_violations']));
        $this->components->twoColumnDetail('required_next_action', (string) $payload['required_next_action']);

        return self::SUCCESS;
    }
}
