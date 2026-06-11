<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;
use Throwable;

/**
 * Atlas Forge Governed Provider Invocation Service.
 *
 * Turns a Runtime Dispatch Plan into a governed provider invocation.
 *
 * Hard rules (validated by 13 gates + tests + audit):
 *   - never invokes an external provider without operator + budget approval;
 *   - never spends provider tokens without explicit confirmation flags;
 *   - never reaches `execute` without `live_atlas_decide` decision receipt;
 *   - never promotes completion claim;
 *   - never bypasses review/completion gate or repair loop;
 *   - blocks honestly when the driver router has no runtime driver;
 *   - records output hashes + ledger events for replay/audit.
 *
 * Schemas:
 *   - atlas.forge.provider_invocation.v1
 *   - atlas.forge.provider_invocation_receipt.v1
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
 */
class AtlasForgeProviderInvocationService
{
    public const SCHEMA_VERSION = 'atlas.forge.provider_invocation.v1';

    public const RECEIPT_SCHEMA_VERSION = 'atlas.forge.provider_invocation_receipt.v1';

    public const MODE_DRY_RUN = 'dry_run';

    public const MODE_EXECUTE = 'execute';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TIMED_OUT = 'timed_out';

    public const STATUS_CANCELLED = 'cancelled';

    public const BLOCKER_OBRA_REQUIRED = 'obra_required';

    public const BLOCKER_OBRA_NOT_FOUND = 'obra_not_found';

    public const BLOCKER_RUNTIME_DISPATCH_REQUIRED = 'runtime_dispatch_required';

    public const BLOCKER_LIVE_DECIDE_DISPATCH_REQUIRED = 'live_decide_dispatch_required';

    public const BLOCKER_DECISION_RECEIPT_REQUIRED = 'decision_receipt_required';

    public const BLOCKER_RUNTIME_DISPATCH_NOT_ALLOWED = 'runtime_dispatch_not_allowed';

    public const BLOCKER_ROLE_INVALID = 'role_invalid';

    public const BLOCKER_OPERATOR_APPROVAL_REQUIRED = 'operator_provider_approval_required';

    public const BLOCKER_BUDGET_APPROVAL_REQUIRED = 'budget_approval_required';

    public const BLOCKER_RUNTIME_DISPATCH_CONFIRMATION_REQUIRED = 'runtime_dispatch_confirmation_required';

    public const BLOCKER_PROVIDER_DRIVER_MISSING = 'provider_driver_missing';

    public const BLOCKER_PROVIDER_CAPACITY_EXHAUSTED = 'provider_capacity_exhausted';

    public const BLOCKER_TIMEOUT_INVALID = 'timeout_invalid';

    public const BLOCKER_MODE_INVALID = 'mode_invalid';

    public const BLOCKER_AWIS_EXECUTION_GATE_REQUIRED = 'awis_execution_gate_required';

    public const BLOCKER_AWIS_EXECUTION_GATE_BLOCKED = 'awis_execution_gate_blocked';

    public const EVENT_SUBTYPE_PLANNED = 'PROVIDER_INVOCATION_PLANNED';

    public const EVENT_SUBTYPE_BLOCKED = 'PROVIDER_INVOCATION_BLOCKED';

    public const EVENT_SUBTYPE_STARTED = 'PROVIDER_INVOCATION_STARTED';

    public const EVENT_SUBTYPE_COMPLETED = 'PROVIDER_INVOCATION_COMPLETED';

    public const EVENT_SUBTYPE_FAILED = 'PROVIDER_INVOCATION_FAILED';

    public const EVENT_SUBTYPE_TIMED_OUT = 'PROVIDER_INVOCATION_TIMED_OUT';

    /**
     * @return list<string>
     */
    public static function canonicalBlockerCodes(): array
    {
        return [
            self::BLOCKER_OBRA_REQUIRED,
            self::BLOCKER_OBRA_NOT_FOUND,
            self::BLOCKER_RUNTIME_DISPATCH_REQUIRED,
            self::BLOCKER_LIVE_DECIDE_DISPATCH_REQUIRED,
            self::BLOCKER_DECISION_RECEIPT_REQUIRED,
            self::BLOCKER_RUNTIME_DISPATCH_NOT_ALLOWED,
            self::BLOCKER_ROLE_INVALID,
            self::BLOCKER_OPERATOR_APPROVAL_REQUIRED,
            self::BLOCKER_BUDGET_APPROVAL_REQUIRED,
            self::BLOCKER_RUNTIME_DISPATCH_CONFIRMATION_REQUIRED,
            self::BLOCKER_PROVIDER_DRIVER_MISSING,
            self::BLOCKER_PROVIDER_CAPACITY_EXHAUSTED,
            self::BLOCKER_TIMEOUT_INVALID,
            self::BLOCKER_MODE_INVALID,
            self::BLOCKER_AWIS_EXECUTION_GATE_REQUIRED,
            self::BLOCKER_AWIS_EXECUTION_GATE_BLOCKED,
        ];
    }

    public static function normalizeObraIdInput(mixed $value): ?string
    {
        return AiValueNormalizer::trimmedStringOrNull($value);
    }

    /**
     * @return array<int,string>
     */
    public static function canonicalContextRefPaths(): array
    {
        return array_values(array_map(
            static fn (array $ref): string => (string) ($ref['path'] ?? ''),
            self::canonicalContextRefDefinitions(),
        ));
    }

