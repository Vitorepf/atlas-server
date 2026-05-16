<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use App\Services\Ai\Programming\ForgeRivals\Schema\AtlasForgeRivalsSchemaContractService;
use App\Services\Ai\Programming\WorkspaceHygieneService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Atlas Forge Rivals · Run Real (orchestrator).
 *
 * Owns the only path through which a real provider can be invoked. Every
 * gate is non-negotiable:
 *
 *   1. Mode must be one of {fair, full_power, local_fake}. Diagnostic /
 *      replay_only are rejected — those don't ever run providers.
 *   2. Workspace must be a worktree under runs/<run_id>/{atlas,rival},
 *      provisioned via Setup. If absent, the run is blocked with
 *      `worktrees_missing` and a copy-safe setup hint.
 *   3. Real-provider modes (fair, full_power) REQUIRE all three
 *      `--confirm-*` flags simultaneously. Without them the run is
 *      blocked with `missing_confirmations` and NO provider is invoked.
 *   4. `local_fake` mode is in-process: it emits the canonical events
 *      and writes a deterministic fake provider receipt, but never
 *      executes a subprocess. The full chain stays exercisable in CI.
 *   5. Every subprocess inherits PYTHONDONTWRITEBYTECODE=1.
 *   6. Workspace hash/diff/test logs are captured before/after. Expected
 *      scoped source changes are evidence, not failure; untracked bytecode or
 *      out-of-scope changes stay terminal blockers.
 *   7. Output is streamed to events.jsonl with heartbeat-eligible
 *      stream_select(); on timeout we kill the subprocess and close
 *      evidence as `invalid_provider_timeout`.
 *
 * Schema: atlas.forge.rivals.run_real.v1
 */
