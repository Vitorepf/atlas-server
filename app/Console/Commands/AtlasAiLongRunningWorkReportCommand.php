<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use App\Services\Ai\Scheduling\LongRunningWorkReadModel;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAiLongRunningWorkReportCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:long-running-work-report
        {--hours=24 : Window size in hours}
        {--emit-baseline-inbox : Emit a proposal Inbox item for human review of the missing structure-mother schedule baseline}
        {--json : Print machine-readable JSON}';

    protected $description = 'Summarize Atlas scheduled long-running work and autonomy receipts without dispatching jobs.';

    public function handle(LongRunningWorkReadModel $readModel, KernelReplayReportInput $input, ProposalInboxEmitter $inbox): int
    {
        $hours = $input->hours($this->option('hours'));
        $report = $readModel->report(now()->subHours($hours));
        $payload = [
            'status' => ($report['available'] ?? false) ? 'ok' : 'storage_unavailable',
            'hours' => $hours,
            'long_running_work' => $report,
            'emitted_baseline_inbox_item' => $this->emitBaselineInbox($report, $inbox),
        ];

        return $this->render($payload);
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>|null
     */
    private function emitBaselineInbox(array $report, ProposalInboxEmitter $inbox): ?array
    {
        if (! (bool) $this->option('emit-baseline-inbox')) {
            return null;
        }

        $contract = (array) ($report['baseline_contract'] ?? []);
        if ($contract === []) {
            return [
                'status' => 'not_emitted',
                'reason' => 'baseline_contract_missing',
            ];
        }

        $item = $inbox->emit([
            'title' => 'Revisar baseline de Long-Running Work da estrutura mae',
            'category' => 'automation',
            'source_type' => 'long_running_work_report',
            'source_id' => null,
            'dedupe_key' => 'structure-mother-long-running-baseline:'.sha1((string) data_get($contract, 'contract_hash', 'unknown')),
            'problem' => 'A estrutura mae tem monitoramento de Long-Running Work, mas ainda nao declarou uma agenda minima operacional para snapshots e revisoes recorrentes.',
            'solution' => 'Revisar o baseline, aprovar quais familias de agenda devem ser criadas e registrar decisao humana antes de qualquer schedule mutation.',
            'worth_it' => 'Este item transforma a lacuna de autonomia em fila governada sem disparar jobs, sem mudar agenda e sem escalar autonomia.',
            'metadata' => [
                'schema_version' => 'atlas.long_running_work.baseline_inbox.v1',
                'review_signal' => [
                    'status' => 'review_required',
                    'severity' => 'medium',
                    'recommended_action' => 'discuss',
                    'reasons' => [
                        'no_long_running_work_schedule_declared',
                        'human_review_required_before_schedule_mutation',
                        'structure_mother_recurring_monitoring_needed',
                    ],
                ],
            ],
            'payload' => [
                'long_running_work_baseline_contract' => [
                    'schema_version' => data_get($contract, 'schema_version'),
                    'status' => data_get($contract, 'status'),
                    'purpose' => data_get($contract, 'purpose'),
                    'minimum_schedule_families' => data_get($contract, 'minimum_schedule_families', []),
                    'execution_authority' => data_get($contract, 'execution_authority', []),
                    'required_receipts' => data_get($contract, 'required_receipts', []),
                    'required_before_promotion' => data_get($contract, 'required_before_promotion', []),
                    'contract_hash' => data_get($contract, 'contract_hash'),
                    'writes' => false,
                    'raw_prompt_persisted' => false,
                    'raw_output_in_metadata' => false,
                    'workspace_path_exposed' => false,
                ],
                'current_report_summary' => [
                    'scheduled_task_count' => (int) ($report['scheduled_task_count'] ?? 0),
                    'enabled_task_count' => (int) ($report['enabled_task_count'] ?? 0),
                    'recent_run_count' => (int) ($report['recent_run_count'] ?? 0),
                    'unsafe_autonomy_receipt_count' => (int) ($report['unsafe_autonomy_receipt_count'] ?? 0),
                    'review_signal' => data_get($report, 'review_signal', []),
                    'writes' => false,
                ],
            ],
            'source_refs' => [
                ['type' => 'engineering_knowledge', 'id' => 'docs/engineering-knowledge-base/domains/background.md'],
                ['type' => 'engineering_knowledge', 'id' => 'docs/engineering-knowledge-base/spec-operating-system/autonomy-and-clarification-policy.md'],
            ],
            'available_actions' => [
                ['id' => 'discuss', 'label' => 'Discutir agenda', 'style' => 'primary'],
            ],
            'confidence' => 0.86,
        ]);

        if ($item === null) {
            return [
                'status' => 'not_emitted',
                'reason' => 'inbox_tables_unavailable',
            ];
        }

        return [
            'status' => 'emitted',
            'id' => $item->id,
            'title' => $item->title,
            'review_signal' => data_get($item->payload ?? [], 'proposal_contract.review_signal'),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $report = (array) ($payload['long_running_work'] ?? []);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Long-Running Work</>', (string) ($report['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hours', (string) ($payload['hours'] ?? '-'));
        $this->components->twoColumnDetail('Scheduled tasks', (string) ($report['scheduled_task_count'] ?? 0));
        $this->components->twoColumnDetail('Enabled tasks', (string) ($report['enabled_task_count'] ?? 0));
        $this->components->twoColumnDetail('Due tasks', (string) ($report['due_task_count'] ?? 0));
        $this->components->twoColumnDetail('Recent runs', (string) ($report['recent_run_count'] ?? 0));
        $this->components->twoColumnDetail('Failed recent runs', (string) ($report['failed_recent_run_count'] ?? 0));
        $this->components->twoColumnDetail('Unsafe receipts', (string) ($report['unsafe_autonomy_receipt_count'] ?? 0));
        $this->components->twoColumnDetail('Review signal', (string) data_get($report, 'review_signal.status', 'unknown'));
        $this->components->twoColumnDetail('Recommended action', (string) data_get($report, 'review_signal.recommended_action', 'none'));

        return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
