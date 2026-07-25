<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextBudget;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\VerificationPlan;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use App\Services\Ai\Programming\AtlasDev\Support\SpecComposerBuildersSupport;
use App\Services\Ai\Programming\HermesWorkspaceDefaults;
use InvalidArgumentException;

/**
 * Deterministic composer for the Atlas Dev plan-layer artifacts.
 *
 * Produces CompactSdd, MiniProgrammingSpec and LightTaskContract from the
 * other typed services. No model is consulted. No filesystem call outside
 * the workspace-relative path that came in via discovery.
 *
 * "Compact" must not mean "shallow": for any task that implies write, the
 * resulting MiniProgrammingSpec carries acceptance criteria, non-goals,
 * allowed/forbidden files, a concrete verification plan with commands, and
 * the LightTaskContract carries a repair policy proportional to the R-level.
 */
class SpecComposer
{
    public const PROFILE_PHP_LARAVEL = 'php_laravel';

    public const PROFILE_TS_REACT = 'ts_react';

    public const PROFILE_GENERIC_NO_TEST = 'generic_no_test';

    public const SCOPE_COMPACT = 'compact';

    public const SCOPE_STRUCTURAL = 'structural';

    public const MODE_READ_ONLY = 'read_only';

    public const MODE_PATCH = 'patch';

    public const MODE_REPAIR = 'repair';

    public const MODE_REVIEW = 'review';

    public const MODE_FRONTEND_VISUAL = 'frontend_visual';

    public const MODE_ESCALATE_PREVIEW = 'escalate_preview';

    /**
     * Budget tiers come from doc 15.2 (chars). Conservative midpoints chosen
     * deliberately so we don't over-burn context on a fast path.
     */
    private const BUDGET_BY_MODE = [
        self::MODE_READ_ONLY => 6000,
        self::MODE_REVIEW => 8000,
        self::MODE_PATCH => 12000,
        self::MODE_REPAIR => 12000,
        self::MODE_FRONTEND_VISUAL => 18000,
        self::MODE_ESCALATE_PREVIEW => 24000,
    ];

    private const MAX_FILES_BY_RISK = [
        RiskLevelScorer::R0 => 0,
        RiskLevelScorer::R1 => 1,
        RiskLevelScorer::R2 => 2,
        RiskLevelScorer::R3 => 5,
        RiskLevelScorer::R4 => 0,
        RiskLevelScorer::R5 => 0,
    ];

    private const MAX_REPAIR_ATTEMPTS_BY_RISK = [
        RiskLevelScorer::R0 => 0,
        RiskLevelScorer::R1 => 1,
        RiskLevelScorer::R2 => 1,
        RiskLevelScorer::R3 => 2,
        RiskLevelScorer::R4 => 0,
        RiskLevelScorer::R5 => 0,
    ];

    /**
     * The IntentActionExtractor owns the recognized action-verb vocabulary
     * (single source of truth, shared with the E1 intent-falsification probe
     * and the persisted LightTaskContract::intentVerbs field). Defaulted so
     * existing callers that `new SpecComposer` without args keep working.
     */
    public function __construct(
        private readonly IntentActionExtractor $intentActionExtractor = new IntentActionExtractor,
        private readonly DesignPathSelector $designPathSelector = new DesignPathSelector,
    ) {}

    public function composeCompactSdd(
        OperationEnvelope $envelope,
        TaskClassification $classification,
        string $riskLevel,
    ): CompactSdd {
        $this->assertRiskLevel($riskLevel);
        $mode = $this->resolveMode($envelope, $classification, $riskLevel);
        $scopeMode = $this->resolveScopeMode($classification, $riskLevel);
        $budgetChars = self::BUDGET_BY_MODE[$mode] ?? 8000;
        $maxRepair = $this->maxRepairAttemptsFor($envelope, $riskLevel);

        $contextBudget = new ContextBudget(
            maxChars: $budgetChars,
            maxDocs: $mode === self::MODE_READ_ONLY ? 3 : 5,
            maxCandidateFiles: $this->maxCandidates($classification, $riskLevel),
            maxPlanSteps: $mode === self::MODE_READ_ONLY ? 2 : 4,
            maxProviderCalls: $mode === self::MODE_ESCALATE_PREVIEW ? 0 : 1,
            maxRepairAttempts: $maxRepair,
        );

        $tiers = $this->resolveDocTiers($envelope, $classification, $riskLevel);
        $verificationProfile = $this->resolveProfile($envelope, $classification);
        $escalation = $this->resolveEscalationTriggers($classification, $riskLevel);

        $compact = new CompactSdd(
            runId: $envelope->runId,
            envelopeHash: $envelope->envelopeHash,
            intentRaw: $envelope->rawIntent,
            intentNormalized: $envelope->normalizedIntent,
            taskKind: $classification->taskKind,
            riskLevel: $riskLevel,
            scopeMode: $scopeMode,
            mode: $mode,
            contextBudget: $contextBudget,
            docTiersRequired: $tiers,
            verificationProfile: $verificationProfile,
            contextDigest: null,
            escalationTriggers: $escalation,
            miniSpecHash: null,
            taskContractHash: null,
            compactSddHash: '',
        );
        $hash = $compact->hash();

        return $this->rebuildCompactWithHash($compact, $hash);
    }

