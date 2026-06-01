<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasResearchAutomationRunbookService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Research Self-Improvement Automation Runbook
 * doc. With no args it renders the runbook contract snapshot: the 9-stage
 * activation order, the 10 required guards before any scheduler, the 6 forbidden
 * first versions, and the read-only first-implementation modes. Default posture
 * mirrors the doc — nothing activated yet, so only stage 1 may turn on and the
 * scheduler gate is closed.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/automation-runbook.md
 */
class AtlasResearchAutomationRunbookCommand extends Command
{
    protected $signature = 'atlas:aaeos:research-automation-runbook {--json : Print machine-readable JSON}';

    protected $description = 'Render the read-only research-automation runbook contract (9-stage activation order, 10 scheduler guards, 6 forbidden first versions, read-only-first).';

    public function handle(AtlasResearchAutomationRunbookService $service): int
    {
        try {
            // Safe default: no operator overrides, so we render the canonical
            // fail-closed snapshot exactly as the doc states.
            $payload = $service->snapshot();
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasResearchAutomationRunbookService::RECEIPT_SCHEMA,
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
        $this->components->twoColumnDetail('stage_count', (string) $payload['stage_count']);
        $this->components->twoColumnDetail('first_scheduled_step', (string) $payload['first_scheduled_step']);
        $this->components->twoColumnDetail('required_guard_count', (string) $payload['required_guard_count']);
        $this->components->twoColumnDetail('next_activatable_step', (string) $payload['next_activatable_step']);
        $this->components->twoColumnDetail('scheduler_gate', $payload['scheduler_gate'] ? 'open' : 'closed');
        $this->components->twoColumnDetail('forbidden_first_versions', implode(', ', $payload['forbidden_first_versions']));

        return self::SUCCESS;
    }
}