    /**
     * @return array<int,array{path:string,kind:string,reason:string}>
     */
    private static function canonicalContextRefDefinitions(): array
    {
        return [
            ['path' => 'docs/engineering-knowledge-base/atlas-forge-continuum-os.md', 'kind' => 'canonical_doc', 'reason' => 'Forge Continuum OS canonico.'],
            ['path' => 'docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md', 'kind' => 'canonical_doc', 'reason' => 'Provider topology e fallback governado.'],
            ['path' => 'docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md', 'kind' => 'canonical_doc', 'reason' => 'Provider invocation governada (13 gates + receipt).'],
            ['path' => 'app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php', 'kind' => 'service_implementation', 'reason' => 'Orquestrador governado de invocacao de provider (nunca chama externo sem confirmacao).'],
            ['path' => 'app/Console/Commands/AtlasForgeProviderInvokeCommand.php', 'kind' => 'console_command', 'reason' => 'Entrada CLI replayable do provider invoke.'],
            ['path' => 'tests/Feature/Ai/Programming/AtlasForgeProviderInvocationTest.php', 'kind' => 'test_evidence', 'reason' => 'Suite feature que prova gates, dry-run e receipt.'],
            ['path' => 'tests/Unit/Ai/Programming/AtlasForgeProviderInvocationServiceTest.php', 'kind' => 'test_evidence', 'reason' => 'Testes unitarios focados (obra fail-closed, blockers e refs canonicas).'],
        ];
    }

