<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasForgeNativeRivalsPreflightService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsProtocolService;
use Illuminate\Console\Command;

/**
 * Forge-Native Rivals Preflight CLI.
 *
 * Diagnostic-only. Never dispatches providers. With --strict the command
 * exits non-zero if the result is not ready_for_dry_run or
 * ready_for_provider_battery, which makes it safe to chain in CI/operator
 * runbooks.
 */
class AtlasProgrammingRivalsForgePreflightCommand extends Command
{
    protected $signature = 'atlas:programming:rivals-forge-preflight
        {--workspace= : Atlas workspace path. Defaults to the Laravel base path.}
        {--baseline-workspace= : Separate clean baseline workspace for the rival arm.}
        {--suite= : Suite identifier. Defaults to atlas-fair-claude-v1.}
        {--case= : Optional case_id to validate. Defaults to the first registered case.}
        {--intends-provider-battery : Mark intent to run a real provider battery (requires --confirm-provider-cost and --confirm-runbook-reviewed).}
        {--confirm-provider-cost : Operator confirms paid provider cost. Never assumed.}
        {--confirm-runbook-reviewed : Operator confirms runbook has been reviewed.}
        {--json : Emit JSON output.}
        {--strict : Exit non-zero when status is not ready_for_dry_run or ready_for_provider_battery.}';

    protected $description = 'Diagnostic-only preflight for Forge-Native Rivals batteries (Atlas arm must run through Forge).';

    /**
     * Canonical v2 entrypoint that supersedes this command operationally.
     * Slice 0 emits a deprecation banner; Slice 6 flips FORWARD_TO_CANONICAL_ENABLED
     * to delegate execution to `atlas:forge:rivals` directly.
     */
    private const CANONICAL_COMMAND = 'atlas:forge:rivals';

    private const CANONICAL_PRIMARY_ACTION = 'preflight';

    private const FORWARD_TO_CANONICAL_ENABLED = false;

    public function handle(AtlasForgeNativeRivalsPreflightService $preflight): int
    {
        app(\App\Services\Ai\Programming\ForgeRivals\ForgeRivalsDeprecationNotifier::class)
            ->notify('atlas:programming:rivals-forge-preflight', self::CANONICAL_PRIMARY_ACTION);

        if (self::FORWARD_TO_CANONICAL_ENABLED) {
            // Slice 6: forward to atlas:forge:rivals preflight --mode=diagnostic
            // via \Illuminate\Support\Facades\Artisan::call(self::CANONICAL_COMMAND, $args, $this->output);
            // Slice 0 keeps the legacy logic executing below.
        }

        $intendsBattery = (bool) $this->option('intends-provider-battery');
        $providerApproved = (bool) $this->option('confirm-provider-cost');
        $runbookReviewed = (bool) $this->option('confirm-runbook-reviewed');

        $report = $preflight->preflight([
            'workspace' => $this->option('workspace'),
            'baseline_workspace' => $this->option('baseline-workspace'),
            'suite_id' => is_string($this->option('suite')) && $this->option('suite') !== ''
                ? $this->option('suite')
                : AtlasForgeNativeRivalsProtocolService::DEFAULT_SUITE_ID,
            'case_id' => $this->option('case'),
            'intends_provider_battery' => $intendsBattery,
            'provider_cost_approved' => $providerApproved,
            'runbook_reviewed' => $runbookReviewed,
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderHuman($report);
        }

        $status = (string) ($report['status'] ?? 'unknown');
        $ready = in_array($status, ['ready_for_dry_run', 'ready_for_provider_battery'], true);

        if ((bool) $this->option('strict')) {
            return $ready ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderHuman(array $report): void
    {
        $this->components->twoColumnDetail('Forge-Native Rivals preflight', (string) ($report['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Atlas side must use Forge', $report['atlas_side_must_use_forge'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Ready for dry-run', $report['ready_for_dry_run'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Ready for provider battery', $report['ready_for_provider_battery'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Provider dispatched now', $report['external_provider_call'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Workspace', (string) data_get($report, 'checks.workspace.status', 'unknown'));
        $this->components->twoColumnDetail('Baseline workspace', (string) data_get($report, 'checks.baseline_workspace.status', 'unknown'));
        $this->components->twoColumnDetail('Forge runtime', (string) data_get($report, 'checks.forge_runtime.status', 'unknown'));
        $this->components->twoColumnDetail('Forge commands', (string) data_get($report, 'checks.forge_commands.status', 'unknown'));
        $this->components->twoColumnDetail('Canonical docs', (string) data_get($report, 'checks.canonical_docs.status', 'unknown'));
        $this->components->twoColumnDetail('Case manifest', (string) data_get($report, 'checks.case_manifest.status', 'unknown'));
        $this->components->twoColumnDetail('Operator approval', (string) data_get($report, 'checks.operator_approval.status', 'unknown'));
        $this->newLine();
        foreach ((array) ($report['blocking_reasons'] ?? []) as $reason) {
            $this->warn((string) $reason);
        }
        $this->line('Next: '.(string) ($report['next_action'] ?? ''));
    }
}
