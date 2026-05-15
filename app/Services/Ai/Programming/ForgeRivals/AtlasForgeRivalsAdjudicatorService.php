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

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsReplayService $replay,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function adjudicate(array $input): array
    {
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
            $gateOutcome = $this->oneSidedTestFailureOutcome($hardFailureCodes, $hardGates);
            if ($gateOutcome !== null) {
                $scorecard = [
                    'schema_version' => self::SCHEMA_VERSION,
                    'run_id' => $paths['run_id'],
                    'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
                    'winner' => self::WINNER_NONE,
                    'gate_winner' => $gateOutcome['winner'],
                    'gate_loser' => $gateOutcome['loser'],
                    'gate_result' => [
                        'kind' => 'one_sided_test_failure',
                        'winner' => $gateOutcome['winner'],
                        'loser' => $gateOutcome['loser'],
                        'quality_score_available' => false,
                        'quality_score_reason' => 'one_side_failed_tests_before_comparable_quality_scoring',
                    ],
                    'winner_reason' => [
                        'verdict:'.($manifest['verdict'] ?? 'unknown'),
                        'hard_failures:'.implode(',', $hardFailureCodes),
                        $gateOutcome['loser'].'_failed_tests',
                        $gateOutcome['winner'].'_passed_tests',
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
                    'quality_score_reason' => 'one_side_failed_tests_before_comparable_quality_scoring',
                    'score_explanation' => 'One arm failed deterministic test gates while replay, evidence, scope, bytecode, provider exit, dirty-after-run and patch-presence gates remained intact. This is a gate outcome only: no comparable quality score is emitted.',
                    'tie_threshold' => self::DEFAULT_TIE_THRESHOLD,
                    'hard_gates' => $hardGates,
                    'hard_failures' => $hardFailureCodes,
                    'quality_dimensions' => null,
                    'replay_passes' => $replayPasses,
                    'claim_ready' => false,
                    'human_review_required' => false,
                    'separated_from_external_rivals_certification' => true,
                    'note' => 'Gate winner by deterministic test outcome. Quality score is null because both arms did not pass gates. External rivals certification remains blocked; claim_ready=false.',
                ];
                $this->persistScorecard($paths, $scorecard);

                return [
                    'status' => 'ok',
                    'schema_version' => self::SCHEMA_VERSION,
                    'run_id' => $paths['run_id'],
                    'scorecard' => $scorecard,
                    'scorecard_path' => $paths['scorecard_json'],
                    'evidence_paths' => [$paths['scorecard_json']],
                    'next_command' => 'php artisan atlas:forge:rivals report --run-id='.$paths['run_id'].' --json',
                    'external_provider_call' => false,
                    'provider_tokens_spent' => false,
                ];
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
            $this->persistScorecard($paths, $scorecard);

            return [
                'status' => 'ok',
                'schema_version' => self::SCHEMA_VERSION,
                'run_id' => $paths['run_id'],
                'scorecard' => $scorecard,
                'scorecard_path' => $paths['scorecard_json'],
                'evidence_paths' => [$paths['scorecard_json']],
                'next_command' => 'php artisan atlas:forge:rivals report --run-id='.$paths['run_id'].' --json',
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
            ];
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
        $this->persistScorecard($paths, $scorecard);

        return [
            'status' => 'ok',
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $paths['run_id'],
            'scorecard' => $scorecard,
            'scorecard_path' => $paths['scorecard_json'],
            'evidence_paths' => [$paths['scorecard_json']],
            'next_command' => 'php artisan atlas:forge:rivals report --run-id='.$paths['run_id'].' --json',
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];
    }

    /**
     * A real battery is still useful when one side fails deterministic tests
     * and the other side passes. That is not a "quality dimensions" score and
     * it never becomes an external claim, but it is a valid provider arena
     * outcome. Keep every other hard failure fail-closed.
     *
     * @param  list<string>  $hardFailureCodes
     * @param  list<array{code:string,ok:bool,detail:string}>  $hardGates
     * @return array{winner:string,loser:string}|null
     */
    private function oneSidedTestFailureOutcome(array $hardFailureCodes, array $hardGates): ?array
    {
        $failures = array_values(array_unique($hardFailureCodes));
        $allowed = ['verdict_comparable', 'tests_passed_atlas', 'tests_passed_rival'];
        foreach ($failures as $failure) {
            if (! in_array($failure, $allowed, true)) {
                return null;
            }
        }

        $atlasFailedTests = in_array('tests_passed_atlas', $failures, true);
        $rivalFailedTests = in_array('tests_passed_rival', $failures, true);
        if ($atlasFailedTests === $rivalFailedTests) {
            return null;
        }

        $gateOk = [];
        foreach ($hardGates as $gate) {
            $gateOk[(string) $gate['code']] = (bool) $gate['ok'];
        }

        if ($atlasFailedTests && ($gateOk['tests_passed_rival'] ?? false)) {
            return [
                'winner' => self::WINNER_RIVAL,
                'loser' => self::WINNER_ATLAS,
            ];
        }

        if ($rivalFailedTests && ($gateOk['tests_passed_atlas'] ?? false)) {
            return [
                'winner' => self::WINNER_ATLAS,
                'loser' => self::WINNER_RIVAL,
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
