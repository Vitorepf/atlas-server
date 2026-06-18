<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

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

        $providerLock = $this->resolveProviderLock($envelope);

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

    private function resolveProviderLock(OperationEnvelope $envelope): ProviderLock
    {
        $choice = strtolower(trim((string) ($envelope->surfaceContext->providerChoice ?? '')));
        $provider = match ($choice) {
            'cursor', 'cursor_cli', 'cursor-agent', 'cursor_agent' => 'cursor_cli',
            'claude', 'claude_cli', 'sonnet', 'claude-code', 'claude_code' => 'claude_cli',
            'codex', 'codex_cli', 'openai_codex' => 'codex_cli',
            'minimax', 'minimax_cli', 'minimax_m27', 'minimax_m27_cli' => 'minimax_m27_cli',
            'hermes', 'hermes_cli', 'hermes-agent', 'hermes_agent', 'nous' => 'hermes_cli',
            default => (static function (): string {
                if (! function_exists('config')) {
                    return 'claude_cli';
                }
                try {
                    $val = config('atlas_dev.provider.default_provider', 'claude_cli');

                    return is_string($val) && trim($val) !== '' ? trim($val) : 'claude_cli';
                } catch (\Throwable) {
                    return 'claude_cli';
                }
            })(),
        };

        return new ProviderLock(
            provider: $provider,
            modelFamily: $this->resolveModelFamily($envelope, $provider),
            fallbackAllowed: false,
        );
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
        if ($provider !== 'claude_cli') {
            return $model;
        }

        $normalized = strtolower(trim($model));
        if ($normalized === 'sonnet' || str_contains($normalized, 'sonnet')) {
            return 'sonnet';
        }

        return $model;
    }

    /**
     * @param  list<string>  $constraints
     */
    private function constraintValue(array $constraints, string $key): ?string
    {
        $prefix = strtolower($key).'=';
        foreach ($constraints as $constraint) {
            if (! is_string($constraint)) {
                continue;
            }
            $trimmed = trim($constraint);
            if (str_starts_with(strtolower($trimmed), $prefix)) {
                $value = trim(substr($trimmed, strlen($prefix)));

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    private function resolveMode(OperationEnvelope $envelope, TaskClassification $classification, string $riskLevel): string
    {
        if (in_array($riskLevel, [RiskLevelScorer::R4, RiskLevelScorer::R5], true)
            && $this->allowsRivalsIsolatedRuntimeExecution($envelope)) {
            return match ($classification->taskKind) {
                TaskClassification::KIND_QUESTION => self::MODE_READ_ONLY,
                TaskClassification::KIND_REVIEW => self::MODE_REVIEW,
                TaskClassification::KIND_REPAIR => self::MODE_REPAIR,
                TaskClassification::KIND_FRONTEND => self::MODE_FRONTEND_VISUAL,
                default => self::MODE_PATCH,
            };
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
        if (in_array($riskLevel, [RiskLevelScorer::R3, RiskLevelScorer::R4, RiskLevelScorer::R5], true)) {
            return self::SCOPE_STRUCTURAL;
        }

        return self::SCOPE_COMPACT;
    }

    private function maxCandidates(TaskClassification $classification, string $riskLevel): int
    {
        return match ($riskLevel) {
            RiskLevelScorer::R0 => 4,
            RiskLevelScorer::R1 => 4,
            RiskLevelScorer::R2 => 6,
            RiskLevelScorer::R3 => 10,
            RiskLevelScorer::R4 => 12,
            RiskLevelScorer::R5 => 12,
            default => 6,
        };
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
        if (in_array($riskLevel, [RiskLevelScorer::R4, RiskLevelScorer::R5], true)) {
            return [
                'risk_level_at_or_above_r4',
                'human_action_required',
            ];
        }
        if ($classification->taskKind === TaskClassification::KIND_REPAIR) {
            return [
                'same_signature_failure_twice',
                'diff_grew_without_progress',
                'new_scope_appeared',
            ];
        }
        if ($classification->writeImplied) {
            return [
                'scope_explosion',
                'same_signature_failure_twice',
            ];
        }

        return [];
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
        $base = ['vendor/*', 'node_modules/*', 'storage/framework/*'];

        $extra = [];
        if ($compactSdd->mode === self::MODE_ESCALATE_PREVIEW) {
            $extra[] = 'database/migrations/*';
            $extra[] = 'config/*';
        }

        // Merge in discovery's forbidden globs but strip any env-style entries
        // for the same reason. The runtime scope guard owns env enforcement.
        $clean = array_filter(
            $discovery->forbiddenFiles,
            static fn (string $g): bool => ! str_contains(strtolower($g), '.env'),
        );

        return AtlasDevStringListNormalizer::uniqueTrimmedStrings(array_merge($base, $extra, $clean));
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

    private function mapContextRefKind(ContextRef $ref): string
    {
        return match ($ref->kind) {
            ContextRef::KIND_TEST => 'test',
            ContextRef::KIND_SYMBOL => 'symbol',
            ContextRef::KIND_KNOWLEDGE => 'knowledge',
            ContextRef::KIND_DECISION => 'decision',
            ContextRef::KIND_LEARNING => 'learning',
            ContextRef::KIND_TECHNICAL_CONTEXT => 'technical_context',
            ContextRef::KIND_HARNESS_LEARNING => 'harness_learning',
            ContextRef::KIND_MEMORY => 'memory',
            ContextRef::KIND_CODE => 'code',
            default => 'context',
        };
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
        if (str_starts_with($ref, 'file://')) {
            return substr($ref, 7);
        }
        if (str_contains($ref, '://')) {
            return null;
        }

        return $ref;
    }

    private function phpUnitFilterFromPath(string $path): ?string
    {
        $base = basename($path);
        if (! str_ends_with($base, 'Test.php')) {
            return null;
        }

        return substr($base, 0, -4);
    }

    /**
     * Build the acceptance criteria for the spec.
     *
     * For write tasks (patch/repair/frontend), the AC set is the union of:
     *
     *   1. Behavioral ACs (E2): one per recognized intent verb, each carrying
     *      a real verification_ref pointing at a concrete test command sourced
     *      from the verification plan (or the profile default when no explicit
     *      command exists). These describe OBSERVABLE behavior tied to the
     *      intent verb, distinct from the tautological command/scope backstop.
     *      Gated by atlas_dev.elevations.e2.mode: off => byte-identical to the
     *      pre-E2 baseline (no behavioral ACs emitted).
     *
     *   2. The command backstop ACs: "command X terminates with exit_code=0"
     *      per verification command. Always retained (never reduced).
     *
     *   3. The scope backstop AC: "diff touches only expected_files". Always
     *      retained when expected files exist (never reduced).
     *
     * AC strength is never reduced: behavioral ACs are ADDITIVE to the
     * command/scope backstop, never a replacement. A tautology-only AC set
     * (no behavioral AC despite a write task with verbs) is never silently
     * produced while E2 is on, so the intent_not_tested flag (sibling
     * e2-intent-text-contract feature) has a detectable basis.
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
        if ($compactSdd->mode === self::MODE_READ_ONLY) {
            return [[
                'id' => 'ac_1',
                'description' => 'resposta cita refs reais (file://, doc://, symbol://) e nao inventa caminho',
                'verification' => 'manual',
                'verification_ref' => null,
            ]];
        }

        if ($compactSdd->mode === self::MODE_REVIEW) {
            return [[
                'id' => 'ac_1',
                'description' => 'review identifica pontos verificaveis com refs',
                'verification' => 'manual',
                'verification_ref' => null,
            ]];
        }

        if ($compactSdd->mode === self::MODE_ESCALATE_PREVIEW) {
            return [[
                'id' => 'ac_1',
                'description' => 'preview de promotion para Forge gerado com reasons observable, sem patch aplicado',
                'verification' => 'evidence',
                'verification_ref' => null,
            ]];
        }

        $criteria = [];

        // E2: behavioral ACs per recognized intent verb. Emitted BEFORE the
        // command/scope backstop so the intent-grounded criteria lead the set.
        // Each behavioral AC carries a real verification_ref (the concrete
        // test command), never a placeholder. Gated off entirely when e2.mode=off
        // so the AC set is byte-identical to the pre-E2 baseline.
        $e2 = $e2Config ?? $this->resolveE2Config();
        if (! $e2->isOff() && $envelope !== null) {
            foreach ($this->buildBehavioralAcceptanceCriteria($compactSdd, $envelope, $verificationCommands) as $behavioral) {
                $criteria[] = $behavioral;
            }
        }

        // Command backstop (verifiable): "command X exits 0".
        $i = 1;
        foreach ($verificationCommands as $cmd) {
            $criteria[] = [
                'id' => 'ac_cmd_'.$i,
                'description' => "comando '{$cmd}' termina com exit_code=0",
                'verification' => 'test',
                'verification_ref' => $cmd,
            ];
            $i++;
        }

        // Scope backstop (verifiable): "diff touches only expected files".
        if ($expectedFiles !== []) {
            $criteria[] = [
                'id' => 'ac_scope',
                'description' => 'diff toca somente arquivos previstos em expected_files',
                'verification' => 'scope_guard',
                'verification_ref' => null,
            ];
        }

        // Fallback only when no command/scope/behavioral AC exists at all:
        // preserves the pre-E2 manual fallback for edge cases (no tests, no
        // verbs, no expected files).
        if ($criteria === []) {
            $criteria[] = [
                'id' => 'ac_1',
                'description' => 'patch produzido reflete o goal sem expandir escopo',
                'verification' => 'manual',
                'verification_ref' => null,
            ];
        }

        return $criteria;
    }

    /**
     * Build the behavioral (non-tautological) acceptance criteria, one per
     * recognized intent verb. Each carries a real verification_ref pointing
     * at a concrete test command:
     *   - the first explicit verification command when available;
     *   - otherwise the profile default (composer test / pnpm test).
     *
     * When no verification command is resolvable (e.g. generic_no_test
     * profile with no explicit commands), no behavioral AC is emitted here;
     * the sibling intent_not_tested flag owns the "no test backs the intent"
     * signal, and the command/scope backstop is still emitted by the caller.
     *
     * AC ids are namespaced as `ac_behavior_<verb_label>` (slugified) so they
     * never collide with the command (`ac_cmd_<n>`) or scope (`ac_scope`)
     * backstop ids, even for multi-verb intents.
     *
     * @param  list<string>  $verificationCommands
     * @return list<array{id: string, description: string, verification: string, verification_ref: ?string}>
     */
    private function buildBehavioralAcceptanceCriteria(
        CompactSdd $compactSdd,
        OperationEnvelope $envelope,
        array $verificationCommands,
    ): array {
        $verbs = $this->extractIntentVerbs($compactSdd->intentNormalized);
        if ($verbs === []) {
            return [];
        }

        $verificationRef = $this->resolveBehavioralVerificationRef($compactSdd, $verificationCommands);
        // When no concrete test command can back the behavioral AC, defer: the
        // sibling intent_not_tested flag owns that signal and the command/scope
        // backstop still carries the verifiable floor. Emitting a behavioral AC
        // with an empty/placeholder ref would violate VAL-E2-005.
        if ($verificationRef === null) {
            return [];
        }

        $criteria = [];
        foreach ($verbs as $verbLabel) {
            $slug = $this->verbSlug($verbLabel);
            $criteria[] = [
                'id' => 'ac_behavior_'.$slug,
                'description' => "diff implementa observavelmente o verbo de intencao '{$verbLabel}' (coberto por: {$verificationRef})",
                'verification' => 'test',
                'verification_ref' => $verificationRef,
            ];
        }

        return $criteria;
    }

    /**
     * Resolve a concrete test command to back a behavioral AC. Prefers the
     * first explicit verification command; falls back to the profile default
     * (composer test / pnpm test) for the write-capable profiles. Returns
     * null when no executable test command is resolvable (generic_no_test
     * profile with no explicit commands).
     *
     * @param  list<string>  $verificationCommands
     */
    private function resolveBehavioralVerificationRef(CompactSdd $compactSdd, array $verificationCommands): ?string
    {
        foreach ($verificationCommands as $cmd) {
            $trimmed = trim($cmd);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        // Profile default fallback so a write task always has a real test
        // command backing its behavioral ACs when explicit commands are absent.
        return match ($compactSdd->verificationProfile) {
            self::PROFILE_PHP_LARAVEL => 'composer test',
            self::PROFILE_TS_REACT => 'pnpm test',
            default => null,
        };
    }

    /**
     * Slugify a verb label for use in an AC id. Keeps ids stable, lowercase,
     * alphanumeric-only, so multi-verb intents never produce id collisions
     * or characters that break downstream id matching.
     */
    private function verbSlug(string $verbLabel): string
    {
        $slug = strtolower(trim($verbLabel));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? $slug;

        return trim($slug, '_');
    }

    /**
     * @return list<string>
     */
    private function buildNonGoals(CompactSdd $compactSdd): array
    {
        $base = [
            'nao expandir escopo alem dos arquivos previstos',
            'nao introduzir dependencias novas sem mini-spec atualizado',
        ];
        if ($compactSdd->mode === self::MODE_REPAIR) {
            $base[] = 'nao reescrever feature, apenas restaurar comportamento esperado';
        }
        if ($compactSdd->mode === self::MODE_FRONTEND_VISUAL) {
            $base[] = 'nao alterar contrato de API para resolver problema visual';
        }
        if ($compactSdd->mode === self::MODE_ESCALATE_PREVIEW) {
            $base[] = 'nao aplicar patch no fast path';
            $base[] = 'nao tomar decisao automatica de promotion';
        }

        return $base;
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
     * @param  list<string>  $expectedFiles
     * @return list<array{description: string, observable_by: string}>
     */
    private function buildExpectedBehavior(CompactSdd $compactSdd, array $expectedFiles): array
    {
        if ($compactSdd->mode === self::MODE_READ_ONLY) {
            return [[
                'description' => 'resposta humana com refs canonicas e limites de confianca',
                'observable_by' => 'human',
            ]];
        }
        if ($compactSdd->mode === self::MODE_REVIEW) {
            return [[
                'description' => 'review aponta riscos e refs com confidence visivel',
                'observable_by' => 'human',
            ]];
        }
        if ($compactSdd->mode === self::MODE_ESCALATE_PREVIEW) {
            return [[
                'description' => 'preview de Forge promotion contem reasons, signals, score e human_action_required=true',
                'observable_by' => 'evidence',
            ]];
        }

        // E2: derive one observable behavior per recognized intent verb from
        // the normalized intent. This populates the previously-dark
        // expectedBehavior[] field so the rendered `## Definition of Done`
        // carries intent-grounded, observable behavior entries (the basis E1
        // will probe the diff against). Falls back to a generic verifiable
        // behavior when no verb matches so a write task always carries at
        // least one expected behavior.
        $verbs = $this->extractIntentVerbs($compactSdd->intentNormalized);
        if ($verbs === []) {
            return [[
                'description' => 'comportamento esperado verificavel pelos comandos de verificacao da spec',
                'observable_by' => 'test',
            ]];
        }

        $behaviors = [];
        foreach ($verbs as $verbLabel) {
            $behaviors[] = [
                'description' => "diff implementa o verbo de intenção '{$verbLabel}' de forma observável pelos comandos de verificação da spec",
                'observable_by' => 'test',
            ];
        }

        return $behaviors;
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
        $assumptions = [];
        if ($discovery->confidence === CodeDiscoveryManifest::CONFIDENCE_HYPOTHESIS
            || $discovery->confidence === CodeDiscoveryManifest::CONFIDENCE_BLOCKING_AMBIGUITY) {
            $assumptions[] = [
                'text' => 'arquivos alvo dependem de confirmacao adicional (confidence='.$discovery->confidence.')',
                'confidence' => $discovery->confidence === CodeDiscoveryManifest::CONFIDENCE_BLOCKING_AMBIGUITY ? 'blocking' : 'inference',
            ];
        } elseif ($discovery->confidence === CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE) {
            $assumptions[] = [
                'text' => 'arquivos provaveis inferidos por simbolos/paths reais no workspace',
                'confidence' => 'inference',
            ];
        }
        if ($compactSdd->mode === self::MODE_ESCALATE_PREVIEW) {
            $assumptions[] = [
                'text' => 'risco/escopo demanda decisao humana antes de qualquer patch',
                'confidence' => 'blocking',
            ];
        }

        return $assumptions;
    }

    /**
     * @param  list<string>  $verificationCommands
     * @return list<string>
     */
    private function buildCompletionCriteria(CompactSdd $compactSdd, array $verificationCommands): array
    {
        if ($compactSdd->mode === self::MODE_READ_ONLY) {
            return ['resposta entregue com refs e limites de confianca'];
        }
        if ($compactSdd->mode === self::MODE_REVIEW) {
            return ['review entregue com refs verificaveis'];
        }
        if ($compactSdd->mode === self::MODE_ESCALATE_PREVIEW) {
            return ['promotion_preview persistido com decision_ref'];
        }

        $criteria = [];
        if ($verificationCommands !== []) {
            $criteria[] = 'todos os comandos da verification_plan passam';
        }
        $criteria[] = 'scope_guard nao reporta forbidden_touch';
        $criteria[] = 'nenhum gate required termina em failed';

        return $criteria;
    }

    /**
     * @param  list<string>  $expectedFiles
     */
    private function buildRollback(CompactSdd $compactSdd, array $expectedFiles): string
    {
        if ($compactSdd->mode === self::MODE_READ_ONLY
            || $compactSdd->mode === self::MODE_REVIEW
            || $compactSdd->mode === self::MODE_ESCALATE_PREVIEW) {
            return 'sem rollback aplicavel (no patch)';
        }
        if ($expectedFiles === []) {
            return 'git checkout -- .';
        }
        $first = $expectedFiles[0];

        return "git checkout -- {$first}";
    }

    private function buildGoal(OperationEnvelope $envelope, CompactSdd $compactSdd): string
    {
        $intent = trim($envelope->normalizedIntent);

        return $intent !== '' ? $intent : 'goal nao inferivel; aguardar clarificacao do operador';
    }

    /**
     * @return list<string>
     */
    private function buildBlockedActions(CompactSdd $compactSdd): array
    {
        return [
            'production_write',
            'migration_apply',
            'secret_access',
            'broad_refactor',
            'council_invoke',
            'forge_invoke_direct',
        ];
    }

    /**
     * @return list<string>
     */
    private function buildEvidenceRequired(CompactSdd $compactSdd): array
    {
        if ($compactSdd->mode === self::MODE_READ_ONLY) {
            return ['context_refs'];
        }
        if ($compactSdd->mode === self::MODE_REVIEW) {
            return ['review_notes', 'context_refs'];
        }
        if ($compactSdd->mode === self::MODE_ESCALATE_PREVIEW) {
            return ['escalation_decision', 'reasons'];
        }

        return [
            'diff_hash',
            'changed_files',
            'test_output_hash',
            'scope_guard_receipt',
            'verification_receipt',
        ];
    }

    private function writeAllowed(OperationEnvelope $envelope, CompactSdd $compactSdd): bool
    {
        if (! $envelope->preflight->writeAllowed) {
            return false;
        }

        return ! in_array($compactSdd->mode, [
            self::MODE_READ_ONLY,
            self::MODE_REVIEW,
            self::MODE_ESCALATE_PREVIEW,
        ], true);
    }

    private function allowsRivalsIsolatedRuntimeExecution(OperationEnvelope $envelope): bool
    {
        if ($envelope->surfaceId !== 'atlas_forge_rivals') {
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
        $workspace = rtrim($workspace, DIRECTORY_SEPARATOR);
        if ($workspace !== '' && str_starts_with($absolute, $workspace.DIRECTORY_SEPARATOR)) {
            return substr($absolute, strlen($workspace) + 1);
        }

        return $absolute;
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
        $normalized = trim($envelope->normalizedIntent);
        if ($normalized !== '') {
            return $normalized;
        }

        if (! $writeAllowed) {
            return '';
        }

        $raw = trim($envelope->rawIntent);
        if ($raw !== '') {
            return $raw;
        }

        // Final floor for a write task with a blank intent: never empty.
        return 'write_task_intent_unavailable';
    }
}
