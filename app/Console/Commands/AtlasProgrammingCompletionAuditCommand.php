<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use Illuminate\Console\Command;

class AtlasProgrammingCompletionAuditCommand extends Command
{
    protected $signature = 'atlas:programming:completion-audit
        {--workspace= : Workspace to audit. Defaults to the Laravel base path.}
        {--refresh-local-benchmarks : Recompute local programming benchmarks instead of using process-memory cache.}
        {--json : Emit JSON output.}';

    protected $description = 'Audit whether the professional programming implementation can be marked complete.';

    public function handle(ProgrammingProfessionalCompletionAuditService $audit): int
    {
        $workspace = is_string($this->option('workspace')) && trim((string) $this->option('workspace')) !== ''
            ? trim((string) $this->option('workspace'))
            : base_path();

        $report = $audit->report($workspace, (bool) $this->option('refresh-local-benchmarks'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($report['status'] ?? null) === 'complete' ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('Programming completion audit', (string) ($report['status'] ?? 'unknown'));
        $this->line((string) data_get($report, 'executive_report.headline', 'No executive headline available.'));
        $this->newLine();
        $this->components->twoColumnDetail('Local foundation', (string) data_get($report, 'executive_report.primary_state.local_foundation', 'unknown'));
        $this->components->twoColumnDetail('External Rivals claim', (string) data_get($report, 'executive_report.primary_state.external_rivals_claim', 'unknown'));
        $this->components->twoColumnDetail('Completion', (string) data_get($report, 'executive_report.primary_state.completion', 'unknown'));
        $this->components->twoColumnDetail('Provider dispatch now', data_get($report, 'executive_report.safety_summary.provider_dispatches_now') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Synthetic scores', data_get($report, 'executive_report.safety_summary.synthetic_scores_allowed') ? 'allowed' : 'blocked');
        $this->components->twoColumnDetail('Fair Claude result', (string) data_get($report, 'verification_evidence.fair_claude_result_integrity.status', 'unknown'));
        $this->components->twoColumnDetail('Claim winner admitted', data_get($report, 'verification_evidence.fair_claude_result_integrity.claim_winner_admitted') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Render winner in UI', data_get($report, 'verification_evidence.fair_claude_result_integrity.ui_contract.must_not_render_winner') ? 'no' : 'yes');
        $this->components->twoColumnDetail('API battery guard', data_get($report, 'artifact_coverage.api_rivals_battery_guard.covered') ? 'passed' : 'blocked');
        $this->components->twoColumnDetail('API blocks dirty/non-Git', data_get($report, 'artifact_coverage.api_rivals_battery_guard.checks.blocks_dirty_atlas_workspace') && data_get($report, 'artifact_coverage.api_rivals_battery_guard.checks.blocks_non_git_baseline_workspace') ? 'yes' : 'no');
        $this->components->twoColumnDetail('API blocks invalid rerun', data_get($report, 'artifact_coverage.api_rivals_battery_guard.checks.blocks_historical_invalid_battery') ? 'yes' : 'no');
        $this->newLine();
        foreach ((array) data_get($report, 'executive_report.key_metrics', []) as $metric) {
            if (! is_array($metric)) {
                continue;
            }

            $this->components->twoColumnDetail(
                (string) ($metric['label'] ?? 'Metric'),
                (string) ($metric['value'] ?? 'n/a').' ['.(string) ($metric['status'] ?? 'unknown').']',
            );
        }
        $this->newLine();
        foreach ((array) ($report['blocking_items'] ?? []) as $item) {
            $this->warn((string) ($item['id'] ?? 'unknown').': '.(string) ($item['blocker'] ?? $item['status'] ?? 'blocked'));
        }
        $triage = (array) data_get($report, 'verification_evidence.invalid_battery_triage_packet', []);
        if (($triage['status'] ?? null) === 'triage_required_before_rerun') {
            $this->newLine();
            $this->components->twoColumnDetail('Invalid battery triage', (string) ($triage['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Rerun provider battery', data_get($triage, 'rerun_provider_battery_allowed_now') ? 'allowed' : 'blocked');
            $this->components->twoColumnDetail('Spend provider tokens now', data_get($triage, 'provider_budget_policy.spend_more_provider_tokens_now') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Current rerun preconditions', (string) data_get($triage, 'current_rerun_preconditions.current_workspace_status', 'unknown'));
            $this->components->twoColumnDetail('Rerun precondition dispatch', data_get($triage, 'current_rerun_preconditions.provider_dispatch_allowed_now') ? 'allowed' : 'blocked');
            foreach ((array) data_get($triage, 'current_rerun_preconditions.why_provider_dispatch_is_blocked', []) as $reason) {
                $this->warn('rerun precondition '.$reason);
            }
            foreach (array_slice((array) ($triage['triage_checklist'] ?? []), 0, 5) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $this->warn('triage '.$item['id'].': '.($item['status'] ?? 'unknown'));
            }
        }
        $this->line('Next: '.(string) data_get($report, 'executive_report.operator_next_action', 'Review blocking items.'));

        return ($report['status'] ?? null) === 'complete' ? self::SUCCESS : self::FAILURE;
    }
}
