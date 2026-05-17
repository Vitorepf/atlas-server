<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · Adjudicator v2 (per-category scoring + suspicious triage).
 *
 * The v2 layer answers "in which categories was Atlas or Claude better?", in
 * an auditable, deterministic, local-only way. It NEVER calls a provider, NEVER
 * judges with an LLM, NEVER promotes `external_rivals_certification`.
 *
 * Schema: atlas.forge.rivals.adjudication.v2
 * Batch input schema: atlas.forge.rivals.adjudication_batch_input.v1
 *
 * Two consumption modes:
 *
 *   1. single_run — `enrichFromSingleRun(...)`: pairs with the existing v1
 *      service. v1 emits scorecard.json untouched; v2 produces a 1-case
 *      envelope from the same evidence and writes scorecard.v2.json. This keeps
 *      the cert (`atlas.forge.rivals.adjudication.v1`, WEIGHTS, replay_passes,
 *      missing_evidence, 'atlas_score' => null) and the ledger entry pipeline
 *      working byte-for-byte.
 *
 *   2. batch — `adjudicateBatch(...)`: accepts an in-memory or on-disk
 *      `adjudication_batch_input.v1` payload with N runs, each declaring its
 *      task_category and (optionally) a case manifest that brings
 *      `quality_gates.weights`. v2 scores per case, per category, per overall
 *      with confidence ladder, suspicious result triage, ledger projection and
 *      a deterministic adjudication_hash.
 *
 * Hard gates emitted by v2 (superset of v1 — the v1 gates remain present
 * verbatim, and v2 layers operator-friendly extras):
 *
 *   v1 retained:
 *     - verdict_comparable
 *     - provider_exit_zero_atlas / provider_exit_zero_rival
 *     - tests_passed_atlas / tests_passed_rival
 *     - replay_passes
 *     - evidence_complete
 *     - no_out_of_scope_files_atlas / no_out_of_scope_files_rival
 *     - no_bytecode_artifacts_atlas / no_bytecode_artifacts_rival
 *     - dirty_after_run_false
 *     - patch_diff_present_atlas / patch_diff_present_rival
 *
 *   v2 additions (any single one ⇒ score=null, winner=null for the case):
 *     - missing_provider_receipt:<arm>
 *     - missing_patch_diff:<arm> (alias of patch_diff_present_*, kept as
 *       operator-friendly code)
 *     - missing_test_log:<arm>
 *     - tests_failed:<arm>
 *     - workspace_dirty_before
 *     - workspace_dirty_after (alias of dirty_after_run_false)
 *     - tracked_python_bytecode (alias of no_bytecode_artifacts_*)
 *     - scope_violation:<arm>:<path> (alias of no_out_of_scope_files_*)
 *     - forbidden_file_changed:<arm>:<path>
 *     - replay_manifest_missing
 *     - replay_failed (alias of replay_passes=false)
 *     - evidence_pack_incomplete (alias of evidence_complete)
 *     - timeout_without_result:<arm>
 *     - stalled_runner_no_heartbeat:<arm>
 *     - synthetic_score_detected
 *     - provider_policy_violation:<arm>
 *     - atlas_arm_not_forge
 *     - model_lock_violation
 *
 * Confidence ladder (ascending strength):
 *   flow_validated < directional_signal < trusted_battery < provider_ranking
 *   < decide_signal
 *
 * Trust rules:
 *   - claim_allowed = false unless confidence ∈ {trusted_battery,
 *     provider_ranking, decide_signal} AND winner ∉ {null, tie,
 *     no_trusted_winner} AND zero suspicious_results.affects_winner.
 *   - claim_ready stays false in every code path (canon: v2 NEVER promotes
 *     completion claim).
 *   - external_rivals_certification_status stays "BLOCKED" by construction.
 *
 * IMPORTANT: This service NEVER calls a provider. NEVER spends tokens. NEVER
 * unlocks `external_rivals_certification`. NEVER promotes `claim_ready=true`.
 * NEVER accepts a synthetic score. It is a local, deterministic, read-side
 * judge built on artifacts that already exist on disk.
 */
