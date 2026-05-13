<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasStructureMotherAuditReadModel;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Console\Command;

class AtlasAiStructureMotherAuditCommand extends Command
{
    protected $signature = 'atlas:ai:structure-mother-audit
        {--hours=720 : Window size in hours}
        {--workspace= : Workspace path}
        {--json : Print machine-readable JSON}';

    protected $description = 'Audit the eight Atlas AI structure-mother modules without mutating runtime, memory or provider state.';

    public function handle(AtlasStructureMotherAuditReadModel $audit, KernelReplayReportInput $input): int
    {
        $hours = $input->hours($this->option('hours'));
        $payload = [
            'status' => 'ok',
            'structure_mother_audit' => $audit->report([
                'hours' => $hours,
                'workspace' => $this->option('workspace'),
            ]),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $report = $payload['structure_mother_audit'];
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Structure Mother Audit</>', (string) $report['status']);
        $this->components->twoColumnDetail('Complete', ((bool) $report['complete']) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Ready modules', (string) data_get($report, 'summary.ready_count', 0));
        $this->components->twoColumnDetail('Attention modules', (string) data_get($report, 'summary.attention_count', 0));
        $this->components->twoColumnDetail('Blocked modules', (string) data_get($report, 'summary.blocked_count', 0));
        $this->components->twoColumnDetail('Qualitative level', (string) data_get($report, 'summary.qualitative_level', 'unknown'));
        $this->components->twoColumnDetail('Completion gate', (string) data_get($report, 'completion_gate.status', 'unknown'));

        $this->table(
            ['module', 'implementation', 'operational', 'blockers'],
            collect((array) ($report['modules'] ?? []))
                ->map(fn (array $module): array => [
                    $module['label'] ?? $module['id'] ?? '-',
                    $module['implementation_status'] ?? $module['status'] ?? 'unknown',
                    $module['operational_status'] ?? 'unknown',
                    implode(', ', (array) ($module['operational_blockers'] ?? $module['blockers'] ?? [])) ?: '-',
                ])
                ->all(),
        );

        $this->renderOperatorActionPlan((array) data_get($report, 'operator_action_plan', []));

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function renderOperatorActionPlan(array $plan): void
    {
        $actions = collect((array) ($plan['actions'] ?? []));
        if ($actions->isEmpty()) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=green;options=bold>Operator actions</>', 'clear');

            return;
        }

        $summary = (array) ($plan['action_summary'] ?? []);
        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow;options=bold>Operator actions</>', (string) ($plan['status'] ?? 'pending'));
        $this->components->twoColumnDetail('Actionable now', (string) ($summary['actionable_now_count'] ?? $actions->where('actionable_now', true)->count()));
        $this->components->twoColumnDetail('Calendar wait', (string) ($summary['calendar_wait_count'] ?? $actions->where('calendar_wait_required', true)->count()));
        $this->components->twoColumnDetail('External effect', (string) ($summary['external_effect_action_count'] ?? $actions->where('external_notification_possible', true)->count()));
        if ($dueAt = $summary['next_calendar_due_at'] ?? null) {
            $this->components->twoColumnDetail('Next calendar due', (string) $dueAt);
        }

        $this->table(
            ['id', 'type', 'when', 'safe command / endpoint'],
            $actions->map(fn (array $action): array => [
                (string) ($action['id'] ?? '-'),
                (string) ($action['type'] ?? '-'),
                $this->operatorActionWhen($action),
                $this->operatorActionPrimaryCommand($action),
            ])->all(),
        );

        $rules = (array) ($plan['rules'] ?? []);
        $ruleLines = collect($rules)
            ->filter(fn (mixed $value): bool => $value === true)
            ->keys()
            ->map(fn (mixed $key): string => (string) $key)
            ->values()
            ->all();

        if ($ruleLines !== []) {
            $this->line('<fg=gray>Safety rules: '.implode('; ', $ruleLines).'</>');
        }
    }

    /**
     * @param  array<string,mixed>  $action
     */
    private function operatorActionWhen(array $action): string
    {
        if ((bool) ($action['actionable_now'] ?? false)) {
            return 'now';
        }

        if ((bool) ($action['calendar_wait_required'] ?? false)) {
            return 'wait until '.(string) ($action['due_at'] ?? 'calendar due');
        }

        return 'pending';
    }

    /**
     * @param  array<string,mixed>  $action
     */
    private function operatorActionPrimaryCommand(array $action): string
    {
        foreach ([
            'dry_run_command',
            'command',
            'review_command',
        ] as $key) {
            if (is_string($action[$key] ?? null) && $action[$key] !== '') {
                return $action[$key];
            }
        }

        $itemCommands = (array) data_get($action, 'item_commands.0', []);
        foreach (['show', 'discuss', 'mark_read_after_review'] as $key) {
            if (is_string($itemCommands[$key] ?? null) && $itemCommands[$key] !== '') {
                return $itemCommands[$key];
            }
        }

        $apiMethod = data_get($action, 'api.method') ?? data_get($action, 'api.review.method') ?? data_get($action, 'api.respond.method');
        $apiEndpoint = data_get($action, 'api.endpoint') ?? data_get($action, 'api.review.endpoint') ?? data_get($action, 'api.respond.endpoint_template');
        if (is_string($apiMethod) && is_string($apiEndpoint)) {
            return $apiMethod.' '.$apiEndpoint;
        }

        return '-';
    }
}