    public function composeMiniSpec(
        OperationEnvelope $envelope,
        CompactSdd $compactSdd,
        CodeDiscoveryManifest $discovery,
        OpenBrainProgrammingProjection $projection,
        ?ElevationConfig $e2Config = null,
    ): MiniProgrammingSpec {
        $e2 = $e2Config ?? $this->resolveE2Config();
        $allowedFiles = $this->allowedFilesFor($envelope, $compactSdd, $discovery);
        $forbidden = $this->forbiddenFilesFor($compactSdd, $discovery);
        $expectedFiles = $this->expectedFilesFor($discovery);
        $canonicalContext = $this->buildCanonicalContext($discovery, $projection);
        $canonicalContext[] = $this->buildDesignPathContextEntry($compactSdd, $discovery);
        usort($canonicalContext, static fn (array $a, array $b): int => strcmp($a['ref'], $b['ref']));
        $verificationCommands = $this->buildVerificationCommands($envelope, $compactSdd, $discovery);

        $acceptance = $this->buildAcceptanceCriteria($compactSdd, $verificationCommands, $expectedFiles, $envelope, $e2);
        $nonGoals = $this->buildNonGoals($compactSdd);
        $expectedBehavior = $this->buildExpectedBehavior($compactSdd, $expectedFiles);
        $assumptions = $this->buildAssumptions($compactSdd, $discovery);
        $completion = $this->buildCompletionCriteria($compactSdd, $verificationCommands);
        $rollback = $this->buildRollback($compactSdd, $expectedFiles);

        $verificationPlan = new VerificationPlan(
            profile: $compactSdd->verificationProfile,
            commands: $verificationCommands,
            noTestReason: $verificationCommands === [] && $compactSdd->verificationProfile === self::PROFILE_GENERIC_NO_TEST
                ? 'no_test_profile=generic_no_test (docs/config)'
                : null,
        );

        $compactHash = $compactSdd->compactSddHash !== '' ? $compactSdd->compactSddHash : $compactSdd->hash();

        $skeleton = new MiniProgrammingSpec(
            runId: $envelope->runId,
            compactSddHash: $compactHash,
            goal: $this->buildGoal($envelope, $compactSdd),
            nonGoals: $nonGoals,
            canonicalContext: $canonicalContext,
            expectedBehavior: $expectedBehavior,
            assumptions: $assumptions,
            expectedFiles: $expectedFiles,
            allowedFiles: $allowedFiles,
            forbiddenFiles: $forbidden,
            acceptanceCriteria: $acceptance,
            verificationPlan: $verificationPlan,
            rollbackOrContainment: $rollback,
            completionCriteria: $completion,
            miniSpecHash: '',
        );
        $hash = $skeleton->hash();

        return new MiniProgrammingSpec(
            runId: $envelope->runId,
            compactSddHash: $compactHash,
            goal: $skeleton->goal,
            nonGoals: $skeleton->nonGoals,
            canonicalContext: $skeleton->canonicalContext,
            expectedBehavior: $skeleton->expectedBehavior,
            assumptions: $skeleton->assumptions,
            expectedFiles: $skeleton->expectedFiles,
            allowedFiles: $skeleton->allowedFiles,
            forbiddenFiles: $skeleton->forbiddenFiles,
            acceptanceCriteria: $skeleton->acceptanceCriteria,
            verificationPlan: $skeleton->verificationPlan,
            rollbackOrContainment: $skeleton->rollbackOrContainment,
            completionCriteria: $skeleton->completionCriteria,
            miniSpecHash: $hash,
        );
    }

