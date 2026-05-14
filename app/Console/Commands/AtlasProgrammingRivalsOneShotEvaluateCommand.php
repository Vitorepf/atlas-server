<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasForgeNativeRivalsCaseManifestService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsDryRunService;
use App\Services\Ai\Programming\AtlasRivalsEvidencePackService;
use App\Services\Ai\Programming\AtlasRivalsOneShotEnterpriseEvaluationService;
use Illuminate\Console\Command;

/**
 * Rivals One-Shot Enterprise Evaluation CLI.
 *
 * Never dispatches providers, never spends tokens, never promotes claim.
 * Reads case manifest + plans dry-run + scores the planned replay manifest
 * against the canonical rubric. Output is diagnostic.
 *
 * --strict exits non-zero when grade is `invalid` or `not_enterprise_ready`.
 * --with-evidence-pack pipes a real local evidence pack into the rubric so
 * dimensions that depend on tests, patch diff and quality scan can be scored
 * honestly (still local, still without providers).
 */
class AtlasProgrammingRivalsOneShotEvaluateCommand extends Command
{
    protected $signature = 'atlas:programming:rivals-one-shot-evaluate
        {--case= : Case identifier. Defaults to the first registered case.}
        {--workspace= : Atlas workspace path. Defaults to the Laravel base path.}
        {--with-evidence-pack : Build a local evidence pack and pipe it into the rubric.}
        {--run-tests : When using --with-evidence-pack, execute the test command and record the log.}
        {--test-command= : Override the default test command used by --run-tests.}
        {--run-quality : When using --with-evidence-pack, execute the quality scan command and record the log.}
        {--quality-command= : Override the default quality command used by --run-quality.}
        {--json : Emit JSON output.}
        {--strict : Exit non-zero when grade is invalid or not_enterprise_ready.}';

    protected $description = 'Score an Atlas Code one-shot delivery against the Rivals enterprise rubric. Local diagnostic only — never dispatches providers and never promotes the Rivals claim.';

    public function handle(
        AtlasForgeNativeRivalsCaseManifestService $caseManifestService,
        AtlasForgeNativeRivalsDryRunService $dryRunService,
        AtlasRivalsOneShotEnterpriseEvaluationService $evaluator,
        AtlasRivalsEvidencePackService $evidencePackService,
    ): int {
        $caseId = is_string($this->option('case')) && trim((string) $this->option('case')) !== ''
            ? trim((string) $this->option('case'))
            : null;
        $workspace = is_string($this->option('workspace')) && trim((string) $this->option('workspace')) !== ''
            ? trim((string) $this->option('workspace'))
            : base_path();
        $withEvidencePack = (bool) $this->option('with-evidence-pack');

        $caseManifest = $caseManifestService->manifest($caseId);
        $dryRun = $dryRunService->dryRun([
            'case_id' => $caseId,
            'workspace' => $workspace,
        ]);

        $evidencePack = null;
        $evidenceInput = $this->defaultEvidenceInput($dryRun);
        if ($withEvidencePack) {
            $evidencePack = $evidencePackService->generate([
                'case_id' => $caseId,
                'workspace' => $workspace,
                'run_tests' => (bool) $this->option('run-tests'),
                'test_command' => $this->option('test-command'),
                'run_quality' => (bool) $this->option('run-quality'),
                'quality_command' => $this->option('quality-command'),
            ]);
            $evidenceInput = $evidencePackService->toEvaluationEvidenceInput($evidencePack);
        }

        $report = $evaluator->evaluate([
            'replay_manifest' => $dryRun['replay_manifest'] ?? $dryRun['planned']['replay_manifest'] ?? null,
            'case_manifest' => $caseManifest,
            'evidence_pack' => $evidenceInput,
            'evaluation_mode' => 'local_manifest_evaluation',
        ]);

        if ($evidencePack !== null) {
            $report['evidence_pack'] = [
                'evidence_pack_id' => $evidencePack['evidence_pack_id'] ?? null,
                'schema_version' => $evidencePack['schema_version'] ?? null,
                'workspace_clean' => (bool) data_get($evidencePack, 'workspace.clean', false),
                'replay_manifest_hash' => $evidencePack['replay_manifest']['hash'] ?? null,
                'missing_evidence' => $evidencePack['missing_evidence'] ?? [],
                'external_provider_call' => false,
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderHuman($report);
        }

        $grade = (string) ($report['grade'] ?? 'invalid');

        if ((bool) $this->option('strict')) {
            return in_array($grade, [
                AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_ENTERPRISE_READY,
                AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_REVIEW_REQUIRED,
            ], true) ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $dryRun
     * @return array<string,mixed>
     */
    private function defaultEvidenceInput(array $dryRun): array
    {
        return [
            'preflight_status' => $dryRun['planned']['preflight_status'] ?? null,
            'canonical_docs_consulted' => array_values((array) data_get($dryRun, 'preflight.checks.canonical_docs.present_docs', [])),
            'canonical_docs_required' => true,
            'tests_present' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderHuman(array $report): void
    {
        $this->components->twoColumnDetail('Rivals One-Shot Enterprise Evaluation', (string) ($report['grade'] ?? 'unknown'));
        $this->components->twoColumnDetail('Diagnostic score', (string) ($report['diagnostic_score'] ?? 'n/a').'/'.(string) ($report['max_score'] ?? '100'));
        $this->components->twoColumnDetail('Primary objective', (string) ($report['primary_objective'] ?? 'unknown'));
        $this->components->twoColumnDetail('Speed is secondary', $report['speed_is_secondary'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('External provider call', $report['external_provider_call'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Promotes external rivals claim', $report['promotes_external_rivals_claim'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Synthetic scores allowed', $report['synthetic_scores_allowed'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Claim ready', $report['claim_ready'] ? 'yes' : 'no');
        $epPack = $report['evidence_pack'] ?? null;
        if (is_array($epPack)) {
            $this->components->twoColumnDetail('Evidence pack', (string) ($epPack['evidence_pack_id'] ?? 'n/a'));
            $this->components->twoColumnDetail('Workspace clean (pack)', $epPack['workspace_clean'] ? 'yes' : 'no');
        }
        $this->newLine();
        foreach ((array) ($report['dimension_scores'] ?? []) as $dim) {
            if (! is_array($dim)) {
                continue;
            }
            $this->components->twoColumnDetail(
                'dim '.($dim['id'] ?? 'unknown'),
                (string) ($dim['earned'] ?? 0).' / '.(string) ($dim['weight'] ?? 0).' ['.($dim['status'] ?? 'unknown').']',
            );
        }
        $this->newLine();
        foreach ((array) ($report['hard_fails'] ?? []) as $fail) {
            $this->warn('hard_fail: '.(string) $fail);
        }
        foreach ((array) ($report['improvement_priorities'] ?? []) as $imp) {
            $this->line($imp);
        }
        $this->line('Verdict: '.(string) data_get($report, 'verdict.summary', ''));
    }
}
