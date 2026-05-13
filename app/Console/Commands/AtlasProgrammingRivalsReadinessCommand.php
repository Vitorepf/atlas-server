<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\ProgrammingRivalsReadinessService;
use Illuminate\Console\Command;

class AtlasProgrammingRivalsReadinessCommand extends Command
{
    protected $signature = 'atlas:programming:rivals-readiness
        {--workspace= : Workspace to evaluate. Defaults to the Laravel base path.}
        {--refresh-local-benchmarks : Recompute local programming benchmarks instead of using process-memory cache.}
        {--triage : Emit focused invalid-battery triage packet without provider execution.}
        {--json : Emit JSON output.}';

    protected $description = 'Report Programming Agentic RAG readiness for real Atlas Rivals provider batteries without executing providers.';

    public function handle(ProgrammingRivalsReadinessService $readiness): int
    {
        $workspace = is_string($this->option('workspace')) && trim((string) $this->option('workspace')) !== ''
            ? trim((string) $this->option('workspace'))
            : base_path();

        $report = $readiness->report($workspace, (bool) $this->option('refresh-local-benchmarks'));
        $triage = $this->triagePacket($report);

        if ((bool) $this->option('triage')) {
            if ((bool) $this->option('json')) {
                $this->line(json_encode($triage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('Programming Rivals triage', (string) ($triage['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Provider dispatch', data_get($triage, 'safety.provider_dispatches_now') ? 'allowed' : 'blocked');
            $this->components->twoColumnDetail('Spend provider tokens', data_get($triage, 'safety.spend_provider_tokens_now') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Provider budget reason', (string) data_get($triage, 'provider_budget_policy.reason', 'unknown'));
            $this->components->twoColumnDetail('Score admitted', data_get($triage, 'result_integrity.score_admitted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Claim winner admitted', data_get($triage, 'result_integrity.claim_winner_admitted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Current workspace', (string) data_get($triage, 'current_workspace.status', 'unknown'));
            $this->components->twoColumnDetail('Current local rechecks', (string) data_get($triage, 'current_local_rechecks.status', 'unknown'));
            $this->components->twoColumnDetail('Quality changed-only', (string) data_get($triage, 'current_local_rechecks.quality_changed_only.status', 'unknown'));
            $this->components->twoColumnDetail('Visual smoke', (string) data_get($triage, 'current_local_rechecks.visual_smoke.status', 'unknown'));
            $this->newLine();
            foreach ((array) data_get($triage, 'blocking_reasons', []) as $reason) {
                $this->warn((string) $reason);
            }
            foreach ((array) data_get($triage, 'triage_checklist', []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $this->components->twoColumnDetail(
                    'triage '.$item['id'],
                    (string) ($item['status'] ?? 'unknown'),
                );
            }
            $this->newLine();
            foreach ((array) data_get($triage, 'diagnostic_commands', []) as $name => $command) {
                $this->line($name.': '.$command);
            }
            $this->line('Next: '.(string) data_get($triage, 'next_action', 'Review triage packet.'));

            return self::SUCCESS;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return in_array($report['status'] ?? null, ['external_battery_required', 'external_battery_invalid', 'claim_ready'], true)
                ? self::SUCCESS
                : self::FAILURE;
        }

        $this->components->twoColumnDetail('Programming Rivals readiness', (string) ($report['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Local foundation', data_get($report, 'summary.local_programming_foundation_ready') ? 'passed' : 'failed');
        $this->components->twoColumnDetail('Comparable real cases', (string) data_get($report, 'summary.comparable_case_count', 0));
        $this->components->twoColumnDetail('Real battery attempted', data_get($report, 'summary.real_provider_battery_attempted') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Invalid real cases', (string) data_get($report, 'summary.invalid_case_count', 0));
        $this->components->twoColumnDetail('Claim ready', data_get($report, 'summary.claim_ready') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Integrity status', (string) data_get($report, 'integrity_assurance.status', 'unknown'));
        $this->components->twoColumnDetail('Score diagnosis', (string) data_get($report, 'result_integrity_diagnostics.status', 'unknown'));
        $this->components->twoColumnDetail('Fair Claude result', (string) data_get($report, 'fair_claude_result_integrity.status', 'unknown'));
        $this->components->twoColumnDetail('Claim winner admitted', data_get($report, 'fair_claude_result_integrity.claim_winner_admitted') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Render winner in UI', data_get($report, 'fair_claude_result_integrity.ui_contract.must_not_render_winner') ? 'no' : 'yes');
        $this->components->twoColumnDetail('Latest battery evidence', (string) data_get($report, 'latest_real_battery_evidence.status', 'unknown'));
        $this->components->twoColumnDetail('Current workspace preflight', (string) data_get($report, 'current_workspace_preflight.status', 'unknown'));
        $this->components->twoColumnDetail('Current dirty files', (string) data_get($report, 'current_workspace_preflight.git.dirty_count', 0));
        $this->components->twoColumnDetail('Provider dispatch now', data_get($report, 'operator_execution_packet.provider_dispatches_now') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Spend provider tokens now', data_get($report, 'invalid_battery_triage_packet.provider_budget_policy.spend_more_provider_tokens_now') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Provider budget reason', (string) data_get($report, 'invalid_battery_triage_packet.provider_budget_policy.reason', 'unknown'));
        $this->components->twoColumnDetail('Rerun allowed now', data_get($report, 'operator_execution_packet.rerun_provider_battery_allowed_now') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Current rerun preconditions', (string) data_get($report, 'invalid_battery_triage_packet.current_rerun_preconditions.current_workspace_status', 'unknown'));
        $this->components->twoColumnDetail('Rerun precondition dispatch', data_get($report, 'invalid_battery_triage_packet.current_rerun_preconditions.provider_dispatch_allowed_now') ? 'allowed' : 'blocked');
        $this->components->twoColumnDetail('Current local rechecks', (string) data_get($report, 'current_local_recheck_evidence.status', 'unknown'));
        $this->components->twoColumnDetail('Quality changed-only', (string) data_get($report, 'current_local_recheck_evidence.quality_changed_only.status', 'unknown'));
        $this->components->twoColumnDetail('Visual smoke', (string) data_get($report, 'current_local_recheck_evidence.visual_smoke.status', 'unknown'));
        $this->components->twoColumnDetail('Synthetic scores', data_get($report, 'summary.synthetic_scores_allowed') ? 'allowed' : 'blocked');
        $this->components->twoColumnDetail('Local benchmark cache', data_get($report, 'local_benchmark_cache.hit') ? 'hit' : 'fresh');
        $this->newLine();
        foreach ((array) data_get($report, 'local_benchmarks', []) as $name => $benchmark) {
            if (! is_array($benchmark)) {
                continue;
            }

            $this->components->twoColumnDetail(
                'Benchmark '.$name,
                (string) ($benchmark['status'] ?? 'unknown'),
            );
        }
        $this->newLine();
        foreach ((array) data_get($report, 'integrity_assurance.blocking_reasons', []) as $reason) {
            $this->warn((string) $reason);
        }
        $operatorAnswer = data_get($report, 'result_integrity_diagnostics.operator_answer');
        if (is_string($operatorAnswer) && $operatorAnswer !== '') {
            $this->line('Score interpretation: '.$operatorAnswer);
        }
        foreach ((array) data_get($report, 'invalid_battery_triage_packet.current_rerun_preconditions.why_provider_dispatch_is_blocked', []) as $reason) {
            $this->warn('rerun precondition '.$reason);
        }
        $this->line('Runbook: '.data_get($report, 'commands.real_rivals_runbook'));
        $this->line('Diagnostic: '.data_get($report, 'operator_execution_packet.recommended_diagnostic.command'));
        if (data_get($report, 'operator_execution_packet.rerun_provider_battery_allowed_now')) {
            $this->line('Recommended first run: '.data_get($report, 'operator_execution_packet.recommended_first_run.command'));
        } else {
            $this->warn('Provider rerun blocked: '.(string) data_get($report, 'operator_execution_packet.status', 'blocked'));
            $this->line('Blocked run template: '.data_get($report, 'operator_execution_packet.recommended_first_run.command'));
        }

        return in_array($report['status'] ?? null, ['external_battery_required', 'external_battery_invalid', 'claim_ready'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function triagePacket(array $report): array
    {
        return [
            'schema_version' => 'atlas.programming.rivals_invalid_battery_operator_triage.v1',
            'status' => data_get($report, 'invalid_battery_triage_packet.status', 'unknown'),
            'generated_at' => now()->toJSON(),
            'safety' => [
                'provider_dispatches_now' => false,
                'spend_provider_tokens_now' => false,
                'read_only' => true,
                'no_benchmark_run_created' => true,
                'synthetic_scores_allowed' => false,
            ],
            'result_integrity' => [
                'status' => data_get($report, 'fair_claude_result_integrity.status', 'unknown'),
                'score_admitted' => (bool) data_get($report, 'fair_claude_result_integrity.score_admitted', false),
                'claim_winner_admitted' => (bool) data_get($report, 'fair_claude_result_integrity.claim_winner_admitted', false),
                'winner_for_claim' => data_get($report, 'fair_claude_result_integrity.winner_for_claim'),
                'must_not_render_winner' => (bool) data_get($report, 'fair_claude_result_integrity.ui_contract.must_not_render_winner', true),
            ],
            'counts' => [
                'comparable_case_count' => (int) data_get($report, 'summary.comparable_case_count', 0),
                'invalid_case_count' => (int) data_get($report, 'summary.invalid_case_count', 0),
                'real_battery_attempted' => (bool) data_get($report, 'summary.real_provider_battery_attempted', false),
            ],
            'current_workspace' => [
                'status' => data_get($report, 'current_workspace_preflight.status', 'unknown'),
                'ready_for_provider_battery' => (bool) data_get($report, 'current_workspace_preflight.ready_for_provider_battery', false),
                'dirty_count' => (int) data_get($report, 'current_workspace_preflight.git.dirty_count', 0),
                'dirty_files_sample' => data_get($report, 'current_workspace_preflight.git.dirty_files_sample', []),
            ],
            'provider_budget_policy' => data_get($report, 'invalid_battery_triage_packet.provider_budget_policy', []),
            'current_local_rechecks' => data_get($report, 'current_local_recheck_evidence', []),
            'blocking_reasons' => data_get($report, 'invalid_battery_triage_packet.current_rerun_preconditions.why_provider_dispatch_is_blocked', []),
            'triage_checklist' => data_get($report, 'invalid_battery_triage_packet.triage_checklist', []),
            'failed_tests' => data_get($report, 'invalid_battery_triage_packet.failed_tests', []),
            'release_gate_failures' => data_get($report, 'invalid_battery_triage_packet.release_gate_failures', []),
            'risk_flags' => data_get($report, 'invalid_battery_triage_packet.risk_flags', []),
            'artifact_integrity' => data_get($report, 'invalid_battery_triage_packet.artifact_integrity', []),
            'diagnostic_commands' => data_get($report, 'invalid_battery_triage_packet.current_rerun_preconditions.diagnostic_commands_without_provider_spend', []),
            'blocked_run_template' => data_get($report, 'operator_execution_packet.recommended_first_run.command'),
            'next_action' => data_get($report, 'invalid_battery_triage_packet.next_action', 'Fix invalid battery triage items before another provider run.'),
        ];
    }
}