    public function composeTaskContract(
        OperationEnvelope $envelope,
        CompactSdd $compactSdd,
        MiniProgrammingSpec $miniSpec,
    ): LightTaskContract {
        $maxFiles = $this->maxFilesChangedFor($envelope, $compactSdd, $miniSpec);
        $maxRepair = $this->maxRepairAttemptsFor($envelope, $compactSdd->riskLevel);
        $writeAllowed = $this->writeAllowed($envelope, $compactSdd);

        $allowedTools = $writeAllowed
            ? ['read', 'write', 'grep', 'run_test']
            : ['read', 'grep'];

        $repairPolicy = new RepairPolicy(
            maxAttempts: $maxRepair,
            sameProvider: true,
            requiresFailedGateOutput: true,
            abortOnSameSignatureTwice: true,
        );

        $providerLock = $this->resolveProviderLock($envelope, $compactSdd->taskKind);

        // E2: intent_text sourced from the normalized intent. For write tasks
        // (the only path that mutates the workspace), never empty: fall back
        // to the raw intent when the normalized form is blank, then to a
        // stable placeholder so a write task always carries a non-empty
        // intent_text folded into the contract hash. Read-only / review /
        // escalate-preview tasks may carry an empty intent_text (no write
        // implies no intent to fold).
        $intentText = $this->resolveIntentText($envelope, $writeAllowed);

        // E1: extract the recognized action-verb set from the normalized
        // intent and PERSIST it on the contract (new field) so downstream
        // stages (E2 per-verb ACs, the E1 intent-falsification probe) share
        // one stable basis instead of each re-detecting and discarding (the
        // historical behavior). The verb set is extracted unconditionally
        // (not gated by e1.mode): it is structural data about the intent,
        // not elevation behavior. The elevation gates the PROBE that consumes
        // the persisted set; the set itself is always present so the contract
        // hash reflects the recognized intent shape (mirrors how E2
        // intent_text is enriched unconditionally). VAL-E1-001/002.
        $intentVerbs = $this->extractIntentVerbs($envelope->normalizedIntent);

        $miniSpecHash = $miniSpec->miniSpecHash !== '' ? $miniSpec->miniSpecHash : $miniSpec->hash();
        $skeleton = new LightTaskContract(
            runId: $envelope->runId,
            taskId: $this->deriveTaskId($envelope, $miniSpec),
            specHash: $miniSpecHash,
            allowedTools: $allowedTools,
            blockedActions: $this->buildBlockedActions($compactSdd),
            allowedFiles: $miniSpec->allowedFiles,
            watchedFiles: [],
            forbiddenFiles: $miniSpec->forbiddenFiles,
            maxFilesChanged: $maxFiles,
            validationCommands: $miniSpec->verificationPlan->commands,
            evidenceRequired: $this->buildEvidenceRequired($compactSdd),
            repairPolicy: $repairPolicy,
            escalationOn: $compactSdd->escalationTriggers,
            providerLock: $providerLock,
            taskContractHash: '',
            noTestReason: $miniSpec->verificationPlan->noTestReason,
            intentText: $intentText,
            intentVerbs: $intentVerbs,
        );
        $hash = $skeleton->hash();

        return new LightTaskContract(
            runId: $skeleton->runId,
            taskId: $skeleton->taskId,
            specHash: $skeleton->specHash,
            allowedTools: $skeleton->allowedTools,
            blockedActions: $skeleton->blockedActions,
            allowedFiles: $skeleton->allowedFiles,
            watchedFiles: $skeleton->watchedFiles,
            forbiddenFiles: $skeleton->forbiddenFiles,
            maxFilesChanged: $skeleton->maxFilesChanged,
            validationCommands: $skeleton->validationCommands,
            evidenceRequired: $skeleton->evidenceRequired,
            repairPolicy: $skeleton->repairPolicy,
            escalationOn: $skeleton->escalationOn,
            providerLock: $skeleton->providerLock,
            taskContractHash: $hash,
            noTestReason: $skeleton->noTestReason,
            intentText: $skeleton->intentText,
            intentVerbs: $skeleton->intentVerbs,
        );
    }

    // ---- Helpers -----------------------------------------------------------

    private function assertRiskLevel(string $level): void
    {
        if (! in_array($level, RiskLevelScorer::LEVELS, true)) {
            throw new InvalidArgumentException("SpecComposer: unknown risk level '{$level}'.");
        }
    }

    /**
     * Providers this fast path has a runtime driver for. A learned route
     * pointing anywhere else is ignored (fail-open to the config default) —
     * PipelineRunExecutor would otherwise die on unsupported_provider_lock.
     *
     * @var list<string>
     */
    private const DEV_RUNTIME_PROVIDERS = [
        'claude_cli',
        'cursor_cli',
        'codex_cli',
        'minimax_m27_cli',
        'hermes_cli',
    ];

    private function resolveProviderLock(OperationEnvelope $envelope, string $taskKind = ''): ProviderLock
    {
        $choice = strtolower(trim((string) ($envelope->surfaceContext->providerChoice ?? '')));
        $provider = match ($choice) {
            'cursor', 'cursor_cli', 'cursor-agent', 'cursor_agent' => 'cursor_cli',
            'claude', 'claude_cli', 'sonnet', 'claude-code', 'claude_code' => 'claude_cli',
            'codex', 'codex_cli', 'openai_codex' => 'codex_cli',
            'minimax', 'minimax_cli', 'minimax_m27', 'minimax_m27_cli' => 'minimax_m27_cli',
            'hermes', 'hermes_cli', 'hermes-agent', 'hermes_agent', 'nous' => 'hermes_cli',
            default => $this->learnedOrDefaultProvider($taskKind),
        };

        return new ProviderLock(
            provider: $provider,
            modelFamily: $this->resolveModelFamily($envelope, $provider),
            fallbackAllowed: false,
        );
    }

    /**
     * No explicit operator provider choice: consult the Atlas Decide learned
     * route (ADML cost+outcome ledger — the SAME brain the gateway consults
     * via AiProviderManager::getRecommended) before the static config
     * default. The scope is (programming, <task_kind>), so ADML can learn
     * e.g. that repairs land better on one provider and questions on a
     * cheaper one. Fail-open on every edge: consultation error, no learned
     * signal (free_to_choose), requires_approval/blocked verdicts, or a
     * learned provider without a Dev runtime driver all fall back to the
     * config default. The consultation service persists its own append-only
     * ticket, so the routing decision is auditable without new receipts.
     */
    private function learnedOrDefaultProvider(string $taskKind): string
    {
        $default = (static function (): string {
            if (! function_exists('config')) {
                return 'claude_cli';
            }
            try {
                $val = config('atlas_dev.provider.default_provider', 'claude_cli');

                return is_string($val) && trim($val) !== '' ? trim($val) : 'claude_cli';
            } catch (\Throwable) {
                return 'claude_cli';
            }
        })();

        try {
            if (! (bool) config('atlas_dev.provider.consult_decide', true)) {
                return $default;
            }

            $consultation = app(AtlasDecideGatewayConsultationService::class)->consult([
                'task_category' => 'programming',
                'role' => $taskKind !== '' ? $taskKind : 'atlas_dev_fast_path',
                'framework' => null,
                'privacy_class' => 'normal',
                'actor' => 'atlas_dev_spec_composer',
            ]);

            if (($consultation['verdict'] ?? null) === AtlasDecideGatewayConsultationService::VERDICT_FOLLOW_LEARNED) {
                $candidate = $consultation['active_route']['provider'] ?? null;
                if (is_string($candidate) && in_array($candidate, self::DEV_RUNTIME_PROVIDERS, true)) {
                    return $candidate;
                }
            }
        } catch (\Throwable) {
            // ADML must never break plan composition (mirrors the gateway).
        }

        return $default;
    }

