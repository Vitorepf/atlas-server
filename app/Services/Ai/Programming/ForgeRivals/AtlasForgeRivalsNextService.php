<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use App\Services\Ai\Support\JsonFileStore;

/**
 * Atlas Forge Rivals · Next (pipeline state advisor).
 *
 * Detects the current state of a rivals run (or the operator's environment
 * when no run_id is supplied) and returns the single next command the
 * operator should copy-paste. Complements `doctor` (env probe) with a
 * flow-state advisor.
 *
 * Detections (highest priority first):
 *   - run_id missing or invalid format → suggest run-battery quick smoke.
 *   - corpus_registry not loadable → escalate.
 *   - worktrees missing → run setup.
 *   - manifest missing for run_id → run run-real.
 *   - manifest verdict invalid → suggest reset.
 *   - scorecard missing → run adjudicate.
 *   - replay not passing → run replay.
 *   - confirmations missing (real-provider modes) → list flags.
 *   - report not generated → run report.
 *   - everything green → suggest feeding the ledger.
 *
 * Read-only. Never invokes provider.
 */
final class AtlasForgeRivalsNextService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.next.v1';

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsProviderArenaCorpusService $corpus,
        private readonly AtlasForgeRivalsBatteryStateService $battery,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function next(array $input): array
    {
        $runId = trim((string) ($input['run_id'] ?? ''));
        $observations = [];
        $blockers = [];

        $corpusOk = $this->probeCorpus($observations);
        if (! $corpusOk) {
            $blockers[] = 'corpus_registry_unavailable';
        }

        if ($runId === '') {
            return $this->advise(
                status: 'ok',
                phase: 'no_run_id',
                summary: 'Nenhum run_id informado — escolha entre rodar uma bateria nova ou inspecionar um run existente.',
                command: 'php artisan atlas:forge:rivals run-battery --mode=local_fake --preset=quick --json',
                actions: [
                    [
                        'kind' => 'start_local_fake_smoke',
                        'reason' => 'safest_validation_of_harness',
                        'command' => 'php artisan atlas:forge:rivals run-battery --mode=local_fake --preset=quick --json',
                    ],
                    [
                        'kind' => 'check_environment',
                        'reason' => 'doctor_probes_git_worktree_python_bytecode',
                        'command' => 'php artisan atlas:forge:rivals doctor --json',
                    ],
                ],
                observations: $observations,
                blockers: $blockers,
            );
        }

        $paths = $this->paths->paths($runId);
        $runExists = is_dir($paths['base']);
        $observations['run_dir_exists'] = $runExists;
        $observations['runs_root'] = $paths['root'];
        $observations['expected_run_dir'] = $paths['base'];
        if (! $runExists) {
            if ($this->looksLikeExternalEvidenceRun($paths['run_id'])) {
                return $this->advise(
                    status: 'blocked',
                    phase: 'external_evidence_missing',
                    summary: 'Run histórico/externo não está disponível no runs_root atual — restaure o diretório de evidência ou ingira um resultado externo antes de report/replay/ledger.',
                    command: 'php artisan atlas:forge:rivals next --run-id='.$paths['run_id'].' --json',
                    actions: [
                        [
                            'kind' => 'restore_run_evidence_directory',
                            'reason' => 'historical_or_external_run_dir_missing',
                            'command' => 'restore '.$paths['base'].' from trusted backup or artifact store',
                        ],
                        [
                            'kind' => 'ingest_external_deepswe_result',
                            'reason' => 'external_result_must_be_materialized_before_claim',
                            'command' => 'php artisan atlas:forge:rivals deepswe-batch-ingest --input=<external-result-root> --json',
                        ],
                        [
                            'kind' => 'inspect_available_runs',
                            'reason' => 'confirm_current_runs_root_before_replay',
                            'command' => 'ls -la '.$paths['root'],
                        ],
                    ],
                    observations: array_replace($observations, [
                        'external_evidence_required_before_claim' => true,
                        'score_or_claim_allowed' => false,
                    ]),
                    blockers: array_merge($blockers, [
                        'run_not_found:'.$paths['run_id'],
                        'external_evidence_artifact_missing',
                    ]),
                );
            }

            return $this->advise(
                status: 'blocked',
                phase: 'run_dir_missing',
                summary: 'Diretório do run não existe — rode setup + run-real (ou run-battery).',
                command: 'php artisan atlas:forge:rivals setup --source-ref=HEAD --json',
                actions: [
                    [
                        'kind' => 'setup_worktree',
                        'reason' => 'run_dir_missing',
                        'command' => 'php artisan atlas:forge:rivals setup --source-ref=HEAD --json',
                    ],
                ],
                observations: $observations,
                blockers: array_merge($blockers, ['run_not_found:'.$paths['run_id']]),
            );
        }

        $atlasWorktreeOk = is_dir($paths['atlas']);
        $rivalWorktreeOk = is_dir($paths['rival']);
        $observations['atlas_worktree'] = $atlasWorktreeOk;
        $observations['rival_worktree'] = $rivalWorktreeOk;
        if (! $atlasWorktreeOk || ! $rivalWorktreeOk) {
            return $this->advise(
                status: 'blocked',
                phase: 'worktrees_missing',
                summary: 'Worktrees ausentes — rode setup novamente antes de continuar.',
                command: 'php artisan atlas:forge:rivals setup --source-ref=HEAD --json',
                actions: [
                    [
                        'kind' => 'setup_worktree',
                        'reason' => 'worktree_missing',
                        'command' => 'php artisan atlas:forge:rivals setup --source-ref=HEAD --run-id='.$paths['run_id'].' --json',
                    ],
                ],
                observations: $observations,
                blockers: array_merge($blockers, ['worktrees_missing']),
            );
        }

        // Battery-aware short-circuit: if a battery.json exists for this
        // run_id and any case is still in pending/running, the canonical
        // next move is `resume`, not `run-real`. If every case is terminal,
        // suggest the battery-report so the operator collects evidence
        // before chasing manifest/scorecard files that may not exist for a
        // multi-case run.
        $batterySnapshot = $this->battery->snapshot($paths['run_id']);
        $observations['battery_exists'] = (bool) ($batterySnapshot['exists'] ?? false);
        if (($batterySnapshot['exists'] ?? false) === true) {
            $observations['battery_status'] = $batterySnapshot['battery_status'] ?? null;
            $observations['battery_case_count'] = (int) ($batterySnapshot['case_count'] ?? 0);
            $observations['battery_pending_case_count'] = (int) ($batterySnapshot['pending_case_count'] ?? 0);
            $observations['battery_next_case_id'] = $batterySnapshot['next_case_id'] ?? null;

            $pendingCount = (int) ($batterySnapshot['pending_case_count'] ?? 0);
            if ($pendingCount > 0) {
                $nextCaseId = (string) ($batterySnapshot['next_case_id'] ?? '');
                $resumeCommand = 'php artisan atlas:forge:rivals resume --run-id='.$paths['run_id'].' --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json';
                $summary = 'Bateria com '.$pendingCount.' case(s) pending'
                    .($nextCaseId !== '' ? ' (próximo: '.$nextCaseId.')' : '')
                    .' — retome para iterar só os pendings.';

                return $this->advise(
                    status: 'ok',
                    phase: 'battery_paused_pending_cases',
                    summary: $summary,
                    command: $resumeCommand,
                    actions: [
                        [
                            'kind' => 'resume_battery',
                            'reason' => 'pending_cases_in_battery',
                            'command' => $resumeCommand,
                        ],
                        [
                            'kind' => 'inspect_status',
                            'reason' => 'show_per_case_progress_counters',
                            'command' => 'php artisan atlas:forge:rivals status --run-id='.$paths['run_id'].' --json',
                        ],
                    ],
                    observations: $observations,
                    blockers: $blockers,
                );
            }

            // All cases settled → suggest the matrix/battery report.
            $reportCommand = 'php artisan atlas:forge:rivals battery-report --run-id='.$paths['run_id'].' --json';

            return $this->advise(
                status: 'ok',
                phase: 'battery_settled_all_cases_terminal',
                summary: 'Bateria com todos os '.((int) ($batterySnapshot['case_count'] ?? 0)).' cases em estado terminal — gere o report agregado.',
                command: $reportCommand,
                actions: [
                    [
                        'kind' => 'render_battery_report',
                        'reason' => 'every_case_reached_terminal_state',
                        'command' => $reportCommand,
                    ],
                    [
                        'kind' => 'inspect_status',
                        'reason' => 'audit_per_case_outcome_counters',
                        'command' => 'php artisan atlas:forge:rivals status --run-id='.$paths['run_id'].' --json',
                    ],
                ],
                observations: $observations,
                blockers: $blockers,
            );
        }

        $manifest = $this->readJson($paths['manifest_json']);
        $observations['manifest_present'] = $manifest !== [];
        if ($manifest === []) {
            return $this->advise(
                status: 'blocked',
                phase: 'manifest_missing',
                summary: 'Manifest ausente — rode run-real (ou run-battery) para esse run_id.',
                command: 'php artisan atlas:forge:rivals run-real --run-id='.$paths['run_id'].' --json',
                actions: [
                    [
                        'kind' => 'run_real',
                        'reason' => 'manifest_missing',
                        'command' => 'php artisan atlas:forge:rivals run-real --run-id='.$paths['run_id'].' --json',
                    ],
                ],
                observations: $observations,
                blockers: array_merge($blockers, ['manifest_missing']),
            );
        }

        $verdict = (string) ($manifest['verdict'] ?? 'unknown');
        $observations['verdict'] = $verdict;
        if (str_starts_with($verdict, 'invalid')) {
            return $this->advise(
                status: 'blocked',
                phase: 'verdict_invalid',
                summary: 'Manifest com verdict inválido ('.$verdict.') — abrir reset com razão antes de tentar de novo.',
                command: 'php artisan atlas:forge:rivals reset --run-id='.$paths['run_id'].' --reason=invalid_run --json',
                actions: [
                    [
                        'kind' => 'reset_invalid_run',
                        'reason' => 'manifest_invalid',
                        'command' => 'php artisan atlas:forge:rivals reset --run-id='.$paths['run_id'].' --reason=invalid_run --json',
                    ],
                ],
                observations: $observations,
                blockers: array_merge($blockers, ['verdict_invalid:'.$verdict]),
            );
        }

        $mode = (string) ($manifest['mode'] ?? 'unknown');
        $requiresProvider = in_array($mode, ['fair', 'full_power'], true);
        $confirmations = (array) ($manifest['confirmations'] ?? []);
        if ($requiresProvider) {
            $missing = [];
            foreach (['runbook_reviewed', 'provider_cost', 'real_provider_call'] as $flag) {
                if (! ($confirmations[$flag] ?? false)) {
                    $missing[] = $flag;
                }
            }
            if ($missing !== []) {
                $observations['missing_confirmations'] = $missing;

                return $this->advise(
                    status: 'blocked',
                    phase: 'confirmations_missing',
                    summary: 'Modo '.$mode.' exige todas as três confirmações operacionais antes de chamar provider.',
                    command: 'php artisan atlas:forge:rivals run-real --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --run-id='.$paths['run_id'].' --json',
                    actions: [
                        [
                            'kind' => 'pass_confirmations',
                            'reason' => 'missing:'.implode(',', $missing),
                            'command' => 'php artisan atlas:forge:rivals run-real --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --run-id='.$paths['run_id'].' --json',
                        ],
                    ],
                    observations: $observations,
                    blockers: array_merge($blockers, array_map(static fn (string $f): string => 'missing_confirmation:'.$f, $missing)),
                );
            }
        }

        $hasEvidence = is_file($paths['evidence'].'/atlas_receipt.json')
            && is_file($paths['evidence'].'/rival_receipt.json');
        $observations['evidence_present'] = $hasEvidence;
        if (! $hasEvidence) {
            return $this->advise(
                status: 'blocked',
                phase: 'evidence_missing',
                summary: 'Receipts ausentes — rode collect-evidence para garantir o pack.',
                command: 'php artisan atlas:forge:rivals collect-evidence --run-id='.$paths['run_id'].' --json',
                actions: [
                    [
                        'kind' => 'collect_evidence',
                        'reason' => 'evidence_missing',
                        'command' => 'php artisan atlas:forge:rivals collect-evidence --run-id='.$paths['run_id'].' --json',
                    ],
                ],
                observations: $observations,
                blockers: array_merge($blockers, ['evidence_missing']),
            );
        }

        $scorecardPresent = is_file($paths['scorecard_json']);
        $observations['scorecard_present'] = $scorecardPresent;
        if (! $scorecardPresent) {
            return $this->advise(
                status: 'ok',
                phase: 'scorecard_missing',
                summary: 'Scorecard ausente — rode adjudicate para gerar o veredito determinístico.',
                command: 'php artisan atlas:forge:rivals adjudicate --run-id='.$paths['run_id'].' --json',
                actions: [
                    [
                        'kind' => 'run_adjudicate',
                        'reason' => 'scorecard_missing',
                        'command' => 'php artisan atlas:forge:rivals adjudicate --run-id='.$paths['run_id'].' --json',
                    ],
                ],
                observations: $observations,
                blockers: $blockers,
            );
        }

        $reportPresent = is_file($paths['report_md']);
        $observations['report_present'] = $reportPresent;
        if (! $reportPresent) {
            return $this->advise(
                status: 'ok',
                phase: 'report_missing',
                summary: 'Scorecard pronto — rode report para gerar o relatório humano.',
                command: 'php artisan atlas:forge:rivals report --run-id='.$paths['run_id'].' --json --strict',
                actions: [
                    [
                        'kind' => 'render_report',
                        'reason' => 'report_md_missing',
                        'command' => 'php artisan atlas:forge:rivals report --run-id='.$paths['run_id'].' --json --strict',
                    ],
                ],
                observations: $observations,
                blockers: $blockers,
            );
        }

        return $this->advise(
            status: 'ok',
            phase: 'complete',
            summary: 'Pipeline completo para esse run_id — considere alimentar o ledger ou rodar nova bateria.',
            command: 'php artisan atlas:forge:rivals ledger-record --run-id='.$paths['run_id'].' --json',
            actions: [
                [
                    'kind' => 'feed_ledger',
                    'reason' => 'pipeline_complete',
                    'command' => 'php artisan atlas:forge:rivals ledger-record --run-id='.$paths['run_id'].' --json',
                ],
                [
                    'kind' => 're_read_report',
                    'reason' => 'inspect_final_human_report',
                    'command' => 'php artisan atlas:forge:rivals report --run-id='.$paths['run_id'].' --json',
                ],
            ],
            observations: $observations,
            blockers: $blockers,
        );
    }

    /**
     * @param  array<string,mixed>  $observations
     */
    private function probeCorpus(array &$observations): bool
    {
        try {
            $observations['corpus_case_count'] = count($this->corpus->cases());

            return true;
        } catch (\Throwable $e) {
            $observations['corpus_error'] = $e->getMessage();

            return false;
        }
    }

    private function looksLikeExternalEvidenceRun(string $runId): bool
    {
        return str_starts_with($runId, 'battery-')
            || str_starts_with($runId, 'deepswe-')
            || str_starts_with($runId, 'arena-')
            || str_contains($runId, 'external');
    }

    /**
     * @param  list<array<string,string>>  $actions
     * @param  array<string,mixed>  $observations
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function advise(
        string $status,
        string $phase,
        string $summary,
        string $command,
        array $actions,
        array $observations,
        array $blockers,
    ): array {
        return [
            'status' => $status,
            'next_schema_version' => self::SCHEMA_VERSION,
            'phase' => $phase,
            'summary' => $summary,
            'next_command' => $command,
            'actions' => $actions,
            'observations' => $observations,
            'blockers' => $blockers,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'separated_from_external_rivals_certification' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        return JsonFileStore::readArray($path) ?? [];
    }
}
