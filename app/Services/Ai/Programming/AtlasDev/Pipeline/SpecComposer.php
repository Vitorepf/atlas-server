<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

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
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
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

    public function composeCompactSdd(
        OperationEnvelope $envelope,
        TaskClassification $classification,
        string $riskLevel,
    ): CompactSdd {
        $this->assertRiskLevel($riskLevel);
        $mode = $this->resolveMode($classification, $riskLevel);
        $scopeMode = $this->resolveScopeMode($classification, $riskLevel);
        $budgetChars = self::BUDGET_BY_MODE[$mode] ?? 8000;
        $maxRepair = self::MAX_REPAIR_ATTEMPTS_BY_RISK[$riskLevel] ?? 0;

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
    ): MiniProgrammingSpec {
        $allowedFiles = $this->allowedFilesFor($envelope, $compactSdd, $discovery);
        $forbidden = $this->forbiddenFilesFor($compactSdd, $discovery);
        $expectedFiles = $this->expectedFilesFor($discovery);
        $canonicalContext = $this->buildCanonicalContext($discovery, $projection);
        $verificationCommands = $this->buildVerificationCommands($compactSdd, $discovery);

        $acceptance = $this->buildAcceptanceCriteria($compactSdd, $verificationCommands, $expectedFiles);
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
        $maxFiles = self::MAX_FILES_BY_RISK[$compactSdd->riskLevel] ?? 0;
        $maxRepair = self::MAX_REPAIR_ATTEMPTS_BY_RISK[$compactSdd->riskLevel] ?? 0;
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

        $providerLock = new ProviderLock(
            provider: 'claude_cli',
            modelFamily: 'sonnet',
            fallbackAllowed: false,
        );

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
        );
    }

    // ---- Helpers -----------------------------------------------------------

    private function assertRiskLevel(string $level): void
    {
        if (! in_array($level, RiskLevelScorer::LEVELS, true)) {
            throw new InvalidArgumentException("SpecComposer: unknown risk level '{$level}'.");
        }
    }

    private function resolveMode(TaskClassification $classification, string $riskLevel): string
    {
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

        return array_values(array_unique($tiers));
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

        $files = [];
        foreach ($discovery->likelyFiles as $candidate) {
            $files[] = $this->relativise($envelope->workspace, $candidate->path);
        }

        return array_values(array_unique(array_filter($files, static fn (string $p): bool => $p !== '')));
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

        return array_values(array_unique(array_merge($base, $extra, $clean)));
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
        sort($paths, SORT_STRING);

        return array_values(array_unique($paths));
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
    private function buildVerificationCommands(CompactSdd $compactSdd, CodeDiscoveryManifest $discovery): array
    {
        if ($compactSdd->mode === self::MODE_READ_ONLY
            || $compactSdd->mode === self::MODE_REVIEW
            || $compactSdd->mode === self::MODE_ESCALATE_PREVIEW
            || $compactSdd->verificationProfile === self::PROFILE_GENERIC_NO_TEST) {
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

        return array_values(array_unique($commands));
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
     * @param  list<string>  $verificationCommands
     * @param  list<string>  $expectedFiles
     * @return list<array{id: string, description: string, verification: string, verification_ref: ?string}>
     */
    private function buildAcceptanceCriteria(CompactSdd $compactSdd, array $verificationCommands, array $expectedFiles): array
    {
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
        $i = 1;
        foreach ($verificationCommands as $cmd) {
            $criteria[] = [
                'id' => 'ac_'.$i,
                'description' => "comando '{$cmd}' termina com exit_code=0",
                'verification' => 'test',
                'verification_ref' => $cmd,
            ];
            $i++;
        }
        if ($expectedFiles !== []) {
            $criteria[] = [
                'id' => 'ac_'.$i,
                'description' => 'diff toca somente arquivos previstos em expected_files',
                'verification' => 'scope_guard',
                'verification_ref' => null,
            ];
            $i++;
        }
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

        return [[
            'description' => 'comportamento esperado verificavel pelos comandos de verificacao da spec',
            'observable_by' => 'test',
        ]];
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
}