    private function resolveModelFamily(OperationEnvelope $envelope, string $provider): string
    {
        $constraintModel = $this->constraintValue($envelope->userConstraints, 'composer_model')
            ?? $this->constraintValue($envelope->userConstraints, 'model_family')
            ?? $this->constraintValue($envelope->userConstraints, 'model');

        if (is_string($constraintModel) && trim($constraintModel) !== '') {
            return $this->normalizeModelFamily($provider, trim($constraintModel));
        }

        if ($provider === 'cursor_cli') {
            $configured = function_exists('config') ? config('atlas.ai.providers.cursor_cli.model') : null;

            return is_string($configured) && trim($configured) !== ''
                ? trim($configured)
                : 'composer-2.5-fast';
        }

        if ($provider === 'minimax_m27_cli') {
            $configured = function_exists('config') ? config('atlas.ai.providers.minimax_m27_cli.model', 'MiniMax-M3') : null;

            return is_string($configured) && trim($configured) !== ''
                ? trim($configured)
                : 'MiniMax-M3';
        }

        if ($provider === 'codex_cli') {
            $configured = function_exists('config') ? config('atlas.ai.providers.codex_cli.model', 'gpt-5.3-codex-spark') : null;

            return is_string($configured) && trim($configured) !== ''
                ? trim($configured)
                : 'gpt-5.3-codex-spark';
        }

        if ($provider === 'hermes_cli') {
            // Hermes self-selects its sub-model; single-sourced sentinel, robust
            // without a bound config container. {@see HermesWorkspaceDefaults::model()}
            return HermesWorkspaceDefaults::model();
        }

        return 'sonnet';
    }

    private function normalizeModelFamily(string $provider, string $model): string
    {
        return SpecComposerBuildersSupport::normalizeModelFamily($provider, $model);
    }

    /**
     * @param  list<string>  $constraints
     */
    private function constraintValue(array $constraints, string $key): ?string
    {
        return SpecComposerBuildersSupport::constraintValue($constraints, $key);
    }

    private function resolveMode(OperationEnvelope $envelope, TaskClassification $classification, string $riskLevel): string
    {
        if ($this->allowsRivalsIsolatedRuntimeExecution($envelope)) {
            // Benchmark isolado é SEMPRE tarefa de execução: o bridge manda
            // "apply a production-quality patch". KIND_QUESTION aqui é misfire
            // do classificador ("Fix the git repository" lido como pergunta →
            // read_only_answer com referência de OUTRO workspace — visto ao
            // vivo em tb_fix_git, run 20260718_181509) e matava a unidade.
            // Vale para TODO risco, não só R4/R5: em R2 o misfire passava reto.
            // E TODO kind vira PATCH: o contrato medido do benchmark é UM —
            // "gere o patch" — e MODE_REPAIR com escopo vazio bloqueava o
            // plano (dev_plan_blocked, visto ao vivo); o caminho PATCH é o
            // único blindado ponta a ponta (adoção de escopo no kernel).
            return self::MODE_PATCH;
        }

        if ($riskLevel === RiskLevelScorer::R4 || $riskLevel === RiskLevelScorer::R5) {
            return self::MODE_ESCALATE_PREVIEW;
        }

        return match ($classification->taskKind) {
            TaskClassification::KIND_QUESTION => self::MODE_READ_ONLY,
            TaskClassification::KIND_REVIEW => self::MODE_REVIEW,
            TaskClassification::KIND_REPAIR => self::MODE_REPAIR,
            TaskClassification::KIND_FRONTEND => self::MODE_FRONTEND_VISUAL,
            TaskClassification::KIND_RISKY => self::MODE_ESCALATE_PREVIEW,
            default => self::MODE_PATCH,
        };
    }

    private function resolveScopeMode(TaskClassification $classification, string $riskLevel): string
    {
        return SpecComposerBuildersSupport::resolveScopeMode($riskLevel);
    }

    private function maxCandidates(TaskClassification $classification, string $riskLevel): int
    {
        return SpecComposerBuildersSupport::maxCandidates($riskLevel);
    }

    private function maxFilesChangedFor(OperationEnvelope $envelope, CompactSdd $compactSdd, MiniProgrammingSpec $miniSpec): int
    {
        $declaredFiles = max(count($miniSpec->expectedFiles), count($miniSpec->allowedFiles));
        if ($this->allowsRivalsIsolatedRuntimeExecution($envelope)
            && in_array($compactSdd->riskLevel, [RiskLevelScorer::R4, RiskLevelScorer::R5], true)
            && ! in_array($compactSdd->mode, [self::MODE_READ_ONLY, self::MODE_REVIEW, self::MODE_ESCALATE_PREVIEW], true)) {
            return max(1, min(5, $declaredFiles > 0 ? $declaredFiles : 1));
        }

        $riskCap = self::MAX_FILES_BY_RISK[$compactSdd->riskLevel] ?? 0;
        if ($declaredFiles > 0 && $riskCap > 0) {
            return max($riskCap, min(5, $declaredFiles));
        }

        return $riskCap;
    }