final class AtlasForgeRivalsAdjudicatorV2Service
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.adjudication.v2';

    public const BATCH_INPUT_SCHEMA_VERSION = 'atlas.forge.rivals.adjudication_batch_input.v1';

    public const DEFAULT_TIE_THRESHOLD = 5.0;

    public const NARROW_WIN_THRESHOLD = 7.0;

    public const WINNER_ATLAS = 'atlas';

    public const WINNER_RIVAL = 'rival';

    public const WINNER_TIE = 'tie';

    public const WINNER_NO_TRUSTED = 'no_trusted_winner';

    public const WINNER_NONE = null;

    public const CONFIDENCE_FLOW_VALIDATED = 'flow_validated';

    public const CONFIDENCE_DIRECTIONAL_SIGNAL = 'directional_signal';

    public const CONFIDENCE_TRUSTED_BATTERY = 'trusted_battery';

    public const CONFIDENCE_PROVIDER_RANKING = 'provider_ranking';

    public const CONFIDENCE_DECIDE_SIGNAL = 'decide_signal';

    public const CONFIDENCE_INSUFFICIENT = 'insufficient_evidence';

    public const EXTERNAL_RIVALS_STATUS_BLOCKED = 'BLOCKED';

    /**
     * Default dimension weights when a case does not declare its own. Source:
     * canon `atlas-forge-rivals-benchmark-strategy-v1.md` §Scoring canonico.
     * Sum is exactly 1.0.
     *
     * @var array<string,float>
     */
    public const DEFAULT_WEIGHTS = [
        'correctness' => 0.18,
        'test_coverage' => 0.12,
        'scope_discipline' => 0.15,
        'minimality' => 0.10,
        'maintainability' => 0.10,
        'architecture_fit' => 0.10,
        'evidence_quality' => 0.10,
        'cost_time' => 0.05,
        'ux_quality' => 0.05,
        'performance' => 0.05,
    ];

    /** @var list<string> Canonical task categories (11, plus aliases and the catch-all `unknown`). */
    public const TASK_CATEGORIES = [
        'frontend',
        'frontend_ui',
        'backend',
        'backend_logic',
        'bugfix',
        'realistic_bugfix',
        'tests',
        'test_design',
        'refactor',
        'architecture',
        'docs',
        'integration',
        'performance',
        'performance_edge_case',
        'security',
        'planning',
        'unknown',
    ];

    /** @var list<string> Outcome labels emitted on a per-case basis. */
    public const OUTCOME_WINNER = 'winner';

    public const OUTCOME_LOSER = 'loser';

    public const OUTCOME_TIE = 'tie';

    public const OUTCOME_NO_TRUSTED_WINNER = 'no_trusted_winner';

    public const OUTCOME_NEEDS_TRIAGE = 'needs_triage';

    public const OUTCOME_INVALID = 'invalid';

    /** @var list<string> Rivals considered "strong" — anomalously low scores from these arms trigger suspicious triage. */
    public const STRONG_RIVAL_MODELS = [
        'claude_sonnet',
        'claude_opus',
        'sonnet',
        'opus',
        'codex',
        'gemini',
        'gpt-4',
        'gpt-4o',
        'gpt-5',
    ];

    public const SUSPICIOUS_RIVAL_SCORE_CEILING = 70.0;

    public const SUSPICIOUS_ATLAS_MARGIN_FLOOR = 35.0;

    public const SUSPICIOUS_ATLAS_SCORE_FLOOR = 85.0;

    public const SUSPICIOUS_HISTORY_DELTA = 30.0;

    public const SUSPICIOUS_DURATION_FLOOR_SECONDS = 5;

    private readonly AtlasForgeRivalsAdjudicatorCalibrationService $calibration;

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        ?AtlasForgeRivalsAdjudicatorCalibrationService $calibration = null,
    ) {
        $this->calibration = $calibration ?? new AtlasForgeRivalsAdjudicatorCalibrationService;
    }

    /**
     * Single-run enrichment. Pairs with v1: caller supplies the already-built
     * v1 scorecard, plus the on-disk artifacts produced by run-real /
     * collect-evidence. We compose a 1-case v2 envelope and persist it to
     * `evidence/scorecard.v2.json`. v1 scorecard is NOT mutated.
     *
     * @param  array<string,mixed>  $v1Scorecard
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @param  array<string,mixed>  $evidencePack
     * @return array<string,mixed>
     */
    public function enrichFromSingleRun(
        string $runId,
        array $v1Scorecard,
        array $manifest,
        array $atlasReceipt,
        array $rivalReceipt,
        array $evidencePack,
    ): array {
        $arms = $this->resolveArmsFromManifest($manifest);
        $taskCategory = $this->resolveTaskCategoryFromManifest($manifest);
        $case = $this->scoreCaseFromV1(
            runId: $runId,
            v1Scorecard: $v1Scorecard,
            manifest: $manifest,
            atlasReceipt: $atlasReceipt,
            rivalReceipt: $rivalReceipt,
            evidencePack: $evidencePack,
            taskCategory: $taskCategory,
            arms: $arms,
        );

        $envelope = $this->composeEnvelope(
            inputMode: 'single_run',
            runId: $runId,
            inputPath: null,
            preset: (string) ($manifest['preset'] ?? 'unknown'),
            mode: (string) ($manifest['mode'] ?? 'unknown'),
            arms: $arms,
            cases: [$case],
            historicalContext: [],
        );

        $paths = $this->paths->paths($runId);
        $this->persist($paths['scorecard_v2_json'], $envelope);
        $envelope['scorecard_v2_path'] = $paths['scorecard_v2_json'];

        return $envelope;
    }

    /**
     * Batch adjudication. Accepts either an in-memory payload (`runs` key)
     * or a path to a JSON file on disk that holds the payload.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function adjudicateBatch(array $input): array
    {
        $payload = $this->loadBatchPayload($input);
        if (isset($payload['__error'])) {
            return [
                'status' => 'blocked',
                'blockers' => [$payload['__error']],
                'next_command' => 'php artisan atlas:forge:rivals adjudicate --input=<path> --json',
            ];
        }

        $runs = $payload['runs'] ?? [];
        if (! is_array($runs) || $runs === []) {
            return [
                'status' => 'blocked',
                'blockers' => ['batch_input_runs_empty'],
                'next_command' => '',
            ];
        }

        $preset = (string) ($payload['preset'] ?? 'unknown');
        $mode = (string) ($payload['mode'] ?? 'unknown');
        $arms = isset($payload['arms']) && is_array($payload['arms'])
            ? $this->normalizeArms($payload['arms'])
            : $this->resolveArmsFromManifest($runs[0]['manifest'] ?? []);

        $cases = [];
        foreach ($runs as $idx => $run) {
            if (! is_array($run)) {
                continue;
            }
            $cases[] = $this->scoreCaseFromBatchEntry((array) $run, $idx, $arms, $mode);
        }

        $envelope = $this->composeEnvelope(
            inputMode: 'batch',
            runId: null,
            inputPath: $payload['__source_path'] ?? null,
            preset: $preset,
            mode: $mode,
            arms: $arms,
            cases: $cases,
            historicalContext: is_array($payload['historical_context'] ?? null)
                ? (array) $payload['historical_context']
                : [],
        );

        if (! empty($input['output_path']) && is_string($input['output_path'])) {
            $this->persist($input['output_path'], $envelope);
            $envelope['scorecard_v2_path'] = $input['output_path'];
        }

        return $envelope;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function loadBatchPayload(array $input): array
    {
        if (isset($input['payload']) && is_array($input['payload'])) {
            return $input['payload'];
        }
        $path = trim((string) ($input['input'] ?? $input['input_path'] ?? ''));
        if ($path === '') {
            return ['__error' => 'batch_input_path_required'];
        }
        if (! is_file($path)) {
            return ['__error' => 'batch_input_file_not_found:'.$path];
        }
        $blob = (string) @file_get_contents($path);
        $decoded = json_decode($blob, true);
        if (! is_array($decoded)) {
            return ['__error' => 'batch_input_invalid_json'];
        }
        $decoded['__source_path'] = $path;

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return list<array<string,mixed>>
     */
    private function resolveArmsFromManifest(array $manifest): array
    {
        $atlasModel = (string) ($manifest['atlas_model'] ?? 'unknown');
        $rivalModel = (string) ($manifest['rival_model'] ?? 'unknown');

        return [
            [
                'arm_id' => 'atlas',
                'runner_type' => 'atlas_forge',
                'provider' => $this->inferProvider($atlasModel),
                'model' => $atlasModel,
                'role' => (string) ($manifest['atlas_role'] ?? $manifest['role'] ?? 'builder'),
            ],
            [
                'arm_id' => 'rival',
                'runner_type' => 'raw_provider',
                'provider' => $this->inferProvider($rivalModel),
                'model' => $rivalModel,
                'role' => (string) ($manifest['rival_role'] ?? $manifest['role'] ?? 'builder'),
            ],
        ];
    }

    /**
     * @param  array<int,mixed>  $raw
     * @return list<array<string,mixed>>
     */
    private function normalizeArms(array $raw): array
    {
        $arms = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $arms[] = [
                'arm_id' => (string) ($entry['arm_id'] ?? 'unknown'),
                'runner_type' => (string) ($entry['runner_type'] ?? 'unknown'),
                'provider' => (string) ($entry['provider'] ?? $this->inferProvider((string) ($entry['model'] ?? ''))),
                'model' => (string) ($entry['model'] ?? 'unknown'),
                'role' => (string) ($entry['role'] ?? 'builder'),
            ];
        }
        if ($arms === []) {
            return $this->resolveArmsFromManifest([]);
        }

        return $arms;
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function resolveTaskCategoryFromManifest(array $manifest): string
    {
        $tc = strtolower(trim((string) ($manifest['task_category'] ?? '')));

        return $tc !== '' ? $tc : 'unknown';
    }

    /**
     * Compose a Case object from a single-run v1 scorecard + on-disk artifacts.
     *
     * @param  array<string,mixed>  $v1Scorecard
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @param  array<string,mixed>  $evidencePack
     * @param  list<array<string,mixed>>  $arms
     * @return array<string,mixed>
     */
    private function scoreCaseFromV1(
        string $runId,
        array $v1Scorecard,
        array $manifest,
        array $atlasReceipt,
        array $rivalReceipt,
        array $evidencePack,
        string $taskCategory,
        array $arms,
    ): array {
        $caseId = (string) ($manifest['case_id'] ?? $runId);
        $hardGatesV1 = (array) ($v1Scorecard['hard_gates'] ?? []);
        $hardFailuresV1 = $this->stringList($v1Scorecard['hard_failures'] ?? []);

        $extraGates = $this->evaluateV2ExtraHardGates(
            manifest: $manifest,
            atlasReceipt: $atlasReceipt,
            rivalReceipt: $rivalReceipt,
            evidencePack: $evidencePack,
            armA: $arms[0] ?? [],
            armB: $arms[1] ?? [],
            mode: (string) ($manifest['mode'] ?? 'unknown'),
            inputAssertions: [],
        );
        $extraFailures = array_values(array_filter(
            $extraGates,
            static fn (array $g): bool => ! (bool) $g['ok']
        ));

        $allHardFailures = array_values(array_unique(array_merge(
            $hardFailuresV1,
            array_map(static fn (array $g): string => (string) $g['code'], $extraFailures)
        )));

        $caseHasHardFail = $allHardFailures !== [];

        $atlasScoreV1 = $v1Scorecard['atlas_score'] ?? null;
        $rivalScoreV1 = $v1Scorecard['rival_score'] ?? null;

        $weightsResolved = $this->resolveWeights($manifest);
        $dimensionsV1 = (array) ($v1Scorecard['quality_dimensions'] ?? []);
        $dimensionsV2 = $this->mapDimensionsToV2($dimensionsV1, $weightsResolved['weights'], $atlasReceipt, $rivalReceipt, $evidencePack);

        if ($caseHasHardFail) {
            $atlasScore = null;
            $rivalScore = null;
        } else {
            // If v1 produced scores, keep them as the canonical aggregate so v2
            // never drifts. v2 just exposes the per-dimension projection.
            $atlasScore = is_numeric($atlasScoreV1) ? (float) $atlasScoreV1 : null;
            $rivalScore = is_numeric($rivalScoreV1) ? (float) $rivalScoreV1 : null;
        }

        $margin = ($atlasScore !== null && $rivalScore !== null)
            ? abs($atlasScore - $rivalScore)
            : null;

        $winner = $this->resolveCaseWinner($atlasScore, $rivalScore, $caseHasHardFail);
        $suspicious = $this->triageSuspicious(
            atlasScore: $atlasScore,
            rivalScore: $rivalScore,
            margin: $margin,
            winner: $winner,
            armA: $arms[0] ?? [],
            armB: $arms[1] ?? [],
            atlasReceipt: $atlasReceipt,
            rivalReceipt: $rivalReceipt,
            caseHasHardFail: $caseHasHardFail,
            taskCategory: $taskCategory,
            roleFocus: (string) ($manifest['role_focus'] ?? 'unknown'),
            historicalAtlas: null,
            historicalRival: null,
            evidencePack: $evidencePack,
        );

        $valid = ! $caseHasHardFail && $atlasScore !== null && $rivalScore !== null;
        $humanReviewRequired = $this->caseRequiresHumanReview($winner, $margin, $suspicious, $caseHasHardFail);

        $case = [
            'case_id' => $caseId,
            'run_id' => $runId,
            'task_category' => $taskCategory,
            'role_focus' => (string) ($manifest['role_focus'] ?? 'unknown'),
            'weights_source' => $weightsResolved['source'],
            'weights' => $weightsResolved['weights'],
            'dimensions' => $dimensionsV2,
            'scores' => [
                'atlas' => $atlasScore,
                'rival' => $rivalScore,
                'margin' => $margin,
            ],
            'hard_gates' => array_merge($hardGatesV1, $extraGates),
            'hard_failures' => $allHardFailures,
            'winner' => $winner,
            'tie_threshold' => self::DEFAULT_TIE_THRESHOLD,
            'suspicious_results' => $suspicious,
            'human_review_required' => $humanReviewRequired,
            'valid' => $valid,
            'evidence_freshness' => $this->resolveEvidenceFreshness($atlasReceipt, $rivalReceipt),
            'claim_ready' => false,
            'winner_reason' => $this->buildCaseWinnerReason($winner, $atlasScore, $rivalScore, $margin, $suspicious, $allHardFailures),
        ];

        return $this->enrichCaseWithDifficultyAndBreakdown($case, $manifest, $atlasReceipt, $rivalReceipt);
    }

    /**
     * Score a single case from a batch entry. Each batch entry must declare:
     *   - task_category
     *   - manifest
     *   - atlas_receipt, rival_receipt
     *   - workspace_hashes
     *   - evidence_pack
     *   - replay_passes (bool)
     * Optional:
     *   - case_manifest (with quality_gates.weights / dimensions / role_focus)
     *   - historical_atlas, historical_rival (floats, for divergence triage)
     *   - assertions (synthetic_score_detected / model_lock_violation / etc)
     *
     * @param  array<string,mixed>  $entry
     * @param  list<array<string,mixed>>  $arms
     * @return array<string,mixed>
     */
    private function scoreCaseFromBatchEntry(array $entry, int $idx, array $arms, string $mode): array
    {
        $runId = (string) ($entry['run_id'] ?? 'batch-run-'.$idx);
        $manifest = is_array($entry['manifest'] ?? null) ? (array) $entry['manifest'] : [];
        $atlasReceipt = is_array($entry['atlas_receipt'] ?? null) ? (array) $entry['atlas_receipt'] : [];
        $rivalReceipt = is_array($entry['rival_receipt'] ?? null) ? (array) $entry['rival_receipt'] : [];
        $evidencePack = is_array($entry['evidence_pack'] ?? null) ? (array) $entry['evidence_pack'] : [];
        $workspaceHashes = is_array($entry['workspace_hashes'] ?? null) ? (array) $entry['workspace_hashes'] : [];
        $replayPasses = (bool) ($entry['replay_passes'] ?? false);

        $taskCategory = strtolower(trim((string) (
            $entry['task_category']
            ?? $manifest['task_category']
            ?? 'unknown'
        )));
        if ($taskCategory === '') {
            $taskCategory = 'unknown';
        }
        $caseManifest = is_array($entry['case_manifest'] ?? null) ? (array) $entry['case_manifest'] : [];

        $caseId = (string) ($entry['case_id']
            ?? $manifest['case_id']
            ?? $caseManifest['case_id']
            ?? 'batch-case-'.$idx);

        $hardGates = $this->evaluateV1ParityHardGates(
            manifest: $manifest,
            atlasReceipt: $atlasReceipt,
            rivalReceipt: $rivalReceipt,
            workspaceHashes: $workspaceHashes,
            evidencePack: $evidencePack,
            replayPasses: $replayPasses,
        );
        $extraGates = $this->evaluateV2ExtraHardGates(
            manifest: $manifest,
            atlasReceipt: $atlasReceipt,
            rivalReceipt: $rivalReceipt,
            evidencePack: $evidencePack,
            armA: $arms[0] ?? [],
            armB: $arms[1] ?? [],
            mode: $mode,
            inputAssertions: is_array($entry['assertions'] ?? null) ? (array) $entry['assertions'] : [],
        );
        $hardGatesAll = array_merge($hardGates, $extraGates);

        $hardFailures = [];
        foreach ($hardGatesAll as $g) {
            if (! (bool) $g['ok']) {
                $hardFailures[] = (string) $g['code'];
            }
        }
        $hardFailures = array_values(array_unique($hardFailures));

        $weightsResolved = $this->resolveWeights($caseManifest === [] ? $manifest : $caseManifest);
        $atlasReceiptForScoring = $atlasReceipt;
        $rivalReceiptForScoring = $rivalReceipt;

        $caseHasHardFail = $hardFailures !== [];

        if ($caseHasHardFail) {
            $dimensions = $this->emptyDimensions($weightsResolved['weights']);
            $atlasScore = null;
            $rivalScore = null;
        } else {
            $dimensions = $this->computeDimensions(
                weights: $weightsResolved['weights'],
                manifest: $manifest,
                atlasReceipt: $atlasReceiptForScoring,
                rivalReceipt: $rivalReceiptForScoring,
                evidencePack: $evidencePack,
            );
            $atlasScore = $this->aggregateScore($dimensions, 'atlas');
            $rivalScore = $this->aggregateScore($dimensions, 'rival');
        }

        $margin = ($atlasScore !== null && $rivalScore !== null)
            ? abs($atlasScore - $rivalScore)
            : null;
        $winner = $this->resolveCaseWinner($atlasScore, $rivalScore, $caseHasHardFail);

        $historicalAtlas = isset($entry['historical_atlas']) && is_numeric($entry['historical_atlas'])
            ? (float) $entry['historical_atlas']
            : null;
        $historicalRival = isset($entry['historical_rival']) && is_numeric($entry['historical_rival'])
            ? (float) $entry['historical_rival']
            : null;

        $suspicious = $this->triageSuspicious(
            atlasScore: $atlasScore,
            rivalScore: $rivalScore,
            margin: $margin,
            winner: $winner,
            armA: $arms[0] ?? [],
            armB: $arms[1] ?? [],
            atlasReceipt: $atlasReceipt,
            rivalReceipt: $rivalReceipt,
            caseHasHardFail: $caseHasHardFail,
            taskCategory: $taskCategory,
            roleFocus: (string) ($caseManifest['role_focus'] ?? $manifest['role_focus'] ?? 'unknown'),
            historicalAtlas: $historicalAtlas,
            historicalRival: $historicalRival,
            evidencePack: $evidencePack,
        );

        $valid = ! $caseHasHardFail && $atlasScore !== null && $rivalScore !== null;
        $humanReviewRequired = $this->caseRequiresHumanReview($winner, $margin, $suspicious, $caseHasHardFail);

        $case = [
            'case_id' => $caseId,
            'run_id' => $runId,
            'task_category' => $taskCategory,
            'role_focus' => (string) ($caseManifest['role_focus'] ?? $manifest['role_focus'] ?? 'unknown'),
            'weights_source' => $weightsResolved['source'],
            'weights' => $weightsResolved['weights'],
            'dimensions' => $dimensions,
            'scores' => [
                'atlas' => $atlasScore !== null ? round($atlasScore, 2) : null,
                'rival' => $rivalScore !== null ? round($rivalScore, 2) : null,
                'margin' => $margin !== null ? round($margin, 2) : null,
            ],
            'hard_gates' => $hardGatesAll,
            'hard_failures' => $hardFailures,
            'winner' => $winner,
            'tie_threshold' => self::DEFAULT_TIE_THRESHOLD,
            'suspicious_results' => $suspicious,
            'human_review_required' => $humanReviewRequired,
            'valid' => $valid,
            'evidence_freshness' => $this->resolveEvidenceFreshness($atlasReceipt, $rivalReceipt),
            'claim_ready' => false,
            'winner_reason' => $this->buildCaseWinnerReason($winner, $atlasScore, $rivalScore, $margin, $suspicious, $hardFailures),
        ];

        // Prefer case_manifest fields when present (they are the authoring
        // source of difficulty for batch entries); fallback to the run-real
        // manifest if the case_manifest doesn't carry difficulty/role_focus.
        $difficultyManifest = $caseManifest !== [] ? $caseManifest : $manifest;

        return $this->enrichCaseWithDifficultyAndBreakdown($case, $difficultyManifest, $atlasReceipt, $rivalReceipt);
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array{source:string, weights:array<string,float>}
     */
    private function resolveWeights(array $manifest): array
    {
        $qg = $manifest['quality_gates'] ?? null;
        if (is_array($qg) && isset($qg['weights']) && is_array($qg['weights'])) {
            $raw = [];
            foreach ($qg['weights'] as $k => $v) {
                if (is_numeric($v) && (float) $v > 0) {
                    $raw[(string) $k] = (float) $v;
                }
            }
            if ($raw !== []) {
                $sum = array_sum($raw);
                if ($sum <= 0) {
                    return ['source' => 'default_policy', 'weights' => self::DEFAULT_WEIGHTS];
                }
                // Normalize without changing semantics: rescale so sum is 1.0.
                $normalized = [];
                foreach ($raw as $k => $v) {
                    $normalized[$k] = round($v / $sum, 6);
                }

                return ['source' => 'case_manifest', 'weights' => $normalized];
            }
        }

        return ['source' => 'default_policy', 'weights' => self::DEFAULT_WEIGHTS];
    }

    /**
     * v1-parity hard gates re-computed in batch mode where no on-disk run
     * exists. Mirrors the canonical list used by v1's evaluateHardGates.
     *
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @param  array<string,mixed>  $workspaceHashes
     * @param  array<string,mixed>  $evidencePack
     * @return list<array{code:string,ok:bool,detail:string}>
     */
    private function evaluateV1ParityHardGates(
        array $manifest,
        array $atlasReceipt,
        array $rivalReceipt,
        array $workspaceHashes,
        array $evidencePack,
        bool $replayPasses,
    ): array {
        $verdict = (string) ($manifest['verdict'] ?? 'unknown');
        $dirty = (bool) ($manifest['dirty_after_run'] ?? ($workspaceHashes['dirty_after_run'] ?? false));
        $atlasOos = $this->stringList($atlasReceipt['out_of_scope_files'] ?? []);
        $rivalOos = $this->stringList($rivalReceipt['out_of_scope_files'] ?? []);
        $atlasBytecode = $this->stringList($atlasReceipt['bytecode_artifacts'] ?? []);
        $rivalBytecode = $this->stringList($rivalReceipt['bytecode_artifacts'] ?? []);
        $missingEvidence = $this->stringList($evidencePack['missing_evidence'] ?? []);

        return [
            ['code' => 'verdict_comparable', 'ok' => $verdict === 'comparable', 'detail' => $verdict],
            ['code' => 'provider_exit_zero_atlas', 'ok' => (int) ($atlasReceipt['exit_code'] ?? -1) === 0, 'detail' => 'exit_code='.(int) ($atlasReceipt['exit_code'] ?? -1)],
            ['code' => 'provider_exit_zero_rival', 'ok' => (int) ($rivalReceipt['exit_code'] ?? -1) === 0, 'detail' => 'exit_code='.(int) ($rivalReceipt['exit_code'] ?? -1)],
            ['code' => 'tests_passed_atlas', 'ok' => (int) ($atlasReceipt['test_exit_code'] ?? -1) === 0, 'detail' => 'test_exit_code='.(int) ($atlasReceipt['test_exit_code'] ?? -1)],
            ['code' => 'tests_passed_rival', 'ok' => (int) ($rivalReceipt['test_exit_code'] ?? -1) === 0, 'detail' => 'test_exit_code='.(int) ($rivalReceipt['test_exit_code'] ?? -1)],
            ['code' => 'replay_passes', 'ok' => $replayPasses, 'detail' => $replayPasses ? 'ok' : 'replay_failed'],
            ['code' => 'evidence_complete', 'ok' => $missingEvidence === [], 'detail' => $missingEvidence === [] ? 'ok' : implode(',', $missingEvidence)],
            ['code' => 'no_out_of_scope_files_atlas', 'ok' => $atlasOos === [], 'detail' => $atlasOos === [] ? 'ok' : implode(',', $atlasOos)],
            ['code' => 'no_out_of_scope_files_rival', 'ok' => $rivalOos === [], 'detail' => $rivalOos === [] ? 'ok' : implode(',', $rivalOos)],
            ['code' => 'no_bytecode_artifacts_atlas', 'ok' => $atlasBytecode === [], 'detail' => $atlasBytecode === [] ? 'ok' : implode(',', $atlasBytecode)],
            ['code' => 'no_bytecode_artifacts_rival', 'ok' => $rivalBytecode === [], 'detail' => $rivalBytecode === [] ? 'ok' : implode(',', $rivalBytecode)],
            ['code' => 'dirty_after_run_false', 'ok' => $dirty === false, 'detail' => $dirty ? 'dirty_after_run=true' : 'clean'],
            ['code' => 'patch_diff_present_atlas', 'ok' => (int) ($atlasReceipt['patch_diff_bytes'] ?? 0) > 0, 'detail' => 'patch_diff_bytes='.(int) ($atlasReceipt['patch_diff_bytes'] ?? 0)],
            ['code' => 'patch_diff_present_rival', 'ok' => (int) ($rivalReceipt['patch_diff_bytes'] ?? 0) > 0, 'detail' => 'patch_diff_bytes='.(int) ($rivalReceipt['patch_diff_bytes'] ?? 0)],
        ];
    }

    /**
     * v2-only operator-friendly hard gates that catch failure modes the v1
     * canonical list does not name explicitly. Any of these failing forces
     * score=null / winner=null for the case.
     *
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @param  array<string,mixed>  $evidencePack
     * @param  array<string,mixed>  $armA
     * @param  array<string,mixed>  $armB
     * @param  array<string,mixed>  $inputAssertions
     * @return list<array{code:string,ok:bool,detail:string}>
     */
    private function evaluateV2ExtraHardGates(
        array $manifest,
        array $atlasReceipt,
        array $rivalReceipt,
        array $evidencePack,
        array $armA,
        array $armB,
        string $mode,
        array $inputAssertions,
    ): array {
        $gates = [];

        foreach ([['atlas', $atlasReceipt], ['rival', $rivalReceipt]] as [$arm, $receipt]) {
            $hasReceiptArray = is_array($receipt) && $receipt !== [];
            $gates[] = [
                'code' => 'missing_provider_receipt:'.$arm,
                'ok' => $hasReceiptArray,
                'detail' => $hasReceiptArray ? 'ok' : 'receipt_blob_missing',
            ];

            $hasPatch = (int) ($receipt['patch_diff_bytes'] ?? 0) > 0;
            $gates[] = [
                'code' => 'missing_patch_diff:'.$arm,
                'ok' => $hasPatch,
                'detail' => $hasPatch ? 'ok' : 'patch_diff_bytes=0',
            ];

            $hasTestLog = isset($receipt['test_log_path']) && trim((string) $receipt['test_log_path']) !== '';
            $gates[] = [
                'code' => 'missing_test_log:'.$arm,
                'ok' => $hasTestLog,
                'detail' => $hasTestLog ? 'ok' : 'test_log_path_empty',
            ];

            $testExit = (int) ($receipt['test_exit_code'] ?? -1);
            $gates[] = [
                'code' => 'tests_failed:'.$arm,
                'ok' => $testExit === 0,
                'detail' => 'test_exit_code='.$testExit,
            ];

            $timedOut = (bool) ($receipt['timeout'] ?? false);
            $hasResult = (int) ($receipt['patch_diff_bytes'] ?? 0) > 0 || trim((string) ($receipt['test_log_path'] ?? '')) !== '';
            $timeoutWithoutResult = $timedOut && ! $hasResult;
            $gates[] = [
                'code' => 'timeout_without_result:'.$arm,
                'ok' => ! $timeoutWithoutResult,
                'detail' => $timeoutWithoutResult ? 'timeout_true_no_result' : 'ok',
            ];

            $killed = (bool) ($receipt['killed'] ?? false);
            $stalled = $killed && (int) ($receipt['exit_code'] ?? -1) !== 0 && ! $hasResult;
            $gates[] = [
                'code' => 'stalled_runner_no_heartbeat:'.$arm,
                'ok' => ! $stalled,
                'detail' => $stalled ? 'killed_true_exit_nonzero_no_result' : 'ok',
            ];

            $forbiddenTouched = $this->stringList($receipt['forbidden_paths_touched'] ?? $receipt['forbidden_files_touched'] ?? []);
            $gates[] = [
                'code' => 'forbidden_file_changed:'.$arm,
                'ok' => $forbiddenTouched === [],
                'detail' => $forbiddenTouched === [] ? 'ok' : implode(',', $forbiddenTouched),
            ];

            $oos = $this->stringList($receipt['out_of_scope_files'] ?? []);
            $gates[] = [
                'code' => 'scope_violation:'.$arm,
                'ok' => $oos === [],
                'detail' => $oos === [] ? 'ok' : implode(',', $oos),
            ];

            $bytecode = $this->stringList($receipt['bytecode_artifacts'] ?? []);
            $gates[] = [
                'code' => 'tracked_python_bytecode:'.$arm,
                'ok' => $bytecode === [],
                'detail' => $bytecode === [] ? 'ok' : implode(',', $bytecode),
            ];

            $providerPolicyViolation = (bool) ($receipt['provider_policy_violation'] ?? false);
            $gates[] = [
                'code' => 'provider_policy_violation:'.$arm,
                'ok' => ! $providerPolicyViolation,
                'detail' => $providerPolicyViolation ? 'provider_policy_violation=true' : 'ok',
            ];
        }

        // Workspace dirty before run (manifest-level).
        $dirtyBefore = (bool) ($manifest['workspace_dirty_before'] ?? false);
        $gates[] = [
            'code' => 'workspace_dirty_before',
            'ok' => ! $dirtyBefore,
            'detail' => $dirtyBefore ? 'workspace_dirty_before=true' : 'ok',
        ];

        $dirtyAfter = (bool) ($manifest['dirty_after_run'] ?? false);
        $gates[] = [
            'code' => 'workspace_dirty_after',
            'ok' => ! $dirtyAfter,
            'detail' => $dirtyAfter ? 'workspace_dirty_after=true' : 'ok',
        ];

        // Replay manifest presence + pack completeness as operator-friendly aliases.
        $replayManifestPresent = $this->evidenceArtifactPresent($evidencePack, ['replay_manifest', 'replay_manifest_json']);
        $gates[] = [
            'code' => 'replay_manifest_missing',
            'ok' => $replayManifestPresent,
            'detail' => $replayManifestPresent ? 'ok' : 'replay_manifest_missing',
        ];

        $packComplete = empty($this->stringList($evidencePack['missing_evidence'] ?? []));
        $gates[] = [
            'code' => 'evidence_pack_incomplete',
            'ok' => $packComplete,
            'detail' => $packComplete ? 'ok' : 'missing:'.implode(',', $this->stringList($evidencePack['missing_evidence'] ?? [])),
        ];

        // Mode-derived structural gates.
        $atlasArmIsForge = (string) ($armA['runner_type'] ?? '') === 'atlas_forge';
        // full_power asserts Atlas is the Atlas Forge arm; if not, this is a
        // structural integrity failure regardless of scores.
        $needsAtlasForge = in_array($mode, ['full_power', 'fair'], true);
        $gates[] = [
            'code' => 'atlas_arm_not_forge',
            'ok' => ! $needsAtlasForge || $atlasArmIsForge,
            'detail' => $needsAtlasForge && ! $atlasArmIsForge ? 'mode='.$mode.' but arm_a.runner_type='.((string) ($armA['runner_type'] ?? 'unknown')) : 'ok',
        ];

        $modelLockOk = true;
        if ($mode === 'fair') {
            $atlasModel = $this->canonModel((string) ($armA['model'] ?? 'unknown'));
            $rivalModel = $this->canonModel((string) ($armB['model'] ?? 'unknown'));
            $modelLockOk = $atlasModel === $rivalModel;
        }
        $gates[] = [
            'code' => 'model_lock_violation',
            'ok' => $modelLockOk,
            'detail' => $modelLockOk ? 'ok' : 'fair_mode_requires_same_model',
        ];

        // Synthetic score: any input asserts a numeric score without evidence,
        // OR any receipt declares claim_ready=true without evidence hash,
        // OR caller flags it explicitly.
        $synthetic = (bool) ($inputAssertions['synthetic_score'] ?? false);
        if (! $synthetic) {
            $manifestClaimsReady = (bool) ($manifest['claim_ready'] ?? false);
            $evidenceHashPresent = isset($evidencePack['artifacts']) && is_array($evidencePack['artifacts']) && $evidencePack['artifacts'] !== [];
            $synthetic = $manifestClaimsReady && ! $evidenceHashPresent;
        }
        $gates[] = [
            'code' => 'synthetic_score_detected',
            'ok' => ! $synthetic,
            'detail' => $synthetic ? 'claim_ready_true_without_evidence' : 'ok',
        ];

        return $gates;
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     * @param  list<string>  $keys
     */
    private function evidenceArtifactPresent(array $evidencePack, array $keys): bool
    {
        $artifacts = $evidencePack['artifacts'] ?? null;
        if (! is_array($artifacts)) {
            // Without artifacts the pack is incomplete — replay manifest gate
            // is informational; the canonical evidence_complete gate already
            // fails closed.
            return true;
        }
        foreach ($keys as $k) {
            $desc = $artifacts[$k] ?? null;
            if (is_array($desc) && ! empty($desc['present'])) {
                return true;
            }
        }

        // Default to present so we do not double-penalize when the canonical
        // evidence_complete gate already covers the omission.
        return ! array_key_exists('replay_manifest', $artifacts)
            && ! array_key_exists('replay_manifest_json', $artifacts);
    }

    private function canonModel(string $model): string
    {
        $m = strtolower(trim($model));

        return match ($m) {
            'sonnet' => 'claude_sonnet',
            'opus' => 'claude_opus',
            default => $m === '' ? 'unknown' : $m,
        };
    }

    /**
     * Map v1 quality_dimensions ({objective_alignment, patch_focus, ...}) onto
     * the v2 canonical dimensions used by Atlas Decide
     * ({correctness, test_coverage, scope_discipline, ...}). We do NOT change
     * v1's aggregate; we re-project for transparency.
     *
     * @param  array<string,mixed>  $v1Dimensions
     * @param  array<string,float>  $weights
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @param  array<string,mixed>  $evidencePack
     * @return array<string,array{atlas:float,rival:float,weight:float,explanation:string}>
     */
    private function mapDimensionsToV2(
        array $v1Dimensions,
        array $weights,
        array $atlasReceipt,
        array $rivalReceipt,
        array $evidencePack,
    ): array {
        $score = static function (array $dim, string $arm): float {
            return is_array($dim) && isset($dim[$arm]) && is_numeric($dim[$arm])
                ? (float) $dim[$arm]
                : 0.0;
        };

        $mapping = [
            'correctness' => 'objective_alignment',
            'test_coverage' => 'test_quality',
            'minimality' => 'patch_focus',
            'maintainability' => 'maintainability',
            'scope_discipline' => 'scope_discipline',
            'architecture_fit' => 'implementation_complexity',
            'evidence_quality' => 'evidence_quality',
            'cost_time' => 'cost_time_efficiency',
        ];

        $out = [];
        foreach ($mapping as $v2 => $v1) {
            $dim = (array) ($v1Dimensions[$v1] ?? []);
            $out[$v2] = [
                'atlas' => round($score($dim, 'atlas'), 2),
                'rival' => round($score($dim, 'rival'), 2),
                // Trust the resolved weights map verbatim. When the case
                // manifest brings its own (normalized to sum 1.0), dimensions
                // it does NOT declare must be weight=0. Falling back to
                // DEFAULT_WEIGHTS here would inflate the aggregate above 100.
                'weight' => (float) ($weights[$v2] ?? 0.0),
                'explanation' => (string) ($dim['explanation'] ?? 'derived_from_v1:'.$v1),
            ];
        }

        // ux_quality / performance are case-dependent extras. They are 0 unless
        // the case manifest declares them in `quality_gates.weights`.
        foreach (['ux_quality', 'performance'] as $extra) {
            $out[$extra] = [
                'atlas' => 0.0,
                'rival' => 0.0,
                'weight' => (float) ($weights[$extra] ?? 0.0),
                'explanation' => 'category_specific_dimension_not_scored_in_v1',
            ];
        }

        return $out;
    }

    /**
     * Compute dimension scores from scratch (batch path). This is a v2-native
     * scorer used when there is no v1 scorecard to project from. It uses the
     * same heuristics as v1 but exposes them under the v2 dimension names.
     *
     * @param  array<string,float>  $weights
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @param  array<string,mixed>  $evidencePack
     * @return array<string,array{atlas:float,rival:float,weight:float,explanation:string}>
     */
    private function computeDimensions(
        array $weights,
        array $manifest,
        array $atlasReceipt,
        array $rivalReceipt,
        array $evidencePack,
    ): array {
        $dims = [];

        $dims['correctness'] = $this->dimCorrectness($atlasReceipt, $rivalReceipt);
        $dims['test_coverage'] = $this->dimTestCoverage($atlasReceipt, $rivalReceipt);
        $dims['scope_discipline'] = $this->dimScopeDiscipline($atlasReceipt, $rivalReceipt);
        $dims['minimality'] = $this->dimMinimality($atlasReceipt, $rivalReceipt);
        $dims['maintainability'] = $this->dimMaintainability($atlasReceipt, $rivalReceipt);
        $dims['architecture_fit'] = $this->dimArchitectureFit($atlasReceipt, $rivalReceipt);
        $dims['evidence_quality'] = $this->dimEvidenceQuality($evidencePack);
        $dims['cost_time'] = $this->dimCostTime($atlasReceipt, $rivalReceipt);
        $dims['ux_quality'] = $this->dimNeutral('ux_quality_not_scored_without_case_signal');
        $dims['performance'] = $this->dimNeutral('performance_not_scored_without_case_signal');

        foreach ($dims as $k => $v) {
            // Trust the resolved weights map verbatim — when a case manifest
            // declares its own weights, dimensions it omits must be zero so
            // the aggregate cannot exceed 100.
            $dims[$k]['weight'] = (float) ($weights[$k] ?? 0.0);
        }

        return $dims;
    }

    /**
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @return array{atlas:float,rival:float,weight:float,explanation:string}
     */
    private function dimCorrectness(array $atlasReceipt, array $rivalReceipt): array
    {
        $s = static function (array $r): float {
            $exit = (int) ($r['exit_code'] ?? -1) === 0 ? 50.0 : 0.0;
            $test = (int) ($r['test_exit_code'] ?? -1) === 0 ? 50.0 : 0.0;
            $killed = (bool) ($r['killed'] ?? false) ? 20.0 : 0.0;

            return max(0.0, $exit + $test - $killed);
        };

        return [
            'atlas' => round($s($atlasReceipt), 2),
            'rival' => round($s($rivalReceipt), 2),
            'weight' => 0.0,
            'explanation' => 'exit_zero(50) + tests_pass(50) - killed_penalty(20).',
        ];
    }

    private function dimTestCoverage(array $atlasReceipt, array $rivalReceipt): array
    {
        $extract = static function (string $tail): int {
            if (preg_match('/(\d+)\s+assertions?\b/i', $tail, $m)) {
                return (int) $m[1];
            }
            if (preg_match('/Assertions:\s*(\d+)/i', $tail, $m)) {
                return (int) $m[1];
            }
            if (preg_match('/Tests:\s*(\d+)/i', $tail, $m)) {
                return (int) $m[1];
            }

            return 0;
        };
        $s = static function (array $r) use ($extract): float {
            if ((int) ($r['test_exit_code'] ?? -1) !== 0) {
                return 0.0;
            }
            $tail = (string) ($r['test_log_tail'] ?? '');
            $a = $extract($tail);
            if ($a >= 200) {
                return 95.0;
            }
            if ($a >= 50) {
                return 85.0;
            }
            if ($a >= 10) {
                return 75.0;
            }

            return 50.0;
        };

        return [
            'atlas' => round($s($atlasReceipt), 2),
            'rival' => round($s($rivalReceipt), 2),
            'weight' => 0.0,
            'explanation' => 'Assertion-count derived from test log tail. tests_failed ⇒ 0.',
        ];
    }

    private function dimScopeDiscipline(array $atlasReceipt, array $rivalReceipt): array
    {
        $s = function (array $r): float {
            return ($this->stringList($r['out_of_scope_files'] ?? []) === []
                && $this->stringList($r['bytecode_artifacts'] ?? []) === [])
                ? 100.0 : 0.0;
        };

        return [
            'atlas' => $s($atlasReceipt),
            'rival' => $s($rivalReceipt),
            'weight' => 0.0,
            'explanation' => 'Zero out-of-scope + zero bytecode ⇒ 100.',
        ];
    }

    private function dimMinimality(array $atlasReceipt, array $rivalReceipt): array
    {
        $s = static function (array $r): float {
            $b = (int) ($r['patch_diff_bytes'] ?? 0);
            if ($b <= 0) {
                return 0.0;
            }
            if ($b <= 2_000) {
                return 100.0;
            }
            if ($b <= 10_000) {
                return 90.0;
            }
            if ($b <= 50_000) {
                $f = ($b - 10_000) / 40_000.0;

                return 90.0 - 40.0 * $f;
            }
            if ($b <= 200_000) {
                $f = ($b - 50_000) / 150_000.0;

                return 50.0 - 25.0 * $f;
            }

            return 25.0;
        };

        return [
            'atlas' => round($s($atlasReceipt), 2),
            'rival' => round($s($rivalReceipt), 2),
            'weight' => 0.0,
            'explanation' => 'Smaller substantive diff wins; <=2KB:100, decay to 25 above 200KB.',
        ];
    }

    private function dimMaintainability(array $atlasReceipt, array $rivalReceipt): array
    {
        $s = function (array $r): float {
            $bytes = (int) ($r['patch_diff_bytes'] ?? 0);
            $count = max(1, count($this->stringList($r['changed_files'] ?? [])));
            $avg = $bytes / $count;
            if ($avg <= 1_500) {
                return 95.0;
            }
            if ($avg <= 5_000) {
                return 80.0;
            }
            if ($avg <= 15_000) {
                return 60.0;
            }

            return 40.0;
        };

        return [
            'atlas' => round($s($atlasReceipt), 2),
            'rival' => round($s($rivalReceipt), 2),
            'weight' => 0.0,
            'explanation' => 'Average diff bytes per touched file. Lower ⇒ more maintainable.',
        ];
    }

    private function dimArchitectureFit(array $atlasReceipt, array $rivalReceipt): array
    {
        $s = function (array $r): float {
            $changed = $this->stringList($r['changed_files'] ?? []);
            $count = count($changed);
            if ($count === 0) {
                return 50.0;
            }
            $bytes = (int) ($r['patch_diff_bytes'] ?? 0);
            if ($count <= 1 && $bytes > 50_000) {
                return 30.0;
            }
            if ($count <= 3) {
                return 100.0;
            }
            if ($count <= 6) {
                return 75.0;
            }
            if ($count <= 12) {
                return 55.0;
            }

            return 35.0;
        };

        return [
            'atlas' => round($s($atlasReceipt), 2),
            'rival' => round($s($rivalReceipt), 2),
            'weight' => 0.0,
            'explanation' => 'Touched-files + big-single-file penalty.',
        ];
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     * @return array{atlas:float,rival:float,weight:float,explanation:string}
     */
    private function dimEvidenceQuality(array $evidencePack): array
    {
        $artifacts = (array) ($evidencePack['artifacts'] ?? []);
        $required = ['manifest', 'events_jsonl', 'atlas_receipt', 'rival_receipt', 'workspace_hashes'];
        $hits = 0;
        foreach ($required as $k) {
            $desc = $artifacts[$k] ?? null;
            if (is_array($desc) && ! empty($desc['present']) && ! empty($desc['sha256'])) {
                $hits++;
            }
        }
        $score = ($hits / max(1, count($required))) * 100.0;

        return [
            'atlas' => round($score, 2),
            'rival' => round($score, 2),
            'weight' => 0.0,
            'explanation' => 'Required artifacts present + hashed.',
        ];
    }

    private function dimCostTime(array $atlasReceipt, array $rivalReceipt): array
    {
        $elapsed = function (array $r): int {
            $a = (string) ($r['started_at'] ?? '');
            $b = (string) ($r['finished_at'] ?? '');
            if ($a === '' || $b === '') {
                return 0;
            }
            try {
                return max(0, (new \DateTimeImmutable($b))->getTimestamp() - (new \DateTimeImmutable($a))->getTimestamp());
            } catch (\Throwable) {
                return 0;
            }
        };
        $s = function (array $r) use ($elapsed): float {
            $bytes = (int) ($r['stdout_bytes'] ?? 0) + (int) ($r['stderr_bytes'] ?? 0);
            $sec = $elapsed($r);
            $score = 100.0;
            if ($bytes > 200_000) {
                $score -= 20.0;
            }
            if ($bytes > 500_000) {
                $score -= 20.0;
            }
            if ($sec > 300) {
                $score -= 20.0;
            }
            if ($sec > 900) {
                $score -= 20.0;
            }

            return max(0.0, $score);
        };

        return [
            'atlas' => round($s($atlasReceipt), 2),
            'rival' => round($s($rivalReceipt), 2),
            'weight' => 0.0,
            'explanation' => 'Wall-time + stdout volume proxy.',
        ];
    }

    /**
     * @return array{atlas:float,rival:float,weight:float,explanation:string}
     */
    private function dimNeutral(string $why): array
    {
        return [
            'atlas' => 0.0,
            'rival' => 0.0,
            'weight' => 0.0,
            'explanation' => $why,
        ];
    }

    /**
     * @param  array<string,float>  $weights
     * @return array<string,array{atlas:float,rival:float,weight:float,explanation:string}>
     */
    private function emptyDimensions(array $weights): array
    {
        $out = [];
        foreach (self::DEFAULT_WEIGHTS as $k => $_) {
            $out[$k] = [
                'atlas' => 0.0,
                'rival' => 0.0,
                'weight' => $weights[$k] ?? self::DEFAULT_WEIGHTS[$k],
                'explanation' => 'dimension_not_scored_due_to_hard_fail',
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,array{atlas:float,rival:float,weight:float,explanation:string}>  $dimensions
     */
    private function aggregateScore(array $dimensions, string $arm): float
    {
        $total = 0.0;
        foreach ($dimensions as $d) {
            $w = (float) ($d['weight'] ?? 0.0);
            $v = (float) ($d[$arm] ?? 0.0);
            $total += $w * $v;
        }

        return $total;
    }

    private function resolveCaseWinner(?float $atlas, ?float $rival, bool $hardFail): ?string
    {
        if ($hardFail || $atlas === null || $rival === null) {
            return self::WINNER_NONE;
        }
        $diff = $atlas - $rival;
        if (abs($diff) < self::DEFAULT_TIE_THRESHOLD) {
            return self::WINNER_TIE;
        }

        return $diff > 0 ? self::WINNER_ATLAS : self::WINNER_RIVAL;
    }

    /**
     * @param  list<array<string,mixed>>  $suspicious
     * @param  list<string>  $hardFailures
     * @return list<string>
     */
    private function buildCaseWinnerReason(?string $winner, ?float $atlas, ?float $rival, ?float $margin, array $suspicious, array $hardFailures): array
    {
        $bullets = [];
        if ($hardFailures !== []) {
            $bullets[] = 'hard_failures:'.implode(',', $hardFailures);
            $bullets[] = 'no_quality_score_when_hard_fail';

            return $bullets;
        }
        $bullets[] = sprintf(
            'aggregate_score:atlas=%s rival=%s margin=%s',
            $atlas === null ? 'null' : number_format($atlas, 2, '.', ''),
            $rival === null ? 'null' : number_format($rival, 2, '.', ''),
            $margin === null ? 'null' : number_format($margin, 2, '.', ''),
        );
        if ($winner === self::WINNER_TIE) {
            $bullets[] = 'tie_threshold_breached';
            $bullets[] = 'human_review_recommended';
        } elseif ($winner === self::WINNER_ATLAS || $winner === self::WINNER_RIVAL) {
            $bullets[] = $winner.'_leads_aggregate_score';
        }
        foreach ($suspicious as $s) {
            if (! empty($s['affects_winner'])) {
                $bullets[] = 'suspicious_affects_winner:'.((string) ($s['code'] ?? 'unknown'));
            }
        }

        return $bullets;
    }

    /**
     * @param  array<string,mixed>  $armA
     * @param  array<string,mixed>  $armB
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @param  array<string,mixed>  $evidencePack
     * @return list<array<string,mixed>>
     */
    private function triageSuspicious(
        ?float $atlasScore,
        ?float $rivalScore,
        ?float $margin,
        ?string $winner,
        array $armA,
        array $armB,
        array $atlasReceipt,
        array $rivalReceipt,
        bool $caseHasHardFail,
        string $taskCategory,
        string $roleFocus,
        ?float $historicalAtlas,
        ?float $historicalRival,
        array $evidencePack = [],
    ): array {
        if ($caseHasHardFail) {
            return [];
        }
        $out = [];
        $tg = AtlasForgeRivalsAdjudicatorCalibrationService::TRUTH_GUARD;

        $rivalModel = strtolower((string) ($armB['model'] ?? 'unknown'));
        if ($rivalScore !== null
            && in_array($rivalModel, self::STRONG_RIVAL_MODELS, true)
            && $rivalScore < (float) $tg['rival_strong_score_ceiling']
        ) {
            $out[] = [
                'code' => 'rival_underperformed_unexpectedly',
                'aliases' => ['rival_score_below_70_for_strong_provider'],
                'arm' => 'rival',
                'score' => $rivalScore,
                'context' => 'strong_model='.$rivalModel.' scored below '.$tg['rival_strong_score_ceiling'],
                'affects_winner' => $winner === self::WINNER_ATLAS,
                'next_action' => 'triage_provider_log_and_replay',
            ];
        }

        // Truth Guard: 100×0 absurd score. Fires on any of three patterns —
        // literal 100 vs 0, wide blowout margin, or "extreme outlier" (one arm
        // near-perfect AND the gap is large). Always affects winner because
        // an absurd scoreline without a structural cause needs triage before
        // it ever becomes a claim.
        if ($atlasScore !== null && $rivalScore !== null) {
            $isAbsolute100vs0 = ($atlasScore >= 99.0 && $rivalScore <= 1.0)
                || ($rivalScore >= 99.0 && $atlasScore <= 1.0);
            $isMarginBlowout = ($margin ?? 0.0) >= (float) $tg['score_100_vs_0_margin'];
            $isExtremeOutlier = ($margin ?? 0.0) >= (float) $tg['extreme_outlier_margin']
                && max($atlasScore, $rivalScore) >= (float) $tg['extreme_outlier_score_floor'];
            if ($isAbsolute100vs0 || $isMarginBlowout || $isExtremeOutlier) {
                $out[] = [
                    'code' => 'score_100_vs_0',
                    'aliases' => ['absurd_scoreline'],
                    'arm' => $atlasScore > $rivalScore ? 'atlas' : 'rival',
                    'score' => max($atlasScore, $rivalScore),
                    'context' => sprintf('atlas=%.2f rival=%.2f margin=%.2f', $atlasScore, $rivalScore, $margin ?? 0),
                    'affects_winner' => true,
                    'next_action' => 'triage_adjudicator_calibration',
                ];
            }
            // Score blowout without an obvious cause (no hard fail, no patch
            // emptiness, no provider timeout): the model is far from the
            // rival without any structural explanation — needs triage.
            $atlasOk = $atlasScore >= (float) $tg['blowout_atlas_score_floor'];
            $rivalOk = $rivalScore <= (float) $tg['blowout_rival_score_ceiling'];
            $rivalKilled = (bool) ($rivalReceipt['killed'] ?? false);
            $rivalTimeout = (bool) ($rivalReceipt['timeout'] ?? false);
            $atlasKilled = (bool) ($atlasReceipt['killed'] ?? false);
            $atlasTimeout = (bool) ($atlasReceipt['timeout'] ?? false);
            $hasObviousCause = $rivalKilled || $rivalTimeout || $atlasKilled || $atlasTimeout;
            if (($atlasOk && $rivalOk) || ($rivalScore >= (float) $tg['blowout_atlas_score_floor'] && $atlasScore <= (float) $tg['blowout_rival_score_ceiling'])) {
                if (! $hasObviousCause) {
                    $winnerArm = $atlasScore > $rivalScore ? 'atlas' : 'rival';
                    $out[] = [
                        'code' => 'score_blowout_without_cause',
                        'aliases' => ['large_margin_in_single_case'],
                        'arm' => $winnerArm,
                        'score' => $winnerArm === 'atlas' ? $atlasScore : $rivalScore,
                        'context' => sprintf('atlas=%.2f rival=%.2f no_timeout_no_kill', $atlasScore, $rivalScore),
                        'affects_winner' => true,
                        'next_action' => 'triage_difficulty_and_prompt',
                    ];
                }
            }
        }

        // Patch almost empty (per arm). Canon thresholds in TRUTH_GUARD.
        foreach ([['atlas', $atlasReceipt], ['rival', $rivalReceipt]] as [$arm, $receipt]) {
            $patch = (int) ($receipt['patch_diff_bytes'] ?? 0);
            if ($patch > 0 && $patch <= (int) $tg['patch_almost_empty_bytes']) {
                $out[] = [
                    'code' => $arm.'_patch_almost_empty',
                    'arm' => $arm,
                    'score' => $arm === 'atlas' ? $atlasScore : $rivalScore,
                    'context' => 'patch_diff_bytes='.$patch.' <= '.$tg['patch_almost_empty_bytes'],
                    'affects_winner' => ($winner === $arm),
                    'next_action' => 'triage_patch_substance',
                ];
            }
        }

        // Provider timeout mistaken for low quality: timeout=true AND a
        // surprisingly low score. The score is NOT a quality signal here.
        foreach ([['atlas', $atlasReceipt, $atlasScore], ['rival', $rivalReceipt, $rivalScore]] as [$arm, $receipt, $sc]) {
            $timedOut = (bool) ($receipt['timeout'] ?? false);
            if ($timedOut && $sc !== null && $sc < (float) $tg['suspicious_provider_timeout_score_ceiling']) {
                $out[] = [
                    'code' => 'provider_timeout_mistaken_for_low_quality',
                    'arm' => $arm,
                    'score' => $sc,
                    'context' => 'timeout=true score='.number_format((float) $sc, 2, '.', ''),
                    'affects_winner' => ($winner !== null && $winner !== $arm),
                    'next_action' => 'triage_timeout_policy_and_retry',
                ];
            }
        }

        // Evidence pack not strictly complete but case still passed
        // evidence_complete because missing_evidence was empty (e.g. soft pack
        // with partial artifact hashes). We surface this as a triage hint
        // without forcing a hard failure.
        $artifacts = (array) ($evidencePack['artifacts'] ?? []);
        if ($artifacts !== []) {
            $required = ['manifest', 'events_jsonl', 'atlas_receipt', 'rival_receipt', 'workspace_hashes'];
            $present = 0;
            foreach ($required as $key) {
                $desc = $artifacts[$key] ?? null;
                if (is_array($desc) && ! empty($desc['present']) && ! empty($desc['sha256'])) {
                    $present++;
                }
            }
            $fraction = $present / max(1, count($required));
            if ($fraction < (float) $tg['evidence_artifact_presence_floor']) {
                $out[] = [
                    'code' => 'evidence_incomplete_suspicious',
                    'arm' => 'both',
                    'score' => null,
                    'context' => sprintf('artifact_present_fraction=%.2f floor=%.2f', $fraction, $tg['evidence_artifact_presence_floor']),
                    'affects_winner' => true,
                    'next_action' => 'triage_evidence_pack_collection',
                ];
            }
        }

        $simpleRoleFocus = in_array($roleFocus, ['minimal_diff', 'ui_correctness', 'cohesion', 'clarity'], true);
        if ($atlasScore !== null
            && $margin !== null
            && $winner === self::WINNER_ATLAS
            && $margin > self::SUSPICIOUS_ATLAS_MARGIN_FLOOR
            && $atlasScore > self::SUSPICIOUS_ATLAS_SCORE_FLOOR
            && $simpleRoleFocus
        ) {
            $out[] = [
                'code' => 'atlas_won_easy_case_by_huge_margin',
                'arm' => 'atlas',
                'score' => $atlasScore,
                'context' => 'role_focus='.$roleFocus.' margin='.number_format($margin, 2, '.', ''),
                'affects_winner' => true,
                'next_action' => 'triage_case_difficulty_vs_prompt',
            ];
        }

        foreach ([['atlas', $atlasReceipt, $armA], ['rival', $rivalReceipt, $armB]] as [$arm, $receipt, $armData]) {
            $patch = (int) ($receipt['patch_diff_bytes'] ?? 0);
            $stdoutBytes = (int) ($receipt['stdout_bytes'] ?? 0);
            if ($stdoutBytes === 0 && $patch > 0) {
                $out[] = [
                    'code' => 'empty_stdout_with_expected_change',
                    'arm' => $arm,
                    'score' => $arm === 'atlas' ? $atlasScore : $rivalScore,
                    'context' => 'patch_diff_bytes='.$patch.' stdout_bytes=0',
                    'affects_winner' => false,
                    'next_action' => 'triage_provider_log',
                ];
            }
            // Too fast for difficulty: <5s wall time on a non-trivial case.
            $started = (string) ($receipt['started_at'] ?? '');
            $finished = (string) ($receipt['finished_at'] ?? '');
            $elapsed = $this->elapsedSeconds($started, $finished);
            if ($elapsed > 0
                && $elapsed < self::SUSPICIOUS_DURATION_FLOOR_SECONDS
                && in_array($taskCategory, ['backend', 'backend_logic', 'architecture', 'performance', 'performance_edge_case', 'integration', 'security'], true)
            ) {
                $out[] = [
                    'code' => 'too_fast_for_difficulty',
                    'arm' => $arm,
                    'score' => $arm === 'atlas' ? $atlasScore : $rivalScore,
                    'context' => 'elapsed_seconds='.$elapsed.' task_category='.$taskCategory,
                    'affects_winner' => false,
                    'next_action' => 'triage_case_difficulty',
                ];
            }
        }

        if ($historicalAtlas !== null && $atlasScore !== null && abs($atlasScore - $historicalAtlas) > self::SUSPICIOUS_HISTORY_DELTA) {
            $out[] = [
                'code' => 'score_diverges_from_history',
                'arm' => 'atlas',
                'score' => $atlasScore,
                'context' => 'historical_atlas='.$historicalAtlas,
                'affects_winner' => $winner === self::WINNER_ATLAS,
                'next_action' => 'triage_ledger_freshness',
            ];
        }
        if ($historicalRival !== null && $rivalScore !== null && abs($rivalScore - $historicalRival) > self::SUSPICIOUS_HISTORY_DELTA) {
            $out[] = [
                'code' => 'score_diverges_from_history',
                'arm' => 'rival',
                'score' => $rivalScore,
                'context' => 'historical_rival='.$historicalRival,
                'affects_winner' => $winner === self::WINNER_RIVAL,
                'next_action' => 'triage_ledger_freshness',
            ];
        }

        if ($margin !== null
            && $margin < self::DEFAULT_TIE_THRESHOLD
            && in_array($winner, [self::WINNER_ATLAS, self::WINNER_RIVAL], true)
        ) {
            // Defense in depth: should not happen because resolveCaseWinner
            // returns tie below the threshold. Kept as guard so any future
            // weighting drift surfaces as a triage signal.
            $out[] = [
                'code' => 'narrow_winner',
                'arm' => $winner,
                'score' => $winner === self::WINNER_ATLAS ? $atlasScore : $rivalScore,
                'context' => 'margin='.number_format($margin, 2, '.', '').' below tie_threshold',
                'affects_winner' => true,
                'next_action' => 'triage_dimension_breakdown',
            ];
        } elseif ($margin !== null
            && $margin < self::NARROW_WIN_THRESHOLD
            && in_array($winner, [self::WINNER_ATLAS, self::WINNER_RIVAL], true)
        ) {
            $out[] = [
                'code' => 'narrow_win',
                'arm' => $winner,
                'score' => $winner === self::WINNER_ATLAS ? $atlasScore : $rivalScore,
                'context' => 'margin='.number_format($margin, 2, '.', '').' below narrow_win_threshold',
                'affects_winner' => false,
                'next_action' => 'human_review_recommended',
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $suspicious
     */
    private function caseRequiresHumanReview(?string $winner, ?float $margin, array $suspicious, bool $hardFail): bool
    {
        if ($hardFail) {
            return true;
        }
        if ($winner === self::WINNER_TIE) {
            return true;
        }
        foreach ($suspicious as $s) {
            if (! empty($s['affects_winner'])) {
                return true;
            }
        }
        if ($margin !== null && $margin < self::NARROW_WIN_THRESHOLD && in_array($winner, [self::WINNER_ATLAS, self::WINNER_RIVAL], true)) {
            return true;
        }

        return false;
    }

    /**
     * Compose the canonical v2 envelope from a list of case objects.
     *
     * @param  list<array<string,mixed>>  $arms
     * @param  list<array<string,mixed>>  $cases
     * @param  array<string,mixed>  $historicalContext
     * @return array<string,mixed>
     */
    private function composeEnvelope(
        string $inputMode,
        ?string $runId,
        ?string $inputPath,
        string $preset,
        string $mode,
        array $arms,
        array $cases,
        array $historicalContext,
    ): array {
        $caseCount = count($cases);
        $validCases = array_values(array_filter($cases, static fn (array $c): bool => (bool) $c['valid']));
        $validCount = count($validCases);
        $invalidCount = $caseCount - $validCount;

        $categories = $this->aggregateCategories($cases);
        $overall = $this->buildOverall($cases, $categories, $caseCount, $validCount, $historicalContext);

        $confidence = $overall['confidence'];

        $suspiciousFlat = [];
        foreach ($cases as $c) {
            foreach ((array) $c['suspicious_results'] as $s) {
                $s['case_id'] = $c['case_id'];
                $s['task_category'] = $c['task_category'];
                $suspiciousFlat[] = $s;
            }
        }

        $hardFailuresFlat = [];
        foreach ($cases as $c) {
            foreach ((array) $c['hard_failures'] as $code) {
                $hardFailuresFlat[] = ['case_id' => $c['case_id'], 'code' => $code];
            }
        }

        $humanReview = $this->buildHumanReviewBlock($cases, $overall, $suspiciousFlat);

        $ledgerProjection = $this->buildLedgerProjectionReady($cases, $arms, $preset, $mode, $confidence);

        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'input_mode' => $inputMode,
            'run_id' => $runId,
            'input_path' => $inputPath,
            'preset' => $preset,
            'mode' => $mode,
            'case_count' => $caseCount,
            'valid_case_count' => $validCount,
            'invalid_case_count' => $invalidCount,
            'arms' => $arms,
            'cases' => $cases,
            'categories' => $categories,
            'overall' => $overall,
            'confidence' => $confidence,
            'suspicious_results' => $suspiciousFlat,
            'hard_failures' => $hardFailuresFlat,
            'human_review' => $humanReview,
            'safety' => [
                'never_promotes_completion_claim' => true,
                'never_unlocks_external_rivals_certification' => true,
                'claim_ready' => false,
                'separated_from_external_rivals_certification' => true,
                'external_rivals_certification_status' => self::EXTERNAL_RIVALS_STATUS_BLOCKED,
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
            ],
            'ledger_projection_ready' => $ledgerProjection,
            'external_rivals_certification_status' => self::EXTERNAL_RIVALS_STATUS_BLOCKED,
            'claim_ready' => false,
            'separated_from_external_rivals_certification' => true,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'note' => 'v2 adjudication is local, deterministic. Never promotes external_rivals_certification. Never promotes completion claim.',
        ];

        $envelope['adjudication_hash'] = $this->canonicalHash($envelope);

        return $envelope;
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @return list<array<string,mixed>>
     */
    private function aggregateCategories(array $cases): array
    {
        $buckets = [];
        foreach ($cases as $c) {
            $cat = (string) $c['task_category'];
            $buckets[$cat] ??= [
                'cases' => [],
                'atlas_scores' => [],
                'rival_scores' => [],
                'suspicious' => [],
            ];
            $buckets[$cat]['cases'][] = $c;
            $atlas = $c['scores']['atlas'] ?? null;
            $rival = $c['scores']['rival'] ?? null;
            if ($c['valid'] && is_numeric($atlas) && is_numeric($rival)) {
                $buckets[$cat]['atlas_scores'][] = (float) $atlas;
                $buckets[$cat]['rival_scores'][] = (float) $rival;
            }
            foreach ((array) $c['suspicious_results'] as $s) {
                $buckets[$cat]['suspicious'][] = $s;
            }
        }

        $out = [];
        foreach ($buckets as $cat => $b) {
            $atlasAvg = $b['atlas_scores'] === [] ? null : array_sum($b['atlas_scores']) / count($b['atlas_scores']);
            $rivalAvg = $b['rival_scores'] === [] ? null : array_sum($b['rival_scores']) / count($b['rival_scores']);
            $margin = ($atlasAvg !== null && $rivalAvg !== null) ? abs($atlasAvg - $rivalAvg) : null;

            $suspiciousAffectsWinner = false;
            foreach ($b['suspicious'] as $s) {
                if (! empty($s['affects_winner'])) {
                    $suspiciousAffectsWinner = true;
                    break;
                }
            }

            $winner = null;
            $reasons = [];
            if ($atlasAvg === null || $rivalAvg === null) {
                $winner = self::WINNER_NO_TRUSTED;
                $reasons[] = 'insufficient_valid_cases';
            } elseif ($suspiciousAffectsWinner) {
                $winner = self::WINNER_NO_TRUSTED;
                $reasons[] = 'suspicious_result_affects_category_winner';
            } elseif ($margin < self::DEFAULT_TIE_THRESHOLD) {
                $winner = self::WINNER_TIE;
                $reasons[] = sprintf('margin_below_tie_threshold (margin=%.2f)', $margin);
            } else {
                $winner = $atlasAvg > $rivalAvg ? self::WINNER_ATLAS : self::WINNER_RIVAL;
                $reasons[] = sprintf('%s_leads_category_by_%.2f', $winner, $margin);
            }

            $confidence = $this->categoryConfidence(
                validCount: count($b['atlas_scores']),
                hardFailCount: count(array_filter($b['cases'], static fn (array $c): bool => ! (bool) $c['valid'])),
                suspiciousAffects: $suspiciousAffectsWinner,
            );

            $measuredSignal = $winner === self::WINNER_ATLAS
                ? 'atlas_forge'
                : ($winner === self::WINNER_RIVAL ? 'rival_provider' : 'inconclusive_run_more_cases');

            $out[] = [
                'category' => $cat,
                'cases_count' => count($b['cases']),
                'valid_cases_count' => count($b['atlas_scores']),
                'atlas_score_avg' => $atlasAvg === null ? null : round($atlasAvg, 2),
                'rival_score_avg' => $rivalAvg === null ? null : round($rivalAvg, 2),
                'winner' => $winner,
                'margin' => $margin === null ? null : round($margin, 2),
                'confidence' => $confidence,
                'reasons' => $reasons,
                'suspicious_results' => $b['suspicious'],
                'measured_provider_signal' => $measuredSignal,
                'routing_effect' => 'none',
            ];
        }

        // Stable order: alphabetically by category.
        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['category'], (string) $b['category']));

        return $out;
    }

    private function categoryConfidence(int $validCount, int $hardFailCount, bool $suspiciousAffects): string
    {
        if ($validCount === 0) {
            return self::CONFIDENCE_INSUFFICIENT;
        }
        if ($suspiciousAffects) {
            return self::CONFIDENCE_FLOW_VALIDATED;
        }
        if ($validCount >= 5 && $hardFailCount === 0) {
            return self::CONFIDENCE_DIRECTIONAL_SIGNAL;
        }
        if ($validCount >= 3) {
            return self::CONFIDENCE_DIRECTIONAL_SIGNAL;
        }

        return self::CONFIDENCE_FLOW_VALIDATED;
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @param  list<array<string,mixed>>  $categories
     * @param  array<string,mixed>  $historicalContext
     * @return array<string,mixed>
     */
    private function buildOverall(array $cases, array $categories, int $caseCount, int $validCount, array $historicalContext): array
    {
        if ($validCount === 0) {
            return [
                'winner' => self::WINNER_NONE,
                'atlas_score' => null,
                'rival_score' => null,
                'margin' => null,
                'confidence' => self::CONFIDENCE_INSUFFICIENT,
                'winner_reason' => ['insufficient_valid_cases'],
                'caveats' => ['no_valid_case_produced_score'],
                'claim_allowed' => false,
                'human_review_required' => true,
                'categories_won' => ['atlas' => 0, 'rival' => 0, 'tie' => 0, 'no_trusted_winner' => count($categories)],
            ];
        }

        $atlasSum = 0.0;
        $rivalSum = 0.0;
        $n = 0;
        $atlasWins = 0;
        $rivalWins = 0;
        $tieCats = 0;
        $noTrustedCats = 0;
        $suspiciousAffects = 0;
        foreach ($cases as $c) {
            if ($c['valid']) {
                $atlasSum += (float) $c['scores']['atlas'];
                $rivalSum += (float) $c['scores']['rival'];
                $n++;
            }
            foreach ((array) $c['suspicious_results'] as $s) {
                if (! empty($s['affects_winner'])) {
                    $suspiciousAffects++;
                }
            }
        }
        foreach ($categories as $cat) {
            $w = $cat['winner'];
            if ($w === self::WINNER_ATLAS) {
                $atlasWins++;
            } elseif ($w === self::WINNER_RIVAL) {
                $rivalWins++;
            } elseif ($w === self::WINNER_TIE) {
                $tieCats++;
            } else {
                $noTrustedCats++;
            }
        }

        $atlasAvg = $atlasSum / max(1, $n);
        $rivalAvg = $rivalSum / max(1, $n);
        $margin = abs($atlasAvg - $rivalAvg);

        $distinctCategories = count(array_unique(array_map(static fn (array $c): string => (string) $c['task_category'], $cases)));
        $hasHardFail = false;
        foreach ($cases as $c) {
            if (! $c['valid']) {
                $hasHardFail = true;
                break;
            }
        }
        $ledgerHistoryCount = (int) ($historicalContext['ledger_entry_count'] ?? 0);
        $ledgerFresh = (bool) ($historicalContext['ledger_fresh'] ?? false);

        $confidence = $this->resolveOverallConfidence(
            caseCount: $caseCount,
            validCount: $validCount,
            distinctCategories: $distinctCategories,
            hardFailPresent: $hasHardFail,
            suspiciousAffectsWinner: $suspiciousAffects > 0,
            ledgerHistoryCount: $ledgerHistoryCount,
            ledgerFresh: $ledgerFresh,
        );

        $winner = null;
        $winnerReason = [];
        $caveats = [];

        if ($suspiciousAffects > 0) {
            $winner = self::WINNER_NO_TRUSTED;
            $winnerReason[] = 'suspicious_results_affecting_winner='.$suspiciousAffects;
            $winnerReason[] = 'overall_winner_blocked_pending_triage';
        } elseif ($margin < self::DEFAULT_TIE_THRESHOLD) {
            $winner = self::WINNER_TIE;
            $winnerReason[] = sprintf('aggregate_margin_below_tie_threshold (margin=%.2f)', $margin);
        } else {
            $winner = $atlasAvg > $rivalAvg ? self::WINNER_ATLAS : self::WINNER_RIVAL;
            $winnerReason[] = sprintf('%s_leads_aggregate_by_%.2f', $winner, $margin);
            $winnerReason[] = sprintf('categories_won:atlas=%d rival=%d tie=%d no_trusted=%d', $atlasWins, $rivalWins, $tieCats, $noTrustedCats);
        }

        if ($confidence === self::CONFIDENCE_FLOW_VALIDATED) {
            $caveats[] = 'flow_validated_only_does_not_claim_superiority';
        }
        if ($confidence === self::CONFIDENCE_DIRECTIONAL_SIGNAL) {
            $caveats[] = 'directional_signal_only_not_release_grade';
        }
        if ($noTrustedCats > 0) {
            $caveats[] = 'categories_pending_triage='.$noTrustedCats;
        }
        if ($margin < self::NARROW_WIN_THRESHOLD && in_array($winner, [self::WINNER_ATLAS, self::WINNER_RIVAL], true)) {
            $caveats[] = 'narrow_aggregate_margin_human_review_recommended';
        }

        $claimAllowed = in_array($confidence, [self::CONFIDENCE_TRUSTED_BATTERY, self::CONFIDENCE_PROVIDER_RANKING, self::CONFIDENCE_DECIDE_SIGNAL], true)
            && in_array($winner, [self::WINNER_ATLAS, self::WINNER_RIVAL], true)
            && $suspiciousAffects === 0;

        $humanReviewRequired = $suspiciousAffects > 0
            || $winner === self::WINNER_TIE
            || $winner === self::WINNER_NO_TRUSTED
            || ($margin < self::NARROW_WIN_THRESHOLD && in_array($winner, [self::WINNER_ATLAS, self::WINNER_RIVAL], true))
            || $hasHardFail;

        return [
            'winner' => $winner,
            'atlas_score' => round($atlasAvg, 2),
            'rival_score' => round($rivalAvg, 2),
            'margin' => round($margin, 2),
            'confidence' => $confidence,
            'winner_reason' => $winnerReason,
            'caveats' => $caveats,
            'claim_allowed' => $claimAllowed,
            'human_review_required' => $humanReviewRequired,
            'categories_won' => [
                'atlas' => $atlasWins,
                'rival' => $rivalWins,
                'tie' => $tieCats,
                'no_trusted_winner' => $noTrustedCats,
            ],
        ];
    }

    private function resolveOverallConfidence(
        int $caseCount,
        int $validCount,
        int $distinctCategories,
        bool $hardFailPresent,
        bool $suspiciousAffectsWinner,
        int $ledgerHistoryCount,
        bool $ledgerFresh,
    ): string {
        if ($validCount === 0) {
            return self::CONFIDENCE_INSUFFICIENT;
        }
        if ($ledgerHistoryCount >= 25 && $ledgerFresh) {
            return self::CONFIDENCE_DECIDE_SIGNAL;
        }
        if ($validCount >= 25 && $distinctCategories >= 8 && ! $hardFailPresent && ! $suspiciousAffectsWinner) {
            return self::CONFIDENCE_PROVIDER_RANKING;
        }
        if ($validCount >= 12 && $distinctCategories >= 8 && ! $hardFailPresent && ! $suspiciousAffectsWinner) {
            return self::CONFIDENCE_TRUSTED_BATTERY;
        }
        if ($validCount >= 5 && $distinctCategories >= 2 && ! $suspiciousAffectsWinner) {
            return self::CONFIDENCE_DIRECTIONAL_SIGNAL;
        }

        return self::CONFIDENCE_FLOW_VALIDATED;
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @param  array<string,mixed>  $overall
     * @param  list<array<string,mixed>>  $suspiciousFlat
     * @return array<string,mixed>
     */
    private function buildHumanReviewBlock(array $cases, array $overall, array $suspiciousFlat): array
    {
        $required = (bool) ($overall['human_review_required'] ?? false);
        $reasons = [];
        $checklist = [];
        if ($overall['winner'] === self::WINNER_TIE) {
            $reasons[] = 'overall_winner_is_tie';
            $checklist[] = 'inspect_per_dimension_breakdown_before_declaring';
        }
        if ($overall['winner'] === self::WINNER_NO_TRUSTED) {
            $reasons[] = 'overall_winner_blocked_pending_triage';
            $checklist[] = 'run_triage_against_listed_suspicious_results';
        }
        foreach ($cases as $c) {
            if (! $c['valid']) {
                $reasons[] = 'case_hard_failed:'.$c['case_id'];
                $checklist[] = 'fix_hard_gates_then_re_run:'.$c['case_id'];
            }
        }
        if ($suspiciousFlat !== []) {
            $reasons[] = 'suspicious_results_present='.count($suspiciousFlat);
            $checklist[] = 'audit_each_suspicious_result_before_trusting_winner';
        }
        if (in_array($overall['confidence'], [self::CONFIDENCE_FLOW_VALIDATED, self::CONFIDENCE_DIRECTIONAL_SIGNAL], true)) {
            $checklist[] = 'expand_battery_to_release_preset_to_claim_winner';
        }

        return [
            'required' => $required,
            'reasons' => array_values(array_unique($reasons)),
            'checklist' => array_values(array_unique($checklist)),
        ];
    }

    /**
     * Build the projection block that the Provider Performance Ledger can
     * absorb without further work. Hard-failed runs go in as
     * `valid=false` with `hard_failure_reason` set; the ledger excludes them
     * from rankings automatically.
     *
     * @param  list<array<string,mixed>>  $cases
     * @param  list<array<string,mixed>>  $arms
     * @return list<array<string,mixed>>
     */
    private function buildLedgerProjectionReady(array $cases, array $arms, string $preset, string $mode, string $confidence): array
    {
        $entries = [];
        $atlasArm = $arms[0] ?? [];
        $rivalArm = $arms[1] ?? [];

        foreach ($cases as $c) {
            $freshness = (string) ($c['evidence_freshness'] ?? 'unknown');
            foreach ([['atlas', $atlasArm], ['rival', $rivalArm]] as [$armKey, $armData]) {
                $score = $c['scores'][$armKey] ?? null;
                $valid = (bool) $c['valid'] && $score !== null;
                $entries[] = [
                    'arm' => $armKey,
                    'arm_id' => (string) ($armData['arm_id'] ?? $armKey),
                    'runner_type' => (string) ($armData['runner_type'] ?? ($armKey === 'atlas' ? 'atlas_forge' : 'raw_provider')),
                    'provider' => (string) ($armData['provider'] ?? 'unknown'),
                    'model' => (string) ($armData['model'] ?? 'unknown'),
                    'role' => (string) ($armData['role'] ?? 'builder'),
                    'task_category' => (string) $c['task_category'],
                    'mode' => $mode,
                    'preset' => $preset,
                    'score' => $score === null ? null : (float) $score,
                    'confidence' => $confidence,
                    'valid' => $valid,
                    'hard_failure_reason' => $valid ? null : ($c['hard_failures'][0] ?? 'unknown_invalidation'),
                    'freshness' => $freshness,
                    'cost_estimate' => null,
                    'duration_ms' => null,
                    'case_id' => (string) $c['case_id'],
                    'run_id' => (string) ($c['run_id'] ?? ''),
                    'claim_ready' => false,
                    'separated_from_external_rivals_certification' => true,
                ];
            }
        }

        return $entries;
    }

    /**
     * Use evidence timestamps, not wall-clock adjudication time, so the same
     * input produces the same ledger projection and adjudication hash.
     *
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     */
    private function resolveEvidenceFreshness(array $atlasReceipt, array $rivalReceipt): string
    {
        $candidates = [];
        foreach ([$atlasReceipt, $rivalReceipt] as $receipt) {
            foreach (['finished_at', 'completed_at', 'recorded_at', 'started_at'] as $key) {
                $value = trim((string) ($receipt[$key] ?? ''));
                if ($value !== '') {
                    $candidates[] = $value;
                    break;
                }
            }
        }

        if ($candidates === []) {
            return 'unknown';
        }

        usort($candidates, static function (string $a, string $b): int {
            try {
                return (new \DateTimeImmutable($a))->getTimestamp() <=> (new \DateTimeImmutable($b))->getTimestamp();
            } catch (\Throwable) {
                return strcmp($a, $b);
            }
        });

        return (string) end($candidates);
    }

    /**
     * Deterministic SHA-256 of the canonical v2 payload (sans the hash field
     * itself). Same input ⇒ same output, regardless of process or host.
     *
     * @param  array<string,mixed>  $envelope
     */
    private function canonicalHash(array $envelope): string
    {
        $copy = $envelope;
        unset($copy['adjudication_hash'], $copy['generated_at']);
        $canonical = $this->canonicalize($copy);

        return hash('sha256', (string) json_encode($canonical));
    }

    /**
     * Recursively sort associative keys so the JSON encoding is determinístic.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        // Detect associative array.
        $isAssoc = array_keys($value) !== range(0, count($value) - 1);
        if ($isAssoc) {
            ksort($value);
        }
        foreach ($value as $k => $v) {
            $value[$k] = $this->canonicalize($v);
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function persist(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        @file_put_contents(
            $path,
            (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function inferProvider(string $model): string
    {
        $m = strtolower($model);
        if ($m === '') {
            return 'unknown';
        }
        if (str_contains($m, 'claude') || $m === 'sonnet' || $m === 'opus') {
            return 'anthropic_claude';
        }
        if ($m === 'codex' || str_starts_with($m, 'codex')) {
            return 'openai_codex';
        }
        if (str_starts_with($m, 'gemini')) {
            return 'google_gemini';
        }
        if (str_starts_with($m, 'gpt')) {
            return 'openai_gpt';
        }

        return 'unknown';
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => (string) $v, $value));
    }

    private function elapsedSeconds(string $a, string $b): int
    {
        if ($a === '' || $b === '') {
            return 0;
        }
        try {
            return max(0, (new \DateTimeImmutable($b))->getTimestamp() - (new \DateTimeImmutable($a))->getTimestamp());
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Truth Guard: difficulty-aware enrichment. Adds per-arm breakdown (hard
     * fail, quality score, confidence score, difficulty-weighted score and a
     * human-readable explanation of "por que tirou X e não 100"), the
     * difficulty / planning / execution composition derived from the case
     * manifest, and the case outcome label (winner/loser/tie/needs_triage/
     * no_trusted_winner/invalid).
     *
     * Difficulty NEVER masks a hard failure: when valid=false, the difficulty
     * multiplier is not applied and the breakdown shows quality_score=null.
     *
     * @param  array<string,mixed>  $case
     * @param  array<string,mixed>  $caseManifest
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @return array<string,mixed>
     */
    private function enrichCaseWithDifficultyAndBreakdown(
        array $case,
        array $caseManifest,
        array $atlasReceipt,
        array $rivalReceipt,
    ): array {
        $difficulty = $this->calibration->resolveDifficulty(
            $caseManifest['difficulty_level'] ?? null,
            $caseManifest['difficulty_score'] ?? null,
            is_string($caseManifest['difficulty_reason'] ?? null) ? (string) $caseManifest['difficulty_reason'] : null,
        );
        $planExec = $this->calibration->resolvePlanExecWeights(
            $difficulty['level'],
            $caseManifest['planning_weight'] ?? null,
            $caseManifest['execution_weight'] ?? null,
        );
        $ambiguity = strtolower(trim((string) ($caseManifest['ambiguity_level'] ?? 'medium')));
        if (! in_array($ambiguity, AtlasForgeRivalsAdjudicatorCalibrationService::AMBIGUITY_LEVELS, true)) {
            $ambiguity = 'medium';
        }
        $risk = strtolower(trim((string) ($caseManifest['risk_level'] ?? 'medium')));
        if (! in_array($risk, AtlasForgeRivalsAdjudicatorCalibrationService::RISK_LEVELS, true)) {
            $risk = 'medium';
        }

        $atlasBreakdown = $this->buildArmBreakdown('atlas', $case, $atlasReceipt, $difficulty);
        $rivalBreakdown = $this->buildArmBreakdown('rival', $case, $rivalReceipt, $difficulty);

        $outcome = $this->resolveCaseOutcome($case);

        $scores = (array) $case['scores'];
        $scores['difficulty_level'] = $difficulty['level'];
        $scores['difficulty_score'] = $difficulty['score'];
        $scores['difficulty_multiplier'] = $difficulty['multiplier'];
        $scores['atlas_raw'] = $scores['atlas'] ?? null;
        $scores['rival_raw'] = $scores['rival'] ?? null;
        $scores['atlas_difficulty_weighted'] = $atlasBreakdown['difficulty_weighted_score'];
        $scores['rival_difficulty_weighted'] = $rivalBreakdown['difficulty_weighted_score'];

        return array_replace($case, [
            'difficulty' => [
                'level' => $difficulty['level'],
                'score' => $difficulty['score'],
                'reason' => $difficulty['reason'],
                'multiplier' => $difficulty['multiplier'],
                'planning_weight' => $planExec['planning'],
                'execution_weight' => $planExec['execution'],
                'ambiguity_level' => $ambiguity,
                'risk_level' => $risk,
            ],
            'scores' => $scores,
            'atlas_breakdown' => $atlasBreakdown,
            'rival_breakdown' => $rivalBreakdown,
            'outcome' => $outcome,
        ]);
    }

    /**
     * Build a per-arm score breakdown with explicit bullets explaining the
     * aggregate. The explanation is the answer to "why did you give a 93 and
     * not 100?" — it walks each weighted dimension and its contribution.
     *
     * @param  array<string,mixed>  $case
     * @param  array<string,mixed>  $receipt
     * @param  array{level:string,score:float,multiplier:float,reason:string}  $difficulty
     * @return array<string,mixed>
     */
    private function buildArmBreakdown(string $arm, array $case, array $receipt, array $difficulty): array
    {
        $scoreRaw = $case['scores'][$arm] ?? null;
        $score = is_numeric($scoreRaw) ? (float) $scoreRaw : null;
        $valid = (bool) ($case['valid'] ?? false);
        $hardFailures = (array) ($case['hard_failures'] ?? []);
        $hardFail = $hardFailures !== [];

        $explanation = [];
        if ($hardFail) {
            $explanation[] = 'hard_fail:'.implode(',', array_map('strval', $hardFailures));
            $explanation[] = 'quality_score=null (hard fail prevents quality scoring)';
            $explanation[] = 'difficulty_multiplier_not_applied_because_invalid';
        } else {
            $explanation[] = sprintf(
                'quality_score=%s',
                $score === null ? 'null' : number_format($score, 2, '.', ''),
            );
            $contribs = [];
            foreach ((array) ($case['dimensions'] ?? []) as $dimName => $d) {
                if (! is_array($d)) {
                    continue;
                }
                $w = (float) ($d['weight'] ?? 0);
                $v = (float) ($d[$arm] ?? 0);
                if ($w <= 0.0) {
                    continue;
                }
                $contribs[] = [
                    'dim' => (string) $dimName,
                    'score' => $v,
                    'weight' => $w,
                    'contribution' => round($w * $v, 2),
                ];
            }
            // Sort descending by contribution so top contributors appear first.
            usort($contribs, static fn (array $a, array $b): int => $b['contribution'] <=> $a['contribution']);
            foreach ($contribs as $c) {
                $explanation[] = sprintf(
                    '%s: score=%.2f weight=%.2f contribution=%.2f',
                    $c['dim'], $c['score'], $c['weight'], $c['contribution']
                );
            }
            if ($score !== null && $score < 100.0) {
                $loss = round(100.0 - $score, 2);
                $explanation[] = sprintf(
                    'not_100_because: weighted_dimensions_capped_at_%.2f (gap_to_100=%.2f)',
                    $score, $loss,
                );
                // Surface the largest-loss dimensions explicitly to point
                // operator attention to where the run actually fell short.
                $maxLossDim = null;
                $maxLoss = 0.0;
                foreach ($contribs as $c) {
                    $dimLoss = $c['weight'] * (100.0 - $c['score']);
                    if ($dimLoss > $maxLoss) {
                        $maxLoss = $dimLoss;
                        $maxLossDim = $c['dim'];
                    }
                }
                if ($maxLossDim !== null) {
                    $explanation[] = sprintf(
                        'top_loss_dimension=%s lost_points=%.2f',
                        $maxLossDim, $maxLoss,
                    );
                }
            }
            $explanation[] = sprintf(
                'difficulty=%s multiplier=%.2f',
                $difficulty['level'], $difficulty['multiplier'],
            );
        }

        // Case-level confidence_score: never high without trusted_battery
        // (overall lifts this). We bound here based on case-level signals.
        $confidenceScore = 0.0;
        if ($valid && ! $hardFail) {
            $caseConfidence = AtlasForgeRivalsAdjudicatorV2Service::CONFIDENCE_FLOW_VALIDATED;
            $hasAffectingSuspicious = false;
            foreach ((array) ($case['suspicious_results'] ?? []) as $s) {
                if (! empty($s['affects_winner'])) {
                    $hasAffectingSuspicious = true;
                    break;
                }
            }
            if ($hasAffectingSuspicious) {
                $caseConfidence = AtlasForgeRivalsAdjudicatorV2Service::CONFIDENCE_INSUFFICIENT;
            }
            $confidenceScore = $this->calibration->confidenceScore($caseConfidence);
        }

        $diffWeighted = $this->calibration->applyDifficultyMultiplier(
            $score,
            $difficulty['level'],
            $valid,
        );

        return [
            'hard_fail' => $hardFail,
            'hard_fail_reasons' => $hardFailures,
            'quality_score' => $score === null ? null : round($score, 2),
            'confidence_score' => round($confidenceScore, 2),
            'difficulty_weighted_score' => $diffWeighted,
            'score_explanation' => $explanation,
        ];
    }

    /**
     * Resolve the canonical case outcome label. needs_triage takes precedence
     * over everything else when a suspicious result affects the winner — that
     * is the Truth Guard: an absurd or unexplained scoreline NEVER becomes a
     * claim, only a triage request.
     *
     * @param  array<string,mixed>  $case
     */
    private function resolveCaseOutcome(array $case): string
    {
        $hardFailures = (array) ($case['hard_failures'] ?? []);
        if ($hardFailures !== []) {
            return self::OUTCOME_INVALID;
        }
        foreach ((array) ($case['suspicious_results'] ?? []) as $s) {
            if (! empty($s['affects_winner'])) {
                return self::OUTCOME_NEEDS_TRIAGE;
            }
        }
        $winner = $case['winner'] ?? null;

        return match ($winner) {
            self::WINNER_ATLAS, self::WINNER_RIVAL => self::OUTCOME_WINNER,
            self::WINNER_TIE => self::OUTCOME_TIE,
            self::WINNER_NO_TRUSTED => self::OUTCOME_NO_TRUSTED_WINNER,
            default => self::OUTCOME_NO_TRUSTED_WINNER,
        };
    }
}
