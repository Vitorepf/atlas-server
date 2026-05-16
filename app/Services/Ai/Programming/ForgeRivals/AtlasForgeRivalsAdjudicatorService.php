<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · Adjudicator.
 *
 * Deterministic, local-only quality adjudicator that turns a comparable run
 * into a winner / tie / invalid verdict. Never invokes a provider, never asks
 * an LLM to judge, never reads anything outside the run's evidence directory.
 *
 * Inputs (all already produced by run-real + collect-evidence):
 *   - evidence/manifest.json
 *   - evidence/atlas_receipt.json
 *   - evidence/rival_receipt.json
 *   - evidence/workspace_hashes.json
 *   - evidence/atlas_patch.diff
 *   - evidence/rival_patch.diff
 *   - evidence/atlas_test.log
 *   - evidence/rival_test.log
 *   - evidence/evidence_pack.json
 *   - replay outcome (passed in by caller)
 *
 * Hard gates (each is fail-closed for external claims). Infrastructure or
 * evidence failures ⇒ score=null, winner=null. A one-sided test failure with
 * every evidence/replay/scope gate intact produces a deterministic
 * `gate_outcome` with `gate_winner`, but score remains null. That answers the
 * operator's basic question (which arm survived?) without pretending that a
 * failed arm earned a comparable quality score.
 *
 *   - provider_exit_zero (both arms)
 *   - tests_passed (both arms)
 *   - replay_passes
 *   - evidence_complete
 *   - no_out_of_scope_files (both arms)
 *   - no_bytecode_artifacts (both arms)
 *   - dirty_after_run=false
 *   - patch_diff_present (both arms)
 *
 * Quality dimensions (each scored 0..100 per arm, weighted sum gives the
 * final score). Weights are explicit. None of these dimensions can rescue a
 * hard-gate failure.
 *
 *   - objective_alignment      (15%) provider+tests aligned, no kill, no timeout
 *   - patch_focus              (12%) smaller-but-substantive diff wins
 *   - implementation_complexity( 8%) large new files / sprawling diff penalty
 *   - test_quality             (12%) more relevant test lines / assertions pass
 *   - maintainability          (10%) churn vs new lines balance
 *   - risk_surface             (10%) fewer touched files outside test scope
 *   - scope_discipline         (15%) zero out-of-scope, zero bytecode
 *   - evidence_quality         (10%) all artifacts present + hashed
 *   - cost_time_efficiency     ( 8%) provider stdout bytes / wall time proxies
 *
 * Tie semantics: if |atlas_score - rival_score| < TIE_THRESHOLD (default 5),
 * winner = `human_review_required_tie`. Cost/time can only break a tie; it
 * never overrides a non-tie quality outcome.
 *
 * Schema: atlas.forge.rivals.adjudication.v1
 */
