<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasForgeNativeRivalsDryRunService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsProtocolService;
use Illuminate\Console\Command;

/**
 * Forge-Native Rivals Dry-Run CLI.
 *
 * Never dispatches providers. Validates protocol, case manifest and planned
 * replay manifest. With --strict, exits non-zero when status is not
 * dry_run_passed.
 */
class AtlasProgrammingRivalsForgeDryRunCommand extends Command
{
    protected $signature = 'atlas:programming:rivals-forge-dry-run
        {--case= : Case identifier. Defaults to the first registered case.}
        {--suite= : Suite identifier. Defaults to atlas-fair-claude-v1.}
        {--workspace= : Atlas workspace path. Defaults to the Laravel base path.}
        {--baseline-workspace= : Optional baseline workspace path (not required for dry-run).}
        {--json : Emit JSON output.}
        {--strict : Exit non-zero when status is not dry_run_passed.}';

    protected $description = 'Plan a Forge-Native Rivals case without dispatching providers (Atlas arm must be Forge).';

    /**
     * Canonical v2 entrypoint that supersedes this command operationally.
     * Slice 0 emits a deprecation banner; Slice 6 flips FORWARD_TO_CANONICAL_ENABLED
     * to delegate execution to `atlas:forge:rivals` directly.
     */
    private const CANONICAL_COMMAND = 'atlas:forge:rivals';

    private const CANONICAL_PRIMARY_ACTION = 'dry-run';

    private const FORWARD_TO_CANONICAL_ENABLED = false;

    public function handle(AtlasForgeNativeRivalsDryRunService $dryRun): int
    {
        app(\App\Services\Ai\Programming\ForgeRivals\ForgeRivalsDeprecationNotifier::class)
            ->notify('atlas:programming:rivals-forge-dry-run', self::CANONICAL_PRIMARY_ACTION);

        if (self::FORWARD_TO_CANONICAL_ENABLED) {
            // Slice 6: forward to atlas:forge:rivals dry-run --mode=diagnostic
            // via \Illuminate\Support\Facades\Artisan::call(self::CANONICAL_COMMAND, $args, $this->output);
            // Slice 0 keeps the legacy logic executing below.
        }

        $report = $dryRun->dryRun([
            'case_id' => $this->option('case'),
            'suite_id' => is_string($this->option('suite')) && $this->option('suite') !== ''
                ? $this->option('suite')
                : AtlasForgeNativeRivalsProtocolService::DEFAULT_SUITE_ID,
            'workspace' => $this->option('workspace'),
            'baseline_workspace' => $this->option('baseline-workspace'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderHuman($report);
        }

        $passed = ($report['status'] ?? null) === 'dry_run_passed';

        if ((bool) $this->option('strict')) {
            return $passed ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderHuman(array $report): void
    {
        $this->components->twoColumnDetail('Forge-Native Rivals dry-run', (string) ($report['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('External provider call', $report['external_provider_call'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Provider tokens spent', $report['provider_tokens_spent'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Atlas side must use Forge', $report['atlas_side_must_use_forge'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Synthetic scores allowed', $report['synthetic_scores_allowed'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Case resolved', (string) data_get($report, 'inputs.case_id_resolved', 'none'));
        $this->components->twoColumnDetail('Preflight status', (string) data_get($report, 'planned.preflight_status', 'unknown'));
        $this->components->twoColumnDetail('Replay manifest', data_get($report, 'planned.replay_manifest.valid') ? 'planned' : 'invalid');
        $this->newLine();
        foreach ((array) ($report['blocking_reasons'] ?? []) as $reason) {
            $this->warn((string) $reason);
        }
        $this->line('Note: '.(string) ($report['note'] ?? ''));
    }
}