    private function maxRepairAttemptsFor(OperationEnvelope $envelope, string $riskLevel): int
    {
        if ($this->allowsRivalsIsolatedRuntimeExecution($envelope)) {
            return match ($riskLevel) {
                RiskLevelScorer::R4 => 1,
                RiskLevelScorer::R5 => 2,
                default => self::MAX_REPAIR_ATTEMPTS_BY_RISK[$riskLevel] ?? 0,
            };
        }

        return self::MAX_REPAIR_ATTEMPTS_BY_RISK[$riskLevel] ?? 0;
    }

    /**
     * @return list<string>
     */
    private function resolveDocTiers(OperationEnvelope $envelope, TaskClassification $classification, string $riskLevel): array
    {
        $tiers = ['core'];
        if ($envelope->preflight->workspaceResolved) {
            $tiers[] = 'code_intelligence';
        }
        if (in_array($classification->taskKind, [TaskClassification::KIND_PATCH, TaskClassification::KIND_REPAIR], true)
            || in_array($riskLevel, [RiskLevelScorer::R2, RiskLevelScorer::R3, RiskLevelScorer::R4, RiskLevelScorer::R5], true)) {
            $tiers[] = 'sdd';
        }
        if ($classification->taskKind === TaskClassification::KIND_FRONTEND
            || in_array($envelope->surfaceId, ['atlas_desktop_ai', 'atlas_app', 'atlas_code'], true)) {
            $tiers[] = 'interface';
        }
        if (in_array($riskLevel, [RiskLevelScorer::R4, RiskLevelScorer::R5], true)) {
            $tiers[] = 'forge';
        }

        return AtlasDevStringListNormalizer::uniqueTrimmedStrings($tiers);
    }

    private function resolveProfile(OperationEnvelope $envelope, TaskClassification $classification): string
    {
        if ($classification->taskKind === TaskClassification::KIND_QUESTION
            || $classification->taskKind === TaskClassification::KIND_REVIEW) {
            return self::PROFILE_GENERIC_NO_TEST;
        }
        // The runbook table 19.3 maps profiles to repo layout. Surface is
        // only a fallback: Desktop may point at any workspace, so the actual
        // project files must win over the caller surface.
        $intent = strtolower($envelope->normalizedIntent);
        if (str_contains($intent, 'docs/') || str_contains($intent, '.md')
            || $classification->taskKind === TaskClassification::KIND_REVIEW) {
            return self::PROFILE_GENERIC_NO_TEST;
        }
        if (is_file(rtrim($envelope->workspace, '/').'/composer.json')) {
            return self::PROFILE_PHP_LARAVEL;
        }
        if (is_file(rtrim($envelope->workspace, '/').'/package.json')
            || str_contains($envelope->workspace, 'atlas-desktop')
            || str_contains($envelope->workspace, 'atlas-app')
            || in_array($envelope->surfaceId, ['atlas_desktop_ai', 'atlas_app', 'atlas_code'], true)) {
            return self::PROFILE_TS_REACT;
        }

        return self::PROFILE_PHP_LARAVEL;
    }

    /**
     * @return list<string>
     */
    private function resolveEscalationTriggers(TaskClassification $classification, string $riskLevel): array
    {
        return SpecComposerBuildersSupport::resolveEscalationTriggers(
            $classification->taskKind,
            $riskLevel,
            $classification->writeImplied,
        );
    }

    /**
     * PHP doesn't allow mutating readonly fields; we rebuild a clone with the
     * computed hash. Internal helper, never exposed.
     */
    private function rebuildCompactWithHash(CompactSdd $compact, string $hash): CompactSdd
    {
        return new CompactSdd(
            runId: $compact->runId,
            envelopeHash: $compact->envelopeHash,
            intentRaw: $compact->intentRaw,
            intentNormalized: $compact->intentNormalized,
            taskKind: $compact->taskKind,
            riskLevel: $compact->riskLevel,
            scopeMode: $compact->scopeMode,
            mode: $compact->mode,
            contextBudget: $compact->contextBudget,
            docTiersRequired: $compact->docTiersRequired,
            verificationProfile: $compact->verificationProfile,
            contextDigest: $compact->contextDigest,
            escalationTriggers: $compact->escalationTriggers,
            miniSpecHash: $compact->miniSpecHash,
            taskContractHash: $compact->taskContractHash,
            compactSddHash: $hash,
        );
    }

    /**
     * @return list<string>
     */
    private function allowedFilesFor(OperationEnvelope $envelope, CompactSdd $compactSdd, CodeDiscoveryManifest $discovery): array
    {
        if ($compactSdd->mode === self::MODE_READ_ONLY
            || $compactSdd->mode === self::MODE_ESCALATE_PREVIEW
            || $compactSdd->mode === self::MODE_REVIEW) {
            return [];
        }

        $explicit = $this->explicitAllowedFiles($envelope);
        if ($explicit !== []) {
            return $explicit;
        }

        $files = [];
        foreach ($discovery->likelyFiles as $candidate) {
            $files[] = $this->relativise($envelope->workspace, $candidate->path);
        }

        return AtlasDevStringListNormalizer::uniqueTrimmedStrings($files);
    }