final class AtlasForgeRivalsRunRealService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.run_real.v1';

    public const HEARTBEAT_INTERVAL_SECONDS = 5;

    public const DEFAULT_PROVIDER_TIMEOUT_SECONDS = 900;

    public const DEFAULT_HARD_KILL_SECONDS = 1200;

    /**
     * Dependency/build/cache artifacts created by language toolchains during
     * validation are harness noise unless the case explicitly expects them.
     *
     * @var list<string>
     */
    private const GENERATED_ARTIFACT_PATH_SEGMENTS = [
        'node_modules',
        '.vite',
        '.turbo',
        '.next',
        '.cache',
        'coverage',
        'dist',
        'build',
        '.pytest_cache',
        '__pycache__',
    ];

    /**
     * @var list<string>
     */
    private const GENERATED_ARTIFACT_PATH_SUFFIXES = [
        '.pyc',
        '.pyo',
        'package-lock.json',
        'npm-shrinkwrap.json',
        'pnpm-lock.yaml',
        'yarn.lock',
    ];

    /** @var list<string> Modes admissible by run-real. */
    public const ALLOWED_MODES = [
        AtlasForgeRivalsModeRegistry::MODE_FAIR,
        AtlasForgeRivalsModeRegistry::MODE_FULL_POWER,
        AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
    ];

    /** @var list<string> Prompt styles admissible by real batteries. */
    public const PROMPT_MODES = [
        'spec-perfect',
        'human-normal',
        'messy-real',
        'enterprise-change',
    ];

    /**
     * @var array<string,true>
     */
    private array $runtimeIsolationPrepared = [];

    public function __construct(
        private readonly AtlasForgeRivalsModeRegistry $modes,
        private readonly AtlasForgeRivalsModelMatrix $matrix,
        private readonly AtlasForgeRivalsCasesRegistry $cases,
        private readonly AtlasForgeRivalsProviderArenaCorpusService $corpus,
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsEventStream $events,
        private readonly WorkspaceHygieneService $hygiene,
        private readonly AtlasForgeRivalsBatteryStateService $battery,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $mode = trim((string) ($input['mode'] ?? ''));
        $atlasModel = trim((string) ($input['atlas_model'] ?? 'claude_sonnet'));
        $rivalModel = trim((string) ($input['rival'] ?? $atlasModel));
        $preset = trim((string) ($input['preset'] ?? 'smoke'));
        $promptMode = $this->normalizePromptMode((string) ($input['prompt_mode'] ?? ''));
        $confirms = (array) ($input['confirmations'] ?? []);

        $blockers = [];

        if (! in_array($mode, self::ALLOWED_MODES, true)) {
            $blockers[] = 'mode_not_admissible_for_run_real:'.$mode;

            return $this->blocked($blockers, 'pick --mode=fair|full_power|local_fake');
        }
        if (! in_array($promptMode, self::PROMPT_MODES, true)) {
            $blockers[] = 'prompt_mode_not_admissible_for_run_real:'.$promptMode;

            return $this->blocked($blockers, 'pick --prompt-mode=spec-perfect|human-normal|messy-real|enterprise-change');
        }
        $modeDef = $this->modes->mode($mode);
        $matrixResult = $this->matrix->validate($mode, $atlasModel, $rivalModel);
        foreach ($matrixResult['blockers'] as $b) {
            $blockers[] = $b;
        }

        $caseContext = [];
        try {
            $caseContext = $this->resolveCaseContext($input, $preset);
        } catch (EmptyPresetIsFatalHarnessBug $e) {
            $blockers[] = 'zero_case_preset_fatal_harness_bug:'.$preset;
        } catch (\Throwable $e) {
            $blockers[] = $e->getMessage() !== '' ? $e->getMessage() : 'case_resolution_failed';
        }
        $cases = $caseContext['cases'] ?? [];
        if ($cases === []) {
            return $this->blocked($blockers, 'fix preset and re-run');
        }
        $cases = array_values(array_map(
            static fn (array $case): array => array_replace($case, ['prompt_mode' => $promptMode]),
            $cases,
        ));

        $fixtureBlockers = $this->fixtureReadinessBlockers($cases);
        foreach ($fixtureBlockers as $b) {
            $blockers[] = $b;
        }

        $requiresProvider = $modeDef['requires_provider'];
        $confirmsPresent = [
            'runbook_reviewed' => (bool) ($confirms['runbook_reviewed'] ?? false),
            'provider_cost' => (bool) ($confirms['provider_cost'] ?? false),
            'real_provider_call' => (bool) ($confirms['real_provider_call'] ?? false),
        ];
        if ($requiresProvider) {
            foreach ($confirmsPresent as $k => $v) {
                if (! $v) {
                    $blockers[] = 'missing_confirmation:'.$k;
                }
            }
        }
        if ($blockers !== []) {
            $hint = $fixtureBlockers !== []
                ? 'fix Provider Arena fixture corpus before running any provider battery'
                : 'pass all three --confirm-* flags';

            return $this->blocked(array_values(array_unique($blockers)), $hint);
        }

        if ($requiresProvider) {
            $driverBlockers = $this->driverAvailabilityBlockers($atlasModel, $rivalModel);
            if ($driverBlockers !== []) {
                return $this->blocked(
                    $driverBlockers,
                    'install missing provider CLI (claude/codex) before running real-provider battery',
                );
            }
        }

        // Resolve run_id (existing setup or fresh)
        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            $runId = 'fr2-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        }
        $paths = $this->paths->paths($runId);

        // Worktrees must exist
        $worktreesPresent = (is_dir($paths['atlas'].'/.git') || is_file($paths['atlas'].'/.git'))
            && (is_dir($paths['rival'].'/.git') || is_file($paths['rival'].'/.git'));
        if (! $worktreesPresent) {
            return $this->blocked(
                ['worktrees_missing'],
                "php artisan atlas:forge:rivals setup --source-ref=HEAD --run-id={$paths['run_id']} --json"
            );
        }

        if ($requiresProvider && $mode !== AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE) {
            $runtimeIsolation = $this->prepareProviderRuntimeIsolation($runId, $paths);
            if (($runtimeIsolation['blockers'] ?? []) !== []) {
                return $this->blocked(
                    $this->stringList($runtimeIsolation['blockers']),
                    'fix runtime isolation before spending provider tokens',
                );
            }
        }

        $allCases = $cases;
        $originalCaseCount = count($allCases);
        $isMultiCase = $originalCaseCount > 1;
        $firstCase = $allCases[0];

        // Initialise the multi-case battery catalogue. On a fresh run this
        // creates runs/<run_id>/battery.json with every case marked pending.
        // On a resume (battery.json already on disk) this bumps resume_count
        // and emits a battery_resumed event without overwriting any case
        // that already reached a terminal state.
        $batteryContext = [
            'preset' => $preset,
            'case_set' => trim((string) ($input['case_set'] ?? '')),
            'prompt_mode' => $promptMode,
            'mode' => $mode,
            'atlas_model' => $atlasModel,
            'rival_model' => $rivalModel,
            'external_provider_call' => $requiresProvider && $mode !== AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
            'provider_tokens_spent' => $requiresProvider && $mode !== AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
        ];
        $batteryPayload = $this->battery->initialize($runId, $batteryContext, $allCases);
        $isResume = ((int) ($batteryPayload['resume_count'] ?? 0)) > 0;
        if ($isResume) {
            // Resume path: iterate only the cases still pending (or stuck
            // in `running` — those become attempt 2 with a fresh hash).
            $pendingIds = $this->battery->pendingCases($batteryPayload);
            $cases = array_values(array_filter(
                $allCases,
                static fn (array $c): bool => in_array((string) ($c['id'] ?? ''), $pendingIds, true),
            ));
            if ($cases === []) {
                // Every case already reached a terminal state. Don't re-run
                // anything; report the battery as already settled and let
                // the operator decide whether to reset and start over.
                $finalSnapshot = $this->battery->snapshot($runId);

                return [
                    'status' => 'ok',
                    'run_id' => $runId,
                    'mode' => $mode,
                    'verdict' => $this->batteryAggregateVerdict($finalSnapshot),
                    'score' => null,
                    'claim_ready' => false,
                    'paths' => $paths,
                    'manifest' => null,
                    'atlas_receipt' => null,
                    'rival_receipt' => null,
                    'cases' => $finalSnapshot['cases'] ?? [],
                    'case_count' => $originalCaseCount,
                    'is_multi_case' => $isMultiCase,
                    'battery_snapshot' => $finalSnapshot,
                    'evidence_paths' => [
                        $this->battery->batteryJsonPath($runId),
                        $this->battery->batteryJsonlPath($runId),
                    ],
                    'external_provider_call' => false,
                    'provider_tokens_spent' => false,
                    'note' => 'all_cases_already_terminal_in_battery_resume',
                    'next_command' => 'php artisan atlas:forge:rivals status --run-id='.$runId.' --json',
                ];
            }
        }

        $this->events->start($runId, [
            'mode' => $mode,
            'atlas_model' => $atlasModel,
            'rival_model' => $rivalModel,
            'preset' => $preset,
            'prompt_mode' => $promptMode,
            'case_id' => $firstCase['id'],
            'case_source' => $firstCase['case_source'] ?? 'legacy',
            'case_count' => $originalCaseCount,
            'case_ids' => array_values(array_map(static fn (array $c): string => (string) $c['id'], $allCases)),
            'cases_remaining_this_session' => count($cases),
            'is_resume' => $isResume,
            'resume_count' => (int) ($batteryPayload['resume_count'] ?? 0),
            'requires_provider' => $requiresProvider,
            'started_at' => $this->nowIso(),
        ]);

        $perCase = [];
        $aggregateChangedAtlas = [];
        $aggregateChangedRival = [];
        $aggregateOosAtlas = [];
        $aggregateOosRival = [];
        $aggregateBytecodeAtlas = [];
        $aggregateBytecodeRival = [];
        $aggregateWorkspaceBlockers = [];
        $aggregateWorkspaceBlockersByCase = [];
        $aggregateArmContractBlockers = [];
        $aggregateArmContractBlockersByCase = [];
        $aggregateFixtureBlockers = [];
        $aggregateFixtureBlockersByCase = [];
        $aggregateVerdict = 'comparable';
        $aggregateClaimReady = $mode !== AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE;
        $earliestStartedAt = null;
        $latestFinishedAt = null;
        $fixtureBlockedFatally = false;
        $representativeAtlasReceipt = null;
        $representativeRivalReceipt = null;
        $representativeBeforeAtlas = null;
        $representativeBeforeRival = null;
        $representativeAfterAtlas = null;
        $representativeAfterRival = null;
        $representativeFixtureStageAtlas = null;
        $representativeFixtureStageRival = null;

        foreach ($cases as $caseIndex => $case) {
            $caseSubdir = $isMultiCase ? 'cases/'.$this->safeCaseDir((string) $case['id']) : '';

            $this->battery->markCaseRunning($runId, (string) $case['id']);

            $this->events->event($runId, 'case_started', [
                'case_id' => $case['id'],
                'case_index' => $caseIndex,
                'case_count' => count($cases),
                'case_source' => $case['case_source'] ?? 'legacy',
                'difficulty_level' => $case['difficulty_level'] ?? null,
                'task_category' => $case['task_category'] ?? null,
                'difficulty_weight' => $case['difficulty_weight'] ?? null,
            ]);

            // Between cases we must restore each worktree to HEAD so the
            // next case starts from a clean, deterministic baseline. This
            // guarantees that workspace_hash_before captures only the
            // current case's fixture, not the previous case's diff.
            if ($caseIndex > 0) {
                $this->resetWorktree($paths['atlas']);
                $this->resetWorktree($paths['rival']);
            }

            $seedAtlas = $this->stageCaseFixture($runId, 'atlas', $paths['atlas'], $case);
            $seedRival = $this->stageCaseFixture($runId, 'rival', $paths['rival'], $case);
            if (($seedAtlas['blockers'] ?? []) !== [] || ($seedRival['blockers'] ?? []) !== []) {
                $seedBlockers = array_values(array_merge(
                    $this->stringList($seedAtlas['blockers'] ?? []),
                    $this->stringList($seedRival['blockers'] ?? []),
                ));

                if ($isMultiCase) {
                    $perCase[] = [
                        'case_id' => (string) $case['id'],
                        'case_index' => $caseIndex,
                        'case_source' => $case['case_source'] ?? 'legacy',
                        'verdict' => 'invalid_fixture_blocked',
                        'fixture_stage' => ['atlas' => $seedAtlas, 'rival' => $seedRival],
                        // Fixture blockers stay separate from workspace_blockers:
                        // they describe a pre-run setup problem, not arm-introduced
                        // contamination, and therefore must not pollute the
                        // top-level `dirty_after_run` signal.
                        'fixture_blockers' => $seedBlockers,
                        'workspace_blockers' => [],
                        'atlas_receipt' => null,
                        'rival_receipt' => null,
                        'workspace_hash_before' => null,
                        'workspace_hash_after' => null,
                        'evidence_subdir' => $caseSubdir,
                    ];
                    $aggregateFixtureBlockers = array_values(array_unique(array_merge(
                        $aggregateFixtureBlockers,
                        $seedBlockers,
                    )));
                    foreach ($seedBlockers as $blocker) {
                        $aggregateFixtureBlockersByCase[] = [
                            'case_id' => (string) $case['id'],
                            'blocker' => (string) $blocker,
                        ];
                    }
                    if ($aggregateVerdict === 'comparable') {
                        $aggregateVerdict = 'invalid_fixture_blocked';
                    }
                    $aggregateClaimReady = false;
                    $this->events->event($runId, 'case_finished', [
                        'case_id' => (string) $case['id'],
                        'verdict' => 'invalid_fixture_blocked',
                        'fixture_blockers' => $seedBlockers,
                        'workspace_blockers' => [],
                    ]);
                    $this->battery->markCaseFinished($runId, (string) $case['id'], 'invalid_fixture_blocked', [
                        'fixture_blockers' => $seedBlockers,
                        'workspace_blockers' => [],
                        'fixture_stage' => ['atlas' => $seedAtlas, 'rival' => $seedRival],
                    ]);

                    continue;
                }

                $this->battery->markCaseFinished($runId, (string) $case['id'], 'invalid_fixture_blocked', [
                    'blockers' => $seedBlockers,
                ]);

                return $this->blocked($seedBlockers, 'fix Provider Arena fixture before running real battery');
            }

            $atlasCase = $case;
            $rivalCase = $case;
            $atlasCase['_fixture_baseline_hashes'] = is_array($seedAtlas['file_hashes'] ?? null) ? $seedAtlas['file_hashes'] : [];
            $rivalCase['_fixture_baseline_hashes'] = is_array($seedRival['file_hashes'] ?? null) ? $seedRival['file_hashes'] : [];

            $beforeAtlas = $this->workspaceHash($paths['atlas']);
            $beforeRival = $this->workspaceHash($paths['rival']);
            $this->events->event($runId, 'step_started', [
                'step' => 'before_workspace_hash',
                'case_id' => (string) $case['id'],
            ]);

            $atlasReceipt = $this->runArm($runId, 'atlas', $paths['atlas'], $mode, $atlasModel, $atlasCase, $caseSubdir);
            $rivalReceipt = $this->runArm($runId, 'rival', $paths['rival'], $mode, $rivalModel, $rivalCase, $caseSubdir);

            $afterAtlas = $this->workspaceHash($paths['atlas']);
            $afterRival = $this->workspaceHash($paths['rival']);

            $dirtyAtlas = $this->workspaceDirty($paths['atlas']);
            $dirtyRival = $this->workspaceDirty($paths['rival']);
            $atlasWorkspaceBlockers = $this->stringList($atlasReceipt['workspace_blockers'] ?? []);
            $rivalWorkspaceBlockers = $this->stringList($rivalReceipt['workspace_blockers'] ?? []);
            $caseArmContractBlockers = array_values(array_unique(array_merge(
                $this->armContractBlockers($atlasWorkspaceBlockers),
                $this->armContractBlockers($rivalWorkspaceBlockers),
            )));
            $caseWorkspaceBlockers = array_values(array_unique(array_merge(
                $this->harnessWorkspaceBlockers($atlasWorkspaceBlockers),
                $this->harnessWorkspaceBlockers($rivalWorkspaceBlockers),
            )));
            $caseDirty = $caseWorkspaceBlockers !== [];

            $this->events->event($runId, 'after_clean_check', [
                'case_id' => (string) $case['id'],
                'atlas_clean' => ! (bool) ($atlasReceipt['workspace_has_blocking_changes'] ?? false),
                'rival_clean' => ! (bool) ($rivalReceipt['workspace_has_blocking_changes'] ?? false),
                'atlas_dirty_count' => $dirtyAtlas['count'],
                'rival_dirty_count' => $dirtyRival['count'],
                'atlas_changed_files' => $atlasReceipt['changed_files'] ?? [],
                'rival_changed_files' => $rivalReceipt['changed_files'] ?? [],
                'workspace_blockers' => $caseWorkspaceBlockers,
                'arm_contract_blockers' => $caseArmContractBlockers,
            ]);

            $caseVerdict = 'comparable';
            if ($caseDirty) {
                $caseVerdict = 'invalid_workspace_after_run';
            } elseif ($caseArmContractBlockers !== []) {
                $caseVerdict = 'invalid_scope_violation';
            } elseif (($atlasReceipt['exit_code'] ?? -1) !== 0 || ($rivalReceipt['exit_code'] ?? -1) !== 0) {
                $caseVerdict = (! empty($atlasReceipt['killed']) || ! empty($rivalReceipt['killed']))
                    ? 'invalid_provider_timeout'
                    : 'inconclusive';
            } elseif (($atlasReceipt['test_exit_code'] ?? -1) !== 0 || ($rivalReceipt['test_exit_code'] ?? -1) !== 0) {
                $caseVerdict = 'invalid_tests_failed';
            } elseif ((int) ($atlasReceipt['patch_diff_bytes'] ?? 0) <= 0 || (int) ($rivalReceipt['patch_diff_bytes'] ?? 0) <= 0) {
                $caseVerdict = 'invalid_no_patch_diff';
            }

            // Persist per-case receipts in a stable subfolder so each case
            // keeps its own atlas_receipt.json / rival_receipt.json /
            // workspace_hashes.json — the matter-prima Claude C/D/E consume.
            if ($isMultiCase) {
                $caseDir = $paths['evidence'].'/'.$caseSubdir;
                @mkdir($caseDir, 0o755, true);
                file_put_contents($caseDir.'/atlas_receipt.json', $this->jsonEncode($atlasReceipt));
                file_put_contents($caseDir.'/rival_receipt.json', $this->jsonEncode($rivalReceipt));
                file_put_contents($caseDir.'/workspace_hashes.json', $this->jsonEncode([
                    'before' => ['atlas' => $beforeAtlas, 'rival' => $beforeRival],
                    'after' => ['atlas' => $afterAtlas, 'rival' => $afterRival],
                    'dirty_after_run' => $caseDirty,
                    'workspace_blockers' => $caseWorkspaceBlockers,
                    'arm_contract_violation' => $caseArmContractBlockers !== [],
                    'arm_contract_blockers' => $caseArmContractBlockers,
                    'workspace_changes_after_run' => [
                        'atlas' => $atlasReceipt['changed_files'] ?? [],
                        'rival' => $rivalReceipt['changed_files'] ?? [],
                    ],
                ]));
            }

            $perCaseEntry = [
                'case_id' => (string) $case['id'],
                'case_index' => $caseIndex,
                'case_source' => $case['case_source'] ?? 'legacy',
                'task_category' => $case['task_category'] ?? null,
                'category' => $case['category'] ?? ($case['task_category'] ?? null),
                'case_set' => $case['case_set'] ?? null,
                'prompt_mode' => $case['prompt_mode'] ?? $promptMode,
                'difficulty' => $case['difficulty'] ?? null,
                'difficulty_level' => $case['difficulty_level'] ?? null,
                'difficulty_weight' => $case['difficulty_weight'] ?? null,
                'verdict' => $caseVerdict,
                'workspace_blockers' => $caseWorkspaceBlockers,
                'arm_contract_blockers' => $caseArmContractBlockers,
                'fixture_blockers' => [],
                'workspace_hash_before' => ['atlas' => $beforeAtlas, 'rival' => $beforeRival],
                'workspace_hash_after' => ['atlas' => $afterAtlas, 'rival' => $afterRival],
                'fixture_stage' => ['atlas' => $seedAtlas, 'rival' => $seedRival],
                'atlas_receipt' => $atlasReceipt,
                'rival_receipt' => $rivalReceipt,
                'evidence_subdir' => $isMultiCase ? $caseSubdir : null,
                'canonical_evidence_dir' => $isMultiCase
                    ? 'cases/'.$this->safeCaseDir((string) $case['id']).'/evidence'
                    : null,
            ];
            $perCase[] = $perCaseEntry;

            // Canonical per-case evidence: manifest.json + scorecard.json +
            // report.md under runs/<run_id>/cases/<safe>/evidence/. Legacy
            // receipts under runs/<run_id>/evidence/cases/<safe>/ kept
            // untouched for back-compat with collect-evidence/replay.
            if ($isMultiCase) {
                $this->writeCanonicalCaseEvidence(
                    paths: $paths,
                    caseEntry: $perCaseEntry,
                    context: [
                        'run_id' => $runId,
                        'mode' => $mode,
                        'atlas_model' => $atlasModel,
                        'rival_model' => $rivalModel,
                        'preset' => $preset,
                        'prompt_mode' => $promptMode,
                    ],
                );
            }

            $aggregateChangedAtlas = array_values(array_unique(array_merge(
                $aggregateChangedAtlas,
                $this->stringList($atlasReceipt['changed_files'] ?? []),
            )));
            $aggregateChangedRival = array_values(array_unique(array_merge(
                $aggregateChangedRival,
                $this->stringList($rivalReceipt['changed_files'] ?? []),
            )));
            $aggregateOosAtlas = array_values(array_unique(array_merge(
                $aggregateOosAtlas,
                $this->stringList($atlasReceipt['out_of_scope_files'] ?? []),
            )));
            $aggregateOosRival = array_values(array_unique(array_merge(
                $aggregateOosRival,
                $this->stringList($rivalReceipt['out_of_scope_files'] ?? []),
            )));
            $aggregateBytecodeAtlas = array_values(array_unique(array_merge(
                $aggregateBytecodeAtlas,
                $this->stringList($atlasReceipt['bytecode_artifacts'] ?? []),
            )));
            $aggregateBytecodeRival = array_values(array_unique(array_merge(
                $aggregateBytecodeRival,
                $this->stringList($rivalReceipt['bytecode_artifacts'] ?? []),
            )));
            $aggregateWorkspaceBlockers = array_values(array_unique(array_merge(
                $aggregateWorkspaceBlockers,
                $caseWorkspaceBlockers,
            )));
            foreach ($caseWorkspaceBlockers as $blocker) {
                $aggregateWorkspaceBlockersByCase[] = [
                    'case_id' => (string) $case['id'],
                    'blocker' => (string) $blocker,
                ];
            }
            $aggregateArmContractBlockers = array_values(array_unique(array_merge(
                $aggregateArmContractBlockers,
                $caseArmContractBlockers,
            )));
            foreach ($caseArmContractBlockers as $blocker) {
                $aggregateArmContractBlockersByCase[] = [
                    'case_id' => (string) $case['id'],
                    'blocker' => (string) $blocker,
                ];
            }

            if ($caseVerdict !== 'comparable' && $aggregateVerdict === 'comparable') {
                $aggregateVerdict = $caseVerdict;
            }
            if ($caseVerdict !== 'comparable') {
                $aggregateClaimReady = false;
            }
            if (! empty($atlasReceipt['killed']) || ! empty($rivalReceipt['killed'])) {
                $aggregateClaimReady = false;
            }

            if ($representativeAtlasReceipt === null) {
                $representativeAtlasReceipt = $atlasReceipt;
                $representativeRivalReceipt = $rivalReceipt;
                $representativeBeforeAtlas = $beforeAtlas;
                $representativeBeforeRival = $beforeRival;
                $representativeAfterAtlas = $afterAtlas;
                $representativeAfterRival = $afterRival;
                $representativeFixtureStageAtlas = $seedAtlas;
                $representativeFixtureStageRival = $seedRival;
            }

            $caseStartedAt = (string) ($atlasReceipt['started_at'] ?? '');
            $caseFinishedAt = (string) ($rivalReceipt['finished_at'] ?? $atlasReceipt['finished_at'] ?? '');
            if ($caseStartedAt !== '' && ($earliestStartedAt === null || $caseStartedAt < $earliestStartedAt)) {
                $earliestStartedAt = $caseStartedAt;
            }
            if ($caseFinishedAt !== '' && ($latestFinishedAt === null || $caseFinishedAt > $latestFinishedAt)) {
                $latestFinishedAt = $caseFinishedAt;
            }

            $this->events->event($runId, 'case_finished', [
                'case_id' => (string) $case['id'],
                'verdict' => $caseVerdict,
                'atlas_exit_code' => (int) ($atlasReceipt['exit_code'] ?? -1),
                'rival_exit_code' => (int) ($rivalReceipt['exit_code'] ?? -1),
                'atlas_test_exit_code' => (int) ($atlasReceipt['test_exit_code'] ?? -1),
                'rival_test_exit_code' => (int) ($rivalReceipt['test_exit_code'] ?? -1),
                'atlas_patch_diff_bytes' => (int) ($atlasReceipt['patch_diff_bytes'] ?? 0),
                'rival_patch_diff_bytes' => (int) ($rivalReceipt['patch_diff_bytes'] ?? 0),
                'workspace_blockers' => $caseWorkspaceBlockers,
                'arm_contract_blockers' => $caseArmContractBlockers,
                'difficulty_level' => $case['difficulty_level'] ?? null,
                'task_category' => $case['task_category'] ?? null,
            ]);

            $this->battery->markCaseFinished($runId, (string) $case['id'], $caseVerdict, [
                'verdict' => $caseVerdict,
                'atlas_exit_code' => (int) ($atlasReceipt['exit_code'] ?? -1),
                'rival_exit_code' => (int) ($rivalReceipt['exit_code'] ?? -1),
                'atlas_test_exit_code' => (int) ($atlasReceipt['test_exit_code'] ?? -1),
                'rival_test_exit_code' => (int) ($rivalReceipt['test_exit_code'] ?? -1),
                'atlas_patch_diff_bytes' => (int) ($atlasReceipt['patch_diff_bytes'] ?? 0),
                'rival_patch_diff_bytes' => (int) ($rivalReceipt['patch_diff_bytes'] ?? 0),
                'workspace_blockers' => $caseWorkspaceBlockers,
                'arm_contract_blockers' => $caseArmContractBlockers,
                'evidence_subdir' => $caseSubdir !== '' ? $caseSubdir : null,
            ]);
        }

        // No case actually executed (all fixture-blocked in multi mode) —
        // surface honestly instead of pretending we have receipts.
        if ($representativeAtlasReceipt === null || $representativeRivalReceipt === null) {
            $this->battery->finalize($runId, [
                'aggregate_verdict' => 'invalid_fixture_blocked',
                'claim_ready' => false,
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'blocked' => true,
                'stalled' => false,
            ]);

            return $this->blocked(
                array_values(array_unique(array_merge(
                    ['no_executable_cases_in_run'],
                    $aggregateWorkspaceBlockers,
                ))),
                'fix Provider Arena fixture for all cases before running release battery',
            );
        }

        // Build a single source-of-truth top-level atlas/rival receipt the
        // adjudicator's hard gates can read. For single-case this is identical
        // to v1 (zero shape drift). For multi-case it is the worst-of:
        //   - exit_code / test_exit_code   = first non-zero across cases
        //   - patch_diff_bytes             = MIN across cases (smallest case wins gate)
        //   - changed_files/oos/bytecode   = UNION
        //   - killed                       = OR
        //   - workspace_has_blocking_changes = OR
        // This is honest: any single case bleeding bytecode or going out of
        // scope trips the adjudicator's hard gate without inventing scores.
        $atlasReceipt = $representativeAtlasReceipt;
        $rivalReceipt = $representativeRivalReceipt;
        if ($isMultiCase) {
            $atlasReceipt = $this->aggregateReceipt(
                $representativeAtlasReceipt,
                array_values(array_filter(array_map(
                    static fn (array $p): ?array => is_array($p['atlas_receipt'] ?? null) ? $p['atlas_receipt'] : null,
                    $perCase,
                ))),
                $aggregateChangedAtlas,
                $aggregateOosAtlas,
                $aggregateBytecodeAtlas,
            );
            $rivalReceipt = $this->aggregateReceipt(
                $representativeRivalReceipt,
                array_values(array_filter(array_map(
                    static fn (array $p): ?array => is_array($p['rival_receipt'] ?? null) ? $p['rival_receipt'] : null,
                    $perCase,
                ))),
                $aggregateChangedRival,
                $aggregateOosRival,
                $aggregateBytecodeRival,
            );
        }

        $verdict = $aggregateVerdict;
        // `dirty_after_run` reflects ONLY harness/workspace contamination
        // (tracked .pyc, unregistered artifacts, dirty worktree). Arm contract
        // failures such as out-of-scope writes are scored as per-arm gate
        // failures through `arm_contract_blockers`, not as contaminated
        // battery infrastructure.
        $dirtyAfterRun = $aggregateWorkspaceBlockers !== [];

        $score = null;
        $claimReady = false;
        if ($verdict === 'comparable') {
            $score = [
                'comparable_score' => null,
                'diagnostic_score' => [
                    'atlas' => $this->armGateScore($atlasReceipt),
                    'rival' => $this->armGateScore($rivalReceipt),
                    'winner' => 'automated_quality_tie_requires_human_diff_review',
                ],
                'quality_claim' => 'automated gates passed; human diff review still required for qualitative winner',
                'case_count' => count($cases),
            ];
            $claimReady = $aggregateClaimReady;
        }

        // Persist top-level receipts (legacy compat). When multi-case, these
        // are the worst-of aggregate so adjudicator/replay/collect-evidence
        // stay on the same contract surface.
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents($paths['evidence'].'/atlas_receipt.json', $this->jsonEncode($atlasReceipt));
        file_put_contents($paths['evidence'].'/rival_receipt.json', $this->jsonEncode($rivalReceipt));
        file_put_contents($paths['evidence'].'/workspace_hashes.json', $this->jsonEncode([
            'before' => ['atlas' => $representativeBeforeAtlas, 'rival' => $representativeBeforeRival],
            'after' => ['atlas' => $representativeAfterAtlas, 'rival' => $representativeAfterRival],
            'dirty_after_run' => $dirtyAfterRun,
            'workspace_blockers' => $aggregateWorkspaceBlockers,
            'aggregate_workspace_blockers' => $aggregateWorkspaceBlockersByCase,
            'arm_contract_violation' => $aggregateArmContractBlockers !== [],
            'arm_contract_blockers' => $aggregateArmContractBlockers,
            'aggregate_arm_contract_blockers' => $aggregateArmContractBlockersByCase,
            'fixture_blockers' => $aggregateFixtureBlockers,
            'aggregate_fixture_blockers' => $aggregateFixtureBlockersByCase,
            'workspace_changes_after_run' => [
                'atlas' => $aggregateChangedAtlas,
                'rival' => $aggregateChangedRival,
            ],
            'case_count' => count($cases),
        ]));

        $perCaseSummary = array_values(array_map(
            static function (array $entry): array {
                $atlas = is_array($entry['atlas_receipt'] ?? null) ? $entry['atlas_receipt'] : null;
                $rival = is_array($entry['rival_receipt'] ?? null) ? $entry['rival_receipt'] : null;

                return [
                    'case_id' => (string) ($entry['case_id'] ?? ''),
                    'case_index' => (int) ($entry['case_index'] ?? 0),
                    'case_source' => (string) ($entry['case_source'] ?? 'legacy'),
                    'task_category' => $entry['task_category'] ?? null,
                    'case_set' => $entry['case_set'] ?? null,
                    'prompt_mode' => $entry['prompt_mode'] ?? 'spec-perfect',
                    'difficulty' => $entry['difficulty'] ?? null,
                    'difficulty_level' => $entry['difficulty_level'] ?? null,
                    'difficulty_weight' => $entry['difficulty_weight'] ?? null,
                    'verdict' => (string) ($entry['verdict'] ?? 'unknown'),
                    'evidence_subdir' => $entry['evidence_subdir'] ?? null,
                    'workspace_hash_before' => $entry['workspace_hash_before'] ?? null,
                    'workspace_hash_after' => $entry['workspace_hash_after'] ?? null,
                    'workspace_blockers' => $entry['workspace_blockers'] ?? [],
                    'arm_contract_blockers' => $entry['arm_contract_blockers'] ?? [],
                    'fixture_blockers' => $entry['fixture_blockers'] ?? [],
                    'fixture_stage' => $entry['fixture_stage'] ?? null,
                    'atlas_arm' => $atlas === null ? null : [
                        'exit_code' => (int) ($atlas['exit_code'] ?? -1),
                        'test_exit_code' => (int) ($atlas['test_exit_code'] ?? -1),
                        'killed' => (bool) ($atlas['killed'] ?? false),
                        'timeout_reason' => $atlas['timeout_reason'] ?? null,
                        'patch_diff_bytes' => (int) ($atlas['patch_diff_bytes'] ?? 0),
                        'patch_diff_hash' => $atlas['patch_diff_hash'] ?? null,
                        'patch_diff_path' => $atlas['patch_diff_path'] ?? null,
                        'test_log_path' => $atlas['test_log_path'] ?? null,
                        'changed_files' => $atlas['changed_files'] ?? [],
                        'out_of_scope_files' => $atlas['out_of_scope_files'] ?? [],
                        'bytecode_artifacts' => $atlas['bytecode_artifacts'] ?? [],
                    ],
                    'rival_arm' => $rival === null ? null : [
                        'exit_code' => (int) ($rival['exit_code'] ?? -1),
                        'test_exit_code' => (int) ($rival['test_exit_code'] ?? -1),
                        'killed' => (bool) ($rival['killed'] ?? false),
                        'timeout_reason' => $rival['timeout_reason'] ?? null,
                        'patch_diff_bytes' => (int) ($rival['patch_diff_bytes'] ?? 0),
                        'patch_diff_hash' => $rival['patch_diff_hash'] ?? null,
                        'patch_diff_path' => $rival['patch_diff_path'] ?? null,
                        'test_log_path' => $rival['test_log_path'] ?? null,
                        'changed_files' => $rival['changed_files'] ?? [],
                        'out_of_scope_files' => $rival['out_of_scope_files'] ?? [],
                        'bytecode_artifacts' => $rival['bytecode_artifacts'] ?? [],
                    ],
                ];
            },
            $perCase,
        ));

        $stateCounters = $this->perCaseStateCounters($perCase);
        $aggregateStartedAt = $earliestStartedAt ?? $this->nowIso();
        $aggregateFinishedAt = $latestFinishedAt ?? $this->nowIso();
        $aggregateWorkspaceHashBefore = ['atlas' => $representativeBeforeAtlas, 'rival' => $representativeBeforeRival];
        $aggregateWorkspaceHashAfter = ['atlas' => $representativeAfterAtlas, 'rival' => $representativeAfterRival];

        $manifest = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $paths['run_id'],
            'mode' => $mode,
            'atlas_model' => $atlasModel,
            'rival_model' => $rivalModel,
            'preset' => $preset,
            'prompt_mode' => $promptMode,
            'case_id' => $isMultiCase ? 'multi_case_aggregate' : (string) $firstCase['id'],
            'case_ids' => array_values(array_map(static fn (array $c): string => (string) ($c['case_id'] ?? ''), $perCaseSummary)),
            'case_source' => $firstCase['case_source'] ?? 'legacy',
            'case_set' => $firstCase['case_set'] ?? null,
            'task_category' => $firstCase['task_category'] ?? null,
            'case_count' => count($cases),
            // Battery Runner v1 aggregate counters: total_cases / passed_count /
            // comparable_count / invalid_count / blocked_count / skipped_count.
            // "passed_count" equals "comparable_count" — kept as separate keys
            // so downstream consumers can grep either name.
            'total_cases' => $stateCounters['total'],
            'comparable_count' => $stateCounters['comparable'],
            'passed_count' => $stateCounters['comparable'],
            'invalid_count' => $stateCounters['invalid'],
            'blocked_count' => $stateCounters['blocked'],
            'skipped_count' => $stateCounters['skipped'],
            'other_count' => $stateCounters['other'],
            'pending_count' => max(0, count($cases) - $stateCounters['total']),
            'is_multi_case' => $isMultiCase,
            'cases' => $perCaseSummary,
            // Canonical alias for v3 consumers (BatteryReportService v3 reads
            // either name). `cases` is preserved byte-for-byte for legacy
            // consumers (MatrixReport, ProviderArenaSnapshot, etc.).
            'case_results' => $perCaseSummary,
            'fixture_stage' => [
                'atlas' => $representativeFixtureStageAtlas,
                'rival' => $representativeFixtureStageRival,
            ],
            'started_at' => $aggregateStartedAt,
            'finished_at' => $aggregateFinishedAt,
            'run_duration_ms' => $this->durationMs($aggregateStartedAt, $aggregateFinishedAt),
            'verdict' => $verdict,
            'score' => $score,
            'claim_ready' => $claimReady,
            'atlas_receipt_hash' => hash('sha256', $this->jsonEncode($atlasReceipt)),
            'rival_receipt_hash' => hash('sha256', $this->jsonEncode($rivalReceipt)),
            'workspace_hash_before' => $aggregateWorkspaceHashBefore,
            'workspace_hash_after' => $aggregateWorkspaceHashAfter,
            'workspace_fingerprint_before' => $this->workspaceFingerprint($aggregateWorkspaceHashBefore),
            'workspace_fingerprint_after' => $this->workspaceFingerprint($aggregateWorkspaceHashAfter),
            'dirty_after_run' => $dirtyAfterRun,
            'workspace_blockers' => $aggregateWorkspaceBlockers,
            'aggregate_workspace_blockers' => $aggregateWorkspaceBlockersByCase,
            'fixture_blockers' => $aggregateFixtureBlockers,
            'aggregate_fixture_blockers' => $aggregateFixtureBlockersByCase,
            'workspace_changes_after_run' => [
                'atlas' => $aggregateChangedAtlas,
                'rival' => $aggregateChangedRival,
            ],
            'events_jsonl_path' => $paths['events_jsonl'] ?? null,
            'per_case_evidence_dirs' => array_values(array_filter(array_map(
                static fn (array $e): ?string => is_array($e) ? ($e['canonical_evidence_dir'] ?? null) : null,
                $perCase,
            ), static fn (?string $p): bool => $p !== null && $p !== '')),
            'external_provider_call' => $requiresProvider && $mode !== AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
            'provider_tokens_spent' => $requiresProvider && $mode !== AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
            'separated_from_external_rivals_certification' => true,
        ];
        file_put_contents($paths['evidence'].'/manifest.json', $this->jsonEncode($manifest));

        $this->events->event($runId, 'evidence_pack', [
            'verdict' => $verdict,
            'claim_ready' => $claimReady,
            'manifest_path' => $paths['evidence'].'/manifest.json',
            'case_count' => count($cases),
        ]);
        $this->events->event($runId, 'final_report', [
            'verdict' => $verdict,
            'score' => $score,
            'claim_ready' => $claimReady,
            'case_count' => count($cases),
        ]);

        $evidencePaths = [
            $paths['events_jsonl'],
            $paths['manifest_json'],
            $paths['evidence'].'/atlas_receipt.json',
            $paths['evidence'].'/rival_receipt.json',
            $paths['evidence'].'/workspace_hashes.json',
        ];
        if ($isMultiCase) {
            foreach ($perCase as $entry) {
                $subdir = (string) ($entry['evidence_subdir'] ?? '');
                if ($subdir === '') {
                    continue;
                }
                $caseEvidenceDir = $paths['evidence'].'/'.$subdir;
                foreach (['atlas_receipt.json', 'rival_receipt.json', 'workspace_hashes.json'] as $artifact) {
                    if (is_file($caseEvidenceDir.'/'.$artifact)) {
                        $evidencePaths[] = $caseEvidenceDir.'/'.$artifact;
                    }
                }
            }
        }

        // Finalise the battery: persist aggregate_verdict + claim_ready at
        // the battery-level so resume / status / report all read the same
        // source of truth. battery.json reflects every case across every
        // resume; manifest.json reflects only this session.
        $batterySnapshot = $this->battery->finalize($runId, [
            'aggregate_verdict' => $this->computeBatteryAggregateVerdict($runId),
            'claim_ready' => $this->computeBatteryClaimReady($runId, $mode),
            'external_provider_call' => $manifest['external_provider_call'],
            'provider_tokens_spent' => $manifest['provider_tokens_spent'],
            'blocked' => false,
            'stalled' => false,
        ]);
        if ($batterySnapshot !== null) {
            $evidencePaths[] = $this->battery->batteryJsonPath($runId);
            $evidencePaths[] = $this->battery->batteryJsonlPath($runId);
        }

        return [
            'status' => 'ok',
            'run_id' => $paths['run_id'],
            'mode' => $mode,
            'verdict' => $verdict,
            'score' => $score,
            'claim_ready' => $claimReady,
            'paths' => $paths,
            'manifest' => $manifest,
            'atlas_receipt' => $atlasReceipt,
            'rival_receipt' => $rivalReceipt,
            'cases' => $perCaseSummary,
            'case_count' => $originalCaseCount,
            'cases_executed_this_session' => count($cases),
            'is_multi_case' => $isMultiCase,
            'is_resume' => $isResume,
            'battery_snapshot' => $batterySnapshot,
            'evidence_paths' => array_values(array_unique($evidencePaths)),
            'external_provider_call' => $manifest['external_provider_call'],
            'provider_tokens_spent' => $manifest['provider_tokens_spent'],
            'next_command' => 'php artisan atlas:forge:rivals collect-evidence --run-id='.$paths['run_id'].' --json',
        ];
    }

    /**
     * Compute the battery-level aggregate verdict by scanning every case in
     * battery.json (across all sessions). Worst-of: an `invalid` case wins
     * over `failed`, which wins over `comparable`. Used by finalize so
     * resume runs surface honest aggregate state.
     */
    private function computeBatteryAggregateVerdict(string $runId): ?string
    {
        $battery = $this->battery->load($runId);
        if ($battery === null) {
            return null;
        }
        $hasInvalid = false;
        $hasFailed = false;
        $hasSkipped = false;
        $hasPending = false;
        $hasScopeViolation = false;
        $allCompleted = true;
        foreach ((array) ($battery['cases'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $state = (string) ($row['state'] ?? '');
            $verdict = strtolower(trim((string) ($row['verdict'] ?? '')));
            if ($verdict === 'invalid_scope_violation') {
                $hasScopeViolation = true;
            }
            if ($state !== AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED) {
                $allCompleted = false;
            }
            switch ($state) {
                case AtlasForgeRivalsBatteryStateService::CASE_STATE_INVALID:
                    $hasInvalid = true;
                    break;
                case AtlasForgeRivalsBatteryStateService::CASE_STATE_FAILED:
                    $hasFailed = true;
                    break;
                case AtlasForgeRivalsBatteryStateService::CASE_STATE_SKIPPED:
                    $hasSkipped = true;
                    break;
                case AtlasForgeRivalsBatteryStateService::CASE_STATE_PENDING:
                case AtlasForgeRivalsBatteryStateService::CASE_STATE_RUNNING:
                    $hasPending = true;
                    break;
            }
        }
        if ($hasPending) {
            return 'battery_partial';
        }
        if ($hasInvalid) {
            return 'invalid_workspace_after_run';
        }
        if ($hasFailed) {
            return $hasScopeViolation ? 'invalid_scope_violation' : 'invalid_tests_failed';
        }
        if ($hasSkipped && ! $allCompleted) {
            return 'battery_partial_with_skips';
        }

        return 'comparable';
    }

    /**
     * The battery is claim-ready only when every case reached `completed`,
     * the run mode is not local_fake, and no case carries a kill / timeout
     * signal. Resume runs preserve this — a single failed case keeps
     * claim_ready=false until the operator addresses it.
     */
    private function computeBatteryClaimReady(string $runId, string $mode): bool
    {
        if ($mode === AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE) {
            return false;
        }
        $battery = $this->battery->load($runId);
        if ($battery === null) {
            return false;
        }
        foreach ((array) ($battery['cases'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $state = (string) ($row['state'] ?? '');
            if ($state !== AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED) {
                return false;
            }
        }

        return true;
    }

    /**
     * Map a battery snapshot's aggregate state into a single verdict string
     * the caller can surface as `verdict` on the outer envelope. Used by
     * the resume early-return path when every case is already terminal.
     *
     * @param  array<string,mixed>  $snapshot
     */
    private function batteryAggregateVerdict(array $snapshot): string
    {
        return (string) ($snapshot['aggregate_verdict'] ?? 'battery_partial');
    }

    /**
     * Arm contract blockers are competitor-output failures: they should decide
     * that arm's case outcome, not poison the harness-level clean-room signal.
     */
    private function isArmContractBlocker(string $blocker): bool
    {
        return str_starts_with($blocker, 'fixture_file_modified:')
            || str_starts_with($blocker, 'unexpected_changed_file:')
            || str_starts_with($blocker, 'out_of_scope_change:');
    }

    /**
     * @param  list<string>  $blockers
     * @return list<string>
     */
    private function armContractBlockers(array $blockers): array
    {
        return array_values(array_filter(
            $blockers,
            fn (string $blocker): bool => $this->isArmContractBlocker($blocker),
        ));
    }

    /**
     * @param  list<string>  $blockers
     * @return list<string>
     */
    private function harnessWorkspaceBlockers(array $blockers): array
    {
        return array_values(array_filter(
            $blockers,
            fn (string $blocker): bool => ! $this->isArmContractBlocker($blocker),
        ));
    }

    /**
     * Aggregate per-case receipts into a single worst-of receipt the
     * adjudicator can read. Top-level shape stays compatible with
     * single-case receipts; only summary fields change semantics.
     *
     * @param  array<string,mixed>  $base
     * @param  list<array<string,mixed>>  $perCaseReceipts
     * @param  list<string>  $changedFiles
     * @param  list<string>  $outOfScopeFiles
     * @param  list<string>  $bytecodeArtifacts
     * @return array<string,mixed>
     */
    private function aggregateReceipt(
        array $base,
        array $perCaseReceipts,
        array $changedFiles,
        array $outOfScopeFiles,
        array $bytecodeArtifacts,
    ): array {
        $aggregated = $base;
        $aggregated['changed_files'] = $changedFiles;
        $aggregated['out_of_scope_files'] = $outOfScopeFiles;
        $aggregated['bytecode_artifacts'] = $bytecodeArtifacts;
        $aggregated['workspace_has_blocking_changes'] = $bytecodeArtifacts !== [];

        $worstExit = 0;
        $worstTestExit = 0;
        $killed = false;
        $minPatchBytes = null;
        $sumStdoutBytes = 0;
        $sumStderrBytes = 0;
        $caseIds = [];
        $workspaceBlockers = [];
        $armContractBlockers = [];

        foreach ($perCaseReceipts as $receipt) {
            $exit = (int) ($receipt['exit_code'] ?? -1);
            if ($exit !== 0) {
                $worstExit = $exit;
            }
            $testExit = (int) ($receipt['test_exit_code'] ?? -1);
            if ($testExit !== 0) {
                $worstTestExit = $testExit;
            }
            if (! empty($receipt['killed'])) {
                $killed = true;
            }
            $patchBytes = (int) ($receipt['patch_diff_bytes'] ?? 0);
            $minPatchBytes = $minPatchBytes === null ? $patchBytes : min($minPatchBytes, $patchBytes);
            $sumStdoutBytes += (int) ($receipt['stdout_bytes'] ?? 0);
            $sumStderrBytes += (int) ($receipt['stderr_bytes'] ?? 0);
            $cid = (string) ($receipt['case_id'] ?? '');
            if ($cid !== '') {
                $caseIds[] = $cid;
            }
            foreach ($this->stringList($receipt['workspace_blockers'] ?? []) as $blocker) {
                if ($blocker !== '') {
                    if ($this->isArmContractBlocker($blocker)) {
                        $armContractBlockers[] = $blocker;
                    } else {
                        $workspaceBlockers[] = $blocker;
                    }
                }
            }
        }

        $aggregated['exit_code'] = $worstExit;
        $aggregated['test_exit_code'] = $worstTestExit;
        $aggregated['killed'] = $killed;
        $aggregated['timeout'] = $killed;
        $aggregated['patch_diff_bytes'] = $minPatchBytes ?? 0;
        $aggregated['stdout_bytes'] = $sumStdoutBytes;
        $aggregated['stderr_bytes'] = $sumStderrBytes;
        $aggregated['case_ids'] = array_values(array_unique($caseIds));
        if (count($aggregated['case_ids']) > 1) {
            $aggregated['case_id'] = 'multi_case_aggregate';
        } elseif (count($aggregated['case_ids']) === 1) {
            $aggregated['case_id'] = $aggregated['case_ids'][0];
        }
        $aggregated['aggregate_kind'] = 'worst_of_per_case';

        if ($outOfScopeFiles !== []) {
            foreach ($outOfScopeFiles as $file) {
                $armContractBlockers[] = 'out_of_scope_change:'.$file;
            }
        }
        if ($bytecodeArtifacts !== []) {
            foreach ($bytecodeArtifacts as $file) {
                $workspaceBlockers[] = 'bytecode_artifact_after_run:'.$file;
            }
        }
        $aggregated['workspace_blockers'] = array_values(array_unique($workspaceBlockers));
        $aggregated['workspace_has_blocking_changes'] = $aggregated['workspace_blockers'] !== [];
        $aggregated['arm_contract_blockers'] = array_values(array_unique($armContractBlockers));
        $aggregated['arm_contract_has_failures'] = $aggregated['arm_contract_blockers'] !== [];

        return $aggregated;
    }

    /**
     * Reset a worktree to its tracked HEAD state, including removing any
     * untracked files the previous case produced. Required between cases so
     * the next case starts from a deterministic baseline.
     *
     * Setup provisions runtime-only local files that are intentionally ignored
     * by git (vendor symlink + env files). Those are not competitor output and
     * must survive the inter-case reset, otherwise a real provider run can
     * fail validation with a missing vendor/autoload.php harness error.
     */
    private function resetWorktree(string $worktree): void
    {
        if (! is_dir($worktree)) {
            return;
        }
        $reset = new Process(['git', '-C', $worktree, 'reset', '--hard', 'HEAD']);
        $reset->setTimeout(30);
        try {
            $reset->run();
        } catch (\Throwable) {
            // best-effort; if reset fails, next case's after-clean-check
            // will surface the dirty state as a blocker honestly.
        }

        $clean = new Process([
            'git',
            '-C',
            $worktree,
            'clean',
            '-fdx',
            '-e',
            'vendor',
            '-e',
            '.env',
            '-e',
            '.env.testing',
        ]);
        $clean->setTimeout(30);
        try {
            $clean->run();
        } catch (\Throwable) {
        }
    }

    /**
     * Real-provider batteries cannot share Composer runtime state between
     * arms. A symlinked vendor directory is fine for cheap local smoke tests,
     * but it invalidates paid provider comparisons: `composer dump-autoload`
     * from one arm can rewrite shared autoload files so the other arm boots
     * against the sibling workspace. Before spending tokens, replace any
     * vendor symlink with an isolated vendor copy and regenerate autoload
     * files inside each arm.
     *
     * @param  array<string,mixed>  $paths
     * @return array{status:string,arms:array<string,array<string,mixed>>,blockers:list<string>}
     */
    private function prepareProviderRuntimeIsolation(string $runId, array $paths): array
    {
        $arms = [];
        $blockers = [];

        foreach (['atlas', 'rival'] as $arm) {
            $worktree = (string) ($paths[$arm] ?? '');
            $prepared = $this->prepareArmRuntimeIsolation($runId, $arm, $worktree);
            $arms[$arm] = $prepared;
            foreach ($this->stringList($prepared['blockers'] ?? []) as $blocker) {
                $blockers[] = 'runtime_isolation:'.$arm.':'.$blocker;
            }
        }

        return [
            'status' => $blockers === [] ? 'ok' : 'blocked',
            'arms' => $arms,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * @return array{status:string,actions:list<string>,blockers:list<string>,autoload_blockers:list<string>}
     */
    private function prepareArmRuntimeIsolation(string $runId, string $arm, string $worktree): array
    {
        $cacheKey = $runId.'|'.$arm.'|'.$worktree;
        if (isset($this->runtimeIsolationPrepared[$cacheKey])) {
            $autoloadBlockers = $this->composerAutoloadLeakBlockers($runId, $arm, $worktree);

            return [
                'status' => $autoloadBlockers === [] ? 'ok' : 'blocked',
                'actions' => ['runtime_isolation_already_prepared'],
                'blockers' => $autoloadBlockers,
                'autoload_blockers' => $autoloadBlockers,
            ];
        }

        $actions = [];
        $blockers = [];

        if ($worktree === '' || ! is_dir($worktree)) {
            return [
                'status' => 'blocked',
                'actions' => [],
                'blockers' => ['worktree_missing'],
                'autoload_blockers' => [],
            ];
        }

        $vendorTarget = rtrim($worktree, '/').'/vendor';
        $vendorSource = function_exists('base_path') ? base_path('vendor') : getcwd().'/vendor';
        if (! is_file($vendorSource.'/autoload.php')) {
            $blockers[] = 'source_vendor_autoload_missing';
        } elseif (is_link($vendorTarget)) {
            if (! @unlink($vendorTarget)) {
                $blockers[] = 'vendor_symlink_unlink_failed';
            } elseif ($this->copyDirectory($vendorSource, $vendorTarget)) {
                $actions[] = 'vendor_symlink_replaced_with_isolated_copy';
            } else {
                $blockers[] = 'vendor_copy_failed_after_symlink_unlink';
            }
        } elseif (! is_dir($vendorTarget)) {
            if ($this->copyDirectory($vendorSource, $vendorTarget)) {
                $actions[] = 'vendor_isolated_copy_created';
            } else {
                $blockers[] = 'vendor_copy_failed';
            }
        } else {
            $actions[] = 'vendor_directory_already_isolated';
        }

        if (is_link($vendorTarget)) {
            $blockers[] = 'vendor_remains_symlink';
        }
        if (! is_file($vendorTarget.'/autoload.php')) {
            $blockers[] = 'vendor_autoload_missing';
        }

        if ($blockers === []) {
            $composer = $this->runComposerDumpAutoload($worktree);
            $actions[] = (string) ($composer['action'] ?? 'composer_dump_autoload_attempted');
            foreach ($this->stringList($composer['blockers'] ?? []) as $blocker) {
                $blockers[] = $blocker;
            }
        }

        $autoloadBlockers = $this->composerAutoloadLeakBlockers($runId, $arm, $worktree);
        foreach ($autoloadBlockers as $blocker) {
            $blockers[] = $blocker;
        }

        if ($blockers === []) {
            $this->runtimeIsolationPrepared[$cacheKey] = true;
        }

        return [
            'status' => $blockers === [] ? 'ok' : 'blocked',
            'actions' => array_values(array_unique($actions)),
            'blockers' => array_values(array_unique($blockers)),
            'autoload_blockers' => $autoloadBlockers,
        ];
    }

    /**
     * @return array{action:string,blockers:list<string>}
     */
    private function runComposerDumpAutoload(string $worktree): array
    {
        $commands = [
            ['composer', 'dump-autoload', '--no-scripts', '--optimize'],
            ['/opt/homebrew/bin/composer', 'dump-autoload', '--no-scripts', '--optimize'],
        ];
        $lastError = '';

        foreach ($commands as $command) {
            if ($command[0] !== 'composer' && ! is_file($command[0])) {
                continue;
            }

            try {
                $proc = new Process($command, $worktree, $this->subprocessEnv() + [
                    'COMPOSER_ALLOW_SUPERUSER' => '1',
                ]);
                $proc->setTimeout(120);
                $proc->run();
                if ($proc->isSuccessful()) {
                    return [
                        'action' => 'composer_dump_autoload:'.basename($command[0]),
                        'blockers' => [],
                    ];
                }
                $lastError = trim((string) ($proc->getErrorOutput() ?: $proc->getOutput()));
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        return [
            'action' => 'composer_dump_autoload_failed',
            'blockers' => ['composer_dump_autoload_failed:'.substr($lastError !== '' ? $lastError : 'unknown', 0, 160)],
        ];
    }

    /**
     * @return list<string>
     */
    private function composerAutoloadLeakBlockers(string $runId, string $arm, string $worktree): array
    {
        $paths = $this->paths->paths($runId);
        $otherArm = $arm === 'atlas' ? 'rival' : 'atlas';
        $repoRoot = function_exists('base_path') ? rtrim(base_path(), '/') : '';
        $needles = [
            'sibling_arm_worktree' => (string) ($paths[$otherArm] ?? ''),
            'sibling_arm_base' => (string) ($paths[$otherArm.'_arm_base'] ?? ''),
            'metadata_run_root' => (string) ($paths['base'] ?? ''),
            'source_repo_root' => $repoRoot,
        ];

        $blockers = [];
        foreach ($this->composerAutoloadFiles($worktree) as $file) {
            $content = (string) @file_get_contents($file);
            if ($content === '') {
                continue;
            }
            foreach ($needles as $kind => $needle) {
                $needle = rtrim(trim((string) $needle), '/');
                if ($needle === '' || $needle === rtrim($worktree, '/')) {
                    continue;
                }
                if (str_contains($content, $needle)) {
                    $blockers[] = 'composer_autoload_leak:'.$kind.':'.basename($file);
                }
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @return list<string>
     */
    private function composerAutoloadFiles(string $worktree): array
    {
        $composerDir = rtrim($worktree, '/').'/vendor/composer';
        if (! is_dir($composerDir)) {
            return [];
        }

        $files = [];
        foreach ([
            'autoload_classmap.php',
            'autoload_files.php',
            'autoload_namespaces.php',
            'autoload_psr4.php',
            'autoload_real.php',
            'autoload_static.php',
            'installed.php',
        ] as $file) {
            $path = $composerDir.'/'.$file;
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function copyDirectory(string $source, string $target): bool
    {
        $source = rtrim($source, '/');
        $target = rtrim($target, '/');
        if (! is_dir($source)) {
            return false;
        }

        try {
            @mkdir($target, 0o755, true);
            $rsync = new Process(['rsync', '-a', '--delete', $source.'/', $target.'/']);
            $rsync->setTimeout(300);
            $rsync->run();
            if ($rsync->isSuccessful()) {
                return true;
            }
        } catch (\Throwable) {
            // Fall back to PHP copy below.
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($iterator as $entry) {
                if (! $entry instanceof \SplFileInfo) {
                    continue;
                }
                $relative = ltrim(substr($entry->getPathname(), strlen($source)), '/');
                $dest = $target.'/'.$relative;
                if ($entry->isLink()) {
                    @mkdir(dirname($dest), 0o755, true);
                    $linkTarget = readlink($entry->getPathname());
                    if ($linkTarget !== false && ! file_exists($dest)) {
                        @symlink($linkTarget, $dest);
                    }
                } elseif ($entry->isDir()) {
                    @mkdir($dest, 0o755, true);
                } elseif ($entry->isFile()) {
                    @mkdir(dirname($dest), 0o755, true);
                    if (! @copy($entry->getPathname(), $dest)) {
                        return false;
                    }
                }
            }

            return is_file($target.'/autoload.php');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Sanitize a case id into a filesystem-safe directory name. Falls back
     * to a deterministic short hash if the id contains anything unusual.
     */
    private function safeCaseDir(string $caseId): string
    {
        $trimmed = trim($caseId);
        if ($trimmed === '') {
            return 'case-unknown';
        }
        if (preg_match('/^[A-Za-z0-9_.\-]{1,96}$/', $trimmed) === 1) {
            return $trimmed;
        }

        return 'case-'.substr(hash('sha256', $trimmed), 0, 12);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{cases:list<array<string,mixed>>,source:string}
     */
    private function resolveCaseContext(array $input, string $preset): array
    {
        $explicitCase = trim((string) ($input['case'] ?? ''));
        $explicitCases = $this->stringList($input['cases'] ?? []);
        $caseSet = trim((string) ($input['case_set'] ?? ''));

        if ($explicitCase !== '') {
            return [
                'source' => 'provider_arena_corpus',
                'cases' => [$this->adaptCorpusCase($this->corpus->case($explicitCase), $preset, $caseSet)],
            ];
        }

        if ($explicitCases !== []) {
            return [
                'source' => 'provider_arena_corpus',
                'cases' => array_values(array_map(
                    fn (string $caseId): array => $this->adaptCorpusCase($this->corpus->case($caseId), $preset, $caseSet),
                    $explicitCases,
                )),
            ];
        }

        if ($caseSet !== '') {
            $cases = $this->corpus->casesForCaseSet($caseSet);
            if ($cases === []) {
                throw new \InvalidArgumentException('empty_case_set:'.$caseSet);
            }

            return [
                'source' => 'provider_arena_corpus',
                'cases' => array_values(array_map(
                    fn (array $c): array => $this->adaptCorpusCase($c, $preset, $caseSet),
                    $cases,
                )),
            ];
        }

        // Preset=release without explicit case-set maps to the canonical
        // provider arena release corpus (the 40-case battery). Quick/smoke/full
        // remain on the legacy preset registry for back-compat with single-case
        // tests and the original v1 harness.
        if (strtolower(trim($preset)) === AtlasForgeRivalsCasesRegistry::PRESET_RELEASE) {
            $corpusReleaseSet = AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_RELEASE;
            $cases = $this->corpus->casesForCaseSet($corpusReleaseSet);
            if ($cases === []) {
                throw new \InvalidArgumentException('empty_case_set:'.$corpusReleaseSet);
            }

            return [
                'source' => 'provider_arena_corpus',
                'cases' => array_values(array_map(
                    fn (array $c): array => $this->adaptCorpusCase($c, $preset, $corpusReleaseSet),
                    $cases,
                )),
            ];
        }

        return [
            'source' => 'legacy_preset',
            'cases' => $this->cases->casesForPreset($preset),
        ];
    }

    /**
     * @param  array<string,mixed>  $corpusCase
     * @return array<string,mixed>
     */
    private function adaptCorpusCase(array $corpusCase, string $preset, string $caseSet): array
    {
        $fixture = is_array($corpusCase['setup_fixture'] ?? null) ? $corpusCase['setup_fixture'] : [];
        $quickCommand = trim((string) ($corpusCase['quick_test_command'] ?? ''));
        $fullCommand = trim((string) ($corpusCase['full_test_command'] ?? ''));
        $declaredCommand = trim((string) ($corpusCase['test_command'] ?? ''));
        $testCommand = $declaredCommand !== ''
            ? $declaredCommand
            : (
                strtolower($preset) === AtlasForgeRivalsCasesRegistry::PRESET_QUICK && $quickCommand !== ''
                    ? $quickCommand
                    : ($fullCommand !== '' ? $fullCommand : $quickCommand)
            );

        $difficulty = (string) ($corpusCase['difficulty'] ?? '');
        $difficultyLevel = (string) ($corpusCase['difficulty_level'] ?? '');
        if ($difficultyLevel === '' && $difficulty !== '') {
            $difficultyLevel = AtlasForgeRivalsProviderArenaCorpusService::difficultyToLevel($difficulty);
        }
        if ($difficultyLevel === '') {
            $difficultyLevel = AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVEL_L3;
        }
        $difficultyWeight = AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight($difficultyLevel);

        // Canonical L1..L5 difficulty block — passed through verbatim to the
        // manifest so adjudicator/report/evidence can compute the difficulty
        // multiplier consistently downstream. Real cases declare every field;
        // for unknown sources we fall back to the canonical neutral block.
        $difficultyScoreMap = AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVEL_SCORE
            ?? null;
        $declaredScore = $corpusCase['difficulty_score'] ?? null;
        if (is_int($declaredScore) || is_float($declaredScore)) {
            $difficultyScore = (float) $declaredScore;
        } else {
            $contractMap = AtlasForgeRivalsSchemaContractService::DIFFICULTY_LEVEL_SCORE;
            $difficultyScore = $contractMap[$difficultyLevel] ?? 3.0;
        }

        return [
            'id' => (string) ($corpusCase['case_id'] ?? ''),
            'case_source' => 'provider_arena_corpus',
            'case_set' => $caseSet !== '' ? $caseSet : null,
            'task_category' => (string) ($corpusCase['task_category'] ?? ''),
            'category' => (string) ($corpusCase['category'] ?? ''),
            'difficulty' => $difficulty,
            'difficulty_level' => $difficultyLevel,
            'difficulty_score' => $difficultyScore,
            'difficulty_reason' => (string) ($corpusCase['difficulty_reason'] ?? ''),
            'planning_weight' => is_numeric($corpusCase['planning_weight'] ?? null) ? (float) $corpusCase['planning_weight'] : null,
            'execution_weight' => is_numeric($corpusCase['execution_weight'] ?? null) ? (float) $corpusCase['execution_weight'] : null,
            'ambiguity_level' => (string) ($corpusCase['ambiguity_level'] ?? ''),
            'risk_level' => (string) ($corpusCase['risk_level'] ?? ''),
            'difficulty_weight' => $difficultyWeight,
            'role_focus' => (string) ($corpusCase['role_focus'] ?? ''),
            'objective' => (string) ($corpusCase['objective'] ?? ''),
            'business_rule' => (string) ($corpusCase['business_rule'] ?? ''),
            'allowed_files' => $this->normalizeWorkspacePaths($this->stringList($corpusCase['allowed_files_scope'] ?? [])),
            'forbidden_files' => $this->normalizeWorkspacePaths($this->stringList($corpusCase['forbidden_files_scope'] ?? [])),
            'expected_changed_files' => $this->normalizeWorkspacePaths($this->stringList($corpusCase['expected_changed_files'] ?? [])),
            'acceptance_criteria' => $this->stringList($corpusCase['acceptance_criteria'] ?? []),
            'quick_test_command' => $quickCommand,
            'full_test_command' => $fullCommand,
            'test_command' => $testCommand,
            'expected_artifacts' => $this->stringList($corpusCase['expected_evidence'] ?? []),
            'expected_signal' => (string) ($corpusCase['expected_signal'] ?? ''),
            'setup_fixture' => [
                'seed_dir' => (string) ($fixture['seed_dir'] ?? ''),
                'base_files' => $this->stringList($fixture['base_files'] ?? []),
            ],
            'quality_gates' => is_array($corpusCase['quality_gates'] ?? null) ? $corpusCase['quality_gates'] : [],
            'timeout_policy' => is_array($corpusCase['timeout_policy'] ?? null) ? $corpusCase['timeout_policy'] : [],
            'tags' => ['provider-arena:'.$preset, 'rivals:v2', 'forge:atlas-arm', 'difficulty:'.$difficultyLevel],
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function normalizeWorkspacePaths(array $paths): array
    {
        return array_values(array_map(function (string $path): string {
            return $this->normalizeWorkspacePath($path);
        }, $paths));
    }

    private function normalizeWorkspacePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        foreach (['atlas-server/', './atlas-server/', './'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return substr($path, strlen($prefix));
            }
        }

        return $path;
    }

    /**
     * Provider Arena corpus cases keep fixtures under storage/. The real run
     * must stage those seed files into the declared worktree scope before
     * hashing or invoking providers, otherwise models edit storage fixtures
     * and the harness correctly invalidates the run as out-of-scope.
     *
     * @param  array<string,mixed>  $case
     * @return array{status:string,seed_source?:string,staged_files:list<string>,ignored_files:list<string>,blockers:list<string>,file_hashes?:array<string,string>}
     */
    private function stageCaseFixture(string $runId, string $arm, string $worktree, array $case): array
    {
        if (($case['case_source'] ?? '') !== 'provider_arena_corpus') {
            return [
                'status' => 'not_required',
                'staged_files' => [],
                'ignored_files' => [],
                'blockers' => [],
                'file_hashes' => [],
            ];
        }

        $fixture = is_array($case['setup_fixture'] ?? null) ? $case['setup_fixture'] : [];
        $seedDir = trim((string) ($fixture['seed_dir'] ?? ''));
        if ($seedDir === '') {
            return [
                'status' => 'blocked',
                'staged_files' => [],
                'ignored_files' => [],
                'blockers' => ['fixture_seed_dir_missing:'.$case['id']],
                'file_hashes' => [],
            ];
        }

        $seedSource = 'worktree';
        $seedRoot = $worktree.'/'.$seedDir;
        $sourceSeedRoot = $this->sourceFixtureSeedRoot($seedDir);
        if ($sourceSeedRoot !== null) {
            $seedRoot = $sourceSeedRoot;
            $seedSource = 'source_repo';
        }
        if (! is_dir($seedRoot)) {
            return [
                'status' => 'blocked',
                'staged_files' => [],
                'ignored_files' => [],
                'blockers' => ['fixture_seed_dir_not_found:'.$seedDir],
                'file_hashes' => [],
            ];
        }

        $allowed = $this->stringList($case['allowed_files'] ?? []);
        $expectedChanged = $this->stringList($case['expected_changed_files'] ?? []);
        $targetsByBasename = [];
        foreach (array_values(array_unique(array_merge($allowed, $expectedChanged))) as $target) {
            $targetsByBasename[basename($target)] = $target;
        }

        $staged = [];
        $ignored = [];
        $blockers = [];
        $fileHashes = [];
        $files = array_values(array_filter(
            $this->fixtureSeedFiles($seedRoot),
            fn (string $path): bool => ! $this->isFixtureReadme($seedRoot, $path),
        ));

        if ($files === []) {
            $blockers[] = 'fixture_seed_empty:'.$case['id'];
            $this->events->event($runId, 'fixture_staged', [
                'arm' => $arm,
                'case_id' => $case['id'],
                'seed_dir' => $seedDir,
                'seed_source' => $seedSource,
                'staged_files' => [],
                'fixture_hash_count' => 0,
                'ignored_files' => [],
                'blockers' => $blockers,
            ]);

            return [
                'status' => 'blocked',
                'seed_source' => $seedSource,
                'staged_files' => [],
                'ignored_files' => [],
                'blockers' => array_values(array_unique($blockers)),
                'file_hashes' => [],
            ];
        }

        foreach ($files as $sourcePath) {
            if (! is_file($sourcePath)) {
                continue;
            }
            $basename = basename($sourcePath);
            $target = $this->fixtureTargetForSeedFile($seedRoot, $sourcePath, $targetsByBasename);
            if ($target === null) {
                $ignored[] = $basename;

                continue;
            }
            if (! $this->isSafeFixtureTarget($target)) {
                $blockers[] = 'fixture_target_unsafe:'.$target;

                continue;
            }
            if (
                ! $this->matchesAnyAllowedScope($target, $allowed)
                && ! $this->matchesAnyAllowedScope($target, $expectedChanged)
                && ! $this->isReadOnlyFixtureTarget($target)
            ) {
                $blockers[] = 'fixture_target_out_of_scope:'.$target;

                continue;
            }
            $targetPath = $worktree.'/'.$target;
            $targetDir = dirname($targetPath);
            if (! is_dir($targetDir) && ! @mkdir($targetDir, 0o755, true) && ! is_dir($targetDir)) {
                $blockers[] = 'fixture_target_dir_create_failed:'.$target;

                continue;
            }
            if (! @copy($sourcePath, $targetPath)) {
                $blockers[] = 'fixture_copy_failed:'.$basename.'->'.$target;

                continue;
            }
            $staged[] = $target;
            $fileHashes[$target] = hash_file('sha256', $targetPath) ?: '';
        }

        if ($this->needsFrontendVitestHarness($case)) {
            $this->stageFrontendVitestHarness($worktree, $fileHashes, $staged, $blockers);
        }

        if ($staged === [] && $blockers === []) {
            $blockers[] = 'fixture_seed_no_stageable_files:'.$case['id'];
        }

        $this->events->event($runId, 'fixture_staged', [
            'arm' => $arm,
            'case_id' => $case['id'],
            'seed_dir' => $seedDir,
            'seed_source' => $seedSource,
            'staged_files' => $staged,
            'fixture_hash_count' => count(array_filter($fileHashes)),
            'ignored_files' => $ignored,
            'blockers' => $blockers,
        ]);

        return [
            'status' => $blockers === [] ? 'ok' : 'blocked',
            'seed_source' => $seedSource,
            'staged_files' => array_values(array_unique($staged)),
            'ignored_files' => array_values(array_unique($ignored)),
            'blockers' => array_values(array_unique($blockers)),
            'file_hashes' => array_filter($fileHashes, static fn (string $hash): bool => $hash !== ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function needsFrontendVitestHarness(array $case): bool
    {
        foreach ([
            (string) ($case['test_command'] ?? ''),
            (string) ($case['quick_test_command'] ?? ''),
            (string) ($case['full_test_command'] ?? ''),
        ] as $command) {
            if (preg_match('/(^|&&|\s)vitest\s+run(\s|$)/', $command) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Frontend corpus cases are intentionally tiny fixtures inside a backend
     * worktree. The harness must provide the test runner scaffold itself so
     * providers compete on the component task, not on bootstrapping Vitest.
     *
     * @param  array<string,string>  $fileHashes
     * @param  list<string>  $staged
     * @param  list<string>  $blockers
     */
    private function stageFrontendVitestHarness(string $worktree, array &$fileHashes, array &$staged, array &$blockers): void
    {
        foreach ($this->frontendVitestHarnessFiles() as $target => $contents) {
            $targetPath = $worktree.'/'.$target;
            $targetDir = dirname($targetPath);
            if (! is_dir($targetDir) && ! @mkdir($targetDir, 0o755, true) && ! is_dir($targetDir)) {
                $blockers[] = 'frontend_harness_dir_create_failed:'.$target;

                continue;
            }
            if (@file_put_contents($targetPath, $contents) === false) {
                $blockers[] = 'frontend_harness_write_failed:'.$target;

                continue;
            }

            $staged[] = $target;
            $fileHashes[$target] = hash_file('sha256', $targetPath) ?: '';
        }
    }

    /**
     * @return array<string,string>
     */
    private function frontendVitestHarnessFiles(): array
    {
        return [
            'atlas-desktop/package.json' => <<<'JSON'
{
  "name": "atlas-rivals-frontend-fixture",
  "private": true,
  "type": "module",
  "scripts": {
    "test": "vitest run --reporter=basic"
  },
  "dependencies": {
    "@vitejs/plugin-react": "^4.3.4",
    "@testing-library/jest-dom": "^6.6.3",
    "@testing-library/react": "^16.1.0",
    "@testing-library/user-event": "^14.5.2",
    "@types/react": "^18.3.12",
    "@types/react-dom": "^18.3.1",
    "jsdom": "^25.0.1",
    "react": "^18.3.1",
    "react-dom": "^18.3.1",
    "typescript": "^5.6.3",
    "vite": "^5.4.11",
    "vitest": "^2.1.9"
  }
}
JSON,
            'atlas-desktop/vitest.config.ts' => <<<'TS'
import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";

export default defineConfig({
  plugins: [react()],
  test: {
    environment: "jsdom",
    setupFiles: "./vitest.setup.ts"
  }
});
TS,
            'atlas-desktop/vitest.setup.ts' => <<<'TS'
import { cleanup } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { afterEach } from "vitest";

afterEach(() => {
  cleanup();
});
TS,
        ];
    }

    /**
     * Provider Arena batteries are only scoreable when every selected case has
     * a real, stageable fixture before the first provider can run. This makes
     * incomplete corpus work fail-closed at battery level instead of producing
     * a misleading partial score.
     *
     * @param  list<array<string,mixed>>  $cases
     * @return list<string>
     */
    private function fixtureReadinessBlockers(array $cases): array
    {
        $blockers = [];

        foreach ($cases as $case) {
            if (($case['case_source'] ?? '') !== 'provider_arena_corpus') {
                continue;
            }

            $caseId = trim((string) ($case['id'] ?? 'unknown_case'));
            if ($caseId === '') {
                $caseId = 'unknown_case';
            }

            $fixture = is_array($case['setup_fixture'] ?? null) ? $case['setup_fixture'] : [];
            $seedDir = trim((string) ($fixture['seed_dir'] ?? ''));
            if ($seedDir === '') {
                $blockers[] = 'fixture_seed_dir_missing:'.$caseId;

                continue;
            }

            $seedRoot = $this->sourceFixtureSeedRoot($seedDir);
            if ($seedRoot === null || ! is_dir($seedRoot)) {
                $blockers[] = 'fixture_seed_dir_not_found:'.$seedDir;

                continue;
            }

            $allowed = $this->stringList($case['allowed_files'] ?? []);
            $expectedChanged = $this->stringList($case['expected_changed_files'] ?? []);
            if ($expectedChanged === []) {
                $blockers[] = 'expected_changed_files_missing:'.$caseId;
            }

            $targetsByBasename = [];
            foreach (array_values(array_unique(array_merge($allowed, $expectedChanged))) as $target) {
                $targetsByBasename[basename($target)] = $target;
            }

            $files = array_values(array_filter(
                $this->fixtureSeedFiles($seedRoot),
                fn (string $path): bool => ! $this->isFixtureReadme($seedRoot, $path),
            ));

            if ($files === []) {
                $blockers[] = 'fixture_seed_empty:'.$caseId;

                continue;
            }

            $hasStageableFile = false;
            foreach ($files as $sourcePath) {
                if (! is_file($sourcePath)) {
                    continue;
                }

                $target = $this->fixtureTargetForSeedFile($seedRoot, $sourcePath, $targetsByBasename);
                if ($target === null || ! $this->isSafeFixtureTarget($target)) {
                    continue;
                }

                if (
                    ! $this->matchesAnyAllowedScope($target, $allowed)
                    && ! $this->matchesAnyAllowedScope($target, $expectedChanged)
                    && ! $this->isReadOnlyFixtureTarget($target)
                ) {
                    continue;
                }

                $hasStageableFile = true;
                break;
            }

            if (! $hasStageableFile) {
                $blockers[] = 'fixture_seed_no_stageable_files:'.$caseId;
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<string,string>  $targetsByBasename
     */
    private function fixtureTargetForSeedFile(string $seedRoot, string $sourcePath, array $targetsByBasename): ?string
    {
        $basename = basename($sourcePath);
        $seedRootPrefix = rtrim($seedRoot, '/').'/';
        $relativeSeedPath = str_starts_with($sourcePath, $seedRootPrefix)
            ? substr($sourcePath, strlen($seedRootPrefix))
            : $basename;
        $relativeSeedPath = $this->normalizeWorkspacePath($relativeSeedPath);

        if (str_contains($relativeSeedPath, '/') && $this->isSafeFixtureTarget($relativeSeedPath)) {
            return $relativeSeedPath;
        }

        if (isset($targetsByBasename[$basename])) {
            return $targetsByBasename[$basename];
        }

        return $this->inferFixtureTargetForSeedFile($sourcePath, $basename);
    }

    private function inferFixtureTargetForSeedFile(string $sourcePath, string $basename): ?string
    {
        if (! str_ends_with($basename, '.php') || ! is_file($sourcePath)) {
            return null;
        }

        $contents = (string) @file_get_contents($sourcePath);
        if ($contents === '' || preg_match('/^namespace\s+(Tests\\\\[^;]+);/m', $contents, $matches) !== 1) {
            return null;
        }

        $namespacePath = str_replace('\\', '/', $matches[1]);
        $target = $namespacePath.'/'.$basename;
        $target = preg_replace('/^Tests\//', 'tests/', $target) ?? $target;
        $target = $this->normalizeWorkspacePath($target);

        return $this->isSafeFixtureTarget($target) && $this->isReadOnlyFixtureTarget($target)
            ? $target
            : null;
    }

    private function sourceFixtureSeedRoot(string $seedDir): ?string
    {
        $seedDir = trim($seedDir);
        if ($seedDir === '' || str_contains($seedDir, '..') || str_contains($seedDir, "\0")) {
            return null;
        }
        if (! str_starts_with($seedDir, 'storage/forge-rivals-corpus/')) {
            return null;
        }

        $sourceRoot = base_path($seedDir);

        return is_dir($sourceRoot) ? $sourceRoot : null;
    }

    private function isSafeFixtureTarget(string $target): bool
    {
        $target = $this->normalizeWorkspacePath($target);
        if ($target === '' || str_contains($target, '..') || str_contains($target, "\0")) {
            return false;
        }

        foreach ([
            'app/',
            'bootstrap/',
            'config/',
            'database/',
            'docs/',
            'resources/',
            'routes/',
            'src/',
            'storage/forge-rivals-work/',
            'tests/',
            'atlas-desktop/',
        ] as $prefix) {
            if (str_starts_with($target, $prefix)) {
                return true;
            }
        }

        return in_array($target, [
            'composer.json',
            'composer.lock',
            'package.json',
            'phpunit.xml',
            'vite.config.ts',
            'vitest.config.ts',
        ], true);
    }

    private function isReadOnlyFixtureTarget(string $target): bool
    {
        $target = $this->normalizeWorkspacePath($target);

        // Read-only fixture targets are seed-staged context an arm consumes
        // but is not expected to produce. They live under canonical context
        // directories (tests/, fixtures/, input/, inbox/) or are reference
        // documents (docs/, README.md, context.md). Pre-staging these never
        // contaminates `dirty_after_run`: the post-arm scope check is what
        // enforces what the arm is allowed to write.
        return str_starts_with($target, 'tests/')
            || str_starts_with($target, 'docs/')
            || str_contains($target, '/__tests__/')
            || str_ends_with($target, '.test.ts')
            || str_ends_with($target, '.test.tsx')
            || str_ends_with($target, 'Test.php')
            || str_contains($target, '/fixtures/')
            || str_contains($target, '/input/')
            || str_contains($target, '/inbox/')
            || basename($target) === 'context.md';
    }

    /**
     * @return list<string>
     */
    private function fixtureSeedFiles(string $seedRoot): array
    {
        $files = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($seedRoot, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
        } catch (\Throwable) {
            return [];
        }

        sort($files);

        return array_values($files);
    }

    private function isFixtureReadme(string $seedRoot, string $path): bool
    {
        $relative = ltrim(str_replace('\\', '/', substr($path, strlen(rtrim($seedRoot, '/')))), '/');

        return strtolower($relative) === 'readme.md';
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function runArm(string $runId, string $arm, string $worktree, string $mode, string $model, array $case, string $caseSubdir = ''): array
    {
        $startedAt = $this->nowIso();
        $this->events->event($runId, 'provider_started', [
            'arm' => $arm,
            'mode' => $mode,
            'model' => $model,
            'worktree' => $worktree,
            'case_id' => (string) ($case['id'] ?? ''),
            'case_subdir' => $caseSubdir !== '' ? $caseSubdir : null,
        ]);

        if ($mode === AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE) {
            return $this->fakeArm($runId, $arm, $worktree, $model, $case, $startedAt, $caseSubdir);
        }

        // Real provider: build provider command per arm, spawn subprocess.
        $command = $this->resolveProviderCommand($arm, $model, $case, $worktree);
        $env = $this->subprocessEnv();
        $promptHash = hash('sha256', $this->jsonEncode([
            'command' => $command,
            'arm' => $arm,
            'model' => $model,
            'case_id' => $case['id'],
            'prompt_mode' => $case['prompt_mode'] ?? 'spec-perfect',
        ]));
        $commandHash = hash('sha256', implode(' ', $command));

        $providerTimeoutSeconds = $this->providerTimeoutSeconds();
        $hardKillSeconds = $this->hardKillSeconds();
        $proc = new Process($command, $worktree, $env, null, $hardKillSeconds);
        // We enforce idle and hard timeouts explicitly below. Symfony's idle
        // timeout can be bypassed when callers only poll isRunning(); the
        // explicit clock is the Rivals source of truth.
        $proc->setIdleTimeout(null);

        $stdoutBuf = '';
        $stderrBuf = '';
        $startedAtMonotonic = microtime(true);
        $lastProviderOutputAt = $startedAtMonotonic;
        $lastHeartbeatAt = $startedAtMonotonic;
        $killed = false;
        $timeoutReason = null;

        try {
            $proc->start();
            while ($proc->isRunning()) {
                $sawProviderOutput = false;
                $newStdout = (string) $proc->getIncrementalOutput();
                $newStderr = (string) $proc->getIncrementalErrorOutput();
                if ($newStdout !== '') {
                    $stdoutBuf .= $newStdout;
                    $sawProviderOutput = true;
                    $this->events->event($runId, 'provider_stdout_chunk', [
                        'arm' => $arm,
                        'bytes' => strlen($newStdout),
                        'tail' => substr($newStdout, -200),
                    ]);
                }
                if ($newStderr !== '') {
                    $stderrBuf .= $newStderr;
                    $sawProviderOutput = true;
                    $this->events->event($runId, 'provider_stderr_chunk', [
                        'arm' => $arm,
                        'bytes' => strlen($newStderr),
                        'tail' => substr($newStderr, -200),
                    ]);
                }
                $now = microtime(true);
                if ($sawProviderOutput) {
                    $lastProviderOutputAt = $now;
                }

                if (($now - $lastProviderOutputAt) >= $providerTimeoutSeconds) {
                    $killed = true;
                    $timeoutReason = 'idle_timeout';
                    $this->events->event($runId, 'provider_timeout_warning', [
                        'arm' => $arm,
                        'reason' => $timeoutReason,
                        'seconds_without_provider_output' => (int) floor($now - $lastProviderOutputAt),
                        'provider_timeout_seconds' => $providerTimeoutSeconds,
                    ]);
                    $proc->stop(10);
                    break;
                }

                if (($now - $startedAtMonotonic) >= $hardKillSeconds) {
                    $killed = true;
                    $timeoutReason = 'hard_timeout';
                    $this->events->event($runId, 'provider_timeout_warning', [
                        'arm' => $arm,
                        'reason' => $timeoutReason,
                        'elapsed_seconds' => (int) floor($now - $startedAtMonotonic),
                        'hard_kill_seconds' => $hardKillSeconds,
                    ]);
                    $proc->stop(10);
                    break;
                }

                if (($now - $lastHeartbeatAt) >= self::HEARTBEAT_INTERVAL_SECONDS) {
                    $this->events->event($runId, 'heartbeat', [
                        'arm' => $arm,
                        'elapsed_since_last_provider_output_seconds' => (int) floor($now - $lastProviderOutputAt),
                        'elapsed_total_seconds' => (int) floor($now - $startedAtMonotonic),
                    ]);
                    $lastHeartbeatAt = $now;
                }
                usleep(200_000); // 200ms poll
            }
        } catch (ProcessTimedOutException $e) {
            $killed = true;
            $timeoutReason = 'idle_timeout';
            $this->events->event($runId, 'provider_timeout_warning', [
                'arm' => $arm,
                'reason' => 'idle_timeout',
            ]);
            try {
                $proc->stop(10);
            } catch (\Throwable) {
            }
        } catch (\Throwable $e) {
            $killed = true;
            $timeoutReason = 'process_failed:'.$e->getMessage();
        }

        $exit = (int) ($proc->getExitCode() ?? -1);
        $stdoutBuf .= (string) $proc->getIncrementalOutput();
        $stderrBuf .= (string) $proc->getIncrementalErrorOutput();

        $this->events->event($runId, 'provider_finished', [
            'arm' => $arm,
            'exit_code' => $exit,
            'killed' => $killed,
            'timeout_reason' => $timeoutReason,
            'stdout_tail' => substr($stdoutBuf, -500),
            'stderr_tail' => substr($stderrBuf, -500),
        ]);

        $logPaths = $this->writeProviderLogs($runId, $arm, $stdoutBuf, $stderrBuf, $caseSubdir);
        $patch = $this->capturePatch($runId, $arm, $worktree, $case, $caseSubdir);
        $scope = $this->scopeCheck($worktree, $case);
        $isolation = $this->detectProviderIsolationLeak($runId, $arm, $stdoutBuf, $stderrBuf);
        $workspaceBlockers = array_values(array_unique(array_merge(
            $scope['blockers'],
            $isolation['blockers'],
        )));
        $test = $killed
            ? $this->skippedValidationCommand($runId, $arm, $case, (string) $timeoutReason, $caseSubdir)
            : $this->runValidationCommand($runId, $arm, $worktree, $case, $caseSubdir);

        return [
            'arm' => $arm,
            'mode' => $mode,
            'model' => $model,
            'command' => $command,
            'command_hash' => $commandHash,
            'prompt_hash' => $promptHash,
            'started_at' => $startedAt,
            'finished_at' => $this->nowIso(),
            'exit_code' => $exit,
            'killed' => $killed,
            'timeout' => $killed,
            'timeout_reason' => $timeoutReason,
            'stdout_hash' => hash('sha256', $stdoutBuf),
            'stderr_hash' => hash('sha256', $stderrBuf),
            'stdout_bytes' => strlen($stdoutBuf),
            'stderr_bytes' => strlen($stderrBuf),
            'stdout_tail' => substr($stdoutBuf, -2000),
            'stderr_tail' => substr($stderrBuf, -2000),
            'stdout_path' => $logPaths['stdout_path'],
            'stderr_path' => $logPaths['stderr_path'],
            'token_cost' => null,
            'tokens_used' => null,
            'worktree' => $worktree,
            'case_id' => $case['id'],
            'changed_files' => $scope['changed_files'],
            'out_of_scope_files' => $scope['out_of_scope_files'],
            'bytecode_artifacts' => $scope['bytecode_artifacts'],
            'workspace_blockers' => $workspaceBlockers,
            'workspace_has_blocking_changes' => $workspaceBlockers !== [],
            'isolation_leak_detected' => $isolation['detected'],
            'isolation_leak_blockers' => $isolation['blockers'],
            'isolation_leak_matches' => $isolation['matches'],
            'patch_diff_path' => $patch['path'],
            'patch_diff_hash' => $patch['sha256'],
            'patch_diff_bytes' => $patch['bytes'],
            'test_command' => $test['command'],
            'test_exit_code' => $test['exit_code'],
            'test_log_path' => $test['log_path'],
            'test_log_hash' => $test['log_hash'],
            'test_log_tail' => $test['tail'],
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function fakeArm(string $runId, string $arm, string $worktree, string $model, array $case, string $startedAt, string $caseSubdir = ''): array
    {
        // In-process fake provider: deterministic, fast, zero side-effects on worktree.
        // It still writes real evidence artifacts so the local battery proves
        // the comparable/report path instead of succeeding with an empty diff.
        $paths = $this->paths->paths($runId);
        $artifactDir = $this->artifactDir($paths['evidence'], $caseSubdir);
        @mkdir($artifactDir, 0o755, true);

        $command = ['atlas:forge-rivals-fake-provider', '--arm='.$arm, '--model='.$model, '--case='.$case['id']];
        $fakeStdout = sprintf("[FAKE %s/%s] case=%s status=ok\n", $arm, $model, $case['id']);
        $fakeStderr = '';
        $fakeChangedFiles = $this->fakeChangedFiles($arm);
        $fakePatch = $this->fakePatch($arm, $model, $case, $fakeChangedFiles);
        $patchPath = $artifactDir.'/'.$arm.'_patch.diff';
        file_put_contents($patchPath, $fakePatch);

        $testLog = sprintf(
            "PASS  Tests\\Feature\\Ai\\Programming\\ForgeRivalsLocalFake%sTest\n".
            "Tests: 2 passed (18 assertions)\n".
            "Case: %s\n".
            "Model: %s\n",
            ucfirst($arm),
            (string) $case['id'],
            $model,
        );
        $testLogPath = $artifactDir.'/'.$arm.'_test.log';
        file_put_contents($testLogPath, $testLog);
        $logPaths = $this->writeProviderLogs($runId, $arm, $fakeStdout, $fakeStderr, $caseSubdir);

        $this->events->event($runId, 'provider_stdout_chunk', [
            'arm' => $arm,
            'bytes' => strlen($fakeStdout),
            'tail' => $fakeStdout,
            'fake' => true,
        ]);
        $this->events->event($runId, 'heartbeat', ['arm' => $arm, 'fake' => true]);
        $this->events->event($runId, 'provider_finished', [
            'arm' => $arm,
            'exit_code' => 0,
            'killed' => false,
            'fake' => true,
        ]);

        return [
            'arm' => $arm,
            'mode' => AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
            'model' => $model,
            'command' => $command,
            'command_hash' => hash('sha256', implode(' ', $command)),
            'prompt_hash' => hash('sha256', $case['id'].'|'.$model.'|'.$arm),
            'started_at' => $startedAt,
            'finished_at' => $this->nowIso(),
            'exit_code' => 0,
            'killed' => false,
            'timeout' => false,
            'timeout_reason' => null,
            'stdout_hash' => hash('sha256', $fakeStdout),
            'stderr_hash' => hash('sha256', $fakeStderr),
            'stdout_bytes' => strlen($fakeStdout),
            'stderr_bytes' => strlen($fakeStderr),
            'stdout_tail' => $fakeStdout,
            'stderr_tail' => $fakeStderr,
            'stdout_path' => $logPaths['stdout_path'],
            'stderr_path' => $logPaths['stderr_path'],
            'changed_files' => $fakeChangedFiles,
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
            'workspace_blockers' => [],
            'workspace_has_blocking_changes' => false,
            'isolation_leak_detected' => false,
            'isolation_leak_blockers' => [],
            'isolation_leak_matches' => [],
            'patch_diff_path' => $patchPath,
            'patch_diff_hash' => hash('sha256', $fakePatch),
            'patch_diff_bytes' => strlen($fakePatch),
            'test_command' => 'local_fake_fixture_validation',
            'test_exit_code' => 0,
            'test_log_path' => $testLogPath,
            'test_log_hash' => hash('sha256', $testLog),
            'test_log_tail' => $testLog,
            'token_cost' => 0.0,
            'tokens_used' => 0,
            'worktree' => $worktree,
            'case_id' => $case['id'],
            'fake' => true,
        ];
    }

    /**
     * Provider output may include command summaries and cwd paths. That is
     * fine. It must not include metadata paths, evidence files, or the sibling
     * arm workspace. If it does, the run is contaminated: the provider observed
     * information outside its arm and the score cannot be trusted.
     *
     * @return array{detected:bool,blockers:list<string>,matches:list<array{kind:string,needle:string}>}
     */
    private function detectProviderIsolationLeak(string $runId, string $arm, string $stdout, string $stderr): array
    {
        $paths = $this->paths->paths($runId);
        $otherArm = $arm === 'atlas' ? 'rival' : 'atlas';
        $text = $stdout."\n".$stderr;
        $needles = [
            'metadata_run_root' => $paths['base'],
            'metadata_evidence_dir' => $paths['evidence'],
            'metadata_events_jsonl' => $paths['events_jsonl'],
            'metadata_manifest_json' => $paths['manifest_json'],
            'metadata_scorecard_json' => $paths['scorecard_json'],
            'metadata_report_md' => $paths['report_md'],
            'sibling_arm_worktree' => $paths[$otherArm],
            'sibling_arm_base' => $paths[$otherArm.'_arm_base'] ?? '',
        ];

        $matches = [];
        $blockers = [];
        foreach ($needles as $kind => $needle) {
            $needle = trim((string) $needle);
            if ($needle === '') {
                continue;
            }
            if (str_contains($text, $needle)) {
                $matches[] = ['kind' => $kind, 'needle' => $needle];
                $blockers[] = 'isolation_leak:'.$arm.':'.$kind;
            }
        }

        return [
            'detected' => $blockers !== [],
            'blockers' => array_values(array_unique($blockers)),
            'matches' => $matches,
        ];
    }

    /**
     * @return list<string>
     */
    private function fakeChangedFiles(string $arm): array
    {
        $suffix = $arm === 'atlas' ? 'Atlas' : 'Rival';

        return [
            'app/Services/Ai/Programming/ForgeRivals/LocalFake'.$suffix.'Patch.php',
            'tests/Feature/Ai/Programming/ForgeRivalsLocalFake'.$suffix.'Test.php',
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @param  list<string>  $changedFiles
     */
    private function fakePatch(string $arm, string $model, array $case, array $changedFiles): string
    {
        $caseId = (string) $case['id'];
        $classSuffix = $arm === 'atlas' ? 'Atlas' : 'Rival';
        $serviceFile = $changedFiles[0] ?? 'app/Services/Ai/Programming/ForgeRivals/LocalFake'.$classSuffix.'Patch.php';
        $testFile = $changedFiles[1] ?? 'tests/Feature/Ai/Programming/ForgeRivalsLocalFake'.$classSuffix.'Test.php';

        return <<<DIFF
diff --git a/{$serviceFile} b/{$serviceFile}
new file mode 100644
--- /dev/null
+++ b/{$serviceFile}
@@
+<?php
+
+declare(strict_types=1);
+
+final class LocalFake{$classSuffix}Patch
+{
+    public const ARM = '{$arm}';
+    public const MODEL = '{$model}';
+    public const CASE_ID = '{$caseId}';
+}
diff --git a/{$testFile} b/{$testFile}
new file mode 100644
--- /dev/null
+++ b/{$testFile}
@@
+<?php
+
+declare(strict_types=1);
+
+test('local fake {$arm} evidence fixture is comparable', function (): void {
+    expect('{$caseId}')->not->toBe('');
+});
DIFF;
    }

    /**
     * @param  array<string,mixed>  $case
     * @return list<string>
     */
    private function resolveProviderCommand(string $arm, string $model, array $case, string $worktree): array
    {
        if ($arm === 'atlas') {
            // Atlas arm: execute the provider under the Forge Rivals harness
            // contract. The outer harness owns isolation, evidence, scope, tests,
            // replay and invalidation; keeping this command provider-direct avoids
            // coupling the benchmark to legacy engineering_harness database drift.
            return [
                'claude',
                '--model',
                $this->claudeModelAlias($model),
                '--permission-mode',
                'bypassPermissions',
                '--output-format',
                'stream-json',
                '--verbose',
                '-p',
                $this->atlasForgePrompt($case),
            ];
        }

        // Rival arm: raw provider baseline, same model, no Atlas Forge.
        if ($model === 'codex') {
            return ['codex', 'exec', '--json', $this->rivalPrompt($case)];
        }

        return [
            'claude',
            '--model',
            $this->claudeModelAlias($model),
            '--permission-mode',
            'bypassPermissions',
            '--output-format',
            'stream-json',
            '--verbose',
            '-p',
            $this->rivalPrompt($case),
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function atlasForgePrompt(array $case): string
    {
        return $this->casePrompt($case, 'Você é o braço Atlas Forge. Use o fluxo Atlas Forge, mantenha evidência, respeite escopo e rode o comando de teste informado.');
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function rivalPrompt(array $case): string
    {
        return $this->casePrompt($case, 'Você é o braço baseline. Implemente diretamente no workspace atual, sem usar Atlas Forge, respeitando exatamente o mesmo escopo e teste.');
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function casePrompt(array $case, string $role): string
    {
        $promptMode = (string) ($case['prompt_mode'] ?? 'spec-perfect');
        $allowed = implode("\n- ", $this->stringList($case['allowed_files'] ?? []));
        $expectedChanged = implode("\n- ", $this->expectedChangedScope($case));
        $acceptance = implode("\n- ", $this->stringList($case['acceptance_criteria'] ?? []));
        $testCommand = $this->testCommand($case);
        $businessRule = trim((string) ($case['business_rule'] ?? ''));
        $expectedSignal = trim((string) ($case['expected_signal'] ?? ''));
        $fixtureNote = ($case['case_source'] ?? '') === 'provider_arena_corpus'
            ? "\nEstado inicial:\n- Os arquivos de seed ja foram posicionados no workspace. Trate inputs e testes existentes como fixtures somente-leitura; leia-os, mas nao modifique.\n"
            : '';

        if ($promptMode === 'human-normal') {
            return <<<PROMPT
{$role}

Pedido do operador:
Preciso que você resolva esta demanda no workspace atual: {$case['objective']}

Contexto do problema:
{$businessRule}

Regras do benchmark:
- Fique dentro deste escopo permitido:
- {$allowed}
- A entrega esperada deve tocar estes arquivos:
- {$expectedChanged}
- Preserve os critérios de aceite abaixo:
- {$acceptance}
{$fixtureNote}

Validação obrigatória:
{$testCommand}

Antes de terminar, deixe o patch aplicado no workspace, rode a validação e responda com um resumo curto, arquivos alterados e resultado do teste.
PROMPT;
        }

        if ($promptMode === 'messy-real') {
            return <<<PROMPT
{$role}

Pedido do operador, do jeito que chegou:
Tem algo errado ou incompleto nesta área e eu preciso que você entregue a correção sem abrir escopo. A intenção principal é: {$case['objective']}

Contexto disponível:
{$businessRule}

Limites que não podem ser violados:
- Escopo permitido:
- {$allowed}
- Arquivos esperados:
- {$expectedChanged}
- Critérios que serão usados para aceitar/rejeitar:
- {$acceptance}
{$fixtureNote}

Comando que precisa passar:
{$testCommand}

Se houver ambiguidade, faça a menor suposição compatível com os critérios acima. Não invente arquivos fora do escopo. Termine com resumo curto, arquivos alterados e resultado do teste.
PROMPT;
        }

        if ($promptMode === 'enterprise-change') {
            return <<<PROMPT
{$role}

Mudança enterprise solicitada:
{$case['objective']}

Motivo de negócio:
{$businessRule}

Controles obrigatórios:
- Escopo permitido:
- {$allowed}
- Arquivos esperados:
- {$expectedChanged}
- Critérios de aceite:
- {$acceptance}
{$fixtureNote}

Comando obrigatório de validação:
{$testCommand}

Implemente com cuidado de compatibilidade, evidência e rollback mental. Deixe o patch aplicado no workspace e responda com resumo curto, arquivos alterados, risco residual e resultado do teste.
PROMPT;
        }

        return <<<PROMPT
{$role}

Objetivo:
{$case['objective']}

Regra de negocio:
{$businessRule}

Escopo permitido:
- {$allowed}

Arquivos esperados para alteracao:
- {$expectedChanged}

Critérios de aceitação:
- {$acceptance}

Sinal esperado:
{$expectedSignal}
{$fixtureNote}

Comando obrigatório de validação:
{$testCommand}

Regras:
- Altere somente arquivos dentro do escopo permitido.
- Modifique somente os arquivos esperados para alteracao. Tests/inputs de fixture sao somente-leitura.
- Não crie bytecode, caches, arquivos temporários ou artefatos fora do escopo.
- Execute o comando obrigatório de validação antes de terminar.
- Deixe as alterações no workspace para o harness capturar diff e evidência.
- Responda com resumo curto, arquivos alterados e resultado do teste.
PROMPT;
    }

    private function normalizePromptMode(string $mode): string
    {
        return match (strtolower(trim($mode))) {
            '', 'spec', 'spec_perfect', 'spec-perfect' => 'spec-perfect',
            'human', 'human_normal', 'human-normal' => 'human-normal',
            'messy', 'messy_real', 'messy-real' => 'messy-real',
            'enterprise', 'enterprise_change', 'enterprise-change' => 'enterprise-change',
            default => strtolower(trim($mode)),
        };
    }

    private function claudeModelAlias(string $model): string
    {
        return match ($model) {
            AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_OPUS => 'opus',
            AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_SONNET, AtlasForgeRivalsModelMatrix::MODEL_AUTO => 'sonnet',
            default => $model,
        };
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function testCommand(array $case): string
    {
        $preferred = trim((string) ($case['test_command'] ?? ''));
        if ($preferred !== '') {
            return $this->normalizeValidationCommand($preferred);
        }
        $full = trim((string) ($case['full_test_command'] ?? ''));
        if ($full !== '') {
            return $this->normalizeValidationCommand($full);
        }
        $quick = trim((string) ($case['quick_test_command'] ?? ''));

        return $quick !== '' ? $this->normalizeValidationCommand($quick) : 'php artisan test';
    }

    private function normalizeValidationCommand(string $command): string
    {
        $command = trim($command);
        if (preg_match('/^vitest\s+run(\s|$)/', $command) !== 1) {
            return $command;
        }

        $normalized = preg_replace('#(^|\s)components/forge/#', '$1src/components/forge/', $command) ?? $command;
        $normalized = preg_replace('/^vitest\b/', 'npx --yes vitest', $normalized) ?? $normalized;

        return 'cd atlas-desktop && npm install --silent && '.$normalized;
    }

    /**
     * @return array{stdout_path:string,stderr_path:string}
     */
    private function writeProviderLogs(string $runId, string $arm, string $stdout, string $stderr, string $caseSubdir = ''): array
    {
        $paths = $this->paths->paths($runId);
        $dir = $this->artifactDir($paths['evidence'], $caseSubdir);
        @mkdir($dir, 0o755, true);
        $stdoutPath = $dir.'/'.$arm.'_provider_stdout.log';
        $stderrPath = $dir.'/'.$arm.'_provider_stderr.log';
        file_put_contents($stdoutPath, $stdout);
        file_put_contents($stderrPath, $stderr);

        return ['stdout_path' => $stdoutPath, 'stderr_path' => $stderrPath];
    }

    /**
     * Resolve the on-disk directory where this case's artifacts should land.
     * Empty caseSubdir keeps the v1 single-case layout (artifacts straight
     * under evidence/); a non-empty subdir nests them under
     * evidence/cases/<case_id>/ for multi-case release runs.
     */
    private function artifactDir(string $evidenceRoot, string $caseSubdir): string
    {
        if ($caseSubdir === '') {
            return $evidenceRoot;
        }

        return rtrim($evidenceRoot, '/').'/'.ltrim($caseSubdir, '/');
    }

    /**
     * @return array{path:string,sha256:string,bytes:int}
     */
    private function capturePatch(string $runId, string $arm, string $worktree, array $case = [], string $caseSubdir = ''): array
    {
        $paths = $this->paths->paths($runId);
        $dir = $this->artifactDir($paths['evidence'], $caseSubdir);
        @mkdir($dir, 0o755, true);
        $patchPath = $dir.'/'.$arm.'_patch.diff';

        $proc = new Process(['git', '-C', $worktree, 'diff', '--binary', '--']);
        $proc->setTimeout(60);
        $proc->run();
        file_put_contents($patchPath, (string) $proc->getOutput());

        $status = $this->workspaceStatusLines($worktree);
        $untracked = [];
        $fixtureHashes = $this->fixtureBaselineHashes($case);
        foreach ($status as $line) {
            if (str_starts_with($line, '?? ')) {
                $file = $this->statusPath($line);
                if ($this->isUnchangedFixtureFile($worktree, $file, $fixtureHashes)) {
                    continue;
                }
                if ($this->isPythonBytecode($file)) {
                    continue;
                }
                if ($this->shouldIgnoreGeneratedArtifact($file, $case)) {
                    continue;
                }
                $untracked[] = $file;
            }
        }
        foreach ($untracked as $file) {
            $this->appendUntrackedFilePatch($patchPath, $worktree, $file);
        }

        return [
            'path' => $patchPath,
            'sha256' => is_file($patchPath) ? (string) hash_file('sha256', $patchPath) : hash('sha256', ''),
            'bytes' => is_file($patchPath) ? (int) filesize($patchPath) : 0,
        ];
    }

    private function appendUntrackedFilePatch(string $patchPath, string $worktree, string $file): void
    {
        $path = $worktree.'/'.$file;
        if (! is_file($path)) {
            return;
        }

        $out = @fopen($patchPath, 'ab');
        $in = @fopen($path, 'rb');
        if (! is_resource($out) || ! is_resource($in)) {
            if (is_resource($out)) {
                fclose($out);
            }
            if (is_resource($in)) {
                fclose($in);
            }

            return;
        }

        fwrite($out, "\ndiff --git a/{$file} b/{$file}\nnew file mode 100644\n--- /dev/null\n+++ b/{$file}\n@@\n");
        while (($line = fgets($in)) !== false) {
            fwrite($out, '+'.rtrim($line, "\r\n")."\n");
        }
        fclose($in);
        fclose($out);
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array{
     *   changed_files:list<string>,
     *   out_of_scope_files:list<string>,
     *   bytecode_artifacts:list<string>,
     *   blockers:list<string>
     * }
     */
    private function scopeCheck(string $worktree, array $case): array
    {
        $allowed = $this->stringList($case['allowed_files'] ?? []);
        $expectedChanged = $this->expectedChangedScope($case);
        $fixtureHashes = $this->fixtureBaselineHashes($case);
        $changed = [];
        $outOfScope = [];
        $bytecode = [];
        $blockers = [];

        foreach ($this->workspaceStatusLines($worktree) as $line) {
            $file = $this->statusPath($line);
            if ($file === '') {
                continue;
            }
            if ($this->isUnchangedFixtureFile($worktree, $file, $fixtureHashes)) {
                continue;
            }
            if ($this->shouldIgnoreGeneratedArtifact($file, $case)) {
                continue;
            }
            $changed[] = $file;
            if ($this->isPythonBytecode($file)) {
                $bytecode[] = $file;
                $blockers[] = 'bytecode_artifact_after_run:'.$file;

                continue;
            }
            if (array_key_exists($file, $fixtureHashes) && ! $this->matchesAnyAllowedScope($file, $expectedChanged)) {
                $outOfScope[] = $file;
                $blockers[] = 'fixture_file_modified:'.$file;

                continue;
            }
            if (! $this->matchesAnyAllowedScope($file, $expectedChanged)) {
                $outOfScope[] = $file;
                $blockers[] = $allowed !== [] && $this->matchesAnyAllowedScope($file, $allowed)
                    ? 'unexpected_changed_file:'.$file
                    : 'out_of_scope_change:'.$file;
            }
        }

        return [
            'changed_files' => array_values(array_unique($changed)),
            'out_of_scope_files' => array_values(array_unique($outOfScope)),
            'bytecode_artifacts' => array_values(array_unique($bytecode)),
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return list<string>
     */
    private function expectedChangedScope(array $case): array
    {
        $expected = $this->stringList($case['expected_changed_files'] ?? []);
        if (($case['case_source'] ?? '') === 'provider_arena_corpus' && $expected !== []) {
            return $expected;
        }

        return $this->stringList($case['allowed_files'] ?? []);
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,string>
     */
    private function fixtureBaselineHashes(array $case): array
    {
        $hashes = is_array($case['_fixture_baseline_hashes'] ?? null)
            ? $case['_fixture_baseline_hashes']
            : [];

        $normalized = [];
        foreach ($hashes as $path => $hash) {
            $path = $this->normalizeWorkspacePath((string) $path);
            $hash = trim((string) $hash);
            if ($path !== '' && $hash !== '') {
                $normalized[$path] = $hash;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,string>  $fixtureHashes
     */
    private function isUnchangedFixtureFile(string $worktree, string $file, array $fixtureHashes): bool
    {
        $file = $this->normalizeWorkspacePath($file);
        if (! array_key_exists($file, $fixtureHashes)) {
            return false;
        }

        $path = $worktree.'/'.$file;
        if (! is_file($path)) {
            return false;
        }

        return hash_file('sha256', $path) === $fixtureHashes[$file];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array{command:string,exit_code:int,log_path:string,log_hash:string,tail:string}
     */
    private function runValidationCommand(string $runId, string $arm, string $worktree, array $case, string $caseSubdir = ''): array
    {
        $command = $this->testCommand($case);
        $paths = $this->paths->paths($runId);
        $dir = $this->artifactDir($paths['evidence'], $caseSubdir);
        @mkdir($dir, 0o755, true);
        $logPath = $dir.'/'.$arm.'_test.log';

        $proc = Process::fromShellCommandline($command, $worktree, $this->subprocessEnv(), null, 900);
        $proc->run();
        $log = (string) $proc->getOutput().(string) $proc->getErrorOutput();
        file_put_contents($logPath, $log);
        $this->events->event($runId, 'validation_finished', [
            'arm' => $arm,
            'command' => $command,
            'exit_code' => (int) $proc->getExitCode(),
            'log_tail' => substr($log, -500),
        ]);

        return [
            'command' => $command,
            'exit_code' => (int) ($proc->getExitCode() ?? -1),
            'log_path' => $logPath,
            'log_hash' => hash('sha256', $log),
            'tail' => substr($log, -2000),
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array{command:string,exit_code:int,log_path:string,log_hash:string,tail:string}
     */
    private function skippedValidationCommand(string $runId, string $arm, array $case, string $reason, string $caseSubdir = ''): array
    {
        $command = $this->testCommand($case);
        $paths = $this->paths->paths($runId);
        $dir = $this->artifactDir($paths['evidence'], $caseSubdir);
        @mkdir($dir, 0o755, true);
        $logPath = $dir.'/'.$arm.'_test.log';
        $log = "SKIPPED: provider timed out before validation could run.\nReason: {$reason}\nCommand: {$command}\n";
        file_put_contents($logPath, $log);
        $this->events->event($runId, 'validation_skipped', [
            'arm' => $arm,
            'command' => $command,
            'reason' => $reason,
        ]);

        return [
            'command' => $command,
            'exit_code' => -1,
            'log_path' => $logPath,
            'log_hash' => hash('sha256', $log),
            'tail' => $log,
        ];
    }

    /**
     * @return list<string>
     */
    private function workspaceStatusLines(string $workspace): array
    {
        if (! is_dir($workspace)) {
            return [];
        }
        $proc = new Process(['git', '-C', $workspace, 'status', '--porcelain', '-uall']);
        $proc->setTimeout(15);
        $proc->run();
        if (! $proc->isSuccessful()) {
            return [];
        }

        return array_values(array_filter(
            explode("\n", rtrim((string) $proc->getOutput(), "\n\r")),
            static fn (string $line): bool => trim($line) !== '',
        ));
    }

    private function statusPath(string $line): string
    {
        $path = trim(substr($line, 3));
        if (str_contains($path, ' -> ')) {
            $parts = explode(' -> ', $path);
            $path = trim((string) end($parts));
        }

        return trim($path, "\" \t\n\r\0\x0B");
    }

    /**
     * @param  list<string>  $allowed
     */
    private function matchesAnyAllowedScope(string $file, array $allowed): bool
    {
        foreach ($allowed as $pattern) {
            if ($this->globMatches($pattern, $file)) {
                return true;
            }
        }

        return false;
    }

    private function globMatches(string $pattern, string $file): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return false;
        }
        if (str_ends_with($pattern, '/')) {
            return str_starts_with($file, $pattern);
        }

        $quoted = preg_quote($pattern, '#');
        $quoted = str_replace('\*\*', '.*', $quoted);
        $quoted = str_replace('\*', '[^/]*', $quoted);

        return (bool) preg_match('#^'.$quoted.'$#', $file);
    }

    private function isPythonBytecode(string $file): bool
    {
        return str_ends_with($file, '.pyc')
            || str_ends_with($file, '.pyo')
            || str_contains($file, '__pycache__/');
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function shouldIgnoreGeneratedArtifact(string $file, array $case): bool
    {
        $file = $this->normalizeWorkspacePath($file);
        if ($file === '') {
            return false;
        }
        if ($this->isPythonBytecode($file)) {
            return false;
        }

        $expected = $this->stringList($case['expected_changed_files'] ?? []);
        if ($expected !== [] && $this->matchesAnyAllowedScope($file, $expected)) {
            return false;
        }

        return $this->isGeneratedArtifactPath($file);
    }

    private function isGeneratedArtifactPath(string $file): bool
    {
        $file = $this->normalizeWorkspacePath($file);
        $segments = array_values(array_filter(explode('/', $file), static fn (string $part): bool => $part !== ''));
        foreach ($segments as $segment) {
            if (in_array($segment, self::GENERATED_ARTIFACT_PATH_SEGMENTS, true)) {
                return true;
            }
        }

        foreach (self::GENERATED_ARTIFACT_PATH_SUFFIXES as $suffix) {
            if (str_ends_with($file, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function armGateScore(array $receipt): array
    {
        return [
            'provider_exit_zero' => (int) ($receipt['exit_code'] ?? -1) === 0,
            'tests_passed' => (int) ($receipt['test_exit_code'] ?? -1) === 0,
            'patch_diff_present' => (int) ($receipt['patch_diff_bytes'] ?? 0) > 0,
            'out_of_scope_files' => $this->stringList($receipt['out_of_scope_files'] ?? []),
            'bytecode_artifacts' => $this->stringList($receipt['bytecode_artifacts'] ?? []),
        ];
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

        return array_values(array_map(static fn ($item): string => (string) $item, $value));
    }

    /**
     * @return array<string,string>
     */
    private function subprocessEnv(): array
    {
        $env = $_SERVER ?: [];
        $env['PYTHONDONTWRITEBYTECODE'] = '1';
        $env['ATLAS_FORGE_RIVALS_V2'] = '1';

        // Filter to string-only values (Process expects array<string,string>)
        $out = [];
        foreach ($env as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v) || is_bool($v))) {
                $out[$k] = (string) $v;
            }
        }

        return $out;
    }

    private function providerTimeoutSeconds(): int
    {
        $value = (int) (getenv('ATLAS_FORGE_RIVALS_PROVIDER_TIMEOUT_SECONDS') ?: 0);

        return $value > 0 ? max(5, $value) : self::DEFAULT_PROVIDER_TIMEOUT_SECONDS;
    }

    private function hardKillSeconds(): int
    {
        $value = (int) (getenv('ATLAS_FORGE_RIVALS_HARD_KILL_SECONDS') ?: 0);

        return $value > 0 ? max(10, $value) : self::DEFAULT_HARD_KILL_SECONDS;
    }

    private function workspaceHash(string $workspace): ?string
    {
        if (! is_dir($workspace)) {
            return null;
        }
        $proc = new Process(['git', '-C', $workspace, 'status', '--porcelain', '-uall']);
        $proc->setTimeout(15);
        $proc->run();
        if (! $proc->isSuccessful()) {
            return null;
        }

        return hash('sha256', (string) $proc->getOutput());
    }

    /**
     * @return array{dirty:bool,count:int,sample:list<string>}
     */
    private function workspaceDirty(string $workspace): array
    {
        if (! is_dir($workspace)) {
            return ['dirty' => false, 'count' => 0, 'sample' => []];
        }
        $proc = new Process(['git', '-C', $workspace, 'status', '--porcelain', '-uall']);
        $proc->setTimeout(15);
        $proc->run();
        if (! $proc->isSuccessful()) {
            return ['dirty' => false, 'count' => 0, 'sample' => []];
        }
        $lines = array_values(array_filter(explode("\n", trim((string) $proc->getOutput())), static fn (string $l) => trim($l) !== ''));

        return ['dirty' => $lines !== [], 'count' => count($lines), 'sample' => array_slice($lines, 0, 10)];
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function blocked(array $blockers, string $hint): array
    {
        return [
            'status' => 'blocked',
            'blockers' => $blockers,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'next_command' => $hint,
        ];
    }

    /**
     * @return list<string>
     */
    private function driverAvailabilityBlockers(string $atlasModel, string $rivalModel): array
    {
        $blockers = [];
        $atlasUsesClaude = in_array($atlasModel, [
            AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_SONNET,
            AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_OPUS,
            AtlasForgeRivalsModelMatrix::MODEL_AUTO,
        ], true);
        $rivalUsesClaude = in_array($rivalModel, [
            AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_SONNET,
            AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_OPUS,
            AtlasForgeRivalsModelMatrix::MODEL_AUTO,
        ], true);

        if (($atlasUsesClaude || $rivalUsesClaude) && ! $this->binaryAvailable('claude')) {
            $blockers[] = 'rival_driver_not_configured:claude';
        }
        if (($atlasModel === AtlasForgeRivalsModelMatrix::MODEL_CODEX
            || $rivalModel === AtlasForgeRivalsModelMatrix::MODEL_CODEX)
            && ! $this->binaryAvailable('codex')
        ) {
            $blockers[] = 'rival_driver_not_configured:codex';
        }

        return $blockers;
    }

    private function binaryAvailable(string $binary): bool
    {
        try {
            $proc = new Process(['which', $binary]);
            $proc->setTimeout(5);
            $proc->run();

            return $proc->isSuccessful() && trim((string) $proc->getOutput()) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resolve the canonical per-case evidence directory at top-level
     * `runs/<run_id>/cases/<safe>/evidence/`. The runner uses this as the
     * stable contract for downstream consumers (BatteryReportService v3+).
     *
     * The legacy per-case dir under `runs/<run_id>/evidence/cases/<safe>/`
     * keeps existing receipts so collect-evidence/replay remain on the same
     * contract surface. Both paths live side-by-side.
     *
     * @param  array<string,mixed>  $paths
     */
    private function canonicalCaseEvidenceDir(array $paths, string $caseId): string
    {
        return rtrim((string) $paths['base'], '/').'/cases/'.$this->safeCaseDir($caseId).'/evidence';
    }

    /**
     * Persist the canonical per-case evidence triplet (manifest, scorecard,
     * report.md) at `runs/<run_id>/cases/<safe>/evidence/`. Local-fake mode
     * intentionally produces null scores — no synthetic quality dimensions
     * are emitted. The per-case scorecard records the verdict, the test
     * exit codes and the clean/dirty workspace gates so downstream tooling
     * can audit each case without re-running the runner.
     *
     * @param  array<string,mixed>  $paths
     * @param  array<string,mixed>  $caseEntry  the per-case entry built in run()
     * @param  array<string,mixed>  $context  run-level context (mode, models, preset, run_id)
     */
    private function writeCanonicalCaseEvidence(array $paths, array $caseEntry, array $context): void
    {
        $caseId = (string) ($caseEntry['case_id'] ?? '');
        if ($caseId === '') {
            return;
        }
        $dir = $this->canonicalCaseEvidenceDir($paths, $caseId);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }

        $atlasReceipt = is_array($caseEntry['atlas_receipt'] ?? null) ? $caseEntry['atlas_receipt'] : [];
        $rivalReceipt = is_array($caseEntry['rival_receipt'] ?? null) ? $caseEntry['rival_receipt'] : [];
        $verdict = (string) ($caseEntry['verdict'] ?? 'unknown');
        $workspaceBlockers = $this->stringList($caseEntry['workspace_blockers'] ?? []);
        $armContractBlockers = $this->stringList($caseEntry['arm_contract_blockers'] ?? []);
        $fixtureBlockers = $this->stringList($caseEntry['fixture_blockers'] ?? []);
        $caseDirty = $workspaceBlockers !== [];
        $startedAt = (string) ($atlasReceipt['started_at'] ?? '');
        $finishedAt = (string) ($rivalReceipt['finished_at'] ?? $atlasReceipt['finished_at'] ?? '');
        $durationMs = $this->durationMs($startedAt, $finishedAt);
        $atlasExit = (int) ($atlasReceipt['exit_code'] ?? -1);
        $rivalExit = (int) ($rivalReceipt['exit_code'] ?? -1);
        $atlasTestExit = (int) ($atlasReceipt['test_exit_code'] ?? -1);
        $rivalTestExit = (int) ($rivalReceipt['test_exit_code'] ?? -1);
        $atlasPatchBytes = (int) ($atlasReceipt['patch_diff_bytes'] ?? 0);
        $rivalPatchBytes = (int) ($rivalReceipt['patch_diff_bytes'] ?? 0);
        $atlasReceiptHash = $atlasReceipt === [] ? null : hash('sha256', $this->jsonEncode($atlasReceipt));
        $rivalReceiptHash = $rivalReceipt === [] ? null : hash('sha256', $this->jsonEncode($rivalReceipt));

        $caseManifest = [
            'schema_version' => 'atlas.forge.rivals.case_manifest.v1',
            'run_id' => (string) ($context['run_id'] ?? ''),
            'case_id' => $caseId,
            'case_index' => (int) ($caseEntry['case_index'] ?? 0),
            'case_source' => $caseEntry['case_source'] ?? null,
            'case_set' => $caseEntry['case_set'] ?? null,
            'task_category' => $caseEntry['task_category'] ?? null,
            'category' => $caseEntry['category'] ?? ($caseEntry['task_category'] ?? null),
            'difficulty' => $caseEntry['difficulty'] ?? null,
            'difficulty_level' => $caseEntry['difficulty_level'] ?? null,
            'difficulty_weight' => $caseEntry['difficulty_weight'] ?? null,
            'mode' => $context['mode'] ?? null,
            'prompt_mode' => $context['prompt_mode'] ?? ($caseEntry['prompt_mode'] ?? null),
            'atlas_model' => $context['atlas_model'] ?? null,
            'rival_model' => $context['rival_model'] ?? null,
            'preset' => $context['preset'] ?? null,
            'verdict' => $verdict,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
            'dirty_after_run' => $caseDirty,
            'workspace_blockers' => $workspaceBlockers,
            'arm_contract_violation' => $armContractBlockers !== [],
            'arm_contract_blockers' => $armContractBlockers,
            'fixture_blockers' => $fixtureBlockers,
            'workspace_hash_before' => $caseEntry['workspace_hash_before'] ?? null,
            'workspace_hash_after' => $caseEntry['workspace_hash_after'] ?? null,
            'workspace_fingerprint_before' => $this->workspaceFingerprint((array) ($caseEntry['workspace_hash_before'] ?? [])),
            'workspace_fingerprint_after' => $this->workspaceFingerprint((array) ($caseEntry['workspace_hash_after'] ?? [])),
            'atlas_receipt_hash' => $atlasReceiptHash,
            'rival_receipt_hash' => $rivalReceiptHash,
            'fixture_stage' => $caseEntry['fixture_stage'] ?? null,
            'separated_from_external_rivals_certification' => true,
        ];
        @file_put_contents($dir.'/manifest.json', $this->jsonEncode($caseManifest));

        $atlasPassed = $atlasExit === 0 && $atlasTestExit === 0;
        $rivalPassed = $rivalExit === 0 && $rivalTestExit === 0;
        $hardGates = [
            ['code' => 'verdict_comparable', 'ok' => $verdict === 'comparable', 'detail' => 'verdict='.$verdict],
            ['code' => 'tests_passed_atlas', 'ok' => $atlasPassed, 'detail' => 'atlas exit='.$atlasExit.' test_exit='.$atlasTestExit],
            ['code' => 'tests_passed_rival', 'ok' => $rivalPassed, 'detail' => 'rival exit='.$rivalExit.' test_exit='.$rivalTestExit],
            ['code' => 'no_dirty_after_run', 'ok' => ! $caseDirty, 'detail' => $caseDirty ? 'dirty:'.implode(',', $workspaceBlockers) : 'clean'],
            ['code' => 'no_arm_contract_violation', 'ok' => $armContractBlockers === [], 'detail' => $armContractBlockers === [] ? 'ok' : implode(',', $armContractBlockers)],
            ['code' => 'atlas_patch_present', 'ok' => $atlasPatchBytes > 0, 'detail' => 'atlas_patch_bytes='.$atlasPatchBytes],
            ['code' => 'rival_patch_present', 'ok' => $rivalPatchBytes > 0, 'detail' => 'rival_patch_bytes='.$rivalPatchBytes],
        ];
        $hardFailures = array_values(array_map(
            static fn (array $g): string => (string) $g['code'],
            array_filter($hardGates, static fn (array $g): bool => ($g['ok'] ?? false) === false),
        ));

        $caseScorecard = [
            'schema_version' => 'atlas.forge.rivals.case_scorecard.v1',
            'run_id' => (string) ($context['run_id'] ?? ''),
            'case_id' => $caseId,
            'generated_at' => $this->nowIso(),
            // local_fake produces no synthetic quality score — winner is null
            // and atlas_score/rival_score remain null until a per-case quality
            // adjudication is wired. The BatteryReport then surfaces the case
            // as valid_for_ranking=false (honest).
            'winner' => null,
            'winner_reason' => $hardFailures === []
                ? ['gate_outcome_only', 'mode:'.(string) ($context['mode'] ?? '')]
                : ['hard_failures:'.implode(',', $hardFailures)],
            'atlas_score' => null,
            'rival_score' => null,
            'score_source' => $hardFailures === []
                ? ('gate_outcome_'.(string) ($context['mode'] ?? 'unknown'))
                : 'hard_fail',
            'score_explanation' => 'Per-case scorecard recorded by run-real. Quality dimensions are emitted by the central adjudicator on the aggregate run, not per case. No synthetic per-case score is admitted.',
            'tie_threshold' => AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD,
            'hard_gates' => $hardGates,
            'hard_failures' => $hardFailures,
            'quality_dimensions' => null,
            'replay_passes' => null,
            'verdict' => $verdict,
            'workspace_blockers' => $workspaceBlockers,
            'arm_contract_blockers' => $armContractBlockers,
            'dirty_after_run' => $caseDirty,
            'claim_ready' => false,
            'human_review_required' => $hardFailures === [],
            'separated_from_external_rivals_certification' => true,
            'synthetic_score_admitted' => false,
            'note' => 'Per-case scorecard is descriptive. It never unlocks external_rivals_certification and never substitutes the aggregate scorecard.',
        ];
        @file_put_contents($dir.'/scorecard.json', $this->jsonEncode($caseScorecard));

        $reportLines = [];
        $reportLines[] = '# Atlas Forge Rivals · Case Report';
        $reportLines[] = '';
        $reportLines[] = '> Case: `'.$caseId.'` · Category: `'.($caseManifest['category'] ?? '—').'` · Difficulty: `'.($caseManifest['difficulty_level'] ?? '—').'`';
        $reportLines[] = '> Run: `'.($caseManifest['run_id'] ?? '—').'` · Mode: `'.($caseManifest['mode'] ?? '—').'` · Verdict: `'.$verdict.'`';
        $reportLines[] = '> Started: '.($startedAt !== '' ? $startedAt : '—').' · Finished: '.($finishedAt !== '' ? $finishedAt : '—').' · Duration: '.$durationMs.'ms';
        $reportLines[] = '';
        $reportLines[] = '## Hard gates';
        $reportLines[] = '';
        $reportLines[] = '| Code | Ok | Detail |';
        $reportLines[] = '| --- | --- | --- |';
        foreach ($hardGates as $g) {
            $reportLines[] = '| `'.$g['code'].'` | '.(($g['ok'] ?? false) ? '✓' : '✗').' | '.$g['detail'].' |';
        }
        if ($hardFailures !== []) {
            $reportLines[] = '';
            $reportLines[] = '**Hard failures:** `'.implode('`, `', $hardFailures).'`';
        }
        $reportLines[] = '';
        $reportLines[] = '## Atlas arm';
        $reportLines[] = '';
        $reportLines[] = '- exit_code: `'.$atlasExit.'`';
        $reportLines[] = '- test_exit_code: `'.$atlasTestExit.'`';
        $reportLines[] = '- patch_diff_bytes: `'.$atlasPatchBytes.'`';
        $reportLines[] = '- killed: `'.((bool) ($atlasReceipt['killed'] ?? false) ? 'true' : 'false').'`';
        $reportLines[] = '- changed_files: '.count((array) ($atlasReceipt['changed_files'] ?? []));
        $reportLines[] = '- out_of_scope_files: '.count((array) ($atlasReceipt['out_of_scope_files'] ?? []));
        $reportLines[] = '- bytecode_artifacts: '.count((array) ($atlasReceipt['bytecode_artifacts'] ?? []));
        $reportLines[] = '';
        $reportLines[] = '## Rival arm';
        $reportLines[] = '';
        $reportLines[] = '- exit_code: `'.$rivalExit.'`';
        $reportLines[] = '- test_exit_code: `'.$rivalTestExit.'`';
        $reportLines[] = '- patch_diff_bytes: `'.$rivalPatchBytes.'`';
        $reportLines[] = '- killed: `'.((bool) ($rivalReceipt['killed'] ?? false) ? 'true' : 'false').'`';
        $reportLines[] = '- changed_files: '.count((array) ($rivalReceipt['changed_files'] ?? []));
        $reportLines[] = '- out_of_scope_files: '.count((array) ($rivalReceipt['out_of_scope_files'] ?? []));
        $reportLines[] = '- bytecode_artifacts: '.count((array) ($rivalReceipt['bytecode_artifacts'] ?? []));
        $reportLines[] = '';
        $reportLines[] = '## Workspace';
        $reportLines[] = '';
        $reportLines[] = '- dirty_after_run: `'.($caseDirty ? 'true' : 'false').'`';
        if ($workspaceBlockers !== []) {
            $reportLines[] = '- workspace_blockers: `'.implode('`, `', $workspaceBlockers).'`';
        }
        if ($armContractBlockers !== []) {
            $reportLines[] = '- arm_contract_blockers: `'.implode('`, `', $armContractBlockers).'`';
        }
        if ($fixtureBlockers !== []) {
            $reportLines[] = '- fixture_blockers: `'.implode('`, `', $fixtureBlockers).'`';
        }
        $reportLines[] = '- fingerprint_before: `'.($caseManifest['workspace_fingerprint_before'] ?? '—').'`';
        $reportLines[] = '- fingerprint_after: `'.($caseManifest['workspace_fingerprint_after'] ?? '—').'`';
        $reportLines[] = '';
        $reportLines[] = '## Cláusula';
        $reportLines[] = '';
        $reportLines[] = '- Per-case scorecard é descritivo. Nunca destrava `external_rivals_certification`.';
        $reportLines[] = '- `synthetic_score_admitted=false` — winner global é tarefa do BatteryReport v3.';
        @file_put_contents($dir.'/report.md', implode("\n", $reportLines)."\n");
    }

    /**
     * Single-sha256 fingerprint of a workspace_hash dict (atlas+rival).
     * Returns null when the input is empty so the manifest stays honest.
     *
     * @param  array<string,mixed>  $hashDict
     */
    private function workspaceFingerprint(array $hashDict): ?string
    {
        if ($hashDict === []) {
            return null;
        }

        return hash('sha256', $this->jsonEncode($hashDict));
    }

    /**
     * Duration between two ISO-8601 timestamps in milliseconds. Returns 0
     * when either side is missing or unparseable so the manifest never
     * emits a NaN or negative value.
     */
    private function durationMs(string $startedAt, string $finishedAt): int
    {
        if ($startedAt === '' || $finishedAt === '') {
            return 0;
        }
        $start = strtotime($startedAt);
        $end = strtotime($finishedAt);
        if ($start === false || $end === false || $end < $start) {
            return 0;
        }

        return (int) (($end - $start) * 1000);
    }

    /**
     * Per-state counters from a per-case entry list. Honest about state
     * transitions: comparable=passed, invalid_*=invalid, blocked=blocked,
     * skipped=skipped; anything else collapses to `other`. The caller
     * surfaces these directly in the aggregate manifest.
     *
     * @param  list<array<string,mixed>>  $perCase
     * @return array{total:int,comparable:int,invalid:int,blocked:int,skipped:int,other:int}
     */
    private function perCaseStateCounters(array $perCase): array
    {
        $counters = ['total' => 0, 'comparable' => 0, 'invalid' => 0, 'blocked' => 0, 'skipped' => 0, 'other' => 0];
        foreach ($perCase as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $counters['total']++;
            $verdict = (string) ($entry['verdict'] ?? '');
            if ($verdict === 'comparable') {
                $counters['comparable']++;
            } elseif (str_starts_with($verdict, 'invalid')) {
                $counters['invalid']++;
            } elseif ($verdict === 'blocked') {
                $counters['blocked']++;
            } elseif ($verdict === 'skipped') {
                $counters['skipped']++;
            } else {
                $counters['other']++;
            }
        }

        return $counters;
    }

    private function jsonEncode(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if (is_string($json) && $json !== '') {
            return $json;
        }

        return json_encode([
            'schema_version' => 'atlas.forge.rivals.json_encode_failure.v1',
            'error' => json_last_error_msg(),
            'encoded_at' => $this->nowIso(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function nowIso(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
