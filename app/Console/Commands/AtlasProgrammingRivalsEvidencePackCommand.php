<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasRivalsEvidencePackService;
use App\Services\Ai\Programming\AtlasRivalsEvidencePackVerifierService;
use Illuminate\Console\Command;

/**
 * Rivals Evidence Pack CLI.
 *
 * Never dispatches providers. Default mode is read-only — only when the
 * operator passes --run-tests/--run-quality do we execute the configured
 * commands. With --strict, exits non-zero when any of test_run_log,
 * patch_diff or quality_scan_log are missing.
 */
class AtlasProgrammingRivalsEvidencePackCommand extends Command
{
    protected $signature = 'atlas:programming:rivals-evidence-pack
        {--case= : Case identifier. Defaults to the first registered case.}
        {--workspace= : Atlas workspace path. Defaults to the Laravel base path.}
        {--run-tests : Execute the test command and record the log.}
        {--test-command= : Override the default test command.}
        {--run-quality : Execute the quality scan command and record the log.}
        {--quality-command= : Override the default quality scan command.}
        {--json : Emit JSON output.}
        {--strict : Exit non-zero when test_run_log, patch_diff or quality_scan_log are missing.}';

    protected $description = 'Build a local, replayable Rivals evidence pack for a case. Never dispatches providers and never promotes the Rivals claim.';

    /**
     * Canonical v2 entrypoint that supersedes this command operationally.
     * Slice 0 emits a deprecation banner; Slice 6 flips FORWARD_TO_CANONICAL_ENABLED
     * to delegate execution to `atlas:forge:rivals` directly.
     */
    private const CANONICAL_COMMAND = 'atlas:forge:rivals';

    private const CANONICAL_PRIMARY_ACTION = 'collect-evidence';

    private const FORWARD_TO_CANONICAL_ENABLED = false;

    public function handle(
        AtlasRivalsEvidencePackService $service,
        AtlasRivalsEvidencePackVerifierService $verifier,
    ): int {
        app(\App\Services\Ai\Programming\ForgeRivals\ForgeRivalsDeprecationNotifier::class)
            ->notify('atlas:programming:rivals-evidence-pack', self::CANONICAL_PRIMARY_ACTION);

        if (self::FORWARD_TO_CANONICAL_ENABLED) {
            // Slice 6: forward to atlas:forge:rivals collect-evidence --run-id=...
            // via \Illuminate\Support\Facades\Artisan::call(self::CANONICAL_COMMAND, $args, $this->output);
            // Slice 0 keeps the legacy logic executing below.
        }

        $pack = $service->generate([
            'case_id' => $this->option('case'),
            'workspace' => $this->option('workspace'),
            'run_tests' => (bool) $this->option('run-tests'),
            'test_command' => $this->option('test-command'),
            'run_quality' => (bool) $this->option('run-quality'),
            'quality_command' => $this->option('quality-command'),
        ]);

        $verification = $verifier->verify($pack);

        $output = [
            'evidence_pack' => $pack,
            'verification' => $verification,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderHuman($pack, $verification);
        }

        if ((bool) $this->option('strict')) {
            $missing = (array) ($pack['missing_evidence'] ?? []);
            $blockedByEvidence = array_intersect(
                ['missing_test_run_log', 'missing_patch_diff', 'missing_quality_scan_log'],
                $missing,
            );
            $verifierBlocked = ($verification['status'] ?? null) !== 'passed';
            if ($blockedByEvidence !== [] || $verifierBlocked) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $pack
     * @param  array<string,mixed>  $verification
     */
    private function renderHuman(array $pack, array $verification): void
    {
        $this->components->twoColumnDetail('Rivals Evidence Pack', (string) ($pack['evidence_pack_id'] ?? 'unknown'));
        $this->components->twoColumnDetail('Case', (string) ($pack['case_id'] ?? 'unknown'));
        $this->components->twoColumnDetail('Workspace clean', (data_get($pack, 'workspace.clean') ? 'yes' : 'no').' (dirty='.(string) data_get($pack, 'workspace.dirty_count', 0).')');
        $this->components->twoColumnDetail('Replay manifest', data_get($pack, 'replay_manifest.present') ? 'present' : 'missing');
        $this->components->twoColumnDetail('Business rule', data_get($pack, 'business_rule.present') ? 'present' : 'missing');
        $this->components->twoColumnDetail('Canonical docs', data_get($pack, 'canonical_docs.all_present') ? 'all_present' : 'missing');
        $this->components->twoColumnDetail('Patch diff', data_get($pack, 'patch_diff.present') ? 'present' : 'missing');
        $this->components->twoColumnDetail('Tests', data_get($pack, 'tests.present') ? 'recorded' : 'not_run');
        $this->components->twoColumnDetail('Quality scan', data_get($pack, 'quality_scan.present') ? 'recorded' : 'not_run');
        $this->components->twoColumnDetail('External provider call', $pack['external_provider_call'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Claim ready', $pack['claim_ready'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Verifier', (string) ($verification['status'] ?? 'unknown'));
        $this->newLine();
        foreach ((array) ($pack['missing_evidence'] ?? []) as $missing) {
            $this->warn('missing: '.(string) $missing);
        }
        foreach ((array) ($verification['blockers'] ?? []) as $blocker) {
            $this->warn('verifier_blocker: '.(string) $blocker);
        }
        $this->line('Note: '.(string) ($pack['note'] ?? ''));
    }
}