    /**
     * @return list<string>
     */
    private function explicitAllowedFiles(OperationEnvelope $envelope): array
    {
        $files = [];
        foreach ($envelope->userConstraints as $constraint) {
            if (! is_string($constraint)) {
                continue;
            }
            $trimmed = trim($constraint);
            if (! preg_match('/\Aallowed_files?=(.+)\z/i', $trimmed, $matches)) {
                continue;
            }
            foreach (explode(',', $matches[1]) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '') {
                    $files[] = $candidate;
                }
            }
        }

        return AtlasDevStringListNormalizer::uniqueTrimmedStrings($files);
    }

    /**
     * @return list<string>
     */
    private function forbiddenFilesFor(CompactSdd $compactSdd, CodeDiscoveryManifest $discovery): array
    {
        // We deliberately keep literal `.env` paths OUT of the spec's forbidden
        // list — the rendered prompt includes this list verbatim and shipping
        // ".env" to the model trips provider-safety leak detectors (and is
        // operationally noisy: scope guard already refuses any path under env).
        // Runtime scope guard owns env enforcement; Support strips env globs.
        return SpecComposerBuildersSupport::forbiddenFilesFor($compactSdd->mode, $discovery->forbiddenFiles);
    }

    /**
     * @return list<string>
     */
    private function expectedFilesFor(CodeDiscoveryManifest $discovery): array
    {
        $paths = [];
        foreach ($discovery->likelyFiles as $candidate) {
            $paths[] = $candidate->path;
        }

        return AtlasDevStringListNormalizer::uniqueSortedStrings($paths);
    }

    /**
     * @return list<array{kind: string, ref: string, reason: string}>
     */
    private function buildCanonicalContext(CodeDiscoveryManifest $discovery, OpenBrainProgrammingProjection $projection): array
    {
        $items = [];
        foreach ($discovery->likelyFiles as $candidate) {
            $items[] = [
                'kind' => 'file',
                'ref' => $candidate->path,
                'reason' => $candidate->reason,
            ];
        }
        foreach ($discovery->relatedTests as $ref) {
            $items[] = [
                'kind' => 'test',
                'ref' => $ref->ref,
                'reason' => $ref->reason,
            ];
        }
        foreach ($projection->knowledgeRefs as $ref) {
            $items[] = [
                'kind' => $this->mapContextRefKind($ref),
                'ref' => $ref->ref,
                'reason' => $ref->reason,
            ];
        }
        foreach ($projection->memoryRefs as $ref) {
            $items[] = [
                'kind' => $this->mapContextRefKind($ref),
                'ref' => $ref->ref,
                'reason' => $ref->reason,
            ];
        }
        usort($items, static fn (array $a, array $b): int => strcmp($a['ref'], $b['ref']));

        return $items;
    }

    /**
     * Runs the DesignPathSelector on facts the pipeline already produces — task_kind (from the
     * CompactSdd, sourced from TaskClassifier), risk (RiskLevelScorer), and discovery signals
     * (likely files, callers via related symbols, tests) — and folds the choice additively into
     * canonicalContext as a `design_path` entry. Every existing MiniProgrammingSpec field stays
     * byte-compatible; this only appends one more canonical-context item.
     *
     * @return array{kind:string, ref:string, reason:string}
     */
    private function buildDesignPathContextEntry(CompactSdd $compactSdd, CodeDiscoveryManifest $discovery): array
    {
        $likelyFiles = [];
        foreach ($discovery->likelyFiles as $candidate) {
            $likelyFiles[] = $candidate->path;
        }
        $callers = [];
        foreach ($discovery->relatedSymbols as $ref) {
            $callers[] = $ref->ref;
        }
        $tests = [];
        foreach ($discovery->relatedTests as $ref) {
            $tests[] = $ref->ref;
        }

        $selection = $this->designPathSelector->select([
            'task_kind' => $compactSdd->taskKind,
            'risk' => $compactSdd->riskLevel,
            'likely_files' => $likelyFiles,
            'callers' => $callers,
            'tests' => $tests,
        ]);

        return [
            'kind' => 'design_path',
            'ref' => (string) $selection['design_path'],
            'reason' => (string) $selection['reason'],
        ];
    }

    private function mapContextRefKind(ContextRef $ref): string
    {
        return SpecComposerBuildersSupport::mapContextRefKind($ref->kind);
    }

    /**
     * @return list<string>
     */
    private function buildVerificationCommands(OperationEnvelope $envelope, CompactSdd $compactSdd, CodeDiscoveryManifest $discovery): array
    {
        if ($compactSdd->mode === self::MODE_READ_ONLY
            || $compactSdd->mode === self::MODE_REVIEW
            || $compactSdd->mode === self::MODE_ESCALATE_PREVIEW) {
            return [];
        }

        $explicitCommands = $this->explicitValidationCommands($envelope);
        if ($explicitCommands !== []) {
            return $explicitCommands;
        }

        if ($compactSdd->verificationProfile === self::PROFILE_GENERIC_NO_TEST) {
            return [];
        }

        $commands = [];
        foreach ($discovery->relatedTests as $testRef) {
            $path = $this->testRefToPath($testRef->ref);
            if ($path === null) {
                continue;
            }
            if ($compactSdd->verificationProfile === self::PROFILE_PHP_LARAVEL) {
                $filterTarget = $this->phpUnitFilterFromPath($path);
                $commands[] = $filterTarget !== null
                    ? "composer test -- --filter={$filterTarget}"
                    : "composer test -- {$path}";
            } elseif ($compactSdd->verificationProfile === self::PROFILE_TS_REACT) {
                $commands[] = "pnpm test --filter=\"{$path}\"";
            }
        }

        if ($commands === []) {
            if ($compactSdd->verificationProfile === self::PROFILE_PHP_LARAVEL) {
                $commands[] = 'composer test';
            } elseif ($compactSdd->verificationProfile === self::PROFILE_TS_REACT) {
                $commands[] = 'pnpm test';
            }
        }

        return AtlasDevStringListNormalizer::uniqueTrimmedStrings($commands);
    }

    /**
     * @return list<string>
     */
    private function explicitValidationCommands(OperationEnvelope $envelope): array
    {
        $commands = [];
        foreach ($envelope->userConstraints as $constraint) {
            if (! is_string($constraint)) {
                continue;
            }
            $trimmed = trim($constraint);
            if (! preg_match('/\Avalidation_commands?=(.+)\z/i', $trimmed, $matches)) {
                continue;
            }

            foreach (explode('&&', $matches[1]) as $candidate) {
                $command = trim($candidate);
                if ($command === '' || str_contains($command, "\n") || strlen($command) > 240) {
                    continue;
                }
                $commands[] = $command;
            }
        }

        return AtlasDevStringListNormalizer::uniqueTrimmedStrings($commands);
    }

    private function testRefToPath(string $ref): ?string
    {
        return SpecComposerBuildersSupport::testRefToPath($ref);
    }

    private function phpUnitFilterFromPath(string $path): ?string
    {
        return SpecComposerBuildersSupport::phpUnitFilterFromPath($path);
    }

    /**
     * Build the acceptance criteria for the spec.
     *
     * Pure body lives in {@see SpecComposerBuildersSupport::buildAcceptanceCriteria}.
     * This facade resolves elevation + intent verbs (DI) then delegates.
     *
     * @param  list<string>  $verificationCommands
     * @param  list<string>  $expectedFiles
     * @return list<array{id: string, description: string, verification: string, verification_ref: ?string}>
     */
    private function buildAcceptanceCriteria(
        CompactSdd $compactSdd,
        array $verificationCommands,
        array $expectedFiles,
        ?OperationEnvelope $envelope = null,
        ?ElevationConfig $e2Config = null,
    ): array {
        $e2 = $e2Config ?? $this->resolveE2Config();
        $e2Enabled = ! $e2->isOff() && $envelope !== null;
        $intentVerbs = $e2Enabled
            ? $this->extractIntentVerbs($compactSdd->intentNormalized)
            : [];

        return SpecComposerBuildersSupport::buildAcceptanceCriteria(
            mode: $compactSdd->mode,
            verificationProfile: $compactSdd->verificationProfile,
            verificationCommands: $verificationCommands,
            expectedFiles: $expectedFiles,
            intentVerbs: $intentVerbs,
            e2Enabled: $e2Enabled,
        );
    }

    /**
     * Recognized intent verbs live in {@see IntentActionExtractor::RECOGNIZED_VERBS},
     * the single canonical source shared by intake classification
     * ({@see IntakeNormalizer::inferClarity}), the persisted
     * LightTaskContract::intentVerbs field (E1), the rendered `## Definition
     * of Done` (E2), the behavioral acceptance criteria (E2), and the E1
     * intent-falsification probe. SpecComposer reads them via the injected
     * IntentActionExtractor so the vocabulary is never duplicated.
     */

    /**
     * @return list<string>
     */
    private function buildNonGoals(CompactSdd $compactSdd): array
    {
        return SpecComposerBuildersSupport::buildNonGoals($compactSdd->mode);
    }

    /**
     * @param  list<string>  $expectedFiles
     * @return list<array{description: string, observable_by: string}>
     */
    private function buildExpectedBehavior(CompactSdd $compactSdd, array $expectedFiles): array
    {
        return SpecComposerBuildersSupport::buildExpectedBehavior(
            $compactSdd->mode,
            $this->extractIntentVerbs($compactSdd->intentNormalized),
        );
    }

    /**
     * Resolve the E2 elevation config. When the Laravel kernel is available
     * (feature tests / production) the live config block is read; otherwise
     * the safe default (advisory) is used so plain-PHPunit unit tests do not
     * require a bootstrapped app and never crash. Mirrors the resolution
     * pattern used by PromptSectionsMapper so both layers route identically.
     */
    private function resolveE2Config(): ElevationConfig
    {
        if (function_exists('config')) {
            try {
                return ElevationConfig::fromConfig('e2');
            } catch (\Throwable) {
                return ElevationConfig::for('e2', null);
            }
        }

        return ElevationConfig::for('e2', null);
    }

    /**
     * Extract the recognized intent verbs present in the normalized intent.
     * Delegates to {@see IntentActionExtractor} so the recognized verb
     * vocabulary lives in exactly one place and is shared verbatim with the
     * E1 intent-falsification probe and the persisted
     * LightTaskContract::intentVerbs field. Returns the deduped set of
     * canonical verb labels, preserving first-occurrence order. Empty when
     * no recognized verb matches.
     *
     * @return list<string>
     */
    private function extractIntentVerbs(string $intentNormalized): array
    {
        return $this->intentActionExtractor->extract($intentNormalized);
    }

    /**
     * @return list<array{text: string, confidence: string}>
     */
    private function buildAssumptions(CompactSdd $compactSdd, CodeDiscoveryManifest $discovery): array
    {
        return SpecComposerBuildersSupport::buildAssumptions($compactSdd->mode, $discovery->confidence);
    }

    /**
     * @param  list<string>  $verificationCommands
     * @return list<string>
     */
    private function buildCompletionCriteria(CompactSdd $compactSdd, array $verificationCommands): array
    {
        return SpecComposerBuildersSupport::buildCompletionCriteria($compactSdd->mode, $verificationCommands);
    }

    /**
     * @param  list<string>  $expectedFiles
     */
    private function buildRollback(CompactSdd $compactSdd, array $expectedFiles): string
    {
        return SpecComposerBuildersSupport::buildRollback($compactSdd->mode, $expectedFiles);
    }

    private function buildGoal(OperationEnvelope $envelope, CompactSdd $compactSdd): string
    {
        return SpecComposerBuildersSupport::buildGoal($envelope->normalizedIntent);
    }

    /**
     * @return list<string>
     */
    private function buildBlockedActions(CompactSdd $compactSdd): array
    {
        return SpecComposerBuildersSupport::buildBlockedActions();
    }

    /**
     * @return list<string>
     */
    private function buildEvidenceRequired(CompactSdd $compactSdd): array
    {
        return SpecComposerBuildersSupport::buildEvidenceRequired($compactSdd->mode);
    }

    private function writeAllowed(OperationEnvelope $envelope, CompactSdd $compactSdd): bool
    {
        return SpecComposerBuildersSupport::writeAllowed($envelope->preflight->writeAllowed, $compactSdd->mode);
    }

    /**
     * Runtime isolado do Rivals — MESMO predicado do RoutingDecisionEngine:167.
     *
     * As duas cópias discordavam, e a discordância matava a medição em silêncio:
     * o roteamento aceitava ['atlas_forge_rivals', 'atlas_cli_dev', 'atlas_dev_execution'] e liberava o
     * R4 para o fast path; esta aceitava só o Forge. O bridge chama
     * `atlas:cli:dev` (superfície atlas_cli_dev), então passava na rota e
     * emperrava aqui — e o efeito era invisível: MAX_FILES_BY_RISK[R4] = 0, ou
     * seja, teto de ZERO arquivo alterável, pipeline sem nada a fazer,
     * `provider_calls: 0`, `needs_review` em 1 segundo. Nenhum erro, nenhum
     * blocker, nenhum log: o Atlas simplesmente não trabalhava.
     *
     * Toda issue real de SWE-bench é R4 — logo o braço "com Atlas" jamais
     * chegaria a chamar o modelo em nenhuma delas.
     *
     * O comentário do roteamento já declarava a intenção: "Bridge invokes
     * atlas:cli:dev (surface atlas_cli_dev). Forge rivals path uses
     * atlas_forge_rivals. Both are valid when the operator opts in." Esta cópia
     * ficou para trás. Mantê-las em concordância é obrigatório: as duas guardam
     * a mesma decisão de governança em pontos diferentes do pipeline.
     */
    private function allowsRivalsIsolatedRuntimeExecution(OperationEnvelope $envelope): bool
    {
        if (! in_array($envelope->surfaceId, ['atlas_forge_rivals', 'atlas_cli_dev', 'atlas_dev_execution'], true)) {
            return false;
        }
        if (! $envelope->preflight->operatorExplicit) {
            return false;
        }

        foreach ($envelope->userConstraints as $constraint) {
            $normalized = strtolower(trim((string) $constraint));
            if ($normalized === 'rivals_runtime_execution=true') {
                return true;
            }
        }

        return false;
    }

    private function relativise(string $workspace, string $absolute): string
    {
        return SpecComposerBuildersSupport::relativise($workspace, $absolute);
    }

    private function deriveTaskId(OperationEnvelope $envelope, MiniProgrammingSpec $miniSpec): string
    {
        return 'task-'.substr($envelope->runId, 0, 32);
    }

    /**
     * Resolve the intent_text for the LightTaskContract. Sourced from the
     * envelope's normalized intent; for write tasks (writeAllowed=true) the
     * result is NEVER empty: the raw intent is the first fallback and a
     * stable sentinel is the final floor, so a write task always carries a
     * non-empty intent_text folded into the contract hash. Read-only / review
     * / escalate-preview tasks may carry an empty intent_text (no write
     * implies no intent-mutation to fold).
     *
     * VAL-E2-006 / VAL-E2-012: intent_text is non-empty for write tasks
     * including sparse single-verb intents ("corrija", "fix").
     */
    private function resolveIntentText(OperationEnvelope $envelope, bool $writeAllowed): string
    {
        return SpecComposerBuildersSupport::resolveIntentText(
            $envelope->normalizedIntent,
            $envelope->rawIntent,
            $writeAllowed,
        );
    }
}
