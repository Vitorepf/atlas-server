<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals\DeepSwe;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsAdjudicatorService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryEvidenceService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryReplayVerifierService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsCollectEvidenceService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsDecideSignalProjectionService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEventStream;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidenceBundleManifestService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidencePolicy;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsMatrixReportService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderPerformanceLedgerService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReplayService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsTrustedSignalGateService;
use App\Services\Ai\Support\JsonFileStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Imports already-produced DeepSWE/Pier artifacts into the Rivals evidence
 * contract without spawning Pier, Docker, agents or providers.
 */
final class AtlasForgeRivalsDeepSweResultIngestService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.deepswe_result_ingest.v1';

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsEventStream $events,
        private readonly AtlasForgeRivalsDeepSweTaskParserService $taskParser,
        private readonly AtlasForgeRivalsCollectEvidenceService $collectEvidence,
        private readonly AtlasForgeRivalsEvidenceBundleManifestService $evidenceBundle,
        private readonly AtlasForgeRivalsReplayService $replay,
        private readonly AtlasForgeRivalsTrustedSignalGateService $trustedSignal,
        private readonly AtlasForgeRivalsAdjudicatorService $adjudicator,
        private readonly AtlasForgeRivalsBatteryEvidenceService $batteryEvidence,
        private readonly AtlasForgeRivalsBatteryReplayVerifierService $batteryReplay,
        private readonly AtlasForgeRivalsMatrixReportService $matrixReport,
        private readonly AtlasForgeRivalsProviderPerformanceLedgerService $ledger,
        private readonly AtlasForgeRivalsDecideSignalProjectionService $decideSignal,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function ingest(array $input): array
    {
        $resultRoot = $this->inputPath($input);
        if ($resultRoot === null) {
            return $this->blocked(['deepswe_result_path_required:use_--input'], null);
        }
        if (! is_dir($resultRoot)) {
            return $this->blocked(['deepswe_result_path_not_found:'.$resultRoot], $resultRoot);
        }

        $armA = $this->loadArmResult($resultRoot, ['atlas', 'arm_a', 'a']);
        $armB = $this->loadArmResult($resultRoot, ['rival', 'arm_b', 'b']);
        $blockers = array_merge($armA['blockers'], $armB['blockers']);
        if ($blockers !== []) {
            return $this->blocked($blockers, $resultRoot);
        }
        $planBinding = $this->externalExecutionPlanBinding($input, $armA['result'], $armB['result']);
        $blockers = array_merge($blockers, (array) ($planBinding['blockers'] ?? []));

        $task = $this->taskManifest($input);
        if (($task['status'] ?? 'ok') !== 'ok') {
            foreach ((array) ($task['blockers'] ?? []) as $blocker) {
                $blockers[] = 'task_manifest:'.$blocker;
            }
        }

        foreach (['atlas' => $armA['result'], 'rival' => $armB['result']] as $label => $result) {
            $blockers = array_merge($blockers, $this->armEvidenceBlockers($label, $result));
        }

        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            $runId = 'deepswe-'.gmdate('Ymd-His').'-'.substr(hash('sha256', $resultRoot.microtime(true)), 0, 6);
        }

        $paths = $this->paths->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        $this->events->start($runId, [
            'source' => 'deepswe_result_ingest',
            'schema_version' => self::SCHEMA_VERSION,
            'result_root' => $resultRoot,
            'task_id' => $this->taskId($task, $armA['result'], $armB['result']),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);

        $atlasArtifacts = $this->materializeArmArtifacts($paths, 'atlas', $armA['result'], $resultRoot);
        $rivalArtifacts = $this->materializeArmArtifacts($paths, 'rival', $armB['result'], $resultRoot);
        $atlasReceipt = $this->receipt('atlas', $armA['result'], $atlasArtifacts, $task);
        $rivalReceipt = $this->receipt('rival', $armB['result'], $rivalArtifacts, $task);

        file_put_contents($paths['evidence'].'/atlas_receipt.json', $this->json($atlasReceipt));
        file_put_contents($paths['evidence'].'/rival_receipt.json', $this->json($rivalReceipt));
        file_put_contents($paths['evidence'].'/workspace_hashes.json', $this->json([
            'schema_version' => 'atlas.forge.rivals.external_workspace_hashes.v1',
            'source' => 'deepswe_result_ingest',
            'before' => [
                'task_manifest_hash' => $task['manifest_hash'] ?? null,
                'result_root_hash' => $this->directoryFingerprint($resultRoot),
            ],
            'after' => [
                'atlas_patch_sha256' => $atlasArtifacts['patch_sha256'],
                'rival_patch_sha256' => $rivalArtifacts['patch_sha256'],
            ],
            'dirty_after_run' => false,
        ]));

        $verdict = $blockers === [] ? 'comparable' : 'invalid_external_result_missing_evidence';
        $manifest = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $paths['run_id'],
            'mode' => 'external_deepswe',
            'preset' => 'deepswe',
            'case_source' => 'deepswe',
            'task_format' => 'harbor',
            'task_id' => $this->taskId($task, $armA['result'], $armB['result']),
            'case_id' => 'deepswe:'.$this->taskId($task, $armA['result'], $armB['result']),
            'task_category' => $this->taskCategory($task),
            'difficulty_level' => $this->difficultyLevel($task),
            'difficulty' => $this->difficultyLevel($task),
            'difficulty_level_origin' => 'deepswe_task_metadata',
            'role' => $this->taskRole($task),
            'framework' => $this->taskFramework($task),
            'result_root' => $resultRoot,
            'external_execution_plan_binding' => $planBinding,
            'external_execution_plan_fingerprint' => $planBinding['plan_fingerprint'] ?? null,
            'external_execution_plan_manifest_path' => $planBinding['plan_manifest_path'] ?? null,
            'task_manifest' => $this->taskSummary($task),
            'atlas_model' => (string) ($atlasReceipt['model'] ?? 'unknown'),
            'rival_model' => (string) ($rivalReceipt['model'] ?? 'unknown'),
            'verdict' => $verdict,
            'score' => null,
            'claim_ready' => false,
            'dirty_after_run' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'provider_tokens_may_have_been_spent' => $this->mayHaveSpentTokens($armA['result']) || $this->mayHaveSpentTokens($armB['result']),
            'blockers' => array_values(array_unique($blockers)),
            'evidence_contract' => [
                'requires_task_manifest' => true,
                'requires_patch_per_arm' => true,
                'requires_trajectory_per_arm' => true,
                'requires_verifier_result_per_arm' => true,
                'requires_replay_green' => true,
                'requires_matrix_lock_before_claim' => true,
                'requires_statistical_repeat_for_routing_confidence' => true,
            ],
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'separated_from_external_rivals_certification' => true,
        ];
        file_put_contents($paths['manifest_json'], $this->json($manifest));
        $this->events->event($runId, 'evidence_pack', [
            'source' => 'deepswe_result_ingest',
            'verdict' => $verdict,
            'claim_ready' => false,
            'blockers' => $manifest['blockers'],
        ]);

        $phases = [];
        $collectPre = $this->collectEvidence->collect([
            'run_id' => $paths['run_id'],
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
        $phases[] = $this->phase('collect-evidence-pre', $collectPre);
        $replayPre = $this->replay->replay([
            'run_id' => $paths['run_id'],
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
        $phases[] = $this->phase('replay-pre', $replayPre);

        $adjudicate = null;
        $collectFinal = null;
        $replayFinal = null;
        if (($collectPre['status'] ?? '') === 'ok' && ($replayPre['status'] ?? '') === 'ok') {
            $adjudicate = $this->adjudicator->adjudicate(['run_id' => $paths['run_id']]);
            $phases[] = $this->phase('adjudicate', $adjudicate);
            $collectFinal = $this->collectEvidence->collect([
                'run_id' => $paths['run_id'],
                'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            ]);
            $phases[] = $this->phase('collect-evidence-final', $collectFinal);
            $replayFinal = $this->replay->replay([
                'run_id' => $paths['run_id'],
                'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            ]);
            $phases[] = $this->phase('replay-final', $replayFinal);
        }

        $externalLifecycle = $this->externalEvidenceLifecycle(
            runId: $paths['run_id'],
            runDir: $paths['base'],
            bundlePath: $paths['evidence'].'/external_evidence_bundle_manifest.json',
            taskCategory: (string) ($manifest['task_category'] ?? ''),
            role: (string) ($manifest['role'] ?? 'builder'),
        );
        $phases[] = $this->phase('external-evidence-bundle', $externalLifecycle['bundle_manifest']);
        $phases[] = $this->phase('external-evidence-bundle-verify', $externalLifecycle['bundle_verification']);
        $phases[] = $this->phase('trusted-signal', $externalLifecycle['trusted_signal']);

        $phaseBlockers = [];
        foreach ($phases as $phase) {
            foreach ((array) ($phase['blockers'] ?? []) as $blocker) {
                $phaseBlockers[] = (string) $blocker;
            }
        }

        return [
            'status' => $blockers === [] && $phaseBlockers === [] ? 'ok' : 'blocked',
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $paths['run_id'],
            'result_root' => $resultRoot,
            'external_execution_plan_binding' => $planBinding,
            'verdict' => $verdict,
            'task_id' => $manifest['task_id'],
            'phases' => $phases,
            'blockers' => array_values(array_unique(array_merge($blockers, $phaseBlockers))),
            'manifest_path' => $paths['manifest_json'],
            'evidence_pack_path' => $paths['evidence'].'/evidence_pack.json',
            'scorecard_path' => is_file($paths['scorecard_json']) ? $paths['scorecard_json'] : null,
            'replay_passes' => (bool) (($replayFinal ?? $replayPre)['replay_passes'] ?? false),
            'external_evidence_lifecycle' => $externalLifecycle,
            'external_evidence_bundle_manifest_path' => $externalLifecycle['bundle_manifest_path'],
            'external_evidence_bundle_status' => $externalLifecycle['bundle_status'],
            'external_evidence_bundle_verified' => $externalLifecycle['bundle_verified'],
            'trusted_signal_status' => $externalLifecycle['trusted_signal_status'],
            'trusted_signal_ready' => $externalLifecycle['trusted_signal_ready'],
            'can_feed_provider_performance_ledger' => $externalLifecycle['can_feed_provider_performance_ledger'],
            'can_feed_atlas_decide_advisory_signal' => $externalLifecycle['can_feed_atlas_decide_advisory_signal'],
            'ledger_projection_ready' => $externalLifecycle['trusted_signal_ready'],
            'ledger_record_command' => $externalLifecycle['trusted_signal_ready']
                ? 'php artisan atlas:forge:rivals ledger-record --run-id='.$paths['run_id'].' --task-category='.$manifest['task_category'].' --role='.$manifest['role'].' --json --strict'
                : null,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'provider_tokens_may_have_been_spent' => $manifest['provider_tokens_may_have_been_spent'],
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'next_command' => 'php artisan atlas:forge:rivals replay --run-id='.$paths['run_id'].' --stage=final --json',
            'note' => 'Imported external DeepSWE/Pier artifacts into Rivals evidence. No provider, Pier or Docker process was started by this command.',
        ];
    }

    /**
     * Ingest every task result under a DeepSWE/Pier result root, then reuse
     * the existing Rivals battery evidence, battery replay and matrix report
     * surfaces over the produced run_ids.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function ingestBatch(array $input): array
    {
        $resultRoot = $this->inputPath($input);
        if ($resultRoot === null) {
            return $this->blocked(['deepswe_batch_result_root_required:use_--input'], null);
        }
        if (! is_dir($resultRoot)) {
            return $this->blocked(['deepswe_batch_result_root_not_found:'.$resultRoot], $resultRoot);
        }

        $taskRoot = trim((string) ($input['deepswe_path'] ?? ''));
        if ($taskRoot === '' || ! is_dir($taskRoot)) {
            return $this->blocked(['deepswe_task_root_required_for_batch:use_--deepswe-path'], $resultRoot);
        }

        $resultDirs = $this->discoverResultDirs($resultRoot);
        if ($resultDirs === []) {
            return $this->blocked(['deepswe_batch_no_result_dirs_found:'.$resultRoot], $resultRoot);
        }

        $batchId = trim((string) ($input['battery_id'] ?? ''));
        if ($batchId === '') {
            $batchId = 'deepswe-battery-'.substr(hash('sha256', $resultRoot), 0, 8);
        }
        $runPrefix = trim((string) ($input['run_id'] ?? ''));
        if ($runPrefix === '') {
            $runPrefix = $batchId;
        }

        $ingests = [];
        $runIds = [];
        $blockers = [];
        foreach ($resultDirs as $resultDir) {
            $taskId = $this->resultTaskId($resultDir);
            if ($taskId === null) {
                $blockers[] = 'deepswe_batch_task_id_missing:'.$resultDir;

                continue;
            }
            $taskDir = $this->resolveTaskDir($taskRoot, $taskId);
            if ($taskDir === null) {
                $blockers[] = 'deepswe_batch_task_manifest_missing:'.$taskId;

                continue;
            }
            $runId = $this->safeRunId($runPrefix.'-'.$taskId);
            $ingest = $this->ingest(array_replace($input, [
                'input' => $resultDir,
                'deepswe_path' => $taskDir,
                'run_id' => $runId,
            ]));
            $ingests[] = [
                'task_id' => $taskId,
                'result_dir' => $resultDir,
                'task_dir' => $taskDir,
                'run_id' => $runId,
                'status' => $ingest['status'] ?? 'unknown',
                'blockers' => $ingest['blockers'] ?? [],
                'replay_passes' => $ingest['replay_passes'] ?? false,
                'external_execution_plan_binding' => $ingest['external_execution_plan_binding'] ?? null,
                'external_execution_plan_binding_status' => $ingest['external_execution_plan_binding']['status'] ?? 'not_provided',
                'external_execution_plan_fingerprint' => $ingest['external_execution_plan_binding']['plan_fingerprint'] ?? null,
                'external_evidence_bundle_verified' => $ingest['external_evidence_bundle_verified'] ?? false,
                'trusted_signal_ready' => $ingest['trusted_signal_ready'] ?? false,
                'can_feed_provider_performance_ledger' => $ingest['can_feed_provider_performance_ledger'] ?? false,
                'can_feed_atlas_decide_advisory_signal' => $ingest['can_feed_atlas_decide_advisory_signal'] ?? false,
            ];
            if (($ingest['status'] ?? '') === 'ok') {
                $runIds[] = $runId;
            } else {
                foreach ((array) ($ingest['blockers'] ?? []) as $blocker) {
                    $blockers[] = $taskId.':'.(string) $blocker;
                }
            }
        }
        $batchPlanBinding = $this->batchExternalExecutionPlanBindingSummary($input, $ingests);
        foreach ((array) ($batchPlanBinding['blockers'] ?? []) as $blocker) {
            $blockers[] = (string) $blocker;
        }

        if ($runIds === []) {
            return [
                'status' => 'blocked',
                'schema_version' => 'atlas.forge.rivals.deepswe_batch_ingest.v1',
                'battery_id' => $batchId,
                'result_root' => $resultRoot,
                'task_root' => $taskRoot,
                'run_ids' => [],
                'ingests' => $ingests,
                'external_execution_plan_binding_summary' => $batchPlanBinding,
                'blockers' => array_values(array_unique($blockers ?: ['deepswe_batch_no_successful_ingests'])),
                'claim_ready' => false,
                'external_claim_allowed' => false,
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'advisory_only' => true,
                'should_update_provider_topology' => false,
                'never_changes_atlas_decide_topology' => true,
                'owner_of_model_routing' => 'atlas_decide',
                'routing_effect' => 'none',
                'next_command' => 'fix batch blockers and rerun deepswe-batch-ingest',
            ];
        }

        $batteryInput = [
            'run_ids' => $runIds,
            'battery_id' => $batchId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
        ];
        $batteryEvidence = $this->batteryEvidence->aggregate($batteryInput);
        $batteryReplay = $this->batteryReplay->verify($batteryInput);
        $matrix = $this->matrixReport->render($batteryInput);
        $ledger = $this->recordLedgerForRuns($runIds);
        $decideSignals = $this->projectDecideSignalsForRuns($runIds);
        $ledgerSnapshot = $this->ledger->snapshot([
            'run_ids' => $runIds,
        ]);
        $decideMap = $this->decideSignal->map([
            'run_ids' => $runIds,
        ]);
        $externalBenchmarkClaimGate = $this->externalBenchmarkClaimGate(
            runIds: $runIds,
            batteryEvidence: $batteryEvidence,
            batteryReplay: $batteryReplay,
            matrix: $matrix,
            ledger: $ledger,
            ledgerSnapshot: $ledgerSnapshot,
            decideMap: $decideMap,
        );

        foreach ([$batteryEvidence, $batteryReplay, $ledger] as $payload) {
            foreach ((array) ($payload['blockers'] ?? []) as $blocker) {
                $blockers[] = (string) $blocker;
            }
        }

        return [
            'status' => $blockers === [] ? 'ok' : 'blocked',
            'schema_version' => 'atlas.forge.rivals.deepswe_batch_ingest.v1',
            'battery_id' => $batchId,
            'result_root' => $resultRoot,
            'task_root' => $taskRoot,
            'result_count' => count($resultDirs),
            'successful_ingest_count' => count($runIds),
            'run_ids' => $runIds,
            'ingests' => $ingests,
            'external_execution_plan_binding_summary' => $batchPlanBinding,
            'battery_evidence_status' => $batteryEvidence['status'] ?? 'unknown',
            'battery_replay_status' => $batteryReplay['status'] ?? 'unknown',
            'matrix_report_status' => $matrix['status'] ?? 'unknown',
            'matrix_report_blockers' => array_values(array_map('strval', (array) ($matrix['blockers'] ?? []))),
            'ledger_record_status' => $ledger['status'] ?? 'unknown',
            'ledger_entries_recorded' => $ledger['entries_recorded'] ?? 0,
            'ledger_blockers' => $ledger['blockers'] ?? [],
            'external_evidence_lifecycle_summary' => [
                'ready_count' => count(array_filter(
                    $ingests,
                    static fn (array $row): bool => ($row['trusted_signal_ready'] ?? false) === true
                        && ($row['external_evidence_bundle_verified'] ?? false) === true,
                )),
                'total_successful_ingests' => count($runIds),
                'all_successful_ingests_lifecycle_ready' => count($runIds) > 0 && count(array_filter(
                    $ingests,
                    static fn (array $row): bool => ($row['trusted_signal_ready'] ?? false) === true
                        && ($row['external_evidence_bundle_verified'] ?? false) === true,
                )) === count($runIds),
                'requires_replay_green' => true,
                'requires_bundle_verified' => true,
                'requires_trusted_signal_ready' => true,
                'external_claim_allowed' => false,
            ],
            'decide_signals' => $decideSignals,
            'decide_model_intelligence_map' => $decideMap,
            'atlas_decide_external_learning_packet' => $decideMap['atlas_decide_learning_packet'] ?? null,
            'decide_model_intelligence_map_status' => $decideMap['signal'] ?? 'unknown',
            'decide_model_intelligence_map_segments' => $decideMap['segment_count'] ?? 0,
            'statistical_repeat_readiness' => $ledgerSnapshot['aggregates']['statistical_repeat_readiness'] ?? null,
            'category_difficulty_model_summary' => $ledgerSnapshot['aggregates']['by_task_category_difficulty_role_model'] ?? [],
            'external_benchmark_claim_gate' => $externalBenchmarkClaimGate,
            'battery_evidence_pack_path' => $batteryEvidence['battery_evidence_pack']['battery_pack_path'] ?? null,
            'matrix_report_path' => $matrix['report_path'] ?? null,
            'matrix_summary' => [
                'case_count' => $matrix['matrix_report']['case_count'] ?? null,
                'status' => $matrix['matrix_report']['status'] ?? null,
                'atlas_decide_recommendation' => $matrix['matrix_report']['atlas_decide_recommendation'] ?? null,
            ],
            'blockers' => array_values(array_unique($blockers)),
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'next_command' => 'php artisan atlas:forge:rivals matrix-report --run-ids='.implode(',', $runIds).' --battery-id='.$batchId.' --json',
        ];
    }

    /**
     * A compact gate for external benchmark claims. It intentionally never
     * unlocks a claim by itself; even a green gate only means the evidence is
     * ready for human certification review.
     *
     * @param  list<string>  $runIds
     * @param  array<string,mixed>  $batteryEvidence
     * @param  array<string,mixed>  $batteryReplay
     * @param  array<string,mixed>  $matrix
     * @param  array<string,mixed>  $ledger
     * @param  array<string,mixed>  $ledgerSnapshot
     * @param  array<string,mixed>  $decideMap
     * @return array<string,mixed>
     */
    private function externalBenchmarkClaimGate(
        array $runIds,
        array $batteryEvidence,
        array $batteryReplay,
        array $matrix,
        array $ledger,
        array $ledgerSnapshot,
        array $decideMap,
    ): array {
        $minimumCases = 50;
        $statisticalRepeat = (array) data_get($ledgerSnapshot, 'aggregates.statistical_repeat_readiness', []);
        $externalClaimReadiness = (array) ($ledgerSnapshot['external_claim_readiness'] ?? []);
        $learningPacket = (array) ($decideMap['atlas_decide_learning_packet'] ?? []);
        $dimensionalQuality = (array) ($learningPacket['dimensional_signal_quality'] ?? []);

        $requirements = [
            'minimum_50_successful_external_runs' => count($runIds) >= $minimumCases,
            'battery_evidence_pack_green' => ($batteryEvidence['status'] ?? null) === 'ok',
            'battery_replay_green' => ($batteryReplay['status'] ?? null) === 'ok',
            'matrix_report_green' => ($matrix['status'] ?? null) === 'ok' && (array) ($matrix['blockers'] ?? []) === [],
            'ledger_record_green' => ($ledger['status'] ?? null) === 'ok' && (int) ($ledger['entries_recorded'] ?? 0) > 0,
            'statistical_repeat_confidence_ready' => (bool) ($statisticalRepeat['confidence_ready'] ?? false),
            'decide_dimensional_signal_complete' => (bool) ($dimensionalQuality['complete_for_policy_review'] ?? false),
            'atlas_decide_learning_packet_present' => $learningPacket !== [],
            'human_external_certification_required' => true,
            'external_rivals_certification_unlocked' => false,
        ];

        $blockers = [];
        foreach ($requirements as $requirement => $ok) {
            if (in_array($requirement, [
                'human_external_certification_required',
                'external_rivals_certification_unlocked',
            ], true)) {
                continue;
            }
            if ($ok !== true) {
                $blockers[] = $requirement;
            }
        }

        $evidenceReadyForHumanReview = $blockers === [];

        return [
            'schema_version' => 'atlas.forge.rivals.external_benchmark_claim_gate.v1',
            'status' => $evidenceReadyForHumanReview
                ? 'ready_for_human_certification_external_claim_still_blocked'
                : 'blocked_until_external_benchmark_evidence_complete',
            'successful_external_run_count' => count($runIds),
            'minimum_successful_external_runs_for_strong_claim' => $minimumCases,
            'requirements' => $requirements,
            'blockers' => $blockers,
            'statistical_repeat_status' => $statisticalRepeat['status'] ?? 'insufficient_evidence',
            'external_claim_readiness_status' => $externalClaimReadiness['status'] ?? 'blocked_until_reproducible_evidence_complete',
            'atlas_decide_learning_status' => $learningPacket['status'] ?? 'missing',
            'dimensional_signal_quality_status' => $dimensionalQuality['status'] ?? 'missing',
            'evidence_ready_for_human_certification' => $evidenceReadyForHumanReview,
            'human_external_certification_required' => true,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'score_or_claim_allowed' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $ingests
     * @return array<string,mixed>
     */
    private function batchExternalExecutionPlanBindingSummary(array $input, array $ingests): array
    {
        $manifestPath = trim((string) ($input['plan_manifest'] ?? ''));
        $fingerprints = [];
        $blockers = [];
        $boundCount = 0;
        $blockedCount = 0;
        $notProvidedCount = 0;

        foreach ($ingests as $ingest) {
            $taskId = (string) ($ingest['task_id'] ?? 'unknown-task');
            $binding = (array) ($ingest['external_execution_plan_binding'] ?? []);
            $status = (string) ($binding['status'] ?? 'not_provided');

            if ($status === 'ok') {
                $boundCount++;
                $fingerprint = trim((string) ($binding['plan_fingerprint'] ?? ''));
                if ($fingerprint !== '') {
                    $fingerprints[$fingerprint] = true;
                }

                continue;
            }

            if ($status === 'blocked') {
                $blockedCount++;
                foreach ((array) ($binding['blockers'] ?? []) as $blocker) {
                    $blockers[] = $taskId.':'.(string) $blocker;
                }

                continue;
            }

            $notProvidedCount++;
            if ($manifestPath !== '') {
                $blockers[] = $taskId.':external_execution_plan_binding_not_provided';
            }
        }

        if (count($fingerprints) > 1) {
            $blockers[] = 'deepswe_batch_multiple_external_execution_plan_fingerprints';
        }

        $required = $manifestPath !== '';
        $status = 'not_provided';
        if ($required) {
            $status = $blockers === [] && $boundCount === count($ingests)
                ? 'ok'
                : 'blocked';
        }

        return [
            'schema_version' => 'atlas.forge.rivals.external_execution_plan_batch_binding.v1',
            'status' => $status,
            'plan_manifest_required' => $required,
            'plan_manifest_path' => $manifestPath !== '' ? $manifestPath : null,
            'plan_fingerprint' => count($fingerprints) === 1 ? array_key_first($fingerprints) : null,
            'result_count' => count($ingests),
            'bound_count' => $boundCount,
            'blocked_count' => $blockedCount,
            'not_provided_count' => $notProvidedCount,
            'all_successful_ingests_bound_to_same_plan' => $required
                ? ($blockers === [] && $boundCount === count($ingests))
                : false,
            'blockers' => array_values(array_unique($blockers)),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
        ];
    }

    /**
     * @return array{result:array<string,mixed>,blockers:list<string>}
     */
    private function loadArmResult(string $root, array $names): array
    {
        foreach ($names as $name) {
            foreach ([$root.'/'.$name.'/result.json', $root.'/arms/'.$name.'/result.json'] as $path) {
                if (is_file($path)) {
                    $json = JsonFileStore::readArray($path);

                    return is_array($json)
                        ? ['result' => $json + ['_result_path' => $path, '_result_dir' => dirname($path)], 'blockers' => []]
                        : ['result' => [], 'blockers' => ['deepswe_result_json_invalid:'.$path]];
                }
            }
        }

        return ['result' => [], 'blockers' => ['deepswe_arm_result_missing:'.implode('|', $names)]];
    }

    /**
     * @return array<string,mixed>
     */
    private function taskManifest(array $input): array
    {
        $path = trim((string) ($input['deepswe_path'] ?? ''));
        if ($path === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['deepswe_task_manifest_required_for_external_reproducibility'],
            ];
        }

        return $this->taskParser->parseTask($path);
    }

    /**
     * @return list<string>
     */
    private function armEvidenceBlockers(string $label, array $result): array
    {
        $blockers = [];
        if ($this->patchText($result) === '') {
            $blockers[] = $label.':deepswe_patch_missing';
        }
        if ($this->artifactText($result, ['trajectory_path', 'trajectory_file', 'trajectory_jsonl_path']) === '') {
            $blockers[] = $label.':deepswe_trajectory_missing';
        }
        if ($this->verifierExitCode($result) !== 0) {
            $blockers[] = $label.':deepswe_verifier_failed:exit_code='.$this->verifierExitCode($result);
        }

        return $blockers;
    }

    /**
     * @return array<string,mixed>
     */
    private function externalExecutionPlanBinding(array $input, array $armA, array $armB): array
    {
        $path = trim((string) ($input['plan_manifest'] ?? ''));
        if ($path === '') {
            return [
                'schema_version' => 'atlas.forge.rivals.external_execution_plan_binding.v1',
                'status' => 'not_provided',
                'plan_manifest_path' => null,
                'plan_fingerprint' => null,
                'blockers' => [],
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'advisory_only' => true,
            ];
        }
        if (! is_file($path)) {
            return $this->blockedPlanBinding($path, null, ['external_execution_plan_manifest_not_found:'.$path]);
        }

        $manifest = JsonFileStore::readArray($path);
        if ($manifest === null) {
            return $this->blockedPlanBinding($path, null, ['external_execution_plan_manifest_invalid_json:'.$path]);
        }
        $fingerprint = trim((string) ($manifest['plan_fingerprint'] ?? ''));
        if ($fingerprint === '') {
            return $this->blockedPlanBinding($path, null, ['external_execution_plan_manifest_missing_fingerprint:'.$path]);
        }

        $armAFingerprint = $this->resultPlanFingerprint($armA);
        $armBFingerprint = $this->resultPlanFingerprint($armB);
        $blockers = [];
        if ($armAFingerprint === '') {
            $blockers[] = 'atlas:external_execution_plan_fingerprint_missing';
        } elseif (! hash_equals($fingerprint, $armAFingerprint)) {
            $blockers[] = 'atlas:external_execution_plan_fingerprint_mismatch';
        }
        if ($armBFingerprint === '') {
            $blockers[] = 'rival:external_execution_plan_fingerprint_missing';
        } elseif (! hash_equals($fingerprint, $armBFingerprint)) {
            $blockers[] = 'rival:external_execution_plan_fingerprint_mismatch';
        }

        return [
            'schema_version' => 'atlas.forge.rivals.external_execution_plan_binding.v1',
            'status' => $blockers === [] ? 'ok' : 'blocked',
            'plan_manifest_path' => $path,
            'plan_fingerprint' => $fingerprint,
            'atlas_result_plan_fingerprint' => $armAFingerprint !== '' ? $armAFingerprint : null,
            'rival_result_plan_fingerprint' => $armBFingerprint !== '' ? $armBFingerprint : null,
            'blockers' => $blockers,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedPlanBinding(string $path, ?string $fingerprint, array $blockers): array
    {
        return [
            'schema_version' => 'atlas.forge.rivals.external_execution_plan_binding.v1',
            'status' => 'blocked',
            'plan_manifest_path' => $path,
            'plan_fingerprint' => $fingerprint,
            'blockers' => $blockers,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
        ];
    }

    private function resultPlanFingerprint(array $result): string
    {
        foreach (['external_execution_plan_fingerprint', 'plan_fingerprint', 'runbook_plan_fingerprint'] as $key) {
            $value = $result[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * @return array<string,mixed>
     */
    private function materializeArmArtifacts(array $paths, string $arm, array $result, string $root): array
    {
        $patch = $this->patchText($result);
        $testLog = $this->artifactText($result, ['test_log_path', 'verifier_log_path', 'stdout_path']);
        $stdout = $this->artifactText($result, ['stdout_path']);
        $stderr = $this->artifactText($result, ['stderr_path']);
        $trajectory = $this->artifactText($result, ['trajectory_path', 'trajectory_file', 'trajectory_jsonl_path']);

        $patchPath = $paths['evidence'].'/'.$arm.'_patch.diff';
        $testLogPath = $paths['evidence'].'/'.$arm.'_test.log';
        $stdoutPath = $paths['evidence'].'/'.$arm.'_stdout.log';
        $stderrPath = $paths['evidence'].'/'.$arm.'_stderr.log';
        $trajectoryPath = $paths['evidence'].'/'.$arm.'_trajectory.jsonl';

        file_put_contents($patchPath, $patch);
        file_put_contents($testLogPath, $testLog);
        file_put_contents($stdoutPath, $stdout);
        file_put_contents($stderrPath, $stderr);
        file_put_contents($trajectoryPath, $trajectory);

        return [
            'patch_path' => $patchPath,
            'patch_sha256' => $patch !== '' ? hash('sha256', $patch) : null,
            'test_log_path' => $testLogPath,
            'stdout_path' => $stdoutPath,
            'stderr_path' => $stderrPath,
            'trajectory_path' => $trajectoryPath,
            'trajectory_sha256' => $trajectory !== '' ? hash('sha256', $trajectory) : null,
            'source_result_path' => $result['_result_path'] ?? null,
            'source_result_dir' => $result['_result_dir'] ?? $root,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function receipt(string $arm, array $result, array $artifacts, array $task): array
    {
        $startedAt = $this->stringValue($result, ['started_at']) ?: $this->now();
        $finishedAt = $this->stringValue($result, ['finished_at']) ?: $startedAt;
        $stdoutBytes = is_file((string) $artifacts['stdout_path']) ? (int) filesize((string) $artifacts['stdout_path']) : 0;
        $stderrBytes = is_file((string) $artifacts['stderr_path']) ? (int) filesize((string) $artifacts['stderr_path']) : 0;
        $patchBytes = is_file((string) $artifacts['patch_path']) ? (int) filesize((string) $artifacts['patch_path']) : 0;

        return [
            'schema_version' => 'atlas.forge.rivals.deepswe_external_receipt.v1',
            'source' => 'deepswe_result_ingest',
            'arm' => $arm,
            'task_id' => $this->taskId($task, $result, []),
            'agent' => $this->stringValue($result, ['agent', 'runner', 'tool']) ?: 'unknown',
            'provider' => $this->stringValue($result, ['provider']) ?: 'unknown',
            'model' => $this->stringValue($result, ['model', 'model_id']) ?: 'unknown',
            'model_id' => $this->stringValue($result, ['model_id', 'model']) ?: 'unknown',
            'exit_code' => (int) ($result['exit_code'] ?? $this->verifierExitCode($result)),
            'test_exit_code' => $this->verifierExitCode($result),
            'killed' => (bool) ($result['killed'] ?? false),
            'timed_out' => (bool) ($result['timed_out'] ?? false),
            'duration_ms' => (int) ($result['duration_ms'] ?? 0),
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'stdout_path' => $artifacts['stdout_path'],
            'stderr_path' => $artifacts['stderr_path'],
            'test_log_path' => $artifacts['test_log_path'],
            'trajectory_path' => $artifacts['trajectory_path'],
            'patch_path' => $artifacts['patch_path'],
            'stdout_bytes' => $stdoutBytes,
            'stderr_bytes' => $stderrBytes,
            'patch_diff_bytes' => $patchBytes,
            'trajectory_sha256' => $artifacts['trajectory_sha256'],
            'changed_files' => $this->stringList($result['changed_files'] ?? []),
            'out_of_scope_files' => $this->stringList($result['out_of_scope_files'] ?? []),
            'bytecode_artifacts' => $this->stringList($result['bytecode_artifacts'] ?? []),
            'provider_tokens_spent' => (bool) ($result['provider_tokens_spent'] ?? false),
            'external_provider_call' => (bool) ($result['external_provider_call'] ?? false),
            'cost_usd' => $result['cost_usd'] ?? null,
            'token_usage' => is_array($result['token_usage'] ?? null) ? $result['token_usage'] : null,
            'task_manifest_hash' => $task['manifest_hash'] ?? null,
            'solution_content_exposed' => false,
            'advisory_only' => true,
        ];
    }

    private function inputPath(array $input): ?string
    {
        $path = trim((string) ($input['input'] ?? ''));

        return $path !== '' ? rtrim($path, DIRECTORY_SEPARATOR) : null;
    }

    /**
     * @return list<string>
     */
    private function discoverResultDirs(string $root): array
    {
        if (is_file($root.'/atlas/result.json') || is_file($root.'/arm_a/result.json')) {
            return [$root];
        }

        $dirs = [];
        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_file($dir.'/atlas/result.json') || is_file($dir.'/arm_a/result.json')) {
                $dirs[] = $dir;
            }
        }
        sort($dirs);

        return $dirs;
    }

    private function resultTaskId(string $resultDir): ?string
    {
        foreach (['atlas/result.json', 'arm_a/result.json', 'a/result.json'] as $relative) {
            $path = $resultDir.'/'.$relative;
            if (! is_file($path)) {
                continue;
            }
            $json = JsonFileStore::readArray($path);
            if (is_array($json) && is_string($json['task_id'] ?? null) && trim($json['task_id']) !== '') {
                return trim((string) $json['task_id']);
            }
        }

        return null;
    }

    private function resolveTaskDir(string $taskRoot, string $taskId): ?string
    {
        if (is_file($taskRoot.'/task.toml')) {
            $task = $this->taskParser->parseTask($taskRoot);

            return ($task['task_id'] ?? null) === $taskId ? $taskRoot : null;
        }
        $candidate = rtrim($taskRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$taskId;
        if (is_file($candidate.'/task.toml')) {
            return $candidate;
        }

        foreach (glob(rtrim($taskRoot, DIRECTORY_SEPARATOR).'/*/task.toml') ?: [] as $taskToml) {
            $dir = dirname($taskToml);
            $task = $this->taskParser->parseTask($dir);
            if (($task['task_id'] ?? null) === $taskId) {
                return $dir;
            }
        }

        return null;
    }

    private function safeRunId(string $raw): string
    {
        $id = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $raw) ?? 'deepswe-run';
        $id = trim($id, '-_.');

        return substr($id !== '' ? $id : 'deepswe-run', 0, 128);
    }

    /**
     * @param  list<string>  $runIds
     * @return array<string,mixed>
     */
    private function recordLedgerForRuns(array $runIds): array
    {
        $entries = 0;
        $blockers = [];
        foreach ($runIds as $runId) {
            $manifest = $this->readRunManifest($runId);
            $record = $this->ledger->record([
                'run_id' => $runId,
                'task_category' => $manifest['task_category'] ?? null,
                'role' => $manifest['role'] ?? 'builder',
                'framework' => $manifest['framework'] ?? null,
            ]);
            if (($record['status'] ?? '') === 'ok') {
                $entries += count((array) ($record['entries_recorded'] ?? []));
            } else {
                foreach ((array) ($record['blockers'] ?? []) as $blocker) {
                    $blockers[] = $runId.':'.(string) $blocker;
                }
            }
        }

        return [
            'status' => $blockers === [] ? 'ok' : 'blocked',
            'entries_recorded' => $entries,
            'blockers' => array_values(array_unique($blockers)),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];
    }

    /**
     * @param  list<string>  $runIds
     * @return list<array<string,mixed>>
     */
    private function projectDecideSignalsForRuns(array $runIds): array
    {
        $pairs = [];
        foreach ($runIds as $runId) {
            $manifest = $this->readRunManifest($runId);
            $category = is_string($manifest['task_category'] ?? null) ? (string) $manifest['task_category'] : '';
            $role = is_string($manifest['role'] ?? null) ? (string) $manifest['role'] : 'builder';
            $difficulty = is_string($manifest['difficulty_level'] ?? null) ? (string) $manifest['difficulty_level'] : '';
            if ($category === '') {
                continue;
            }
            $pairs[$category.'|'.$role.'|'.$difficulty] = [
                'task_category' => $category,
                'role' => $role,
                'difficulty_level' => $difficulty,
            ];
        }

        $signals = [];
        foreach ($pairs as $pair) {
            $signals[] = $this->decideSignal->project($pair);
        }

        return $signals;
    }

    /**
     * @return array<string,mixed>
     */
    private function readRunManifest(string $runId): array
    {
        try {
            $path = $this->paths->paths($runId)['manifest_json'];
        } catch (\Throwable) {
            return [];
        }
        if (! is_file($path)) {
            return [];
        }
        return JsonFileStore::readArray($path) ?? [];
    }

    /**
     * @return array<string,mixed>
     */
    private function externalEvidenceLifecycle(
        string $runId,
        string $runDir,
        string $bundlePath,
        string $taskCategory,
        string $role,
    ): array {
        $bundleManifest = $this->evidenceBundle->manifest([
            'run_id' => $runId,
            'output_path' => $bundlePath,
        ]);
        $bundleVerification = $this->evidenceBundle->verify([
            'input' => $bundlePath,
            'bundle_run_dir' => $runDir,
        ]);
        $trustedSignal = $this->trustedSignal->inspect([
            'run_id' => $runId,
            'input' => $bundlePath,
            'bundle_run_dir' => $runDir,
            'task_category' => $taskCategory,
            'role' => $role,
        ]);

        $trustedReady = (bool) ($trustedSignal['trusted_signal_ready'] ?? false);
        $bundleVerified = (bool) ($bundleVerification['bundle_verified'] ?? false);

        return [
            'schema_version' => 'atlas.forge.rivals.deepswe_external_evidence_lifecycle.v1',
            'status' => $trustedReady && $bundleVerified ? 'ok' : 'blocked',
            'run_id' => $runId,
            'run_dir' => $runDir,
            'bundle_manifest_path' => is_file($bundlePath) ? $bundlePath : null,
            'bundle_status' => $bundleManifest['status'] ?? 'unknown',
            'bundle_verified' => $bundleVerified,
            'trusted_signal_status' => $trustedSignal['status'] ?? 'unknown',
            'trusted_signal_ready' => $trustedReady,
            'can_feed_provider_performance_ledger' => (bool) ($trustedSignal['can_feed_provider_performance_ledger'] ?? false),
            'can_feed_atlas_decide_advisory_signal' => (bool) ($trustedSignal['can_feed_atlas_decide_advisory_signal'] ?? false),
            'blockers' => array_values(array_unique(array_merge(
                array_map('strval', (array) ($bundleManifest['blockers'] ?? [])),
                array_map('strval', (array) ($bundleVerification['blockers'] ?? [])),
                array_map('strval', (array) ($trustedSignal['blockers'] ?? [])),
            ))),
            'bundle_manifest' => $bundleManifest,
            'bundle_verification' => $bundleVerification,
            'trusted_signal' => $trustedSignal,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
        ];
    }

    private function patchText(array $result): string
    {
        $inline = $result['patch'] ?? $result['patch_diff'] ?? null;
        if (is_string($inline) && $inline !== '') {
            return $inline;
        }

        return $this->artifactText($result, ['patch_path', 'patch_file', 'diff_path']);
    }

    /**
     * @param  list<string>  $keys
     */
    private function artifactText(array $result, array $keys): string
    {
        foreach ($keys as $key) {
            $path = $result[$key] ?? null;
            if (! is_string($path) || trim($path) === '') {
                continue;
            }
            $candidate = $this->resolvePath((string) ($result['_result_dir'] ?? ''), $path);
            if (is_file($candidate)) {
                return (string) file_get_contents($candidate);
            }
        }

        return '';
    }

    private function resolvePath(string $base, string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
    }

    private function verifierExitCode(array $result): int
    {
        $verifier = $result['verifier'] ?? null;
        if (is_array($verifier) && is_numeric($verifier['exit_code'] ?? null)) {
            return (int) $verifier['exit_code'];
        }

        return (int) ($result['test_exit_code'] ?? $result['verifier_exit_code'] ?? $result['exit_code'] ?? -1);
    }

    private function taskId(array $task, array $armA, array $armB): string
    {
        foreach ([$task['task_id'] ?? null, $armA['task_id'] ?? null, $armB['task_id'] ?? null] as $id) {
            if (is_string($id) && trim($id) !== '') {
                return trim($id);
            }
        }

        return 'unknown-task';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function taskSummary(array $task): ?array
    {
        if (($task['status'] ?? '') !== 'ok') {
            return null;
        }

        return [
            'task_id' => $task['task_id'] ?? null,
            'task_dir' => $task['task_dir'] ?? null,
            'task_category' => $this->taskCategory($task),
            'difficulty_level' => $this->difficultyLevel($task),
            'role' => $this->taskRole($task),
            'framework' => $this->taskFramework($task),
            'manifest_hash' => $task['manifest_hash'] ?? null,
            'artifact_hashes' => $task['artifact_hashes'] ?? [],
            'solution_reference' => $task['solution_reference'] ?? null,
        ];
    }

    private function taskCategory(array $task): ?string
    {
        foreach (['task_category', 'category', 'metadata.category', 'metadata.task_category'] as $key) {
            $value = data_get($task, $key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function difficultyLevel(array $task): ?string
    {
        foreach (['difficulty_level', 'difficulty', 'metadata.difficulty_level', 'metadata.difficulty'] as $key) {
            $value = data_get($task, $key);
            if (is_string($value) && preg_match('/^L[1-5]$/', trim($value)) === 1) {
                return trim($value);
            }
        }

        return null;
    }

    private function taskRole(array $task): string
    {
        foreach (['role', 'metadata.role'] as $key) {
            $value = data_get($task, $key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return match ($this->taskCategory($task)) {
            'bugfix' => 'repair_agent',
            'test', 'tests' => 'test_generator',
            'architecture' => 'architect',
            'docs' => 'docs',
            default => 'builder',
        };
    }

    private function taskFramework(array $task): ?string
    {
        foreach (['framework', 'language', 'metadata.framework', 'metadata.language'] as $key) {
            $value = data_get($task, $key);
            if (is_string($value) && trim($value) !== '') {
                return strtolower(trim($value));
            }
        }

        return null;
    }

    private function mayHaveSpentTokens(array $result): bool
    {
        return (bool) ($result['provider_tokens_spent'] ?? $result['external_provider_call'] ?? false);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $v): string => (string) $v, $value));
    }

    /**
     * @param  list<string>  $keys
     */
    private function stringValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function directoryFingerprint(string $dir): string
    {
        $items = [];
        $rootLen = strlen(rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $path = $file->getPathname();
                $items[] = substr($path, $rootLen).':'.hash_file('sha256', $path);
            }
        }
        sort($items);

        return hash('sha256', implode("\n", $items));
    }

    /**
     * @return array<string,mixed>
     */
    private function phase(string $name, array $payload): array
    {
        return [
            'name' => $name,
            'ok' => ($payload['status'] ?? '') === 'ok',
            'status' => $payload['status'] ?? 'unknown',
            'blockers' => array_values(array_map('strval', (array) ($payload['blockers'] ?? []))),
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function blocked(array $blockers, ?string $path): array
    {
        return [
            'status' => 'blocked',
            'schema_version' => self::SCHEMA_VERSION,
            'result_root' => $path,
            'blockers' => array_values(array_unique($blockers)),
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'next_command' => 'php artisan atlas:forge:rivals deepswe-ingest --input=<pier-result-root> --deepswe-path=<task-dir> --json',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function json(array $payload): string
    {
        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
