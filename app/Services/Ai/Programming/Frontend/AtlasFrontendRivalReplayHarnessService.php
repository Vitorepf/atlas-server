<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class AtlasFrontendRivalReplayHarnessService
{
    public const SCHEMA_VERSION = 'atlas.frontend.rival_replay_harness.v1';

    public const TEMPLATE_SCHEMA_VERSION = 'atlas.frontend.rival_replay_template.v1';

    /**
     * @return array<string,mixed>
     */
    public function inspect(?string $evidenceDirectory = null): array
    {
        $directory = $this->evidenceDirectory($evidenceDirectory);
        $cases = $this->cases();
        $systems = $this->systems();
        $requiredFields = $this->requiredManifestFields();
        $rubric = app(AtlasFrontendCompetitiveRubricService::class)->rubric();
        $runs = [];
        $complete = 0;
        $missing = 0;
        $invalid = 0;

        foreach ($cases as $case) {
            foreach ($systems as $system) {
                $manifestPath = $directory.'/'.$case['id'].'/'.$system['id'].'/manifest.json';
                $run = $this->inspectManifest($manifestPath, $case['id'], $system['id'], $requiredFields);
                $runs[] = $run;

                if ($run['status'] === 'complete') {
                    $complete++;
                } elseif ($run['status'] === 'invalid') {
                    $invalid++;
                } else {
                    $missing++;
                }
            }
        }

        $externalRuns = array_values(array_filter($runs, fn (array $run): bool => $run['system'] !== 'atlas_frontend'));
        $externalReplayCompleted = $externalRuns !== [] && collect($externalRuns)->every(fn (array $run): bool => $run['status'] === 'complete');
        $allRunsCompleted = $runs !== [] && collect($runs)->every(fn (array $run): bool => $run['status'] === 'complete');
        $scoreboard = $this->scoreboard($runs);
        $atlasWinsAllCompleteCases = $this->atlasWinsAllCompleteCases($runs);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $allRunsCompleted ? 'ready' : 'ready_for_replay',
            'replay_type' => 'external_rival_evidence_matrix',
            'source' => self::class,
            'evidence_directory_hash' => hash('sha256', $directory),
            'cases' => $cases,
            'systems' => $systems,
            'competitive_rubric' => [
                'schema_version' => AtlasFrontendCompetitiveRubricService::SCHEMA_VERSION,
                'score_max' => $rubric['score_max'],
                'rubric_hash' => $rubric['rubric_hash'],
            ],
            'required_manifest_fields' => $requiredFields,
            'runs' => $runs,
            'summary' => [
                'total_runs' => count($runs),
                'complete' => $complete,
                'missing_or_pending' => $missing,
                'invalid' => $invalid,
                'external_replay_completed' => $externalReplayCompleted,
                'all_runs_completed' => $allRunsCompleted,
            ],
            'scoreboard' => $scoreboard,
            'claim_policy' => [
                'may_claim_external_replay_completed' => $externalReplayCompleted,
                'may_claim_live_mode_superior_to_impeccable' => $externalReplayCompleted && $atlasWinsAllCompleteCases,
                'may_claim_world_best_frontend_system' => $allRunsCompleted && $atlasWinsAllCompleteCases,
                'world_best_requires_all_rival_runs_complete' => true,
                'world_best_requires_atlas_to_win_each_complete_case' => true,
                'documentation_only_claim_forbidden' => true,
                'raw_customer_source_or_prompt_forbidden' => true,
            ],
            'provider_policy' => [
                'provider_neutral' => true,
                'paid_provider_account_optional' => true,
                'manifest_must_use_hashes_or_artifact_refs' => true,
                'raw_prompts_or_customer_source_returned' => false,
                'external_system_names_are_comparison_labels_not_runtime_dependencies' => true,
            ],
            'remaining_gaps' => $allRunsCompleted ? [] : [
                'external_rival_replay_artifacts_required_for_world_best_claim',
            ],
        ];
        $payload['replay_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function writeTemplate(string $outputDirectory): array
    {
        $directory = $this->evidenceDirectory($outputDirectory);
        $created = [];

        foreach ($this->cases() as $case) {
            foreach ($this->systems() as $system) {
                $runDirectory = $directory.'/'.$case['id'].'/'.$system['id'];
                File::ensureDirectoryExists($runDirectory);
                $manifestPath = $runDirectory.'/manifest.json';

                if (! File::isFile($manifestPath)) {
                    File::put($manifestPath, json_encode($this->pendingManifest($case['id'], $system['id']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
                    $created[] = $case['id'].'/'.$system['id'].'/manifest.json';
                }
            }
        }

        $payload = [
            'schema_version' => self::TEMPLATE_SCHEMA_VERSION,
            'status' => 'ready',
            'template_type' => 'external_rival_replay_manifest_skeleton',
            'evidence_directory_hash' => hash('sha256', $directory),
            'created_count' => count($created),
            'created_manifest_refs' => $created,
            'required_manifest_fields' => $this->requiredManifestFields(),
            'competitive_rubric' => [
                'schema_version' => AtlasFrontendCompetitiveRubricService::SCHEMA_VERSION,
                'score_max' => app(AtlasFrontendCompetitiveRubricService::class)->rubric()['score_max'],
            ],
            'claim_policy' => [
                'template_is_not_replay_evidence' => true,
                'pending_manifests_do_not_authorize_market_claims' => true,
                'raw_prompts_or_customer_source_forbidden' => true,
            ],
        ];
        $payload['template_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function cases(): array
    {
        return [
            ['id' => 'saas_dashboard_repair', 'intent' => 'Repair dense SaaS dashboard UI without losing information density.'],
            ['id' => 'ecommerce_product_page', 'intent' => 'Create production-grade commerce product page with visual proof.'],
            ['id' => 'mobile_app_onboarding', 'intent' => 'Design mobile onboarding with responsive states and accessibility.'],
            ['id' => 'design_system_migration', 'intent' => 'Migrate UI to a company design system without drift.'],
            ['id' => 'live_mode_repair_loop', 'intent' => 'Use live browser selection, preview, accept/discard and source recovery.'],
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function systems(): array
    {
        return [
            ['id' => 'atlas_frontend', 'kind' => 'local_runtime'],
            ['id' => 'pbakaus_impeccable', 'kind' => 'external_rival'],
            ['id' => 'claude_design_plugin', 'kind' => 'external_rival'],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function requiredManifestFields(): array
    {
        return [
            'case_id',
            'system',
            'status',
            'run_id',
            'task_spec_hash',
            'output_artifact_ref',
            'output_artifact_hash',
            'screenshot_hashes',
            'anti_slop_report_hash',
            'verification_hashes',
            'score_breakdown',
            'score_total',
            'score_max',
            'completed_at',
        ];
    }

    /**
     * @param  array<int,string>  $requiredFields
     * @return array<string,mixed>
     */
    private function inspectManifest(string $manifestPath, string $caseId, string $system, array $requiredFields): array
    {
        if (! File::isFile($manifestPath)) {
            return $this->runPayload($caseId, $system, 'missing', ['manifest_missing'], null);
        }

        $raw = File::get($manifestPath);
        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            return $this->runPayload($caseId, $system, 'invalid', ['manifest_json_invalid'], hash('sha256', $raw));
        }

        $issues = [];
        foreach ($requiredFields as $field) {
            if (! array_key_exists($field, $manifest) || $manifest[$field] === null || $manifest[$field] === '') {
                $issues[] = 'missing_'.$field;
            }
        }

        if (($manifest['case_id'] ?? null) !== $caseId) {
            $issues[] = 'case_id_mismatch';
        }
        if (($manifest['system'] ?? null) !== $system) {
            $issues[] = 'system_mismatch';
        }
        if (($manifest['status'] ?? null) !== 'complete') {
            $issues[] = 'status_not_complete';
        }
        if (! is_array($manifest['screenshot_hashes'] ?? null) || $manifest['screenshot_hashes'] === []) {
            $issues[] = 'screenshot_hashes_required';
        }
        if (! is_array($manifest['verification_hashes'] ?? null) || $manifest['verification_hashes'] === []) {
            $issues[] = 'verification_hashes_required';
        }
        if ($this->hasForbiddenRawFields($manifest)) {
            $issues[] = 'forbidden_raw_prompt_or_source_field_present';
        }
        if (! is_numeric($manifest['score_total'] ?? null) || ! is_numeric($manifest['score_max'] ?? null) || (int) $manifest['score_max'] <= 0) {
            $issues[] = 'invalid_score';
        }
        if (! is_array($manifest['score_breakdown'] ?? null)) {
            $issues[] = 'score_breakdown_required';
        } elseif (is_numeric($manifest['score_total'] ?? null) && is_numeric($manifest['score_max'] ?? null)) {
            $issues = array_merge($issues, app(AtlasFrontendCompetitiveRubricService::class)->validateBreakdown(
                $manifest['score_breakdown'],
                (int) $manifest['score_total'],
                (int) $manifest['score_max'],
            ));
        }

        $status = $issues === [] ? 'complete' : (in_array('status_not_complete', $issues, true) ? 'pending' : 'invalid');

        return $this->runPayload($caseId, $system, $status, $issues, hash('sha256', $raw), [
            'run_id_hash' => isset($manifest['run_id']) ? hash('sha256', (string) $manifest['run_id']) : null,
            'score_total' => is_numeric($manifest['score_total'] ?? null) ? (int) $manifest['score_total'] : null,
            'score_max' => is_numeric($manifest['score_max'] ?? null) ? (int) $manifest['score_max'] : null,
            'completed_at' => is_string($manifest['completed_at'] ?? null) ? $manifest['completed_at'] : null,
        ]);
    }

    /**
     * @param  array<int,string>  $issues
     * @param  array<string,mixed>|null  $extra
     * @return array<string,mixed>
     */
    private function runPayload(string $caseId, string $system, string $status, array $issues, ?string $manifestHash, ?array $extra = null): array
    {
        return array_filter([
            'case_id' => $caseId,
            'system' => $system,
            'status' => $status,
            'manifest_hash' => $manifestHash,
            'issues' => $issues,
            'run_id_hash' => $extra['run_id_hash'] ?? null,
            'score_total' => $extra['score_total'] ?? null,
            'score_max' => $extra['score_max'] ?? null,
            'completed_at' => $extra['completed_at'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<int,array<string,mixed>>  $runs
     * @return array<string,mixed>
     */
    private function scoreboard(array $runs): array
    {
        $scoreboard = [];
        foreach ($runs as $run) {
            $system = (string) $run['system'];
            $scoreboard[$system] ??= ['score' => 0, 'max' => 0, 'complete_runs' => 0];
            if (($run['status'] ?? null) !== 'complete') {
                continue;
            }

            $scoreboard[$system]['score'] += (int) ($run['score_total'] ?? 0);
            $scoreboard[$system]['max'] += (int) ($run['score_max'] ?? 0);
            $scoreboard[$system]['complete_runs']++;
        }

        foreach ($scoreboard as $system => $data) {
            $scoreboard[$system]['percent'] = round(((int) $data['score'] / max(1, (int) $data['max'])) * 100, 2);
        }

        return $scoreboard;
    }

    /**
     * @param  array<int,array<string,mixed>>  $runs
     */
    private function atlasWinsAllCompleteCases(array $runs): bool
    {
        foreach ($this->cases() as $case) {
            $caseRuns = collect($runs)->where('case_id', $case['id'])->where('status', 'complete');
            if ($caseRuns->count() < count($this->systems())) {
                return false;
            }

            $atlas = (int) data_get($caseRuns->firstWhere('system', 'atlas_frontend'), 'score_total', -1);
            $bestRival = $caseRuns
                ->reject(fn (array $run): bool => $run['system'] === 'atlas_frontend')
                ->max(fn (array $run): int => (int) ($run['score_total'] ?? -1));

            if ($atlas < $bestRival) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function hasForbiddenRawFields(array $manifest): bool
    {
        $forbidden = ['raw_prompt', 'prompt', 'source', 'raw_source', 'customer_source', 'customer_data'];

        return collect(array_keys($manifest))
            ->contains(fn (string $key): bool => in_array(Str::snake($key), $forbidden, true));
    }

    /**
     * @return array<string,mixed>
     */
    private function pendingManifest(string $caseId, string $system): array
    {
        return [
            'case_id' => $caseId,
            'system' => $system,
            'status' => 'pending',
            'run_id' => null,
            'task_spec_hash' => null,
            'output_artifact_ref' => null,
            'output_artifact_hash' => null,
            'screenshot_hashes' => [],
            'anti_slop_report_hash' => null,
            'verification_hashes' => [],
            'score_breakdown' => $this->pendingScoreBreakdown(),
            'score_total' => null,
            'score_max' => app(AtlasFrontendCompetitiveRubricService::class)->rubric()['score_max'],
            'completed_at' => null,
            'notes' => 'Do not store raw prompts, raw customer source, cookies, tokens or provider secrets in this manifest.',
        ];
    }

    /**
     * @return array<string,null>
     */
    private function pendingScoreBreakdown(): array
    {
        return collect(app(AtlasFrontendCompetitiveRubricService::class)->rubric()['dimensions'])
            ->mapWithKeys(fn (array $dimension): array => [(string) $dimension['id'] => null])
            ->all();
    }

    private function evidenceDirectory(?string $directory): string
    {
        $directory = trim((string) $directory);

        return $directory !== '' ? rtrim($directory, DIRECTORY_SEPARATOR) : storage_path('app/atlas/frontend-rival-replay');
    }
}
