<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev\Support\PipelineRun;

use App\Http\Controllers\AtlasDev\Support\RunExecutionResult;
use App\Models\AiJob;
use App\Services\Ai\Aemor\AtlasEngineeringOutcomeRecorder;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\Concerns\RunsCliProcesses;
use App\Services\Ai\Context\AtlasCanonicalContextRef;
use App\Services\Ai\Context\AtlasContextRuntime;
use App\Services\Ai\Context\AtlasDeliveredPackLedger;
use App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasDevGateAdapter;
use App\Services\Ai\EngineeringKernel\RegressionLock\RegressionLockLedger;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\Governance\GovernanceConsultSkipCounter;
use App\Services\Ai\Governance\ProviderGovernanceConsult;
use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use App\Services\Ai\HermesCliProvider;
use App\Services\Ai\Programming\AtlasDev\Differential\CandidateDivergenceGate;
use App\Services\Ai\Programming\AtlasDev\Differential\DifferentialTestingService;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\PhpSubprocessShadowDiffHarness;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffGate;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffHarness;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffService;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\CompletionDecision;
use App\Services\Ai\Programming\AtlasDev\Gate\CompletionStateGate;
use App\Services\Ai\Programming\AtlasDev\Gate\DevWeakOutputDetector;
use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplier;
use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplyResult;
use App\Services\Ai\Programming\AtlasDev\Gate\ReceiptComposer;
use App\Services\Ai\Programming\AtlasDev\Gate\ReceiptStorageAdapter;
use App\Services\Ai\Programming\AtlasDev\Gate\ScopeGuard;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGate;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Gate\WorktreeBaseline;
use App\Services\Ai\Programming\AtlasDev\Intelligence\PatchIntelligenceInput;
use App\Services\Ai\Programming\AtlasDev\Intelligence\PatchIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Intelligence\ReviewIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Intelligence\TestSelectionInput;
use App\Services\Ai\Programming\AtlasDev\Intelligence\TestSelectionIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\MinimaxFirst\AtlasMinimaxFirstWorkerService;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScoreGate;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScoreVerdict;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingAdapter;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingResult;
use App\Services\Ai\Programming\AtlasDev\Mutation\SymfonyMutationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Probe\IntentCoverageProbe;
use App\Services\Ai\Programming\AtlasDev\Probe\IntentFalsificationProbe;
use App\Services\Ai\Programming\AtlasDev\Probe\SpecConstitutionVerdict;
use App\Services\Ai\Programming\AtlasDev\Probe\SpecDrivenConstitutionGate;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParser;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Provider\SonnetClaudeCliAdapter;
use App\Services\Ai\Programming\AtlasDev\Regression\CallerTestSelectionService;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineCache;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineGate;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineService;
use App\Services\Ai\Programming\AtlasDev\Regression\VerificationRegressionBaselineRunner;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureCapsuleBuilder;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureSignatureHasher;
use App\Services\Ai\Programming\AtlasDev\Repair\RepairPromptComposer;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevFailureCapsuleRuntimeService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevTaskPacketRuntimeService;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GateOutcome;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopePreExistingChange;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use App\Services\Ai\Programming\AtlasDev\Support\WorkspaceOriginIdentity;
use App\Services\Ai\Programming\AtlasDev\WorkspaceMutatingProviders;
use App\Services\Ai\Programming\AtlasForgeCodexCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeCursorCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeMinimaxM27CliInvocationDriver;
use App\Services\Ai\Programming\HermesWorkspaceDefaults;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\AwisExecutionGatePort;
use Illuminate\Contracts\Container\BindingResolutionException;
use Throwable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Container resolution + elevation config / intelligence service resolvers.
 *
 * Extracted verbatim from PipelineRunExecutor (godfile split, GOD-DEBULK
 * 2026-07-22). Behavior unchanged; cross-family calls route through the
 * sibling sections injected below.
 */
final class ResolverSupport
{
    public function __construct(
        private readonly Container $container,
        private readonly WorkspaceGitSupport $workspaceGit,
    ) {}

    public function resolve(string $abstract): ?object
    {
        if (! $this->container->bound($abstract)) {
            return null;
        }

        try {
            $resolved = $this->container->make($abstract);
        } catch (BindingResolutionException) {
            return null;
        }

        return is_object($resolved) ? $resolved : null;
    }

