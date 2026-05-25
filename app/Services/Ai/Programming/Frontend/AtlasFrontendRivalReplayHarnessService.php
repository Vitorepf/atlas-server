<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class AtlasFrontendRivalReplayHarnessService
{
    public const SCHEMA_VERSION = 'atlas.frontend.rival_replay_harness.v1';

    public const TEMPLATE_SCHEMA_VERSION = 'atlas.frontend.rival_replay_template.v1';

    public const TASK_SPEC_SCHEMA_VERSION = 'atlas.frontend.rival_replay_task_spec.v1';

    public const RUNNER_KIT_SCHEMA_VERSION = 'atlas.frontend.rival_replay_runner_kit.v1';

    public const EVIDENCE_WORKLIST_SCHEMA_VERSION = 'atlas.frontend.rival_replay_evidence_worklist.v1';

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
        foreach ($cases as $case) {
            foreach ($systems as $system) {
                $manifestPath = $directory.'/'.$case['id'].'/'.$system['id'].'/manifest.json';
                $run = $this->inspectManifest($manifestPath, $case['id'], $system['id'], $requiredFields);
                $runs[] = $run;
            }
        }

        $runs = $this->applyFairnessGates($runs);
        $complete = collect($runs)->where('status', 'complete')->count();
        $invalid = collect($runs)->where('status', 'invalid')->count();
        $missing = count($runs) - $complete - $invalid;
        $externalRuns = array_values(array_filter($runs, fn (array $run): bool => $run['system'] !== 'atlas_frontend'));
        $externalReplayCompleted = $externalRuns !== [] && collect($externalRuns)->every(fn (array $run): bool => $run['status'] === 'complete');
        $allRunsCompleted = $runs !== [] && collect($runs)->every(fn (array $run): bool => $run['status'] === 'complete');
        $scoreboard = $this->scoreboard($runs);
        $fairness = $this->fairnessSummary($runs);
        $evidencePackReadiness = $this->evidencePackReadiness($directory);
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
            'fairness' => $fairness,
            'evidence_pack_readiness' => $evidencePackReadiness,
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
                'world_best_requires_same_task_spec_hash_per_case' => true,
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
                ...($evidencePackReadiness['status'] === 'ready' ? [] : ['rival_replay_evidence_packs_incomplete']),
            ],
        ];
        $payload['replay_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function evidencePackReadiness(string $directory): array
    {
        $packs = [];
        foreach ($this->cases() as $case) {
            foreach ($this->systems() as $system) {
                $path = $directory.'/'.$case['id'].'/'.$system['id'].'/evidence/evidence-pack.json';
                if (! File::isFile($path)) {
                    $packs[] = [
                        'case_id' => $case['id'],
                        'system' => $system['id'],
                        'status' => 'missing',
                        'blockers' => ['evidence_pack_missing'],
                    ];

                    continue;
                }

                $verification = app(AtlasFrontendEvidencePackVerifierService::class)->verify($path);
                $packs[] = [
                    'case_id' => $case['id'],
                    'system' => $system['id'],
                    'status' => ($verification['status'] ?? null) === 'passed' ? 'passed' : 'blocked',
                    'verification_hash' => $verification['verification_hash'] ?? null,
                    'blockers' => array_values(array_unique((array) ($verification['blockers'] ?? []))),
                    'warnings' => array_values(array_unique((array) ($verification['warnings'] ?? []))),
                ];
            }
        }

        $present = collect($packs)->whereNotIn('status', ['missing'])->count();
        $passed = collect($packs)->where('status', 'passed')->count();
        $blocked = collect($packs)->where('status', 'blocked')->count();
        $missing = collect($packs)->where('status', 'missing')->count();

        return [
            'schema_version' => 'atlas.frontend.rival_replay_evidence_pack_readiness.v1',
            'status' => $passed === count($packs) ? 'ready' : 'pending',
            'summary' => [
                'total' => count($packs),
                'present' => $present,
                'passed' => $passed,
                'blocked' => $blocked,
                'missing' => $missing,
            ],
            'required_artifact_kinds' => app(AtlasFrontendEvidencePackVerifierService::class)->requiredArtifactKinds(),
            'packs' => $packs,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function writeTemplate(string $outputDirectory): array
    {
        $directory = $this->evidenceDirectory($outputDirectory);
        $created = [];

        $createdTaskSpecs = [];

        foreach ($this->cases() as $case) {
            $taskSpec = $this->replayTaskSpec($case);
            $taskSpecPath = $directory.'/'.$case['id'].'/task-spec.json';
            File::ensureDirectoryExists(dirname($taskSpecPath));
            if (! File::isFile($taskSpecPath)) {
                File::put($taskSpecPath, json_encode($taskSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
                $createdTaskSpecs[] = $case['id'].'/task-spec.json';
            }

            foreach ($this->systems() as $system) {
                $runDirectory = $directory.'/'.$case['id'].'/'.$system['id'];
                File::ensureDirectoryExists($runDirectory);
                $manifestPath = $runDirectory.'/manifest.json';

                if (! File::isFile($manifestPath)) {
                    File::put($manifestPath, json_encode($this->pendingManifest($case['id'], $system['id'], $taskSpec), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
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
            'created_task_spec_refs' => $createdTaskSpecs,
            'task_spec_schema_version' => self::TASK_SPEC_SCHEMA_VERSION,
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
     * @return array<string,mixed>
     */
    public function writeRunnerKit(string $outputDirectory): array
    {
        $directory = $this->evidenceDirectory($outputDirectory);
        $template = $this->writeTemplate($directory);
        $rubric = app(AtlasFrontendCompetitiveRubricService::class)->rubric();
        $packets = [];
        $createdEvidencePackRefs = [];

        foreach ($this->cases() as $case) {
            $taskSpec = $this->taskSpecForCase($directory, $case);
            foreach ($this->systems() as $system) {
                $evidencePackRef = $case['id'].'/'.$system['id'].'/evidence/evidence-pack.json';
                if ($this->writePendingEvidencePack($directory, $case['id'], $system['id'], $taskSpec)) {
                    $createdEvidencePackRefs[] = $evidencePackRef;
                }

                $packets[] = [
                    'id' => 'run_'.$case['id'].'_'.$system['id'],
                    'case_id' => $case['id'],
                    'system' => $system['id'],
                    'system_kind' => $system['kind'],
                    'task_spec_ref' => $case['id'].'/task-spec.json',
                    'manifest_ref' => $case['id'].'/'.$system['id'].'/manifest.json',
                    'evidence_pack_ref' => $evidencePackRef,
                    'execution_steps' => $this->runnerSteps($system['id']),
                    'evidence_checklist' => $this->runnerEvidenceChecklist(),
                    'scoring_policy' => [
                        'rubric_schema_version' => AtlasFrontendCompetitiveRubricService::SCHEMA_VERSION,
                        'rubric_hash' => $rubric['rubric_hash'],
                        'score_max' => $rubric['score_max'],
                        'score_breakdown_must_sum_to_total' => true,
                    ],
                    'completion_policy' => [
                        'complete_only_after_manifest_fields_are_filled' => true,
                        'evidence_pack_must_verify_before_complete_manifest' => true,
                        'same_task_spec_hash_required_across_systems_in_case' => true,
                        'raw_prompts_customer_source_tokens_or_cookies_forbidden' => true,
                    ],
                ];
            }
        }

        $payload = [
            'schema_version' => self::RUNNER_KIT_SCHEMA_VERSION,
            'status' => 'ready',
            'runner_type' => 'provider_neutral_external_rival_replay_runner',
            'source' => self::class,
            'evidence_directory_hash' => hash('sha256', $directory),
            'template_hash' => $template['template_hash'] ?? null,
            'run_packet_count' => count($packets),
            'run_packets' => $packets,
            'created_evidence_pack_refs' => $createdEvidencePackRefs,
            'commands' => [
                'inspect' => 'php artisan atlas:frontend:replay inspect --evidence='.$directory.' --json',
                'world_best_plan' => 'php artisan atlas:frontend:world-best-plan --rival-evidence='.$directory.' --json --strict',
            ],
            'claim_policy' => [
                'runner_kit_is_not_replay_evidence' => true,
                'pending_manifests_do_not_authorize_market_claims' => true,
                'world_best_requires_all_run_packets_complete' => true,
                'raw_prompts_or_customer_source_returned' => false,
            ],
        ];
        $payload['runner_kit_hash'] = MissionCanonicalHash::sha256($payload);
        File::put($directory.'/replay-runner-kit.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function writeEvidenceWorklist(string $evidenceDirectory, ?string $outputPath = null): array
    {
        $directory = $this->evidenceDirectory($evidenceDirectory);
        $inspect = $this->inspect($directory);
        $requiredKinds = app(AtlasFrontendEvidencePackVerifierService::class)->requiredArtifactKinds();
        $workItems = [];

        foreach ((array) data_get($inspect, 'evidence_pack_readiness.packs', []) as $pack) {
            $caseId = (string) ($pack['case_id'] ?? '');
            $system = (string) ($pack['system'] ?? '');
            if ($caseId === '' || $system === '' || ($pack['status'] ?? null) === 'passed') {
                continue;
            }

            $root = $caseId.'/'.$system;
            $workItems[] = [
                'id' => 'fill_evidence_pack_'.$caseId.'_'.$system,
                'case_id' => $caseId,
                'system' => $system,
                'status' => $pack['status'] ?? 'missing',
                'blockers' => $pack['blockers'] ?? [],
                'pack_manifest_ref' => $root.'/evidence/evidence-pack.json',
                'run_manifest_ref' => $root.'/manifest.json',
                'task_spec_ref' => $caseId.'/task-spec.json',
                'artifact_slots' => array_map(fn (string $kind): array => [
                    'kind' => $kind,
                    'artifact_ref' => $root.'/evidence/artifacts/'.$kind.'.json',
                    'hash_command' => 'shasum -a 256 '.$root.'/evidence/artifacts/'.$kind.'.json',
                    'pack_sha256_field' => 'artifacts[] where kind='.$kind.'.sha256',
                ], $requiredKinds),
                'manifest_hash_mapping' => [
                    'output_artifact_hash' => 'sha256(output_artifact)',
                    'screenshot_hashes[]' => 'sha256(screenshot_set)',
                    'anti_slop_report_hash' => 'sha256(anti_slop_report)',
                    'verification_hashes[]' => 'sha256(verification_report)',
                ],
                'completion_steps' => [
                    'capture_real_artifact_files_under_artifact_refs',
                    'update_evidence_pack_sha256_values_from_hash_command',
                    'mirror_required_hashes_into_run_manifest',
                    'fill_run_id_score_breakdown_score_total_completed_at',
                    'rerun_php_artisan_atlas_frontend_replay_inspect',
                ],
            ];
        }

        $target = $this->worklistOutputPath($directory, $outputPath);
        File::ensureDirectoryExists(dirname($target));

        $payload = [
            'schema_version' => self::EVIDENCE_WORKLIST_SCHEMA_VERSION,
            'status' => $workItems === [] ? 'ready' : 'pending',
            'worklist_type' => 'rival_replay_evidence_pack_completion',
            'source' => self::class,
            'evidence_directory_hash' => hash('sha256', $directory),
            'replay_hash' => $inspect['replay_hash'] ?? null,
            'evidence_pack_readiness' => [
                'schema_version' => data_get($inspect, 'evidence_pack_readiness.schema_version'),
                'status' => data_get($inspect, 'evidence_pack_readiness.status'),
                'summary' => data_get($inspect, 'evidence_pack_readiness.summary'),
            ],
            'work_item_count' => count($workItems),
            'work_items' => $workItems,
            'output_ref_hash' => hash('sha256', $target),
            'commands' => [
                'inspect' => 'php artisan atlas:frontend:replay inspect --evidence='.$directory.' --json',
                'world_best_plan' => 'php artisan atlas:frontend:world-best-plan --rival-evidence='.$directory.' --json --strict',
            ],
            'claim_policy' => [
                'worklist_is_not_evidence' => true,
                'all_artifact_files_must_be_real_and_hash_verified' => true,
                'raw_prompts_customer_source_tokens_or_cookies_forbidden' => true,
                'world_best_claim_forbidden_until_inspect_and_public_proof_pass' => true,
            ],
        ];
        $payload['worklist_hash'] = MissionCanonicalHash::sha256($payload);
        File::put($target, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $payload;
    }

    /**
     * @param  array<string,string>  $case
     * @return array<string,mixed>
     */
    private function taskSpecForCase(string $directory, array $case): array
    {
        $path = $directory.'/'.$case['id'].'/task-spec.json';
        if (File::isFile($path)) {
            $taskSpec = json_decode(File::get($path), true);
            if (is_array($taskSpec)) {
                return $taskSpec;
            }
        }

        return $this->replayTaskSpec($case);
    }

    /**
     * @param  array<string,mixed>  $taskSpec
     */
    private function writePendingEvidencePack(string $directory, string $caseId, string $system, array $taskSpec): bool
    {
        $evidenceDirectory = $directory.'/'.$caseId.'/'.$system.'/evidence';
        $manifestPath = $evidenceDirectory.'/evidence-pack.json';

        File::ensureDirectoryExists($evidenceDirectory.'/artifacts');
        if (File::isFile($manifestPath)) {
            return false;
        }

        File::put($manifestPath, json_encode([
            'schema_version' => AtlasFrontendEvidencePackVerifierService::PACK_SCHEMA_VERSION,
            'pack_id' => 'replace-with-run-id',
            'case_id' => $caseId,
            'system' => $system,
            'task_spec_hash' => $taskSpec['task_spec_hash'] ?? '<sha256-64-hex>',
            'artifacts' => array_map(fn (string $kind): array => [
                'kind' => $kind,
                'path' => 'artifacts/'.$kind.'.json',
                'sha256' => '<sha256-64-hex>',
            ], app(AtlasFrontendEvidencePackVerifierService::class)->requiredArtifactKinds()),
            'notes' => 'Fill artifact files and hashes only. Do not store raw prompts, customer source, cookies, tokens or provider secrets.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return true;
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
            'task_spec_ref',
            'evidence_pack_ref',
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
        $issues = array_merge($issues, $this->validateTaskSpecRef($manifest, $manifestPath, $caseId));
        $evidencePack = $this->validateEvidencePackRef($manifest, $manifestPath, $caseId, $system);
        $issues = array_merge($issues, $evidencePack['issues']);
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
            'task_spec_hash' => is_string($manifest['task_spec_hash'] ?? null) ? (string) $manifest['task_spec_hash'] : null,
            'task_spec_ref' => is_string($manifest['task_spec_ref'] ?? null) ? (string) $manifest['task_spec_ref'] : null,
            'evidence_pack_ref' => is_string($manifest['evidence_pack_ref'] ?? null) ? (string) $manifest['evidence_pack_ref'] : null,
            'evidence_pack_verification_hash' => $evidencePack['verification_hash'] ?? null,
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
            'task_spec_hash' => $extra['task_spec_hash'] ?? null,
            'task_spec_ref' => $extra['task_spec_ref'] ?? null,
            'evidence_pack_ref' => $extra['evidence_pack_ref'] ?? null,
            'evidence_pack_verification_hash' => $extra['evidence_pack_verification_hash'] ?? null,
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
     * @return array<int,array<string,mixed>>
     */
    private function applyFairnessGates(array $runs): array
    {
        foreach ($this->cases() as $case) {
            $evaluatedIndexes = [];
            foreach ($runs as $index => $run) {
                if (
                    ($run['case_id'] ?? null) === $case['id']
                    && isset($run['task_spec_hash'])
                    && in_array(($run['status'] ?? null), ['complete', 'invalid'], true)
                ) {
                    $evaluatedIndexes[] = $index;
                }
            }

            if (count($evaluatedIndexes) < count($this->systems())) {
                continue;
            }

            $taskSpecHashes = array_values(array_unique(array_map(
                fn (int $index): string => (string) ($runs[$index]['task_spec_hash'] ?? ''),
                $evaluatedIndexes,
            )));

            if (count($taskSpecHashes) > 1) {
                foreach ($evaluatedIndexes as $index) {
                    $runs[$index] = $this->invalidateRun($runs[$index], 'task_spec_hash_mismatch_across_systems');
                }
            }
        }

        return array_values($runs);
    }

    /**
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function invalidateRun(array $run, string $issue): array
    {
        $run['status'] = 'invalid';
        $run['issues'] = array_values(array_unique(array_merge((array) ($run['issues'] ?? []), [$issue])));

        return $run;
    }

    /**
     * @param  array<int,array<string,mixed>>  $runs
     * @return array<string,mixed>
     */
    private function fairnessSummary(array $runs): array
    {
        $cases = [];
        foreach ($this->cases() as $case) {
            $caseRuns = collect($runs)->where('case_id', $case['id']);
            $complete = $caseRuns->where('status', 'complete')->count();
            $invalidIssues = $caseRuns
                ->flatMap(fn (array $run): array => (array) ($run['issues'] ?? []))
                ->filter(fn (mixed $issue): bool => is_string($issue) && str_contains($issue, 'mismatch_across_systems'))
                ->values()
                ->all();

            $cases[] = [
                'case_id' => $case['id'],
                'status' => $invalidIssues !== [] ? 'failed' : ($complete === count($this->systems()) ? 'passed' : 'pending'),
                'same_task_spec_hash_across_systems' => ! in_array('task_spec_hash_mismatch_across_systems', $invalidIssues, true),
                'issues' => array_values(array_unique($invalidIssues)),
            ];
        }

        return [
            'schema_version' => 'atlas.frontend.rival_replay_fairness.v1',
            'status' => collect($cases)->every(fn (array $case): bool => $case['status'] === 'passed')
                ? 'passed'
                : (collect($cases)->contains(fn (array $case): bool => $case['status'] === 'failed') ? 'failed' : 'pending'),
            'policy' => [
                'same_task_spec_hash_required_across_systems' => true,
                'same_rubric_required_across_systems' => true,
                'provider_brand_is_not_a_score_dimension' => true,
            ],
            'cases' => $cases,
        ];
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
     * @return array<int,string>
     */
    private function validateTaskSpecRef(array $manifest, string $manifestPath, string $caseId): array
    {
        $issues = [];
        $ref = is_string($manifest['task_spec_ref'] ?? null) ? (string) $manifest['task_spec_ref'] : '';

        if ($ref !== '../task-spec.json') {
            return ['task_spec_ref_must_be_case_canonical'];
        }

        $taskSpecPath = dirname($manifestPath).DIRECTORY_SEPARATOR.$ref;
        if (! File::isFile($taskSpecPath)) {
            return ['task_spec_ref_missing'];
        }

        $taskSpec = json_decode(File::get($taskSpecPath), true);
        if (! is_array($taskSpec)) {
            return ['task_spec_ref_json_invalid'];
        }

        if (($taskSpec['schema_version'] ?? null) !== self::TASK_SPEC_SCHEMA_VERSION) {
            $issues[] = 'task_spec_ref_schema_invalid';
        }
        if (($taskSpec['case_id'] ?? null) !== $caseId) {
            $issues[] = 'task_spec_ref_case_mismatch';
        }
        if (! is_string($taskSpec['task_spec_hash'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $taskSpec['task_spec_hash'])) {
            $issues[] = 'task_spec_ref_hash_invalid';
        } elseif (($manifest['task_spec_hash'] ?? null) !== $taskSpec['task_spec_hash']) {
            $issues[] = 'task_spec_hash_mismatch_with_task_spec_ref';
        }

        return $issues;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array{issues: array<int,string>, verification_hash?: string}
     */
    private function validateEvidencePackRef(array $manifest, string $manifestPath, string $caseId, string $system): array
    {
        $ref = is_string($manifest['evidence_pack_ref'] ?? null) ? (string) $manifest['evidence_pack_ref'] : '';
        if ($ref === '' || str_starts_with($ref, '/') || str_contains($ref, '..')) {
            return ['issues' => ['evidence_pack_ref_invalid']];
        }

        $packPath = dirname($manifestPath).DIRECTORY_SEPARATOR.$ref;
        if (! File::isFile($packPath)) {
            return ['issues' => ['evidence_pack_ref_missing']];
        }

        $verification = app(AtlasFrontendEvidencePackVerifierService::class)->verify($packPath);
        $issues = [];
        if (($verification['status'] ?? null) !== 'passed') {
            $issues[] = 'evidence_pack_verification_failed';
        }
        if (($verification['case_id'] ?? null) !== $caseId) {
            $issues[] = 'evidence_pack_case_mismatch';
        }
        if (($verification['system'] ?? null) !== $system) {
            $issues[] = 'evidence_pack_system_mismatch';
        }
        if (($verification['task_spec_hash'] ?? null) !== ($manifest['task_spec_hash'] ?? null)) {
            $issues[] = 'evidence_pack_task_spec_hash_mismatch';
        }

        $artifactHashes = collect((array) ($verification['artifact_results'] ?? []))
            ->filter(fn (array $artifact): bool => ($artifact['status'] ?? null) === 'present')
            ->mapWithKeys(fn (array $artifact): array => [(string) ($artifact['kind'] ?? '') => (string) ($artifact['sha256'] ?? '')])
            ->all();

        if (($manifest['output_artifact_hash'] ?? null) !== ($artifactHashes['output_artifact'] ?? null)) {
            $issues[] = 'evidence_pack_output_artifact_hash_mismatch';
        }
        if (($manifest['anti_slop_report_hash'] ?? null) !== ($artifactHashes['anti_slop_report'] ?? null)) {
            $issues[] = 'evidence_pack_anti_slop_hash_mismatch';
        }
        if (! in_array($artifactHashes['screenshot_set'] ?? null, (array) ($manifest['screenshot_hashes'] ?? []), true)) {
            $issues[] = 'evidence_pack_screenshot_hash_missing_from_manifest';
        }
        if (! in_array($artifactHashes['verification_report'] ?? null, (array) ($manifest['verification_hashes'] ?? []), true)) {
            $issues[] = 'evidence_pack_verification_hash_missing_from_manifest';
        }

        return [
            'issues' => array_values(array_unique(array_merge($issues, array_map(
                fn (string $blocker): string => 'evidence_pack_'.$blocker,
                (array) ($verification['blockers'] ?? []),
            )))),
            'verification_hash' => is_string($verification['verification_hash'] ?? null) ? $verification['verification_hash'] : null,
        ];
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
    private function pendingManifest(string $caseId, string $system, array $taskSpec): array
    {
        return [
            'case_id' => $caseId,
            'system' => $system,
            'status' => 'pending',
            'run_id' => null,
            'task_spec_hash' => $taskSpec['task_spec_hash'] ?? null,
            'task_spec_ref' => '../task-spec.json',
            'evidence_pack_ref' => 'evidence/evidence-pack.json',
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
     * @param  array<string,string>  $case
     * @return array<string,mixed>
     */
    private function replayTaskSpec(array $case): array
    {
        $caseId = (string) $case['id'];
        $spec = [
            'schema_version' => self::TASK_SPEC_SCHEMA_VERSION,
            'case_id' => $caseId,
            'intent' => $case['intent'],
            'surface' => 'programming.frontend',
            'systems_under_test' => array_column($this->systems(), 'id'),
            'required_viewports' => ['mobile_390', 'tablet_768', 'desktop_1440'],
            'required_states' => $this->caseStates($caseId),
            'acceptance_criteria' => $this->caseAcceptanceCriteria($caseId),
            'evidence_requirements' => [
                'output_artifact_hash',
                'screenshot_hashes',
                'evidence_pack_ref',
                'anti_slop_report_hash',
                'verification_hashes',
                'competitive_score_breakdown',
            ],
            'fairness_policy' => [
                'same_task_spec_hash_required_for_all_systems' => true,
                'same_rubric_required_for_all_systems' => true,
                'provider_brand_is_not_a_score_dimension' => true,
                'raw_prompts_customer_source_tokens_or_cookies_forbidden' => true,
            ],
        ];
        $spec['task_spec_hash'] = MissionCanonicalHash::sha256($spec);

        return $spec;
    }

    /**
     * @return array<int,string>
     */
    private function caseStates(string $caseId): array
    {
        return match ($caseId) {
            'saas_dashboard_repair' => ['loaded_dense_table', 'empty_state', 'error_state', 'filtering_active'],
            'ecommerce_product_page' => ['default_product', 'variant_selected', 'cart_feedback', 'mobile_checkout_entry'],
            'mobile_app_onboarding' => ['first_step', 'permission_prompt', 'form_error', 'completion_state'],
            'design_system_migration' => ['before_component_mapping', 'after_component_mapping', 'token_exception', 'responsive_regression_check'],
            'live_mode_repair_loop' => ['element_selected', 'variant_previewed', 'variant_accepted', 'source_recovered'],
            default => ['default', 'error', 'empty', 'responsive'],
        };
    }

    /**
     * @return array<int,string>
     */
    private function caseAcceptanceCriteria(string $caseId): array
    {
        return match ($caseId) {
            'saas_dashboard_repair' => [
                'Preserve information density while fixing hierarchy, spacing and scan paths.',
                'No text overlap, console errors, inaccessible controls or viewport-specific regression.',
            ],
            'ecommerce_product_page' => [
                'Show product, price, variant choice, trust proof and conversion action without generic hero filler.',
                'Mobile and desktop states must remain shoppable and visually coherent.',
            ],
            'mobile_app_onboarding' => [
                'Guide the user through clear steps with accessible copy, controls and error recovery.',
                'Respect mobile ergonomics and avoid decorative UI that hides task progress.',
            ],
            'design_system_migration' => [
                'Use existing tokens and components unless an exception is explicitly evidenced.',
                'Prevent visual drift while preserving the product workflow.',
            ],
            'live_mode_repair_loop' => [
                'Support visual selection, variant preview, accept/discard and source recovery with audit hashes.',
                'Do not apply source patches when the file changed after prepare.',
            ],
            default => [
                'Satisfy product intent with responsive, accessible and evidence-backed frontend output.',
            ],
        };
    }

    /**
     * @return array<int,string>
     */
    private function runnerSteps(string $system): array
    {
        $dispatch = $system === 'atlas_frontend'
            ? 'Run Atlas Frontend using the referenced task spec through the normal governed frontend flow.'
            : 'Run the external rival manually or through its official workflow using the referenced task spec unchanged.';

        return [
            'Open task_spec_ref and keep the task_spec_hash unchanged.',
            $dispatch,
            'Capture output artifact ref/hash, screenshots, anti-slop report and verification hashes.',
            'Score the output with atlas.frontend.competitive_rubric.v1 without using provider brand as a score dimension.',
            'Fill manifest_ref with status=complete only after all required fields are backed by hashes or refs.',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function runnerEvidenceChecklist(): array
    {
        return [
            'task_spec_hash_matches_case_task_spec',
            'evidence_pack_ref_verified',
            'output_artifact_ref_present',
            'output_artifact_hash_present',
            'screenshot_hashes_present',
            'anti_slop_report_hash_present',
            'verification_hashes_present',
            'score_breakdown_present_and_valid',
            'completed_at_present',
            'no_raw_prompt_source_customer_data_tokens_or_cookies',
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

    private function worklistOutputPath(string $directory, ?string $outputPath): string
    {
        $outputPath = trim((string) $outputPath);

        return $outputPath !== ''
            ? $outputPath
            : $directory.'/replay-evidence-worklist.json';
    }
}
