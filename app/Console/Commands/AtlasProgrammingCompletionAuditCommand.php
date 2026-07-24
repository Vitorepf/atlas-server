<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasProgrammingCompletionAuditCommand extends Command
{
    use EmitsCanonicalJson;

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
            $this->line($this->encode($report));

            return ($report['status'] ?? null) === 'complete' ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('Programming completion audit', (string) ($report['status'] ?? 'unknown'));
        $this->line((string) data_get($report, 'executive_report.headline', 'No executive headline available.'));
        $this->newLine();
        $this->components->twoColumnDetail('Local foundation', (string) data_get($report, 'executive_report.primary_state.local_foundation', 'unknown'));
        $this->components->twoColumnDetail('External Rivals claim', (string) data_get($report, 'executive_report.primary_state.external_rivals_claim', 'unknown'));
        $this->components->twoColumnDetail('Completion', (string) data_get($report, 'executive_report.primary_state.completion', 'unknown'));
        $this->components->twoColumnDetail('Power score', (string) data_get($report, 'power_scorecard.score_out_of_10', 'unknown').'/10');
        $this->components->twoColumnDetail('Power target', (string) data_get($report, 'power_scorecard.status', 'unknown'));
        $this->components->twoColumnDetail('Professional RAG standard', data_get($report, 'artifact_coverage.professional_operating_standard.covered') ? 'passed' : 'blocked');
        $this->components->twoColumnDetail('Anti-MVP gate', data_get($report, 'artifact_coverage.professional_operating_standard.checks.rejects_weak_mvp') ? 'passed' : 'blocked');
        $this->components->twoColumnDetail('Replayable context pack', data_get($report, 'artifact_coverage.professional_operating_standard.checks.requires_replayable_context_pack') ? 'required' : 'missing');
        $this->components->twoColumnDetail('Semantic code graph', data_get($report, 'artifact_coverage.professional_operating_standard.checks.requires_semantic_code_graph') ? 'required' : 'missing');
        $this->components->twoColumnDetail('Verifier/Test Impact', data_get($report, 'artifact_coverage.professional_operating_standard.checks.requires_patch_verifier_and_test_impact') ? 'required' : 'missing');
        $this->components->twoColumnDetail('Repair Loop benchmark', (string) data_get($report, 'verification_evidence.local_benchmarks.repair_loop.status', 'unknown'));
        $this->components->twoColumnDetail('Provider dispatch now', data_getYesNo::format($report, 'executive_report.safety_summary.provider_dispatches_now'));
        $this->components->twoColumnDetail('Spend provider tokens now', data_getYesNo::format($report, 'executive_report.safety_summary.spend_provider_tokens_now'));
        $this->components->twoColumnDetail('Synthetic scores', data_get($report, 'executive_report.safety_summary.synthetic_scores_allowed') ? 'allowed' : 'blocked');
        $this->components->twoColumnDetail('Fair Claude result', (string) data_get($report, 'verification_evidence.fair_claude_result_integrity.status', 'unknown'));
        $this->components->twoColumnDetail('Claim winner admitted', data_getYesNo::format($report, 'verification_evidence.fair_claude_result_integrity.claim_winner_admitted'));
        $this->components->twoColumnDetail('Render winner in UI', data_get($report, 'verification_evidence.fair_claude_result_integrity.ui_contract.must_not_render_winner') ? 'no' : 'yes');
        $this->components->twoColumnDetail('API battery guard', data_get($report, 'artifact_coverage.api_rivals_battery_guard.covered') ? 'passed' : 'blocked');
        $this->components->twoColumnDetail('API blocks dirty/non-Git', data_get($report, 'artifact_coverage.api_rivals_battery_guard.checks.blocks_dirty_atlas_workspace') && data_getYesNo::format($report, 'artifact_coverage.api_rivals_battery_guard.checks.blocks_non_git_baseline_workspace'));
        $this->components->twoColumnDetail('API blocks invalid rerun', data_getYesNo::format($report, 'artifact_coverage.api_rivals_battery_guard.checks.blocks_historical_invalid_battery'));
        $this->components->twoColumnDetail('Invalid battery quarantine', data_get($report, 'artifact_coverage.rivals_invalid_battery_quarantine.covered') ? 'passed' : 'blocked');
        $this->components->twoColumnDetail('Quarantine admits score', data_get($report, 'artifact_coverage.rivals_invalid_battery_quarantine.checks.declares_no_score_admitted') ? 'no' : 'unknown');
        $this->components->twoColumnDetail('Quarantine deletes history', data_get($report, 'artifact_coverage.rivals_invalid_battery_quarantine.checks.declares_no_history_deleted') ? 'no' : 'unknown');
        $this->components->twoColumnDetail('Current workspace', (string) data_get($report, 'verification_evidence.current_workspace_preflight.status', 'unknown'));
        $this->components->twoColumnDetail('Workspace ready for Rivals', data_getYesNo::format($report, 'verification_evidence.current_workspace_preflight.ready_for_provider_battery'));
        $this->components->twoColumnDetail('Workspace dirty files', (string) data_get($report, 'verification_evidence.current_workspace_preflight.dirty_count', 0));
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
        foreach ((array) data_get($report, 'verification_evidence.current_workspace_preflight.blocking_reasons', []) as $reason) {
            $this->warn('workspace '.$reason);
        }
        $providerBudgetReason = (string) data_get($report, 'executive_report.safety_summary.provider_budget_reason', '');
        if ($providerBudgetReason !== '') {
            $this->warn('provider budget '.$providerBudgetReason);
        }
        $triage = (array) data_get($report, 'verification_evidence.invalid_battery_triage_packet', []);
        if (($triage['status'] ?? null) === 'triage_required_before_rerun') {
            $this->newLine();
            $this->components->twoColumnDetail('Invalid battery triage', (string) ($triage['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Rerun provider battery', data_get($triage, 'rerun_provider_battery_allowed_now') ? 'allowed' : 'blocked');
            $this->components->twoColumnDetail('Spend provider tokens now', data_getYesNo::format($triage, 'provider_budget_policy.spend_more_provider_tokens_now'));
            $this->components->twoColumnDetail('Current rerun preconditions', (string) data_get($triage, 'current_rerun_preconditions.current_workspace_status', 'unknown'));
            $this->components->twoColumnDetail('Rerun precondition dispatch', data_get($triage, 'current_rerun_preconditions.provider_dispatch_allowed_now') ? 'allowed' : 'blocked');
            $this->components->twoColumnDetail('Current local rechecks', (string) data_get($triage, 'current_rerun_preconditions.current_local_rechecks.status', 'unknown'));
            $this->components->twoColumnDetail('Quality changed-only', (string) data_get($triage, 'current_rerun_preconditions.current_local_rechecks.quality_changed_only.status', 'unknown'));
            $this->components->twoColumnDetail('Visual smoke', (string) data_get($triage, 'current_rerun_preconditions.current_local_rechecks.visual_smoke.status', 'unknown'));
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