final class AtlasForgeRivalsAdjudicatorService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.adjudication.v1';

    /** Score points below which the adjudicator declares a tie. */
    public const DEFAULT_TIE_THRESHOLD = 5.0;

    public const WINNER_ATLAS = 'atlas';

    public const WINNER_RIVAL = 'rival';

    public const WINNER_TIE = 'human_review_required_tie';

    public const WINNER_NONE = null;

    /**
     * Fairness confidence labels emitted by the single-run scorecard. The
     * goal is to distinguish a real performance signal from a harness/setup
     * artefact: a `local_fake` mode or a missing piece of evidence can never
     * be a `high` confidence claim, no matter how clean the diff looks.
     */
    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCE_INVALID = 'invalid';

    /**
     * Canonical 5-level confidence ladder for the v1 Scoring Sanity, Fairness
     * & Confidence layer. Exposed in the scorecard as `confidence_level`. The
     * legacy `confidence.level` field is preserved for backwards compatibility.
     *
     *   invalid          — score cannot be compared (provider failure, fixture
     *                      error, missing evidence, replay drift, local_fake).
     *   low              — score exists but is fragile (narrow margin, hard
     *                      gate unclean, single sample, extreme without proof).
     *   medium           — score is usable as a hint but not a claim.
     *   high             — score is trustworthy as a single-case signal.
     *   release_trusted  — battery-level only; NEVER assigned from a single run.
     */
    public const CONFIDENCE_LEVEL_INVALID = 'invalid';

    public const CONFIDENCE_LEVEL_LOW = 'low';

    public const CONFIDENCE_LEVEL_MEDIUM = 'medium';

    public const CONFIDENCE_LEVEL_HIGH = 'high';

    public const CONFIDENCE_LEVEL_RELEASE_TRUSTED = 'release_trusted';

    public const CONFIDENCE_LEVELS = [
        self::CONFIDENCE_LEVEL_INVALID,
        self::CONFIDENCE_LEVEL_LOW,
        self::CONFIDENCE_LEVEL_MEDIUM,
        self::CONFIDENCE_LEVEL_HIGH,
        self::CONFIDENCE_LEVEL_RELEASE_TRUSTED,
    ];

    /** Validity class — `valid` means the run can support a comparable
     * quality score; everything else surfaces *why* the score is unsafe. */
    public const VALIDITY_VALID = 'valid';

    /**
     * Canonical name for provider run failures (kill/timeout/empty output by
     * driver error). The legacy `VALIDITY_INVALID_PROVIDER_FAILURE` constant
     * keeps the same value to avoid breaking external readers — both names
     * resolve to the same string.
     */
    public const VALIDITY_INVALID_PROVIDER_RUN = 'invalid_provider_run';

    public const VALIDITY_INVALID_PROVIDER_FAILURE = self::VALIDITY_INVALID_PROVIDER_RUN;

    public const VALIDITY_INVALID_FIXTURE = 'invalid_fixture';

    public const VALIDITY_INVALID_TEST_FAILURE = 'invalid_test_failure';

    public const VALIDITY_INVALID_MISSING_EVIDENCE = 'invalid_missing_evidence';

    public const VALIDITY_INVALID_REPLAY_DRIFT = 'invalid_replay_drift';

    public const VALIDITY_INVALID_HARNESS = 'invalid_harness_artifact';

    public const VALIDITY_INVALID_LOCAL_FAKE = 'invalid_local_fake_no_real_claim';

    public const VALIDITY_NEEDS_TRIAGE = 'needs_triage_extreme_score';

    /** Score margin above which a result demands extra evidence (else triage). */
    public const EXTREME_SCORE_MARGIN = 50.0;

    /** @var array<string,float> */
    public const WEIGHTS = [
        'objective_alignment' => 0.15,
        'patch_focus' => 0.12,
        'implementation_complexity' => 0.08,
        'test_quality' => 0.12,
        'maintainability' => 0.10,
        'risk_surface' => 0.10,
        'scope_discipline' => 0.15,
        'evidence_quality' => 0.10,
        'cost_time_efficiency' => 0.08,
    ];

    private readonly AtlasForgeRivalsAdjudicatorV2Service $v2;

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsReplayService $replay,
        ?AtlasForgeRivalsAdjudicatorV2Service $v2 = null,
    ) {
        // Lazy default keeps legacy tests that instantiate the service with two
        // args working untouched. Laravel's container injects the explicit v2
        // service in production.
        $this->v2 = $v2 ?? new AtlasForgeRivalsAdjudicatorV2Service($paths);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function adjudicate(array $input): array
    {
        // Batch mode: --input=<path> OR an in-memory payload. The v2 service
        // owns this branch end-to-end (no single-run on disk required) so it
        // can adjudicate fixtures and multi-case release batteries without
        // calling a provider. v1 scorecard is NOT emitted in this branch
        // because there is no single run on disk to attach it to.
        if (! empty($input['input']) || ! empty($input['input_path']) || ! empty($input['payload'])) {
            $envelope = $this->v2->adjudicateBatch($input);
            if (($envelope['status'] ?? 'ok') !== 'ok' && isset($envelope['blockers'])) {
                return $envelope;
            }

            return [
                'status' => 'ok',
                'schema_version' => $envelope['schema_version'] ?? AtlasForgeRivalsAdjudicatorV2Service::SCHEMA_VERSION,
                'schema_version_v2' => AtlasForgeRivalsAdjudicatorV2Service::SCHEMA_VERSION,
                'scorecard_v2' => $envelope,
                'scorecard_v2_path' => $envelope['scorecard_v2_path'] ?? null,
                'evidence_paths' => array_values(array_filter([$envelope['scorecard_v2_path'] ?? null])),
                'next_command' => '',
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
            ];
        }

        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['run_id_required'],
                'next_command' => 'php artisan atlas:forge:rivals adjudicate --run-id=<id> --json',
            ];
        }
        $paths = $this->paths->paths($runId);
        if (! is_dir($paths['base'])) {
            return [
                'status' => 'blocked',
                'blockers' => ['run_not_found:'.$paths['run_id']],
                'next_command' => '',
            ];
        }

        $manifest = $this->readJson($paths['manifest_json']);
        if ($manifest === []) {
            return [
                'status' => 'blocked',
                'blockers' => ['manifest_missing'],
                'next_command' => 'php artisan atlas:forge:rivals run-real --run-id='.$paths['run_id'].' --json',
            ];
        }

        $atlasReceipt = $this->readJson($paths['evidence'].'/atlas_receipt.json');
        $rivalReceipt = $this->readJson($paths['evidence'].'/rival_receipt.json');
        $workspaceHashes = $this->readJson($paths['evidence'].'/workspace_hashes.json');
        $evidencePack = $this->readJson($paths['evidence'].'/evidence_pack.json');

        // Replay must succeed before adjudicator declares anything. We force
        // `pre_adjudication` here because the scorecard is what THIS step is
        // about to write — a `final`-stage replay would always block on
        // `scorecard:not_present_at_replay`, a circular contract bug.
        $replayResult = $this->replay->replay([
            'run_id' => $paths['run_id'],
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
        $replayPasses = (bool) ($replayResult['replay_passes'] ?? false);

        $hardGates = $this->evaluateHardGates(
            manifest: $manifest,
            atlasReceipt: $atlasReceipt,
            rivalReceipt: $rivalReceipt,
            workspaceHashes: $workspaceHashes,
            evidencePack: $evidencePack,
            replayPasses: $replayPasses,
        );

        $hardFailures = array_values(array_filter($hardGates, static fn (array $g): bool => ! $g['ok']));
        $hardFailureCodes = array_values(array_map(static fn (array $g): string => (string) $g['code'], $hardFailures));

        if ($hardFailures !== []) {
            $gateOutcome = $this->oneSidedDeterministicGateOutcome($hardFailureCodes, $hardGates);
            // Suppress gate_winner whenever the losing arm did not actually
            // fail by model output — provider kill/timeout/empty-stdout
            // counts as a harness failure, not as a free win for the other
            // arm. Same logic if the "winning" arm itself looks contaminated.
            if ($gateOutcome !== null) {
                $loserReceipt = $gateOutcome['loser'] === self::WINNER_ATLAS ? $atlasReceipt : $rivalReceipt;
                $winnerReceipt = $gateOutcome['winner'] === self::WINNER_ATLAS ? $atlasReceipt : $rivalReceipt;
                if ($this->detectProviderFailure($loserReceipt) !== null) {
                    $gateOutcome = null;
                } elseif ($this->detectProviderFailure($winnerReceipt) !== null) {
                    $gateOutcome = null;
                }
            }
            if ($gateOutcome !== null) {
                $scorecard = [
                    'schema_version' => self::SCHEMA_VERSION,
                    'run_id' => $paths['run_id'],
                    'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
                    'winner' => self::WINNER_NONE,
                    'gate_winner' => $gateOutcome['winner'],
                    'gate_loser' => $gateOutcome['loser'],
                    'gate_result' => [
                        'kind' => $gateOutcome['kind'],
                        'winner' => $gateOutcome['winner'],
                        'loser' => $gateOutcome['loser'],
                        'quality_score_available' => false,
                        'quality_score_reason' => $gateOutcome['quality_score_reason'],
                    ],
                    'winner_reason' => [
                        'verdict:'.($manifest['verdict'] ?? 'unknown'),
                        'hard_failures:'.implode(',', $hardFailureCodes),
                        $gateOutcome['loser_reason'],
                        $gateOutcome['winner_reason'],
                        'gate_winner:'.$gateOutcome['winner'],
                        'score_source:gate_outcome',
                        'quality_score:null',
                        'claim_ready:false',
                    ],
                    'atlas_score' => null,
                    'rival_score' => null,
                    'score_difference' => null,
                    'score_source' => 'gate_outcome',
                    'quality_score_available' => false,
                    'quality_score_reason' => $gateOutcome['quality_score_reason'],
                    'score_explanation' => 'One arm failed deterministic contract gates while replay, evidence, bytecode, provider exit, dirty-after-run and patch-presence gates remained intact. This is a gate outcome only: no comparable quality score is emitted.',
                    'tie_threshold' => self::DEFAULT_TIE_THRESHOLD,
                    'hard_gates' => $hardGates,
                    'hard_failures' => $hardFailureCodes,
                    'quality_dimensions' => null,
                    'replay_passes' => $replayPasses,
                    'claim_ready' => false,
                    'human_review_required' => false,
                    'separated_from_external_rivals_certification' => true,
                    'note' => 'Gate winner by deterministic contract/test outcome. Quality score is null because both arms did not pass gates. External rivals certification remains blocked; claim_ready=false.',
                ];
                $scorecard['fairness'] = $this->buildFairnessGates(
                    manifest: $manifest,
                    atlasReceipt: $atlasReceipt,
                    rivalReceipt: $rivalReceipt,
                    evidencePack: $evidencePack,
                    replayPasses: $replayPasses,
                    hardGatesClean: false,
                    hardFailureCodes: $hardFailureCodes,
                    winner: null,
                    atlasScore: null,
                    rivalScore: null,
                );
                $this->persistScorecard($paths, $scorecard);

                return $this->finalizeSingleRunEnvelope(
                    paths: $paths,
                    scorecard: $scorecard,
                    manifest: $manifest,
                    atlasReceipt: $atlasReceipt,
                    rivalReceipt: $rivalReceipt,
                    evidencePack: $evidencePack,
                );
            }

            $scorecard = [
                'schema_version' => self::SCHEMA_VERSION,
                'run_id' => $paths['run_id'],
                'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
                'winner' => self::WINNER_NONE,
                'winner_reason' => [
                    'verdict:'.($manifest['verdict'] ?? 'unknown'),
                    'hard_failures:'.implode(',', $hardFailureCodes),
                    'no_quality_score_when_hard_fail',
                ],
                'atlas_score' => null,
                'rival_score' => null,
                'score_source' => 'none',
                'tie_threshold' => self::DEFAULT_TIE_THRESHOLD,
                'hard_gates' => $hardGates,
                'hard_failures' => $hardFailureCodes,
                'quality_dimensions' => null,
                'replay_passes' => $replayPasses,
                'claim_ready' => false,
                'human_review_required' => false,
                'separated_from_external_rivals_certification' => true,
                'note' => 'Hard gate failed — no quality score, no winner. Fix gates and re-run.',
            ];
            $scorecard['fairness'] = $this->buildFairnessGates(
                manifest: $manifest,
                atlasReceipt: $atlasReceipt,
                rivalReceipt: $rivalReceipt,
                evidencePack: $evidencePack,
                replayPasses: $replayPasses,
                hardGatesClean: false,
                hardFailureCodes: $hardFailureCodes,
                winner: null,
                atlasScore: null,
                rivalScore: null,
            );
            $this->persistScorecard($paths, $scorecard);

            return $this->finalizeSingleRunEnvelope(
                paths: $paths,
                scorecard: $scorecard,
                manifest: $manifest,
                atlasReceipt: $atlasReceipt,
                rivalReceipt: $rivalReceipt,
                evidencePack: $evidencePack,
            );
        }

        // All hard gates green ⇒ compute quality scores.
        $quality = $this->evaluateQuality(
            manifest: $manifest,
            atlasReceipt: $atlasReceipt,
            rivalReceipt: $rivalReceipt,
            evidencePack: $evidencePack,
        );
        $atlasScore = $quality['atlas_total'];
        $rivalScore = $quality['rival_total'];
        $diff = $atlasScore - $rivalScore;
        $absDiff = abs($diff);
        $threshold = self::DEFAULT_TIE_THRESHOLD;

        $winner = self::WINNER_NONE;
        $humanReviewRequired = false;
        $winnerReason = [];

        if ($absDiff < $threshold) {
            $winner = self::WINNER_TIE;
            $humanReviewRequired = true;
            $winnerReason[] = sprintf('|atlas-rival|=%.2f < threshold=%.1f', $absDiff, $threshold);
            $winnerReason[] = 'human_review_required_tie';
            // Cost/time tiebreaker — informational only. Never overrides quality outcome.
            $tiebreaker = $this->costTimeTiebreaker($atlasReceipt, $rivalReceipt);
            if ($tiebreaker !== null) {
                $winnerReason[] = 'tiebreaker_hint:'.$tiebreaker;
            }
        } else {
            $winner = $diff > 0 ? self::WINNER_ATLAS : self::WINNER_RIVAL;
            $winnerReason = $this->buildWinnerReason($winner, $quality, $diff);
        }

        $scorecard = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $paths['run_id'],
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'winner' => $winner,
            'winner_reason' => $winnerReason,
            'atlas_score' => round($atlasScore, 2),
            'rival_score' => round($rivalScore, 2),
            'score_difference' => round($diff, 2),
            'score_source' => 'quality_dimensions',
            'tie_threshold' => $threshold,
            'hard_gates' => $hardGates,
            'hard_failures' => [],
            'quality_dimensions' => $quality['dimensions'],
            'weights' => self::WEIGHTS,
            'replay_passes' => $replayPasses,
            'claim_ready' => $winner === self::WINNER_ATLAS || $winner === self::WINNER_RIVAL,
            'human_review_required' => $humanReviewRequired,
            'separated_from_external_rivals_certification' => true,
            'note' => 'Adjudicator is deterministic and local-only. No LLM judged this run.',
        ];
        $fairness = $this->buildFairnessGates(
            manifest: $manifest,
            atlasReceipt: $atlasReceipt,
            rivalReceipt: $rivalReceipt,
            evidencePack: $evidencePack,
            replayPasses: $replayPasses,
            hardGatesClean: true,
            hardFailureCodes: [],
            winner: $winner,
            atlasScore: $atlasScore,
            rivalScore: $rivalScore,
        );
        $scorecard['fairness'] = $fairness;
        // Fairness gates can downgrade a green run: local_fake / triage /
        // invalid validity classes never claim. claim_ready stays true only
        // when fairness agrees.
        if (! $fairness['claim_ready_recommended']) {
            $scorecard['claim_ready'] = false;
            if ($fairness['needs_triage']) {
                $scorecard['winner'] = self::WINNER_NONE;
                $scorecard['winner_reason'][] = 'fairness:'.$fairness['validity_class'];
                $scorecard['human_review_required'] = true;
            } elseif ($fairness['validity_class'] !== self::VALIDITY_VALID) {
                $scorecard['winner_reason'][] = 'fairness:'.$fairness['validity_class'];
            }
        }
        $this->persistScorecard($paths, $scorecard);

        return $this->finalizeSingleRunEnvelope(
            paths: $paths,
            scorecard: $scorecard,
            manifest: $manifest,
            atlasReceipt: $atlasReceipt,
            rivalReceipt: $rivalReceipt,
            evidencePack: $evidencePack,
        );
    }

    /**
     * Shared coda for every single-run branch: persists v1 (already done by
     * caller), builds the v2 envelope, and returns the canonical CLI response
     * with both v1 and v2 attached. Keeps the legacy `scorecard` /
     * `scorecard_path` keys intact so downstream consumers (ledger,
     * report v2, tests) do not break.
     *
     * @param  array<string,mixed>  $paths
     * @param  array<string,mixed>  $scorecard
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @param  array<string,mixed>  $evidencePack
     * @return array<string,mixed>
     */
    private function finalizeSingleRunEnvelope(
        array $paths,
        array $scorecard,
        array $manifest,
        array $atlasReceipt,
        array $rivalReceipt,
        array $evidencePack,
    ): array {
        $v2 = $this->v2->enrichFromSingleRun(
            runId: (string) $paths['run_id'],
            v1Scorecard: $scorecard,
            manifest: $manifest,
            atlasReceipt: $atlasReceipt,
            rivalReceipt: $rivalReceipt,
            evidencePack: $evidencePack,
        );
        $scorecardV2Path = $v2['scorecard_v2_path'] ?? $paths['scorecard_v2_json'];

        return [
            'status' => 'ok',
            'schema_version' => self::SCHEMA_VERSION,
            'schema_version_v2' => AtlasForgeRivalsAdjudicatorV2Service::SCHEMA_VERSION,
            'run_id' => $paths['run_id'],
            'scorecard' => $scorecard,
            'scorecard_path' => $paths['scorecard_json'],
            'scorecard_v2' => $v2,
            'scorecard_v2_path' => $scorecardV2Path,
            'evidence_paths' => [$paths['scorecard_json'], $scorecardV2Path],
            'next_command' => 'php artisan atlas:forge:rivals report --run-id='.$paths['run_id'].' --json',
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];
    }

    /**
     * A real battery is still useful when one side fails deterministic
     * contract/test gates and the other side passes. That is not a "quality
     * dimensions" score and it never becomes an external claim, but it is a
     * valid provider arena outcome. Harness contamination remains fail-closed.
     *
     * @param  list<string>  $hardFailureCodes
     * @param  list<array{code:string,ok:bool,detail:string}>  $hardGates
     * @return array{winner:string,loser:string,kind:string,quality_score_reason:string,loser_reason:string,winner_reason:string}|null
     */
    private function oneSidedDeterministicGateOutcome(array $hardFailureCodes, array $hardGates): ?array
    {
        $failures = array_values(array_unique($hardFailureCodes));
        $allowed = [
            'verdict_comparable',
            'tests_passed_atlas',
            'tests_passed_rival',
            'no_out_of_scope_files_atlas',
            'no_out_of_scope_files_rival',
        ];
        foreach ($failures as $failure) {
            if (! in_array($failure, $allowed, true)) {
                return null;
            }
        }

        $atlasFailedTests = in_array('tests_passed_atlas', $failures, true);
        $rivalFailedTests = in_array('tests_passed_rival', $failures, true);
        $atlasFailedScope = in_array('no_out_of_scope_files_atlas', $failures, true);
        $rivalFailedScope = in_array('no_out_of_scope_files_rival', $failures, true);
        $atlasFailed = $atlasFailedTests || $atlasFailedScope;
        $rivalFailed = $rivalFailedTests || $rivalFailedScope;
        if ($atlasFailed === $rivalFailed) {
            return null;
        }

        $gateOk = [];
        foreach ($hardGates as $gate) {
            $gateOk[(string) $gate['code']] = (bool) $gate['ok'];
        }

        if (
            $atlasFailed
            && ($gateOk['tests_passed_rival'] ?? false)
            && ($gateOk['no_out_of_scope_files_rival'] ?? false)
        ) {
            $kind = $atlasFailedScope ? 'one_sided_scope_failure' : 'one_sided_test_failure';

            return [
                'winner' => self::WINNER_RIVAL,
                'loser' => self::WINNER_ATLAS,
                'kind' => $kind,
                'quality_score_reason' => 'one_side_failed_contract_or_tests_before_comparable_quality_scoring',
                'loser_reason' => $atlasFailedScope ? 'atlas_failed_scope' : 'atlas_failed_tests',
                'winner_reason' => 'rival_passed_scope_and_tests',
            ];
        }

        if (
            $rivalFailed
            && ($gateOk['tests_passed_atlas'] ?? false)
            && ($gateOk['no_out_of_scope_files_atlas'] ?? false)
        ) {
            $kind = $rivalFailedScope ? 'one_sided_scope_failure' : 'one_sided_test_failure';

            return [
                'winner' => self::WINNER_ATLAS,
                'loser' => self::WINNER_RIVAL,
                'kind' => $kind,
                'quality_score_reason' => 'one_side_failed_contract_or_tests_before_comparable_quality_scoring',
                'loser_reason' => $rivalFailedScope ? 'rival_failed_scope' : 'rival_failed_tests',
                'winner_reason' => 'atlas_passed_scope_and_tests',
            ];
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @param  array<string,mixed>  $workspaceHashes
     * @param  array<string,mixed>  $evidencePack
     * @return list<array{code:string,ok:bool,detail:string}>
     */
    private function evaluateHardGates(
        array $manifest,
        array $atlasReceipt,
        array $rivalReceipt,
        array $workspaceHashes,
        array $evidencePack,
        bool $replayPasses,
    ): array {
        $verdict = (string) ($manifest['verdict'] ?? 'unknown');
        $dirty = (bool) ($manifest['dirty_after_run'] ?? false);
        $atlasOos = $this->stringList($atlasReceipt['out_of_scope_files'] ?? []);
        $rivalOos = $this->stringList($rivalReceipt['out_of_scope_files'] ?? []);
        $atlasBytecode = $this->stringList($atlasReceipt['bytecode_artifacts'] ?? []);
        $rivalBytecode = $this->stringList($rivalReceipt['bytecode_artifacts'] ?? []);
        $missingEvidence = $this->stringList($evidencePack['missing_evidence'] ?? []);

        $invalidVerdict = $verdict !== 'comparable';

        return [
            [
                'code' => 'verdict_comparable',
                'ok' => ! $invalidVerdict,
                'detail' => $verdict,
            ],
            [
                'code' => 'provider_exit_zero_atlas',
                'ok' => (int) ($atlasReceipt['exit_code'] ?? -1) === 0,
                'detail' => 'exit_code='.(int) ($atlasReceipt['exit_code'] ?? -1),
            ],
            [
                'code' => 'provider_exit_zero_rival',
                'ok' => (int) ($rivalReceipt['exit_code'] ?? -1) === 0,
                'detail' => 'exit_code='.(int) ($rivalReceipt['exit_code'] ?? -1),
            ],
            [
                'code' => 'tests_passed_atlas',
                'ok' => (int) ($atlasReceipt['test_exit_code'] ?? -1) === 0,
                'detail' => 'test_exit_code='.(int) ($atlasReceipt['test_exit_code'] ?? -1),
            ],
            [
                'code' => 'tests_passed_rival',
                'ok' => (int) ($rivalReceipt['test_exit_code'] ?? -1) === 0,
                'detail' => 'test_exit_code='.(int) ($rivalReceipt['test_exit_code'] ?? -1),
            ],
            [
                'code' => 'replay_passes',
                'ok' => $replayPasses,
                'detail' => $replayPasses ? 'ok' : 'replay_failed',
            ],
            [
                'code' => 'evidence_complete',
                'ok' => $missingEvidence === [],
                'detail' => $missingEvidence === [] ? 'ok' : implode(',', $missingEvidence),
            ],
            [
                'code' => 'no_out_of_scope_files_atlas',
                'ok' => $atlasOos === [],
                'detail' => $atlasOos === [] ? 'ok' : implode(',', $atlasOos),
            ],
            [
                'code' => 'no_out_of_scope_files_rival',
                'ok' => $rivalOos === [],
                'detail' => $rivalOos === [] ? 'ok' : implode(',', $rivalOos),
            ],
            [
                'code' => 'no_bytecode_artifacts_atlas',
                'ok' => $atlasBytecode === [],
                'detail' => $atlasBytecode === [] ? 'ok' : implode(',', $atlasBytecode),
            ],
            [
                'code' => 'no_bytecode_artifacts_rival',
                'ok' => $rivalBytecode === [],
                'detail' => $rivalBytecode === [] ? 'ok' : implode(',', $rivalBytecode),
            ],
            [
                'code' => 'dirty_after_run_false',
                'ok' => $dirty === false,
                'detail' => $dirty ? 'dirty_after_run=true' : 'clean',
            ],
            [
                'code' => 'patch_diff_present_atlas',
                'ok' => (int) ($atlasReceipt['patch_diff_bytes'] ?? 0) > 0,
                'detail' => 'patch_diff_bytes='.(int) ($atlasReceipt['patch_diff_bytes'] ?? 0),
            ],
            [
                'code' => 'patch_diff_present_rival',
                'ok' => (int) ($rivalReceipt['patch_diff_bytes'] ?? 0) > 0,
                'detail' => 'patch_diff_bytes='.(int) ($rivalReceipt['patch_diff_bytes'] ?? 0),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @param  array<string,mixed>  $evidencePack
     * @return array{
     *   atlas_total:float,
     *   rival_total:float,
     *   dimensions:array<string,array{atlas:float,rival:float,explanation:string}>
     * }
     */
    private function evaluateQuality(
        array $manifest,
        array $atlasReceipt,
        array $rivalReceipt,
        array $evidencePack,
    ): array {
        $dimensions = [];

        $dimensions['objective_alignment'] = $this->dimensionObjectiveAlignment($atlasReceipt, $rivalReceipt);
        $dimensions['patch_focus'] = $this->dimensionPatchFocus($atlasReceipt, $rivalReceipt);
        $dimensions['implementation_complexity'] = $this->dimensionComplexity($atlasReceipt, $rivalReceipt);
        $dimensions['test_quality'] = $this->dimensionTestQuality($atlasReceipt, $rivalReceipt);
        $dimensions['maintainability'] = $this->dimensionMaintainability($atlasReceipt, $rivalReceipt);
        $dimensions['risk_surface'] = $this->dimensionRiskSurface($atlasReceipt, $rivalReceipt);
        $dimensions['scope_discipline'] = $this->dimensionScopeDiscipline($atlasReceipt, $rivalReceipt);
        $dimensions['evidence_quality'] = $this->dimensionEvidenceQuality($evidencePack);
        $dimensions['cost_time_efficiency'] = $this->dimensionCostTime($atlasReceipt, $rivalReceipt);

        $atlasTotal = 0.0;
        $rivalTotal = 0.0;
        foreach ($dimensions as $key => $d) {
            $weight = self::WEIGHTS[$key] ?? 0.0;
            $atlasTotal += $weight * (float) $d['atlas'];
            $rivalTotal += $weight * (float) $d['rival'];
        }

        return [
            'atlas_total' => $atlasTotal,
            'rival_total' => $rivalTotal,
            'dimensions' => $dimensions,
        ];
    }

    /**
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @return array{atlas:float,rival:float,explanation:string}
     */
    private function dimensionObjectiveAlignment(array $atlasReceipt, array $rivalReceipt): array
    {
        $score = function (array $r): float {
            $exit = (int) ($r['exit_code'] ?? -1) === 0 ? 50 : 0;
            $test = (int) ($r['test_exit_code'] ?? -1) === 0 ? 50 : 0;
            $killed = (bool) ($r['killed'] ?? false);

            return (float) max(0, $exit + $test - ($killed ? 20 : 0));
        };

        return [
            'atlas' => $score($atlasReceipt),
            'rival' => $score($rivalReceipt),
            'explanation' => 'Provider exit zero (50) + tests pass (50), minus 20 if killed/timeout.',
        ];
    }

    /**
     * Smaller, substantive diff wins (within reason). Empty diff = 0.
     * Curve: full credit at <=2KB, linear decay to 50 at 50KB, 25 above 200KB.
     *
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @return array{atlas:float,rival:float,explanation:string}
     */
    private function dimensionPatchFocus(array $atlasReceipt, array $rivalReceipt): array
    {
        $score = function (array $r): float {
            $bytes = (int) ($r['patch_diff_bytes'] ?? 0);
            if ($bytes <= 0) {
                return 0.0;
            }
            if ($bytes <= 2_000) {
                return 100.0;
            }
            if ($bytes <= 10_000) {
                return 90.0;
            }
            if ($bytes <= 50_000) {
                $frac = ($bytes - 10_000) / 40_000.0;

                return 90.0 - 40.0 * $frac;
            }
            if ($bytes <= 200_000) {
                $frac = ($bytes - 50_000) / 150_000.0;

                return 50.0 - 25.0 * $frac;
            }

            return 25.0;
        };

        return [
            'atlas' => $score($atlasReceipt),
            'rival' => $score($rivalReceipt),
            'explanation' => 'Smaller substantive diff wins. <=2KB:100, <=10KB:90, <=50KB:decay to 50, <=200KB:50→25, >200KB:25.',
        ];
    }

    /**
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @return array{atlas:float,rival:float,explanation:string}
     */
    private function dimensionComplexity(array $atlasReceipt, array $rivalReceipt): array
    {
        $score = function (array $r): float {
            $changed = $this->stringList($r['changed_files'] ?? []);
            $count = count($changed);
            if ($count === 0) {
                return 50.0;
            }
            $bytes = (int) ($r['patch_diff_bytes'] ?? 0);
            // Large new files (>50KB diff with <=1 changed file) ⇒ complexity penalty.
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
            'atlas' => $score($atlasReceipt),
            'rival' => $score($rivalReceipt),
            'explanation' => 'Touched-files count + big-single-file penalty. <=3 files:100, <=6:75, <=12:55, >12:35.',
        ];
    }

    /**
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @return array{atlas:float,rival:float,explanation:string}
     */
    private function dimensionTestQuality(array $atlasReceipt, array $rivalReceipt): array
    {
        $score = function (array $r): float {
            $tail = (string) ($r['test_log_tail'] ?? '');
            $exit = (int) ($r['test_exit_code'] ?? -1);
            if ($exit !== 0) {
                return 0.0;
            }
            if ($tail === '') {
                return 50.0;
            }
            $assertions = $this->extractAssertionCount($tail);
            $touchedTest = false;
            foreach ($this->stringList($r['changed_files'] ?? []) as $f) {
                if (str_contains($f, 'tests/') || str_ends_with($f, 'Test.php') || str_ends_with($f, '.spec.ts') || str_ends_with($f, '.test.ts')) {
                    $touchedTest = true;
                    break;
                }
            }
            $base = 60.0;
            if ($assertions >= 200) {
                $base = 95.0;
            } elseif ($assertions >= 50) {
                $base = 85.0;
            } elseif ($assertions >= 10) {
                $base = 75.0;
            }
            if ($touchedTest) {
                $base += 5.0;
            }

            return min(100.0, $base);
        };

        return [
            'atlas' => $score($atlasReceipt),
            'rival' => $score($rivalReceipt),
            'explanation' => 'Assertion count from test log tail + bonus when a test file was touched.',
        ];
    }

    /**
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @return array{atlas:float,rival:float,explanation:string}
     */
    private function dimensionMaintainability(array $atlasReceipt, array $rivalReceipt): array
    {
        $score = function (array $r): float {
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
            'atlas' => $score($atlasReceipt),
            'rival' => $score($rivalReceipt),
            'explanation' => 'Average diff bytes per touched file. Lower is more maintainable.',
        ];
    }

    /**
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @return array{atlas:float,rival:float,explanation:string}
     */
    private function dimensionRiskSurface(array $atlasReceipt, array $rivalReceipt): array
    {
        $score = function (array $r): float {
            $changed = $this->stringList($r['changed_files'] ?? []);
            $productionTouched = 0;
            $testsTouched = 0;
            foreach ($changed as $f) {
                if (str_contains($f, 'tests/') || str_ends_with($f, 'Test.php')) {
                    $testsTouched++;
                } else {
                    $productionTouched++;
                }
            }
            if ($productionTouched === 0 && $testsTouched > 0) {
                return 90.0;
            }
            if ($productionTouched > 0 && $testsTouched === 0) {
                // Production code without a test ⇒ penalty.
                return 50.0;
            }
            if ($productionTouched <= 3) {
                return 85.0;
            }
            if ($productionTouched <= 6) {
                return 70.0;
            }

            return 50.0;
        };

        return [
            'atlas' => $score($atlasReceipt),
            'rival' => $score($rivalReceipt),
            'explanation' => 'Production-touched count balanced against tests touched. Prod without test = penalty.',
        ];
    }

    /**
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @return array{atlas:float,rival:float,explanation:string}
     */
    private function dimensionScopeDiscipline(array $atlasReceipt, array $rivalReceipt): array
    {
        $score = function (array $r): float {
            $oos = count($this->stringList($r['out_of_scope_files'] ?? []));
            $bytecode = count($this->stringList($r['bytecode_artifacts'] ?? []));
            if ($oos > 0 || $bytecode > 0) {
                return 0.0;
            }

            return 100.0;
        };

        return [
            'atlas' => $score($atlasReceipt),
            'rival' => $score($rivalReceipt),
            'explanation' => 'Zero out-of-scope + zero bytecode ⇒ 100. Otherwise 0 (also a hard gate).',
        ];
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     * @return array{atlas:float,rival:float,explanation:string}
     */
    private function dimensionEvidenceQuality(array $evidencePack): array
    {
        $artifacts = (array) ($evidencePack['artifacts'] ?? []);
        $required = ['manifest', 'events_jsonl', 'atlas_receipt', 'rival_receipt', 'workspace_hashes'];
        $hits = 0;
        foreach ($required as $key) {
            $desc = $artifacts[$key] ?? null;
            if (is_array($desc) && ! empty($desc['present']) && ! empty($desc['sha256'])) {
                $hits++;
            }
        }
        $score = (float) ($hits / max(1, count($required))) * 100.0;

        return [
            'atlas' => $score,
            'rival' => $score,
            'explanation' => 'Required artifacts present and hashed (shared run-level dimension).',
        ];
    }

    /**
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @return array{atlas:float,rival:float,explanation:string}
     */
    private function dimensionCostTime(array $atlasReceipt, array $rivalReceipt): array
    {
        $score = function (array $r): float {
            $bytes = (int) ($r['stdout_bytes'] ?? 0) + (int) ($r['stderr_bytes'] ?? 0);
            $startedAt = (string) ($r['started_at'] ?? '');
            $finishedAt = (string) ($r['finished_at'] ?? '');
            $elapsed = $this->elapsedSeconds($startedAt, $finishedAt);
            $score = 100.0;
            if ($bytes > 200_000) {
                $score -= 20.0;
            }
            if ($bytes > 500_000) {
                $score -= 20.0;
            }
            if ($elapsed > 300) {
                $score -= 20.0;
            }
            if ($elapsed > 900) {
                $score -= 20.0;
            }

            return max(0.0, $score);
        };

        return [
            'atlas' => $score($atlasReceipt),
            'rival' => $score($rivalReceipt),
            'explanation' => 'Wall time + provider stdout volume proxies. Larger output / longer time ⇒ lower score.',
        ];
    }

    /**
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     */
    private function costTimeTiebreaker(array $atlasReceipt, array $rivalReceipt): ?string
    {
        $atlasElapsed = $this->elapsedSeconds(
            (string) ($atlasReceipt['started_at'] ?? ''),
            (string) ($atlasReceipt['finished_at'] ?? ''),
        );
        $rivalElapsed = $this->elapsedSeconds(
            (string) ($rivalReceipt['started_at'] ?? ''),
            (string) ($rivalReceipt['finished_at'] ?? ''),
        );
        if ($atlasElapsed > 0 && $rivalElapsed > 0) {
            return $atlasElapsed < $rivalElapsed ? 'atlas_faster' : ($atlasElapsed > $rivalElapsed ? 'rival_faster' : 'equal_time');
        }

        return null;
    }

    /**
     * @param  array{
     *   atlas_total:float,
     *   rival_total:float,
     *   dimensions:array<string,array{atlas:float,rival:float,explanation:string}>
     * }  $quality
     * @return list<string>
     */
    private function buildWinnerReason(string $winner, array $quality, float $diff): array
    {
        $bullets = [];
        $bullets[] = sprintf(
            'aggregate_score:atlas=%.2f rival=%.2f diff=%.2f',
            $quality['atlas_total'],
            $quality['rival_total'],
            $diff,
        );
        foreach ($quality['dimensions'] as $name => $d) {
            $dDiff = ($d['atlas'] ?? 0) - ($d['rival'] ?? 0);
            if (abs($dDiff) >= 5.0) {
                $bullets[] = sprintf('%s:%s_leads_by_%.1f', $name, $dDiff > 0 ? 'atlas' : 'rival', abs($dDiff));
            }
        }
        $bullets[] = $winner === self::WINNER_ATLAS
            ? 'atlas_wins_on_weighted_quality'
            : 'rival_wins_on_weighted_quality';

        return $bullets;
    }

    /**
     * @param  array<string,mixed>  $paths
     * @param  array<string,mixed>  $scorecard
     */
    private function persistScorecard(array $paths, array $scorecard): void
    {
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents(
            $paths['scorecard_json'],
            (string) json_encode($scorecard, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Fairness gates produced by the single-run adjudicator. Distinguishes
     * real performance signal from harness/setup artefacts so a Claude rival
     * scoring 0 because it was killed/timeouts/no-receipt is *invalid*, not a
     * legitimate Atlas win. The block is additive — every legacy v2-era
     * field stays in place.
     *
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @param  array<string,mixed>  $evidencePack
     * @param  list<string>  $hardFailureCodes
     * @return array{
     *     validity_class:string,
     *     confidence:array{level:string,reason:string},
     *     mode:string,
     *     fairness_notes:list<string>,
     *     claim_ready_recommended:bool,
     *     needs_triage:bool,
     *     extreme_score:bool
     * }
     */
    private function buildFairnessGates(
        array $manifest,
        array $atlasReceipt,
        array $rivalReceipt,
        array $evidencePack,
        bool $replayPasses,
        bool $hardGatesClean,
        array $hardFailureCodes,
        ?string $winner,
        ?float $atlasScore,
        ?float $rivalScore,
    ): array {
        $mode = strtolower((string) ($manifest['mode'] ?? 'unknown'));
        $notes = [];
        $validity = self::VALIDITY_VALID;

        // Fixture/manifest corruption is the cheapest gate to evaluate: if the
        // run never produced a comparable test bed there is no score to defend.
        $fixtureFailure = $this->detectFixtureCorruption($manifest, $atlasReceipt, $rivalReceipt);
        if ($fixtureFailure !== null) {
            $validity = self::VALIDITY_INVALID_FIXTURE;
            $notes[] = 'fixture_corruption:'.$fixtureFailure;
        }

        $atlasFailure = $this->detectProviderFailure($atlasReceipt);
        $rivalFailure = $this->detectProviderFailure($rivalReceipt);
        if ($atlasFailure !== null) {
            // Provider failure overrides anything but fixture corruption — a
            // provider run that never produced output cannot be scored low,
            // it must be marked invalid_provider_run so the operator triages
            // the driver instead of the model.
            if ($validity !== self::VALIDITY_INVALID_FIXTURE) {
                $validity = self::VALIDITY_INVALID_PROVIDER_RUN;
            }
            $notes[] = 'atlas_provider_failure:'.$atlasFailure;
        }
        if ($rivalFailure !== null) {
            if ($validity !== self::VALIDITY_INVALID_FIXTURE) {
                $validity = self::VALIDITY_INVALID_PROVIDER_RUN;
            }
            $notes[] = 'rival_provider_failure:'.$rivalFailure;
        }

        // Replay drift invalidates the quality signal, but it must not hide a
        // more specific root cause such as provider kill/timeout, fixture
        // corruption or test failure. Otherwise a broken harness can look like
        // a model score instead of an operator-triage event.
        if (! $replayPasses) {
            if ($validity === self::VALIDITY_VALID) {
                $validity = self::VALIDITY_INVALID_REPLAY_DRIFT;
            }
            $notes[] = 'replay_drift_invalidates_quality_signal';
        }

        // One-sided test failure tagged as test_failure rather than provider —
        // the rival ran cleanly, the *model* output failed tests.
        if ($validity === self::VALIDITY_VALID && in_array('tests_passed_rival', $hardFailureCodes, true)) {
            $validity = self::VALIDITY_INVALID_TEST_FAILURE;
            $notes[] = 'rival_test_failure_distinct_from_provider_failure';
        }
        if ($validity === self::VALIDITY_VALID && in_array('tests_passed_atlas', $hardFailureCodes, true)) {
            $validity = self::VALIDITY_INVALID_TEST_FAILURE;
            $notes[] = 'atlas_test_failure_distinct_from_provider_failure';
        }

        $missingEvidence = $this->stringList($evidencePack['missing_evidence'] ?? []);
        if ($validity === self::VALIDITY_VALID && $missingEvidence !== []) {
            $validity = self::VALIDITY_INVALID_MISSING_EVIDENCE;
            $notes[] = 'missing_evidence:'.implode(',', $missingEvidence);
        }

        // Harness artefacts (out-of-scope, bytecode, dirty) flag a contaminated
        // setup that should never produce a comparable claim.
        if ($validity === self::VALIDITY_VALID) {
            $harnessCodes = ['no_out_of_scope_files_atlas', 'no_out_of_scope_files_rival', 'no_bytecode_artifacts_atlas', 'no_bytecode_artifacts_rival', 'dirty_after_run_false'];
            $contaminated = array_intersect($harnessCodes, $hardFailureCodes);
            if ($contaminated !== []) {
                $validity = self::VALIDITY_INVALID_HARNESS;
                $notes[] = 'harness_contamination:'.implode(',', $contaminated);
            }
        }

        // local_fake mode never produces a real claim, even if every other
        // gate is green. The signal value is "the harness ran end-to-end",
        // not "Atlas is better than Claude".
        $localFake = $mode === 'local_fake';
        if ($localFake) {
            $notes[] = 'mode_local_fake_suppresses_real_claim';
            // Don't override harder validity classes (provider/test/etc.) —
            // keep the most specific reason. local_fake is its own class
            // only when nothing else flagged.
            if ($validity === self::VALIDITY_VALID) {
                $validity = self::VALIDITY_INVALID_LOCAL_FAKE;
            }
        }

        // Extreme score sanity: |diff| >= 50 demands intact evidence + fair/
        // full_power mode. Otherwise the result is triage-worthy regardless
        // of how the score landed.
        $extremeScore = false;
        $extremeScoreSupported = true;
        if ($atlasScore !== null && $rivalScore !== null) {
            $margin = abs($atlasScore - $rivalScore);
            if ($margin >= self::EXTREME_SCORE_MARGIN) {
                $extremeScore = true;
                $needsBetterEvidence = $missingEvidence !== []
                    || ! $replayPasses
                    || $localFake
                    || $mode === 'unknown';
                if ($needsBetterEvidence) {
                    $extremeScoreSupported = false;
                    $validity = self::VALIDITY_NEEDS_TRIAGE;
                    $notes[] = sprintf('extreme_score_margin_%.1f_requires_intact_evidence', $margin);
                }
            }
        }

        $sanityGates = $this->buildSanityGates(
            manifest: $manifest,
            atlasReceipt: $atlasReceipt,
            rivalReceipt: $rivalReceipt,
            evidencePack: $evidencePack,
            replayPasses: $replayPasses,
            hardFailureCodes: $hardFailureCodes,
            extremeScore: $extremeScore,
            extremeScoreSupported: $extremeScoreSupported,
            atlasProviderFailure: $atlasFailure,
            rivalProviderFailure: $rivalFailure,
            fixtureFailure: $fixtureFailure,
        );

        $confidence = $this->deriveSingleRunConfidence(
            mode: $mode,
            hardGatesClean: $hardGatesClean,
            replayPasses: $replayPasses,
            validity: $validity,
            winner: $winner,
            atlasScore: $atlasScore,
            rivalScore: $rivalScore,
        );
        $confidenceLevel = $this->deriveConfidenceLevelV1(
            validity: $validity,
            hardGatesClean: $hardGatesClean,
            replayPasses: $replayPasses,
            sanityGates: $sanityGates,
            mode: $mode,
            winner: $winner,
            atlasScore: $atlasScore,
            rivalScore: $rivalScore,
            extremeScore: $extremeScore,
        );

        $claimReadyRecommended = $validity === self::VALIDITY_VALID
            && $confidence['level'] === self::CONFIDENCE_HIGH
            && $confidenceLevel['level'] === self::CONFIDENCE_LEVEL_HIGH
            && in_array($winner, [self::WINNER_ATLAS, self::WINNER_RIVAL], true);

        return [
            'validity_class' => $validity,
            'confidence' => $confidence,
            'confidence_level' => $confidenceLevel['level'],
            'confidence_level_reason' => $confidenceLevel['reason'],
            'confidence_ladder' => self::CONFIDENCE_LEVELS,
            'sanity_gates' => $sanityGates,
            'mode' => $mode,
            'fairness_notes' => array_values(array_unique($notes)),
            'claim_ready_recommended' => $claimReadyRecommended,
            'needs_triage' => $validity === self::VALIDITY_NEEDS_TRIAGE,
            'extreme_score' => $extremeScore,
            'extreme_score_supported' => $extremeScoreSupported,
        ];
    }

    /**
     * Detect fixture/manifest corruption. Returns a short string code when
     * the run's setup is broken (e.g. mismatched receipts, manifest verdict
     * inconsistent with workspace state, fixture seed absent). Returns null
     * when the fixture looks intact. Conservative by design: only signals
     * corruption when the evidence is unambiguous so a real model failure is
     * never mislabelled.
     *
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     */
    private function detectFixtureCorruption(array $manifest, array $atlasReceipt, array $rivalReceipt): ?string
    {
        $verdict = strtolower(trim((string) ($manifest['verdict'] ?? '')));
        if ($verdict !== '' && (
            str_starts_with($verdict, 'invalid_fixture')
            || str_starts_with($verdict, 'invalid_workspace')
            || $verdict === 'fixture_error'
            || $verdict === 'corrupt_manifest'
        )) {
            return 'manifest_verdict:'.$verdict;
        }

        foreach ([['atlas', $atlasReceipt], ['rival', $rivalReceipt]] as [$arm, $receipt]) {
            if (! is_array($receipt) || $receipt === []) {
                continue;
            }
            if (($receipt['fixture_error'] ?? false) === true) {
                return $arm.'_receipt_fixture_error';
            }
            $fixtureBlockers = $this->stringList($receipt['fixture_blockers'] ?? []);
            if ($fixtureBlockers !== []) {
                return $arm.'_fixture_blockers:'.implode(',', $fixtureBlockers);
            }
        }

        $declaredCase = (string) ($manifest['case_id'] ?? '');
        $declaredCases = $this->stringList($manifest['case_ids'] ?? []);
        $atlasCase = (string) ($atlasReceipt['case_id'] ?? '');
        $rivalCase = (string) ($rivalReceipt['case_id'] ?? '');

        if ($declaredCase === 'multi_case_aggregate' || $declaredCases !== []) {
            $atlasCases = $this->receiptCaseIds($atlasReceipt);
            $rivalCases = $this->receiptCaseIds($rivalReceipt);
            if ($declaredCases !== [] && $atlasCases !== [] && $this->sortedUnique($declaredCases) !== $this->sortedUnique($atlasCases)) {
                return 'atlas_receipt_case_ids_mismatch';
            }
            if ($declaredCases !== [] && $rivalCases !== [] && $this->sortedUnique($declaredCases) !== $this->sortedUnique($rivalCases)) {
                return 'rival_receipt_case_ids_mismatch';
            }

            return null;
        }

        if ($declaredCase !== '' && $atlasCase !== '' && $declaredCase !== $atlasCase) {
            return 'atlas_receipt_case_id_mismatch';
        }
        if ($declaredCase !== '' && $rivalCase !== '' && $declaredCase !== $rivalCase) {
            return 'rival_receipt_case_id_mismatch';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return list<string>
     */
    private function receiptCaseIds(array $receipt): array
    {
        $caseIds = $this->stringList($receipt['case_ids'] ?? []);
        $caseId = trim((string) ($receipt['case_id'] ?? ''));
        if ($caseIds === [] && $caseId !== '' && $caseId !== 'multi_case_aggregate') {
            $caseIds[] = $caseId;
        }

        return $caseIds;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sortedUnique(array $values): array
    {
        $unique = array_values(array_unique(array_filter(array_map(
            static fn (string $v): string => trim($v),
            $values
        ), static fn (string $v): bool => $v !== '')));
        sort($unique, SORT_STRING);

        return $unique;
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function detectProviderFailure(array $receipt): ?string
    {
        if (($receipt['killed'] ?? false) === true) {
            return 'killed';
        }
        if (($receipt['timeout'] ?? false) === true) {
            return 'timeout';
        }
        $reason = (string) ($receipt['timeout_reason'] ?? '');
        if ($reason !== '' && in_array($reason, ['stalled_runner_no_heartbeat', 'process_timeout', 'driver_not_configured'], true)) {
            return $reason;
        }
        // Drivers that fail silently (no stdout, no patch, no test log)
        // produce zero-output runs that the adjudicator must NOT score as low
        // quality — otherwise a driver bug becomes a model win. We treat the
        // combination of no provider output + no patch + no test log as a
        // provider run failure, independent of exit code.
        $stdoutBytes = (int) ($receipt['stdout_bytes'] ?? 0);
        $stderrBytes = (int) ($receipt['stderr_bytes'] ?? 0);
        $patchBytes = (int) ($receipt['patch_diff_bytes'] ?? 0);
        $testLogPath = trim((string) ($receipt['test_log_path'] ?? ''));
        if ($stdoutBytes === 0 && $stderrBytes === 0 && $patchBytes === 0 && $testLogPath === '') {
            return 'output_empty_driver_error';
        }
        // exit_code !=0 alone is ambiguous (model could legitimately exit
        // non-zero on test failure). Only flag as provider failure when
        // combined with killed/timeout OR when stdout/stderr is empty.
        $exit = (int) ($receipt['exit_code'] ?? 0);
        if ($exit !== 0 && $stdoutBytes === 0) {
            return 'provider_returned_non_zero_with_empty_stdout';
        }

        return null;
    }

    /**
     * Sanity gates v1 — booleans the operator can read in one glance. Each
     * gate answers a yes/no question about whether the artefacts justify the
     * score. The 5-level `confidence_level` only climbs above `low` when every
     * relevant gate is green.
     *
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @param  array<string,mixed>  $evidencePack
     * @param  list<string>  $hardFailureCodes
     * @return array<string,bool>
     */
    private function buildSanityGates(
        array $manifest,
        array $atlasReceipt,
        array $rivalReceipt,
        array $evidencePack,
        bool $replayPasses,
        array $hardFailureCodes,
        bool $extremeScore,
        bool $extremeScoreSupported,
        ?string $atlasProviderFailure,
        ?string $rivalProviderFailure,
        ?string $fixtureFailure,
    ): array {
        $missingEvidence = $this->stringList($evidencePack['missing_evidence'] ?? []);

        $bothSidesProduced = (int) ($atlasReceipt['patch_diff_bytes'] ?? 0) > 0
            && (int) ($rivalReceipt['patch_diff_bytes'] ?? 0) > 0
            && trim((string) ($atlasReceipt['test_log_path'] ?? '')) !== ''
            && trim((string) ($rivalReceipt['test_log_path'] ?? '')) !== '';

        // Human intervention indevida: workspace dirty before the run, dirty
        // after the run, or an explicit operator override flag. Any of those
        // means we are no longer comparing pure model output.
        $dirtyBefore = (bool) ($manifest['workspace_dirty_before'] ?? false);
        $dirtyAfter = (bool) ($manifest['dirty_after_run'] ?? false);
        $humanAssisted = (bool) ($manifest['human_assisted'] ?? false);
        $humanInterventionClean = ! $dirtyBefore && ! $dirtyAfter && ! $humanAssisted;

        return [
            'provider_run_clean' => $atlasProviderFailure === null && $rivalProviderFailure === null,
            'fixture_clean' => $fixtureFailure === null,
            'human_intervention_clean' => $humanInterventionClean,
            'replay_verified' => $replayPasses,
            'evidence_complete' => $missingEvidence === [] && ! in_array('evidence_complete', $hardFailureCodes, true),
            'both_sides_produced_artifacts' => $bothSidesProduced,
            'extreme_score_supported' => $extremeScore ? $extremeScoreSupported : true,
        ];
    }

    /**
     * Derive the canonical 5-level `confidence_level` for a single-run
     * scorecard. `release_trusted` is intentionally unreachable here — that
     * level is reserved for the multi-case battery confidence ladder.
     *
     * @param  array<string,bool>  $sanityGates
     * @return array{level:string,reason:string}
     */
    private function deriveConfidenceLevelV1(
        string $validity,
        bool $hardGatesClean,
        bool $replayPasses,
        array $sanityGates,
        string $mode,
        ?string $winner,
        ?float $atlasScore,
        ?float $rivalScore,
        bool $extremeScore,
    ): array {
        if ($validity !== self::VALIDITY_VALID) {
            return ['level' => self::CONFIDENCE_LEVEL_INVALID, 'reason' => 'validity:'.$validity];
        }
        if (! $replayPasses) {
            return ['level' => self::CONFIDENCE_LEVEL_INVALID, 'reason' => 'replay_drift'];
        }
        if (! $sanityGates['provider_run_clean']) {
            return ['level' => self::CONFIDENCE_LEVEL_INVALID, 'reason' => 'provider_run_failure'];
        }
        if (! $sanityGates['fixture_clean']) {
            return ['level' => self::CONFIDENCE_LEVEL_INVALID, 'reason' => 'fixture_corruption'];
        }
        if (! $sanityGates['evidence_complete']) {
            return ['level' => self::CONFIDENCE_LEVEL_INVALID, 'reason' => 'evidence_incomplete'];
        }
        if (! $sanityGates['both_sides_produced_artifacts']) {
            return ['level' => self::CONFIDENCE_LEVEL_INVALID, 'reason' => 'one_side_missing_artifacts'];
        }
        if ($mode === 'local_fake') {
            return ['level' => self::CONFIDENCE_LEVEL_INVALID, 'reason' => 'mode_local_fake'];
        }
        if (! $hardGatesClean) {
            return ['level' => self::CONFIDENCE_LEVEL_LOW, 'reason' => 'hard_gate_unclean'];
        }
        if (! $sanityGates['human_intervention_clean']) {
            return ['level' => self::CONFIDENCE_LEVEL_LOW, 'reason' => 'human_intervention_detected'];
        }
        if ($winner === self::WINNER_TIE || $winner === self::WINNER_NONE) {
            return ['level' => self::CONFIDENCE_LEVEL_LOW, 'reason' => 'tie_or_no_winner_single_case'];
        }
        if ($atlasScore === null || $rivalScore === null) {
            return ['level' => self::CONFIDENCE_LEVEL_LOW, 'reason' => 'score_null'];
        }
        if ($extremeScore && ! $sanityGates['extreme_score_supported']) {
            return ['level' => self::CONFIDENCE_LEVEL_LOW, 'reason' => 'extreme_score_without_supporting_evidence'];
        }
        $margin = abs($atlasScore - $rivalScore);
        if ($margin < self::DEFAULT_TIE_THRESHOLD * 2) {
            return ['level' => self::CONFIDENCE_LEVEL_MEDIUM, 'reason' => 'narrow_margin_single_case'];
        }
        if ($mode !== 'fair' && $mode !== 'full_power') {
            return ['level' => self::CONFIDENCE_LEVEL_MEDIUM, 'reason' => 'mode_'.$mode.'_single_case'];
        }

        return ['level' => self::CONFIDENCE_LEVEL_HIGH, 'reason' => 'replay_ok_evidence_complete_real_provider_clear_margin'];
    }

    /**
     * @return array{level:string,reason:string}
     */
    private function deriveSingleRunConfidence(
        string $mode,
        bool $hardGatesClean,
        bool $replayPasses,
        string $validity,
        ?string $winner,
        ?float $atlasScore,
        ?float $rivalScore,
    ): array {
        if (! in_array($validity, [self::VALIDITY_VALID], true)) {
            // Invalid classes still emit confidence=invalid so the operator
            // sees the fairness verdict at a glance.
            return ['level' => self::CONFIDENCE_INVALID, 'reason' => $validity];
        }
        if (! $replayPasses) {
            return ['level' => self::CONFIDENCE_INVALID, 'reason' => 'replay_drift'];
        }
        if (! $hardGatesClean) {
            return ['level' => self::CONFIDENCE_LOW, 'reason' => 'hard_gate_unclean'];
        }
        if ($mode === 'local_fake') {
            return ['level' => self::CONFIDENCE_INVALID, 'reason' => 'mode_local_fake'];
        }
        if ($winner === self::WINNER_TIE) {
            return ['level' => self::CONFIDENCE_MEDIUM, 'reason' => 'statistical_tie_requires_more_cases'];
        }
        if ($atlasScore === null || $rivalScore === null) {
            return ['level' => self::CONFIDENCE_LOW, 'reason' => 'score_null'];
        }
        $margin = abs($atlasScore - $rivalScore);
        if ($margin < self::DEFAULT_TIE_THRESHOLD * 2) {
            return ['level' => self::CONFIDENCE_MEDIUM, 'reason' => 'narrow_margin_single_case'];
        }
        if ($mode === 'fair' || $mode === 'full_power') {
            return ['level' => self::CONFIDENCE_HIGH, 'reason' => 'replay_ok_evidence_complete_real_provider'];
        }

        return ['level' => self::CONFIDENCE_MEDIUM, 'reason' => 'mode_'.$mode.'_single_case'];
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $blob = (string) @file_get_contents($path);
        $row = json_decode($blob, true);

        return is_array($row) ? $row : [];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => (string) $v, $value));
    }

    private function extractAssertionCount(string $tail): int
    {
        // PHPUnit style: "(123 tests, 456 assertions)" or "Assertions: 456"
        if (preg_match('/(\d+)\s+assertions?\b/i', $tail, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/Assertions:\s*(\d+)/i', $tail, $m)) {
            return (int) $m[1];
        }
        // PHPUnit: "Tests: 123" as a fallback proxy
        if (preg_match('/Tests:\s*(\d+)/i', $tail, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    private function elapsedSeconds(string $started, string $finished): int
    {
        if ($started === '' || $finished === '') {
            return 0;
        }
        try {
            $a = new \DateTimeImmutable($started);
            $b = new \DateTimeImmutable($finished);

            return max(0, $b->getTimestamp() - $a->getTimestamp());
        } catch (\Throwable) {
            return 0;
        }
    }
}