    public function __construct(
        private readonly AtlasForgeRuntimeDispatchService $runtimeDispatch,
        private readonly AtlasForgeProviderInvocationPromptBuilder $promptBuilder,
        private readonly AtlasForgeProviderInvocationDriverRouter $driverRouter,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function invoke(array $options = []): array
    {
        $obraId = self::normalizeObraIdInput($options['obra_id'] ?? null);
        $role = AiValueNormalizer::trimmedStringOrNull($options['role'] ?? null) ?? AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER;
        $modeRaw = AiValueNormalizer::trimmedStringOrNull($options['mode'] ?? null) ?? self::MODE_DRY_RUN;
        $mode = in_array($modeRaw, [self::MODE_DRY_RUN, self::MODE_EXECUTE], true) ? $modeRaw : self::MODE_DRY_RUN;
        $confirmProviderCall = (bool) ($options['confirm_provider_call'] ?? false);
        $confirmBudget = (bool) ($options['confirm_budget'] ?? false);
        $confirmRuntimeDispatch = (bool) ($options['confirm_runtime_dispatch'] ?? false);
        $timeout = (int) ($options['timeout_seconds'] ?? 120);
        $maxOutputChars = (int) ($options['max_output_chars'] ?? 12000);
        $dispatchIdOption = AiValueNormalizer::trimmedStringOrNull($options['dispatch_id'] ?? null);

        $invocationId = AiValueNormalizer::trimmedStringOrNull($options['invocation_id'] ?? null) ?? 'invoke_'.(string) Str::ulid();
        $generatedAt = now()->toIso8601String();
        $blockers = [];

        if ($modeRaw !== $mode) {
            $blockers[] = self::BLOCKER_MODE_INVALID;
        }
        if ($timeout < 1 || $timeout > 3600) {
            $blockers[] = self::BLOCKER_TIMEOUT_INVALID;
            $timeout = max(1, min(3600, $timeout));
        }

        if ($obraId === null) {
            return $this->finalizeBlocked(
                invocationId: $invocationId,
                generatedAt: $generatedAt,
                mode: $mode,
                project: null,
                obraId: null,
                role: $role,
                provider: null,
                model: null,
                dispatchPlan: null,
                blockers: array_merge([self::BLOCKER_OBRA_REQUIRED], $blockers),
                confirmations: [
                    'operator_confirmed_provider_call' => $confirmProviderCall,
                    'budget_approved' => $confirmBudget,
                    'confirm_runtime_dispatch' => $confirmRuntimeDispatch,
                ],
                timeout: $timeout,
                maxOutputChars: $maxOutputChars,
            );
        }

        $project = AtlasProject::query()->whereKey($obraId)->first();
        if ($project === null) {
            return $this->finalizeBlocked(
                invocationId: $invocationId,
                generatedAt: $generatedAt,
                mode: $mode,
                project: null,
                obraId: $obraId,
                role: $role,
                provider: null,
                model: null,
                dispatchPlan: null,
                blockers: array_merge([self::BLOCKER_OBRA_NOT_FOUND], $blockers),
                confirmations: [
                    'operator_confirmed_provider_call' => $confirmProviderCall,
                    'budget_approved' => $confirmBudget,
                    'confirm_runtime_dispatch' => $confirmRuntimeDispatch,
                ],
                timeout: $timeout,
                maxOutputChars: $maxOutputChars,
            );
        }

        $dispatchPlan = $this->resolveDispatchPlan($project, $dispatchIdOption, $role);

        if ($dispatchPlan === null) {
            $blockers[] = self::BLOCKER_RUNTIME_DISPATCH_REQUIRED;
        } else {
            $dispatchStatus = (string) ($dispatchPlan['status'] ?? '');
            $decisionSource = (string) ($dispatchPlan['decision_source'] ?? '');
            $decisionReceiptId = (string) ($dispatchPlan['decision_receipt_id'] ?? '');
            $decisionReceiptHash = (string) ($dispatchPlan['decision_receipt_hash'] ?? '');
            $runtimeDispatchAllowed = (bool) ($dispatchPlan['runtime_dispatch_allowed'] ?? false);
            $dispatchRole = (string) ($dispatchPlan['role'] ?? '');
            $workspaceExecutionGate = is_array($dispatchPlan['workspace_execution_gate'] ?? null)
                ? $dispatchPlan['workspace_execution_gate']
                : null;

            if ($decisionSource !== 'live_atlas_decide') {
                $blockers[] = self::BLOCKER_LIVE_DECIDE_DISPATCH_REQUIRED;
            }
            if ($decisionReceiptId === '' || $decisionReceiptHash === '') {
                $blockers[] = self::BLOCKER_DECISION_RECEIPT_REQUIRED;
            }
            if (! $runtimeDispatchAllowed || $dispatchStatus !== AtlasForgeRuntimeDispatchService::STATUS_DISPATCH_PLANNED) {
                $blockers[] = self::BLOCKER_RUNTIME_DISPATCH_NOT_ALLOWED;
            }
            if ($dispatchRole !== '' && $dispatchRole !== $role) {
                $blockers[] = self::BLOCKER_ROLE_INVALID;
            }
            if ($workspaceExecutionGate === null) {
                $blockers[] = self::BLOCKER_AWIS_EXECUTION_GATE_REQUIRED;
            } elseif (($workspaceExecutionGate['allowed'] ?? false) !== true) {
                $blockers[] = self::BLOCKER_AWIS_EXECUTION_GATE_BLOCKED;
            }
            if (in_array(AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED, (array) ($dispatchPlan['blockers'] ?? []), true)) {
                $blockers[] = self::BLOCKER_PROVIDER_CAPACITY_EXHAUSTED;
            }
        }

        $provider = $dispatchPlan !== null ? AiValueNormalizer::trimmedStringOrNull($dispatchPlan['provider'] ?? null) : null;
        $model = $dispatchPlan !== null ? AiValueNormalizer::trimmedStringOrNull($dispatchPlan['model'] ?? null) : null;

        // Execute-mode gates.
        if ($mode === self::MODE_EXECUTE) {
            if (! $confirmRuntimeDispatch) {
                $blockers[] = self::BLOCKER_RUNTIME_DISPATCH_CONFIRMATION_REQUIRED;
            }
            if (! $confirmProviderCall) {
                $blockers[] = self::BLOCKER_OPERATOR_APPROVAL_REQUIRED;
            }
            if (! $confirmBudget && $this->driverRouter->callsExternalProvider($provider)) {
                // Budget approval is only mandatory when the driver would call
                // an external provider. atlas-local never spends tokens, so we
                // do not block local executions on budget.
                $blockers[] = self::BLOCKER_BUDGET_APPROVAL_REQUIRED;
            }
            if (! $this->driverRouter->supports($provider)) {
                $blockers[] = self::BLOCKER_PROVIDER_DRIVER_MISSING;
            } elseif (! $this->driverRouter->hasRuntimeDriver($provider) || ! $this->driverRouter->isConfigured($provider)) {
                $blockers[] = self::BLOCKER_PROVIDER_DRIVER_MISSING;
            }
        }

        $blockers = AiStringListNormalizer::uniqueStrings($blockers);
        $prompt = $this->promptBuilder->build($project, $dispatchPlan, [
            'role' => $role,
        ]);

        // No further work in any of these conditions.
        if ($blockers !== []) {
            return $this->finalizeBlocked(
                invocationId: $invocationId,
                generatedAt: $generatedAt,
                mode: $mode,
                project: $project,
                obraId: $obraId,
                role: $role,
                provider: $provider,
                model: $model,
                dispatchPlan: $dispatchPlan,
                blockers: $blockers,
                confirmations: [
                    'operator_confirmed_provider_call' => $confirmProviderCall,
                    'budget_approved' => $confirmBudget,
                    'confirm_runtime_dispatch' => $confirmRuntimeDispatch,
                ],
                timeout: $timeout,
                maxOutputChars: $maxOutputChars,
                prompt: $prompt,
            );
        }

        $plan = $this->driverRouter->plan($provider, $model, $prompt, [
            'obra_id' => $obraId,
            'role' => $role,
            'dispatch_id' => $dispatchPlan['dispatch_id'] ?? null,
            'decision_receipt_id' => $dispatchPlan['decision_receipt_id'] ?? null,
            'decision_receipt_hash' => $dispatchPlan['decision_receipt_hash'] ?? null,
            'cwd' => AiValueNormalizer::trimmedStringOrNull(data_get($project->metadata, 'workspace_path')),
            'timeout_seconds' => $timeout,
            'max_output_chars' => $maxOutputChars,
        ]);

        if ($mode === self::MODE_DRY_RUN) {
            return $this->finalizePlanned(
                invocationId: $invocationId,
                generatedAt: $generatedAt,
                project: $project,
                obraId: $obraId,
                role: $role,
                provider: $provider,
                model: $model,
                dispatchPlan: $dispatchPlan,
                plan: $plan,
                confirmations: [
                    'operator_confirmed_provider_call' => $confirmProviderCall,
                    'budget_approved' => $confirmBudget,
                    'confirm_runtime_dispatch' => $confirmRuntimeDispatch,
                ],
                timeout: $timeout,
                maxOutputChars: $maxOutputChars,
                prompt: $prompt,
            );
        }

        // execute mode with all gates green — invoke the driver router.
        return $this->finalizeExecuted(
            invocationId: $invocationId,
            generatedAt: $generatedAt,
            project: $project,
            obraId: $obraId,
            role: $role,
            provider: $provider,
            model: $model,
            dispatchPlan: $dispatchPlan,
            plan: $plan,
            prompt: $prompt,
            timeout: $timeout,
            maxOutputChars: $maxOutputChars,
            confirmations: [
                'operator_confirmed_provider_call' => $confirmProviderCall,
                'budget_approved' => $confirmBudget,
                'confirm_runtime_dispatch' => $confirmRuntimeDispatch,
            ],
        );
    }

    public function latest(AtlasProject $project): ?array
    {
        $value = data_get($project->metadata, 'latest_atlas_forge_provider_invocation');

        return is_array($value) ? $value : null;
    }

    public function latestReceipt(AtlasProject $project): ?array
    {
        $value = data_get($project->metadata, 'latest_atlas_forge_provider_invocation_receipt');

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string,mixed>  $dispatchPlan
     */
    private function resolveDispatchPlan(AtlasProject $project, ?string $dispatchIdOption, string $role): ?array
    {
        $latest = $this->runtimeDispatch->latest($project);
        if ($dispatchIdOption === null) {
            return $latest;
        }
        if (is_array($latest) && (string) ($latest['dispatch_id'] ?? '') === $dispatchIdOption) {
            return $latest;
        }

        $history = (array) data_get($project->metadata, 'atlas_forge_runtime_dispatch_history', []);
        foreach ($history as $entry) {
            if (is_array($entry) && (string) ($entry['dispatch_id'] ?? '') === $dispatchIdOption) {
                return $entry;
            }
        }

        return $latest;
    }

    /**
     * @param  array<string,mixed>|null  $dispatchPlan
     * @param  array<int,string>  $blockers
     * @param  array<string,mixed>  $confirmations
     * @param  array<string,mixed>|null  $prompt
     * @return array<string,mixed>
     */
    private function finalizeBlocked(
        string $invocationId,
        string $generatedAt,
        string $mode,
        ?AtlasProject $project,
        ?string $obraId,
        string $role,
        ?string $provider,
        ?string $model,
        ?array $dispatchPlan,
        array $blockers,
        array $confirmations,
        int $timeout,
        int $maxOutputChars,
        ?array $prompt = null,
    ): array {
        $invocation = $this->baseInvocation(
            invocationId: $invocationId,
            generatedAt: $generatedAt,
            mode: $mode,
            status: self::STATUS_BLOCKED,
            obraId: $obraId,
            obraPresent: $project !== null,
            role: $role,
            provider: $provider,
            model: $model,
            dispatchPlan: $dispatchPlan,
            confirmations: $confirmations,
            timeout: $timeout,
            maxOutputChars: $maxOutputChars,
            prompt: $prompt,
        );
        $invocation['blockers'] = AiStringListNormalizer::uniqueStrings($blockers);
        $invocation['next_action'] = $this->nextActionForBlockers($invocation['blockers'], $mode);

        $receipt = $this->buildReceipt($invocation);
        $this->recordEvent(self::EVENT_SUBTYPE_BLOCKED, $invocation, $project);

        if ($project !== null) {
            $this->persistProjection($project, $invocation, $receipt);
        }
        $invocation['receipt'] = $receipt;

        return $invocation;
    }

    /**
     * @param  array<string,mixed>  $dispatchPlan
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $prompt
     * @param  array<string,mixed>  $confirmations
     * @return array<string,mixed>
     */
    private function finalizePlanned(
        string $invocationId,
        string $generatedAt,
        AtlasProject $project,
        string $obraId,
        string $role,
        ?string $provider,
        ?string $model,
        array $dispatchPlan,
        array $plan,
        array $confirmations,
        int $timeout,
        int $maxOutputChars,
        array $prompt,
    ): array {
        $invocation = $this->baseInvocation(
            invocationId: $invocationId,
            generatedAt: $generatedAt,
            mode: self::MODE_DRY_RUN,
            status: self::STATUS_PLANNED,
            obraId: $obraId,
            obraPresent: true,
            role: $role,
            provider: $provider,
            model: $model,
            dispatchPlan: $dispatchPlan,
            confirmations: $confirmations,
            timeout: $timeout,
            maxOutputChars: $maxOutputChars,
            prompt: $prompt,
        );
        $invocation['driver_plan'] = $plan;
        $invocation['next_action'] = 'review_invocation_and_request_execute_with_confirmations';

        $receipt = $this->buildReceipt($invocation);
        $this->recordEvent(self::EVENT_SUBTYPE_PLANNED, $invocation, $project);
        $this->persistProjection($project, $invocation, $receipt);
        $invocation['receipt'] = $receipt;

        return $invocation;
    }

    /**
     * @param  array<string,mixed>  $dispatchPlan
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $prompt
     * @param  array<string,mixed>  $confirmations
     * @return array<string,mixed>
     */
    private function finalizeExecuted(
        string $invocationId,
        string $generatedAt,
        AtlasProject $project,
        string $obraId,
        string $role,
        ?string $provider,
        ?string $model,
        array $dispatchPlan,
        array $plan,
        array $prompt,
        int $timeout,
        int $maxOutputChars,
        array $confirmations,
    ): array {
        $invocation = $this->baseInvocation(
            invocationId: $invocationId,
            generatedAt: $generatedAt,
            mode: self::MODE_EXECUTE,
            status: self::STATUS_EXECUTED,
            obraId: $obraId,
            obraPresent: true,
            role: $role,
            provider: $provider,
            model: $model,
            dispatchPlan: $dispatchPlan,
            confirmations: $confirmations,
            timeout: $timeout,
            maxOutputChars: $maxOutputChars,
            prompt: $prompt,
        );
        $invocation['driver_plan'] = $plan;

        $this->recordEvent(self::EVENT_SUBTYPE_STARTED, $invocation, $project);

        $started = microtime(true);
        try {
            $result = $this->driverRouter->invoke($provider, $model, $prompt, [
                'obra_id' => $obraId,
                'role' => $role,
                'dispatch_id' => $dispatchPlan['dispatch_id'] ?? null,
                'decision_receipt_id' => $dispatchPlan['decision_receipt_id'] ?? null,
                'decision_receipt_hash' => $dispatchPlan['decision_receipt_hash'] ?? null,
                'cwd' => AiValueNormalizer::trimmedStringOrNull(data_get($project->metadata, 'workspace_path')),
                'timeout_seconds' => $timeout,
                'max_output_chars' => $maxOutputChars,
            ]);
        } catch (Throwable $e) {
            $duration = (int) round((microtime(true) - $started) * 1000);
            $invocation['status'] = self::STATUS_FAILED;
            $invocation['duration_ms'] = $duration;
            $invocation['exit_code'] = null;
            $invocation['failure_type'] = 'driver_threw_exception';
            $invocation['stderr_hash'] = hash('sha256', $e->getMessage());
            $invocation['blockers'] = AiStringListNormalizer::uniqueMergedStrings($invocation['blockers'] ?? [], ['driver_threw_exception']);
            $invocation['next_action'] = 'inspect_driver_error_then_retry';
            $invocation['note'] = 'Driver threw an exception. Provider was not invoked successfully.';
            $receipt = $this->buildReceipt($invocation);
            $this->recordEvent(self::EVENT_SUBTYPE_FAILED, $invocation, $project);
            $this->persistProjection($project, $invocation, $receipt);
            $invocation['receipt'] = $receipt;

            return $invocation;
        }

        $duration = (int) round((microtime(true) - $started) * 1000);
        $exitCode = $result['exit_code'] ?? null;
        $providerCalled = (bool) ($result['provider_called'] ?? false);
        $externalProviderCall = (bool) ($result['external_provider_call'] ?? false);
        $spendsTokens = (bool) ($result['spends_provider_tokens'] ?? false);
        $stdoutHash = AiValueNormalizer::trimmedStringOrNull($result['stdout_hash'] ?? null) ?? hash('sha256', (string) ($result['stdout'] ?? ''));
        $stderrHash = AiValueNormalizer::trimmedStringOrNull($result['stderr_hash'] ?? null) ?? hash('sha256', (string) ($result['stderr'] ?? ''));
        $outputExcerpt = AiValueNormalizer::trimmedStringOrNull($result['output_excerpt'] ?? null);

        // Honor declared timeout: if duration exceeded budget, mark timed_out.
        $timedOut = $duration > ($timeout * 1000);
        $driverBlocker = AiValueNormalizer::trimmedStringOrNull($result['blocker'] ?? null);

        $invocation['provider_called'] = $providerCalled;
        $invocation['external_provider_call'] = $externalProviderCall;
        $invocation['provider_tokens_spent'] = $spendsTokens && $providerCalled;
        $invocation['duration_ms'] = $duration;
        $invocation['exit_code'] = is_int($exitCode) ? $exitCode : null;
        $invocation['stdout_hash'] = $stdoutHash;
        $invocation['stderr_hash'] = $stderrHash;
        $invocation['output_excerpt'] = $outputExcerpt;
        $invocation['driver_result_note'] = AiValueNormalizer::trimmedStringOrNull($result['note'] ?? null);
        $invocation['changed_files'] = is_array($result['changed_files'] ?? null) ? array_values($result['changed_files']) : [];
        $invocation['artifacts'] = is_array($result['artifacts'] ?? null) ? array_values($result['artifacts']) : [];
        $invocation['provider_performance_signal'] = is_array($result['performance_signal'] ?? null) ? $result['performance_signal'] : null;

        if ($timedOut) {
            $invocation['status'] = self::STATUS_TIMED_OUT;
            $invocation['failure_type'] = 'timeout';
            $invocation['blockers'] = AiStringListNormalizer::uniqueMergedStrings($invocation['blockers'] ?? [], ['timeout']);
            $invocation['next_action'] = 'increase_timeout_or_split_task';
            $this->recordEvent(self::EVENT_SUBTYPE_TIMED_OUT, $invocation, $project);
        } elseif ($driverBlocker !== null || (is_int($exitCode) && $exitCode !== 0)) {
            $invocation['status'] = self::STATUS_FAILED;
            $invocation['failure_type'] = $driverBlocker ?? 'non_zero_exit';
            $invocation['blockers'] = AiStringListNormalizer::uniqueMergedStrings($invocation['blockers'] ?? [], [$invocation['failure_type']]);
            $invocation['next_action'] = 'inspect_failure_then_retry_or_repair';
            $this->recordEvent(self::EVENT_SUBTYPE_FAILED, $invocation, $project);
        } elseif (! $providerCalled && $invocation['changed_files'] !== []) {
            // SEC-002 provider-proof: a diff with NO provider call is unattributed
            // (local stub / stray worktree files). It must never count as a real
            // executed provider result — exit 0 alone is not proof of authorship.
            $invocation['status'] = self::STATUS_FAILED;
            $invocation['failure_type'] = 'unattributed_diff';
            $invocation['blockers'] = AiStringListNormalizer::uniqueMergedStrings($invocation['blockers'] ?? [], ['unattributed_diff']);
            $invocation['next_action'] = 'reject_unattributed_diff_no_provider_proof';
            $invocation['note'] = 'Changed files reported with provider_called=false: unattributed diff, not a real provider execution. Rejected by provider-proof law.';
            $this->recordEvent(self::EVENT_SUBTYPE_FAILED, $invocation, $project);
        } else {
            // NOTE: a real provider call that captured no diff is NOT failed here
            // — a provider may legitimately run a no-op/probe/SDK signal. The
            // provider-proof law that prevents a no-diff forge result from being
            // merged or counted as success lives at the owner-flow completion gate
            // (SEC-001: forge requires changed_files != [] AND provider_calls > 0),
            // not at this standalone-invocation layer.
            $invocation['status'] = self::STATUS_EXECUTED;
            $invocation['failure_type'] = null;
            $invocation['next_action'] = 'open_review_gate_for_invocation_output';
            $this->recordEvent(self::EVENT_SUBTYPE_COMPLETED, $invocation, $project);
        }

        $receipt = $this->buildReceipt($invocation);
        $this->persistProjection($project, $invocation, $receipt);
        $invocation['receipt'] = $receipt;

        return $invocation;
    }

    /**
     * @param  array<string,mixed>|null  $dispatchPlan
     * @param  array<string,mixed>|null  $prompt
     * @param  array<string,mixed>  $confirmations
     * @return array<string,mixed>
     */
    private function baseInvocation(
        string $invocationId,
        string $generatedAt,
        string $mode,
        string $status,
        ?string $obraId,
        bool $obraPresent,
        string $role,
        ?string $provider,
        ?string $model,
        ?array $dispatchPlan,
        array $confirmations,
        int $timeout,
        int $maxOutputChars,
        ?array $prompt,
    ): array {
        $providerCallsExternal = $this->driverRouter->callsExternalProvider($provider);
        $decisionSource = $dispatchPlan !== null ? AiValueNormalizer::trimmedStringOrNull($dispatchPlan['decision_source'] ?? null) : null;
        $decisionReceiptId = $dispatchPlan !== null ? AiValueNormalizer::trimmedStringOrNull($dispatchPlan['decision_receipt_id'] ?? null) : null;
        $decisionReceiptHash = $dispatchPlan !== null ? AiValueNormalizer::trimmedStringOrNull($dispatchPlan['decision_receipt_hash'] ?? null) : null;
        $providerTopologyId = $dispatchPlan !== null ? AiValueNormalizer::trimmedStringOrNull($dispatchPlan['provider_topology_id'] ?? null) : null;
        $dispatchId = $dispatchPlan !== null ? AiValueNormalizer::trimmedStringOrNull($dispatchPlan['dispatch_id'] ?? null) : null;
        $runtimeDispatchAllowed = $dispatchPlan !== null && (bool) ($dispatchPlan['runtime_dispatch_allowed'] ?? false);
        $workspaceExecutionGate = $dispatchPlan !== null && is_array($dispatchPlan['workspace_execution_gate'] ?? null)
            ? $dispatchPlan['workspace_execution_gate']
            : null;

        $qualityGates = is_array(($dispatchPlan['quality_gates'] ?? null))
            ? array_values((array) $dispatchPlan['quality_gates'])
            : [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => $mode,
            'obra_id' => $obraId,
            'obra_present' => $obraPresent,
            'invocation_id' => $invocationId,
            'dispatch_id' => $dispatchId,
            'role' => $role,
            'provider' => $provider,
            'model' => $model,
            'decision_source' => $decisionSource,
            'decision_receipt_id' => $decisionReceiptId,
            'decision_receipt_hash' => $decisionReceiptHash,
            'provider_topology_id' => $providerTopologyId,
            'runtime_dispatch_allowed' => $runtimeDispatchAllowed,
            'workspace_execution_gate' => $workspaceExecutionGate,
            'operator_confirmed_provider_call' => (bool) ($confirmations['operator_confirmed_provider_call'] ?? false),
            'budget_approved' => (bool) ($confirmations['budget_approved'] ?? false),
            'confirm_runtime_dispatch' => (bool) ($confirmations['confirm_runtime_dispatch'] ?? false),
            'provider_called' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'provider_calls_external_when_invoked' => $providerCallsExternal,
            'timeout_seconds' => $timeout,
            'max_output_chars' => $maxOutputChars,
            'duration_ms' => null,
            'exit_code' => null,
            'stdout_hash' => null,
            'stderr_hash' => null,
            'output_excerpt' => null,
            'output_excerpt_hash' => null,
            'prompt_schema_version' => $prompt !== null ? (string) ($prompt['schema_version'] ?? AtlasForgeProviderInvocationPromptBuilder::SCHEMA_VERSION) : null,
            'prompt_hash' => $prompt !== null ? hash('sha256', (string) json_encode($prompt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) : null,
            'quality_gates' => $qualityGates,
            'quality_gates_preserved' => true,
            'review_completion_gate_preserved' => true,
            'completion_claim_promoted' => false,
            'requires_provider_approval' => $providerCallsExternal,
            'requires_budget_approval' => $providerCallsExternal,
            'evidence_refs' => self::canonicalContextRefPaths(),
            'ledger_event_ids' => [],
            'ledger_available' => DatabaseTableAvailability::has('atlas_ledger_events'),
            'blockers' => [],
            'next_action' => null,
            'generated_at' => $generatedAt,
            'recorded_at' => now()->toIso8601String(),
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Forge Governed Provider Invocation: nenhum provider externo invocado sem confirmacao explicita.',
        ];
    }

    /**
     * @param  array<string,mixed>  $invocation
     * @return array<string,mixed>
     */
    private function buildReceipt(array $invocation): array
    {
        $receiptId = 'recp_'.(string) Str::ulid();
        $payload = [
            'schema_version' => self::RECEIPT_SCHEMA_VERSION,
            'receipt_id' => $receiptId,
            'invocation_id' => $invocation['invocation_id'] ?? null,
            'dispatch_id' => $invocation['dispatch_id'] ?? null,
            'decision_receipt_id' => $invocation['decision_receipt_id'] ?? null,
            'provider_topology_id' => $invocation['provider_topology_id'] ?? null,
            'provider' => $invocation['provider'] ?? null,
            'model' => $invocation['model'] ?? null,
            'role' => $invocation['role'] ?? null,
            'mode' => $invocation['mode'] ?? null,
            'status' => $invocation['status'] ?? null,
            'provider_called' => $invocation['provider_called'] ?? false,
            'operator_confirmed_provider_call' => $invocation['operator_confirmed_provider_call'] ?? false,
            'budget_approved' => $invocation['budget_approved'] ?? false,
            'started_at' => $invocation['generated_at'] ?? null,
            'completed_at' => $invocation['recorded_at'] ?? now()->toIso8601String(),
            'duration_ms' => $invocation['duration_ms'] ?? null,
            'exit_code' => $invocation['exit_code'] ?? null,
            'stdout_hash' => $invocation['stdout_hash'] ?? null,
            'stderr_hash' => $invocation['stderr_hash'] ?? null,
            'output_excerpt_hash' => is_string($invocation['output_excerpt'] ?? null)
                ? hash('sha256', (string) $invocation['output_excerpt'])
                : null,
            'evidence_pack_hash' => null,
            'quality_gates_preserved' => true,
            'review_completion_gate_preserved' => true,
            'completion_claim_promoted' => false,
            'fallback_required' => in_array(self::BLOCKER_PROVIDER_DRIVER_MISSING, (array) ($invocation['blockers'] ?? []), true),
            'failure_type' => $invocation['failure_type'] ?? null,
            'next_action' => $invocation['next_action'] ?? null,
            'recorded_at' => now()->toIso8601String(),
            'separated_from' => 'external_rivals_certification',
        ];
        $payload['receipt_hash'] = hash('sha256', (string) json_encode(
            array_diff_key($payload, ['receipt_hash' => true]),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $invocation
     */
    private function recordEvent(string $subtype, array $invocation, ?AtlasProject $project): void
    {
        $ledgerType = match ($subtype) {
            self::EVENT_SUBTYPE_STARTED => LedgerEventType::ExecutionStarted,
            self::EVENT_SUBTYPE_COMPLETED => LedgerEventType::ProviderReturned,
            self::EVENT_SUBTYPE_FAILED => LedgerEventType::ProviderFallback,
            self::EVENT_SUBTYPE_TIMED_OUT => LedgerEventType::ProviderFallback,
            self::EVENT_SUBTYPE_BLOCKED => LedgerEventType::GateBlocked,
            self::EVENT_SUBTYPE_PLANNED => LedgerEventType::ToolPlanned,
            default => LedgerEventType::ToolPlanned,
        };

        try {
            $payload = [
                'subtype' => $subtype,
                'invocation_id' => $invocation['invocation_id'] ?? null,
                'dispatch_id' => $invocation['dispatch_id'] ?? null,
                'decision_receipt_id' => $invocation['decision_receipt_id'] ?? null,
                'provider' => $invocation['provider'] ?? null,
                'model' => $invocation['model'] ?? null,
                'role' => $invocation['role'] ?? null,
                'mode' => $invocation['mode'] ?? null,
                'status' => $invocation['status'] ?? null,
                'provider_called' => (bool) ($invocation['provider_called'] ?? false),
                'external_provider_call' => (bool) ($invocation['external_provider_call'] ?? false),
                'schema_version' => 'atlas.provider_usage.v1',
                'provider_cli' => (string) ($invocation['provider'] ?? 'unknown'),
                'model_name_if_available' => $invocation['model'] ?? null,
                'domain' => 'programming',
                'flow' => 'programming.forge',
                'task_type' => $invocation['role'] ?? null,
                'phase' => $subtype === self::EVENT_SUBTYPE_STARTED ? 'called' : 'returned',
                'exit_status' => ($invocation['status'] ?? null) === self::STATUS_EXECUTED
                    ? 'succeeded'
                    : ((($invocation['status'] ?? null) === self::STATUS_FAILED || ($invocation['status'] ?? null) === self::STATUS_TIMED_OUT) ? 'failed' : ($invocation['status'] ?? null)),
                'failure_reason' => $invocation['failure_type'] ?? null,
                'latency_seconds' => is_numeric($invocation['duration_ms'] ?? null) ? round(((int) $invocation['duration_ms']) / 1000, 3) : null,
                'cost_microusd' => null,
                'cost_confidence' => 'unknown',
                'cost_mode' => 'unknown',
                'total_tokens' => null,
                'token_source' => 'unknown',
                'changed_files' => $invocation['changed_files'] ?? [],
                'provider_performance_signal' => $invocation['provider_performance_signal'] ?? null,
                'blockers' => (array) ($invocation['blockers'] ?? []),
                'stdout_hash' => $invocation['stdout_hash'] ?? null,
                'stderr_hash' => $invocation['stderr_hash'] ?? null,
                'envelope_id' => $invocation['invocation_id'] ?? 'unknown',
                'operator' => ['tenant_id' => 'default', 'operator_id' => 'atlas-code-local-operator'],
            ];
            $event = $this->ledger->record($ledgerType, $payload, [
                'emitter_stage' => 'atlas.forge.provider_invocation',
                'emitter_version' => self::SCHEMA_VERSION,
                'envelope_id' => $invocation['invocation_id'] ?? 'unknown',
            ]);
            if ($event !== null && $project !== null) {
                // Append the event id to the projection on next persist.
                $invocation['ledger_event_ids'] = array_merge(
                    (array) ($invocation['ledger_event_ids'] ?? []),
                    [(string) $event->event_id],
                );
            }
        } catch (Throwable) {
            // Ledger is best-effort; never let an audit failure break invocation.
        }
    }

    /**
     * @param  array<string,mixed>  $invocation
     * @param  array<string,mixed>  $receipt
     */
    private function persistProjection(AtlasProject $project, array $invocation, array $receipt): void
    {
        $project->refresh();
        $metadata = is_array($project->metadata) ? $project->metadata : [];

        $metadata['latest_atlas_forge_provider_invocation'] = $invocation;
        $history = array_values((array) ($metadata['atlas_forge_provider_invocation_history'] ?? []));
        array_unshift($history, $invocation);
        $metadata['atlas_forge_provider_invocation_history'] = array_slice($history, 0, 25);

        $metadata['latest_atlas_forge_provider_invocation_receipt'] = $receipt;
        $receiptHistory = array_values((array) ($metadata['atlas_forge_provider_invocation_receipt_history'] ?? []));
        array_unshift($receiptHistory, $receipt);
        $metadata['atlas_forge_provider_invocation_receipt_history'] = array_slice($receiptHistory, 0, 25);

        $project->forceFill(['metadata' => $metadata])->save();
    }

    /**
     * @param  array<int,string>  $blockers
     */
    private function nextActionForBlockers(array $blockers, string $mode): string
    {
        return match (true) {
            in_array(self::BLOCKER_OBRA_REQUIRED, $blockers, true) => 'bind_obra_to_atlas_code_forge',
            in_array(self::BLOCKER_OBRA_NOT_FOUND, $blockers, true) => 'select_existing_obra',
            in_array(self::BLOCKER_RUNTIME_DISPATCH_REQUIRED, $blockers, true) => 'run_atlas_forge_runtime_dispatch_first',
            in_array(self::BLOCKER_LIVE_DECIDE_DISPATCH_REQUIRED, $blockers, true) => 'run_atlas_decide_for_forge_then_dispatch',
            in_array(self::BLOCKER_DECISION_RECEIPT_REQUIRED, $blockers, true) => 'run_atlas_decide_for_forge_then_dispatch',
            in_array(self::BLOCKER_RUNTIME_DISPATCH_NOT_ALLOWED, $blockers, true) => 'repair_runtime_dispatch_plan',
            in_array(self::BLOCKER_AWIS_EXECUTION_GATE_REQUIRED, $blockers, true) => 'rerun_runtime_dispatch_with_awis_workspace_gate',
            in_array(self::BLOCKER_AWIS_EXECUTION_GATE_BLOCKED, $blockers, true) => 'bind_certified_awis_workspace_before_provider_invocation',
            in_array(self::BLOCKER_OPERATOR_APPROVAL_REQUIRED, $blockers, true) => 'rerun_with_confirm_provider_call',
            in_array(self::BLOCKER_BUDGET_APPROVAL_REQUIRED, $blockers, true) => 'rerun_with_confirm_budget',
            in_array(self::BLOCKER_RUNTIME_DISPATCH_CONFIRMATION_REQUIRED, $blockers, true) => 'rerun_with_confirm_runtime_dispatch',
            in_array(self::BLOCKER_PROVIDER_DRIVER_MISSING, $blockers, true) => 'configure_provider_driver_or_use_atlas_local',
            in_array(self::BLOCKER_PROVIDER_CAPACITY_EXHAUSTED, $blockers, true) => 'wait_for_provider_capacity_or_change_strategy',
            in_array(self::BLOCKER_ROLE_INVALID, $blockers, true) => 'request_canonical_role_match_dispatch',
            in_array(self::BLOCKER_TIMEOUT_INVALID, $blockers, true) => 'use_timeout_between_1_and_3600_seconds',
            in_array(self::BLOCKER_MODE_INVALID, $blockers, true) => 'use_mode_dry_run_or_execute',
            default => $mode === self::MODE_EXECUTE ? 'resolve_remaining_blockers' : 'review_invocation_plan',
        };
    }
}