    public function resolveConcrete(string $abstract): ?object
    {
        try {
            $resolved = $this->container->make($abstract);
        } catch (BindingResolutionException) {
            return null;
        }

        return is_object($resolved) ? $resolved : null;
    }

    /**
     * E2: resolve the e2 elevation config. Reads the live config kernel when
     * available (feature tests / production); otherwise degrades to the safe
     * default (advisory) so plain-PHPunit unit tests never crash. Mirrors
     * the resolution pattern used by SpecComposer and PromptSectionsMapper.
     */
    /**
     * Route a TRIPPED elevation verdict through the two sanctioned channels
     * (no third way, no silent green): hard => rebuild the gate result at
     * STATUS_FAILED preserving the gathered tests/gates with the flag(s)
     * retained for auditability; advisory => append the honesty flag(s) only
     * (the CompletionStateGate downgrades PASSED -> needs_review via the
     * passed-forbids-flags invariant). Callers invoke this ONLY when the
     * verdict actually tripped and the config is not off — off stays a
     * byte-identical no-op upstream. This is the single implementation of
     * the rebuild pattern E1-E6 + W1 previously each copied inline.
     *
     * @param  list<string>  $flags
     */
    public function routeElevationVerdict(
        VerificationGateResult $verificationResult,
        ElevationConfig $config,
        array $flags,
    ): VerificationGateResult {
        if ($config->isHard()) {
            return new VerificationGateResult(
                tests: $verificationResult->tests,
                gates: $verificationResult->gates,
                aggregateStatus: VerificationGateResult::STATUS_FAILED,
                honestyFlags: $verificationResult->withHonestyFlags($flags)->honestyFlags,
                evidenceRefs: $verificationResult->evidenceRefs,
                profile: $verificationResult->profile,
            );
        }

        return $verificationResult->withHonestyFlags($flags);
    }

    /**
     * E1: resolve the e1 elevation config. Same resolution pattern as E2:
     * reads the live config kernel when available, otherwise degrades to the
     * safe default (advisory) so plain-PHPunit unit tests never crash.
     */
    public function resolveE1Config(): ElevationConfig
    {
        try {
            return ElevationConfig::fromConfig('e1');
        } catch (\Throwable) {
            return ElevationConfig::for('e1', null);
        }
    }

    public function resolveE2Config(): ElevationConfig
    {
        try {
            return ElevationConfig::fromConfig('e2');
        } catch (\Throwable) {
            return ElevationConfig::for('e2', null);
        }
    }

    /**
     * E3: resolve the e3 elevation config. Same resolution pattern as E1/E2:
     * reads the live config kernel when available, otherwise degrades to the
     * safe default (advisory) so plain-PHPunit unit tests never crash.
     */
    public function resolveE3Config(): ElevationConfig
    {
        try {
            return ElevationConfig::fromConfig('e3');
        } catch (\Throwable) {
            return ElevationConfig::for('e3', null);
        }
    }

    /**
     * E4: resolve the e4 elevation config. Same resolution pattern as
     * E1/E2/E3/E5: reads the live config kernel when available, otherwise
     * degrades to the safe default (advisory) so plain-PHPunit unit tests
     * never crash.
     */
    public function resolveE4Config(): ElevationConfig
    {
        try {
            return ElevationConfig::fromConfig('e4');
        } catch (\Throwable) {
            return ElevationConfig::for('e4', null);
        }
    }

    /**
     * E5: resolve the e5 elevation config. Same resolution pattern as E1/E2/E3:
     * reads the live config kernel when available, otherwise degrades to the
     * safe default (advisory) so plain-PHPunit unit tests never crash.
     */
    public function resolveE5Config(): ElevationConfig
    {
        try {
            return ElevationConfig::fromConfig('e5');
        } catch (\Throwable) {
            return ElevationConfig::for('e5', null);
        }
    }

    /**
     * E6: resolve the e6 elevation config. Same resolution pattern as
     * E1/E2/E3/E4/E5: reads the live config kernel when available, otherwise
     * degrades to the safe default (advisory) so plain-PHPunit unit tests
     * never crash.
     */
    public function resolveE6Config(): ElevationConfig
    {
        try {
            return ElevationConfig::fromConfig('e6');
        } catch (\Throwable) {
            return ElevationConfig::for('e6', null);
        }
    }

    /**
     * W1: resolve the weak_output elevation config. Same resolution pattern
     * as E1/E2/E3: reads the live config kernel when available, otherwise
     * degrades to the safe default (advisory) so plain-PHPunit unit tests
     * never crash.
     */
    public function resolveWeakOutputConfig(): ElevationConfig
    {
        try {
            return ElevationConfig::fromConfig('weak_output');
        } catch (\Throwable) {
            return ElevationConfig::for('weak_output', null);
        }
    }

    /**
     * E6: distill the verification commands the run actually executed AND
     * passed. Returns the list of TestRun.command strings where ok===true.
     * This is the honest executed-evidence source for the E6 behavioral AC
     * verification_ref satisfaction check (VAL-M2-021): a behavioral AC's
     * declared verification_ref must be among these commands for the
     * criterion's obligation to be considered satisfied.
     *
     * @param  VerificationGateResult  $result  the verification gate result
     *                                          carrying the executed TestRun
     *                                          list.
     * @return list<string>
     */
    public function satisfiedVerificationRefs(VerificationGateResult $result): array
    {
        $refs = [];
        foreach ($result->tests as $test) {
            if ($test->ok) {
                $refs[] = $test->command;
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * E6: resolve the DifferentialTestingService the candidate-divergence
     * gate consumes.
     *
     * Bound through the container via `atlas_dev.e4.differential_testing_service`
     * so tests inject a fake {@see DifferentialTestingService} if needed. The
     * binding is OPTIONAL: when unbound, a fresh instance is returned (the
     * service has no constructor dependencies and is a pure comparison). This
     * mirrors the `atlas_dev.e5.caller_test_selection_service` pattern.
     */
    public function resolveDifferentialTestingService(): DifferentialTestingService
    {
        if ($this->container->bound('atlas_dev.e4.differential_testing_service')) {
            $bound = $this->container->make('atlas_dev.e4.differential_testing_service');
            if ($bound instanceof DifferentialTestingService) {
                return $bound;
            }
        }

        return new DifferentialTestingService;
    }

    /**
     * E4: resolve the ShadowDiffService the shadow-diff gate consumes.
     *
     * Bound through the container via `atlas_dev.e4.shadow_diff_service`
     * so tests inject a fake {@see ShadowDiffService} (with a fake
     * {@see ShadowDiffHarness}) without spawning real PHP subprocesses.
     *
     * The binding is OPTIONAL. When unbound, this method resolves the
     * {@see ShadowDiffHarness} via the separate `atlas_dev.e4.shadow_diff_harness`
     * binding (also optional; defaults to {@see PhpSubprocessShadowDiffHarness})
     * and constructs a fresh ShadowDiffService with it. Returning null is
     * reserved for environments where neither the service nor the harness can
     * be constructed; in that case the post-gate block skips shadow-diff
     * entirely (E4 degrades to off for that run). This mirrors the
     * `atlas_dev.e5.regression_baseline_service` pattern so the frozen M1-M5
     * tests (which pre-date E4 shadow-diff) stay byte-identical.
     */
    public function resolveShadowDiffService(): ?ShadowDiffService
    {
        if ($this->container->bound('atlas_dev.e4.shadow_diff_service')) {
            $bound = $this->container->make('atlas_dev.e4.shadow_diff_service');
            if ($bound instanceof ShadowDiffService) {
                return $bound;
            }
        }

        try {
            $harness = $this->resolveShadowDiffHarness();
        } catch (\Throwable) {
            return null;
        }

        return new ShadowDiffService($harness);
    }

    /**
     * E4: resolve the ShadowDiffHarness the default ShadowDiffService uses.
     *
     * Bound through the container via `atlas_dev.e4.shadow_diff_harness` so
     * tests inject a fake harness that returns scripted outputs. When unbound,
     * a fresh {@see PhpSubprocessShadowDiffHarness} is returned (the production
     * default that executes old vs new in a sandboxed PHP subprocess).
     */
    public function resolveShadowDiffHarness(): ShadowDiffHarness
    {
        if ($this->container->bound('atlas_dev.e4.shadow_diff_harness')) {
            $bound = $this->container->make('atlas_dev.e4.shadow_diff_harness');
            if ($bound instanceof ShadowDiffHarness) {
                return $bound;
            }
        }

        return new PhpSubprocessShadowDiffHarness;
    }

    /**
     * E5: resolve the RegressionBaselineService the regression-baseline gate
     * consumes.
     *
     * Bound through the container via `atlas_dev.e5.regression_baseline_service`
     * so tests inject a fake {@see RegressionBaselineService} (with a fake
     * RegressionBaselineRunner) without ever spawning real test subprocesses
     * during baseline capture. The binding is OPTIONAL: when unbound, this
     * returns null and the executor skips the baseline capture entirely
     * (E5 degrades to off for that run). This guarantees the frozen M1-M5
     * tests (which pre-date E5 and bind their own fake verification command
     * runner) are byte-identical: the baseline service is only active when
     * explicitly bound, so it never consumes the frozen tests' queued command
     * results.
     *
     * Production deployments register the binding in a service provider,
     * wrapping the resolved verification command runner in a
     * VerificationRegressionBaselineRunner. The container binding convention
     * mirrors `atlas_dev.e3.mutation_adapter` and `atlas_dev.e1.intent_judge`.
     */
    public function resolveRegressionBaselineService(?VerificationCommandRunner $commandRunner = null): ?RegressionBaselineService
    {
        if ($this->container->bound('atlas_dev.e5.regression_baseline_service')) {
            $bound = $this->container->make('atlas_dev.e5.regression_baseline_service');
            if ($bound instanceof RegressionBaselineService) {
                return $bound;
            }
        }

        // Produção: nada binda o serviço no container (só testes bindam), o
        // que deixava E5 morto em runs vivos — baseline nunca capturado, gate
        // hard sem efeito e a testemunha red→green sempre falsa. Com o command
        // runner real em mãos, monta o wiring de produção documentado no
        // VerificationRegressionBaselineRunner.
        if ($commandRunner !== null) {
            return new RegressionBaselineService(new VerificationRegressionBaselineRunner($commandRunner));
        }

        return null;
    }

    /**
     * E5: resolve the CallerTestSelectionService the caller-test selection
     * feature consumes.
     *
     * Bound through the container via `atlas_dev.e5.caller_test_selection_service`
     * so tests inject a fake {@see CallerTestSelectionService} (or seed the
     * real Code Intelligence tables) without side effects. The binding is
     * OPTIONAL: when unbound, the executor uses a fresh
     * {@see CallerTestSelectionService} instance (the service has no
     * constructor dependencies and resolves the CodeGraph workspace identity
     * via `app(...)` at call time). This mirrors the
     * `atlas_dev.e3.mutation_adapter` / `atlas_dev.e1.intent_judge` pattern.
     *
     * VAL-E5-009: the service itself degrades safely when CI tables are
     * absent (DatabaseTableAvailability::has() guard), so a fresh instance
     * is always safe to call.
     */
    public function resolveCallerTestSelectionService(): CallerTestSelectionService
    {
        if ($this->container->bound('atlas_dev.e5.caller_test_selection_service')) {
            $bound = $this->container->make('atlas_dev.e5.caller_test_selection_service');
            if ($bound instanceof CallerTestSelectionService) {
                return $bound;
            }
        }

        return new CallerTestSelectionService;
    }

    /**
     * E5: resolve the `$codeGraph` payload (with `related_tests`) for the
     * verification gate's caller-test selection.
     *
     * VAL-E5-006/007/008: when E5 is enabled (not off), the service discovers
     * tests of direct callers of changed symbols via the CodeGraph read-model
     * and returns them as `$codeGraph['related_tests']`. The gate's floor
     * then merges them into `selected_existing_tests` through the
     * ProgrammingTestImpactAnalyzer (VAL-E5-008: through the analyzer, not a
     * side channel), widening the verification floor so a patch that breaks
     * a caller's test T_C runs T_C and surfaces the failure (VAL-E5-007).
     *
     * VAL-E5-009/VAL-E5-011/VAL-CROSS-010: when E5 is OFF, the codeGraph is
     * empty (byte-identical to pre-E5: no caller expansion, conventional
     * floor only). When the service degrades (CI tables absent), it returns
     * an empty `related_tests` list (no crash, conventional fallback).
     *
     * The codeGraph is resolved PER GATE RUN from the scopeReceipt's observed
     * changed files, so both the M2 repair loop and the best-of-N path
     * expand the floor for each candidate against its own diff.
     *
     * @return array<string,mixed>
     */
    public function resolveCallerTestCodeGraph(ScopeGuardReceipt $scopeReceipt, string $workspace): array
    {
        $e5Config = $this->resolveE5Config();
        if ($e5Config->isOff()) {
            // Byte-identical to pre-E5: no caller expansion.
            return [];
        }

        try {
            $changedFiles = array_map(
                static fn (ScopeFileDiff $diff): string => $diff->path,
                $scopeReceipt->observed->fileDiffs,
            );

            return $this->resolveCallerTestSelectionService()->resolveCodeGraph(
                changedFiles: $changedFiles,
                workspace: $workspace,
            );
        } catch (\Throwable) {
            // VAL-E5-009: safe degradation -- never crash the pipeline over
            // caller-test resolution. Empty codeGraph => conventional floor.
            return [];
        }
    }

    /**
     * E3: resolve the MutationTestingAdapter the mutation-score gate consumes.
     *
     * Bound through the container so tests inject a fake
     * {@see MutationTestingAdapter} (with a FakeMutationCommandRunner) without
     * ever spawning a real infection subprocess. When no binding exists the
     * production Symfony-process-backed runner is used with the repo root as
     * the workspace. The adapter is the SOLE caller of the scoped infection
     * invocation; the executor only feeds it the touched files.
     *
     * The container binding convention is `atlas_dev.e3.mutation_adapter`
     * (mirrors `atlas_dev.e1.intent_judge`). Resolved via
     * `$this->container->bound(...) ? make(...) : new ...` so the binding is
     * optional and degrades to a fresh adapter in production.
     */
    public function resolveMutationTestingAdapter(string $workspace = ''): MutationTestingAdapter
    {
        if ($this->container->bound('atlas_dev.e3.mutation_adapter')) {
            $bound = $this->container->make('atlas_dev.e3.mutation_adapter');
            if ($bound instanceof MutationTestingAdapter) {
                return $bound;
            }
        }

        // Infection roda no WORKSPACE do run (onde o diff vive), não no
        // base_path() do servidor: em worktree o E3 media o repo errado —
        // sem o patch — e falhava (mutation_run_failed em toda criação de
        // teste da matriz real 03/07). Fallback antigo só sem workspace.
        $repoRoot = $workspace !== '' && is_dir($workspace)
            ? rtrim($workspace, '/')
            : rtrim((string) ($this->workspaceGit->workspaceRoot() ?? base_path()), '/');

        return new MutationTestingAdapter(
            commandRunner: new SymfonyMutationCommandRunner,
            e3Config: $this->resolveE3Config(),
            repoRoot: $repoRoot,
        );
    }

    /**
     * E1: resolve the optional LLM-as-judge sub-layer options for the critic.
     *
     * The sub-flag lives at atlas_dev.elevations.e1.llm_judge (default OFF).
     * When ON, the judge callable is resolved from the container binding
     * `atlas_dev.e1.intent_judge` (if bound); tests bind a fake there. When
     * OFF or no judge is bound, the options enable nothing and the critic
     * behaves as the pure deterministic probe (byte-identical to pre-E1-judge).
     *
     * VAL-E1-011: the judge is strictly doubt-additive — even when enabled,
     * it can only add doubt/escalate; the critic's detectIntentFalsification()
     * ignores APPROVE/DOWNGRADE outcomes (the deterministic probe's verdict
     * is the immovable floor).
     *
     * @return array{llm_judge: bool, judge: ?callable}
     */
    public function resolveE1CriticOptions(): array
    {
        $enabled = false;
        try {
            $enabled = (bool) config('atlas_dev.elevations.e1.llm_judge', false);
        } catch (\Throwable) {
            $enabled = false;
        }

        $judge = null;
        if ($enabled) {
            try {
                $bound = $this->container->bound('atlas_dev.e1.intent_judge')
                    ? $this->container->make('atlas_dev.e1.intent_judge')
                    : null;
                $judge = is_callable($bound) ? $bound : null;
            } catch (\Throwable) {
                $judge = null;
            }
        }

        return [
            'llm_judge' => $enabled,
            'judge' => $judge,
        ];
    }
}
