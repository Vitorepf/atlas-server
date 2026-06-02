<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

/**
 * Atlas Forge Provider Invocation Driver Router (v2).
 *
 * Maps a provider id (atlas-local, claude_cli, codex_cli, gemini_cli,
 * antigravity_sdk, cursor_sdk, cursor_cli, claude_codex) to a concrete runtime driver implementing
 * `AtlasForgeProviderInvocationDriver`. The router exposes:
 *
 *   - `supports(provider)`           — provider is canonical?
 *   - `hasRuntimeDriver(provider)`   — driver class is registered?
 *   - `isConfigured(provider)`       — driver claims it can actually run?
 *   - `callsExternalProvider(...)`   — would running this driver call out?
 *   - `driverStatus(provider?)`      — runtime config status per driver
 *   - `plan(provider,model,prompt,context)` — legacy compact plan view
 *   - `driverPlan(provider, request)` — full driver plan packet
 *   - `invoke(provider,model,prompt,context)` — legacy compact invoke
 *   - `driverInvoke(provider, request)` — full driver invoke through runner
 *
 * NEVER calls an external provider during configuration/plan; only the
 * `driverInvoke` / `invoke` path may reach a CLI, and only when the upstream
 * Invocation Service has validated every gate.
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md
 */
class AtlasForgeProviderInvocationDriverRouter
{
    public static function focusedUnitTestPath(): string
    {
        return 'tests/Unit/Ai/Programming/AtlasForgeProviderInvocationDriverRouterTest.php';
    }

    public const DRIVER_ATLAS_LOCAL = 'atlas-local';

    public const DRIVER_CLAUDE_CLI = AtlasForgeClaudeCliInvocationDriver::PROVIDER;

    public const DRIVER_CODEX_CLI = AtlasForgeCodexCliInvocationDriver::PROVIDER;

    public const DRIVER_GEMINI_CLI = AtlasForgeGeminiCliInvocationDriver::PROVIDER;

    public const DRIVER_ANTIGRAVITY_SDK = AtlasForgeAntigravitySdkInvocationDriver::PROVIDER;

    public const DRIVER_CURSOR_SDK = AtlasForgeCursorSdkInvocationDriver::PROVIDER;

    public const DRIVER_CURSOR_CLI = AtlasForgeCursorCliInvocationDriver::PROVIDER;

    public const DRIVER_CLAUDE_CODEX = 'claude_codex';

    public const DRIVER_MINIMAX_M27 = 'minimax_m27';

    public const DRIVER_MINIMAX_M27_CLI = 'minimax_m27_cli';

    public const DRIVER_HERMES_CLI = AtlasForgeHermesCliInvocationDriver::PROVIDER;

    /** @var list<string> Drivers the Atlas Forge Continuum OS recognises. */
    public const CANONICAL_DRIVERS = [
        self::DRIVER_ATLAS_LOCAL,
        self::DRIVER_CLAUDE_CLI,
        self::DRIVER_CODEX_CLI,
        self::DRIVER_GEMINI_CLI,
        self::DRIVER_ANTIGRAVITY_SDK,
        self::DRIVER_CURSOR_SDK,
        self::DRIVER_CURSOR_CLI,
        self::DRIVER_CLAUDE_CODEX,
        self::DRIVER_MINIMAX_M27,
        self::DRIVER_MINIMAX_M27_CLI,
        self::DRIVER_HERMES_CLI,
    ];

    public const BLOCKER_PROVIDER_DRIVER_MISSING = 'provider_driver_missing';

    public const BLOCKER_PROVIDER_INVOCATION_NOT_CONFIGURED = 'provider_invocation_not_configured';

    public const BLOCKER_PROVIDER_DRIVER_NOT_CONFIGURED = 'provider_driver_not_configured';

    /** @var array<string, AtlasForgeProviderInvocationDriver> */
    private array $drivers = [];

    public function __construct(
        AtlasForgeClaudeCliInvocationDriver $claude,
        AtlasForgeCodexCliInvocationDriver $codex,
        AtlasForgeGeminiCliInvocationDriver $gemini,
        AtlasForgeAntigravitySdkInvocationDriver $antigravity,
        AtlasForgeCursorSdkInvocationDriver $cursor,
        AtlasForgeCursorCliInvocationDriver $cursorCli,
        AtlasForgeMinimaxM27InvocationDriver $minimax,
        AtlasForgeMinimaxM27CliInvocationDriver $minimaxCli,
    ) {
        $this->drivers = [
            $claude->provider() => $claude,
            $codex->provider() => $codex,
            $gemini->provider() => $gemini,
            $antigravity->provider() => $antigravity,
            $cursor->provider() => $cursor,
            $cursorCli->provider() => $cursorCli,
            $minimax->provider() => $minimax,
            $minimaxCli->provider() => $minimaxCli,
        ];
    }

    public function supports(?string $provider): bool
    {
        return $provider !== null && in_array($provider, self::CANONICAL_DRIVERS, true);
    }

    public function hasRuntimeDriver(?string $provider): bool
    {
        if ($provider === self::DRIVER_ATLAS_LOCAL) {
            return true;
        }
        if ($this->isClaudeCodexCouncil($provider)) {
            return $this->claudeCodexCouncilArmsReady();
        }
        if ($this->isHermesCli($provider)) {
            return true;
        }

        return $provider !== null && isset($this->drivers[$provider]);
    }

    public function isConfigured(?string $provider): bool
    {
        if ($provider === self::DRIVER_ATLAS_LOCAL) {
            return true;
        }
        if ($this->isClaudeCodexCouncil($provider)) {
            return $this->claudeCodexCouncilArmsReady()
                && $this->isConfigured(self::DRIVER_CLAUDE_CLI)
                && $this->isConfigured(self::DRIVER_CODEX_CLI);
        }
        if ($this->isHermesCli($provider)) {
            return (bool) ($this->hermesDriver()->configured()['configured'] ?? false);
        }
        if ($provider === null || ! isset($this->drivers[$provider])) {
            return false;
        }

        return (bool) ($this->drivers[$provider]->configured()['configured'] ?? false);
    }

    public function callsExternalProvider(?string $provider): bool
    {
        if ($provider === null) {
            return false;
        }

        return $provider !== self::DRIVER_ATLAS_LOCAL;
    }

    public function spendsProviderTokens(?string $provider): bool
    {
        return $this->callsExternalProvider($provider);
    }

    /**
     * @return list<string>
     */
    public function configuredDrivers(): array
    {
        $configured = [self::DRIVER_ATLAS_LOCAL];
        foreach ($this->drivers as $provider => $driver) {
            if ($this->isConfigured($provider)) {
                $configured[] = $provider;
            }
        }
        if ($this->isConfigured(self::DRIVER_HERMES_CLI)) {
            $configured[] = self::DRIVER_HERMES_CLI;
        }

        return array_values(array_unique($configured));
    }

    /**
     * Snapshot the configuration status of every governed driver. NEVER calls
     * an external provider — only inspects the local environment.
     *
     * @return array<string,mixed>
     */
    public function driverStatus(?string $provider = null): array
    {
        $atlasLocal = [
            'schema_version' => 'atlas.forge.provider_driver_config_status.v1',
            'provider' => self::DRIVER_ATLAS_LOCAL,
            'configured' => true,
            'runtime_present' => true,
            'binary_path' => 'atlas-runtime',
            'auth_state' => 'not_applicable',
            'model_prefixes' => ['atlas-'],
            'allowed_binaries' => ['atlas-runtime'],
            'blockers' => [],
            'external_provider_call_possible' => false,
            'provider_tokens_may_be_spent' => false,
            'note' => 'Atlas-local executor: deterministic safe runtime.',
        ];

        $statuses = [self::DRIVER_ATLAS_LOCAL => $atlasLocal];
        foreach ($this->drivers as $key => $driver) {
            $statuses[$key] = $driver->configured();
        }
        // Hermes is wired additively via a lazily-resolved adapter (not in the
        // constructor $drivers map), so surface its status explicitly.
        $statuses[self::DRIVER_HERMES_CLI] = $this->hermesDriver()->configured();

        if ($provider !== null) {
            if ($this->isClaudeCodexCouncil($provider)) {
                return $this->claudeCodexCouncilStatus();
            }

            return $statuses[$provider] ?? [
                'schema_version' => 'atlas.forge.provider_driver_config_status.v1',
                'provider' => $provider,
                'configured' => false,
                'runtime_present' => false,
                'binary_path' => null,
                'auth_state' => 'unknown',
                'model_prefixes' => [],
                'allowed_binaries' => [],
                'blockers' => [self::BLOCKER_PROVIDER_DRIVER_MISSING],
                'external_provider_call_possible' => false,
                'provider_tokens_may_be_spent' => false,
                'note' => 'Provider unknown — driver missing.',
            ];
        }

        return [
            'schema_version' => 'atlas.forge.provider_driver_router_status.v1',
            'drivers' => array_values($statuses),
            'configured_drivers' => $this->configuredDrivers(),
            'note' => 'Snapshot read-only; nenhum provider externo foi contatado.',
        ];
    }

    /**
     * Legacy compact plan API (used by the Invocation Service). Returns a
     * stable summary suitable for receipts and dry-run replays.
     *
     * @param  array<string,mixed>  $prompt
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function plan(?string $provider, ?string $model, array $prompt, array $context = []): array
    {
        $supports = $this->supports($provider);
        $hasDriver = $supports && $this->hasRuntimeDriver($provider);
        $configured = $supports && $this->isConfigured($provider);

        $blocker = null;
        if (! $supports) {
            $blocker = self::BLOCKER_PROVIDER_DRIVER_MISSING;
        } elseif (! $hasDriver) {
            $blocker = self::BLOCKER_PROVIDER_INVOCATION_NOT_CONFIGURED;
        } elseif (! $configured) {
            $blocker = self::BLOCKER_PROVIDER_DRIVER_NOT_CONFIGURED;
        }

        return [
            'schema_version' => 'atlas.forge.provider_invocation_plan.v1',
            'provider' => $provider,
            'model' => $model,
            'supports' => $supports,
            'driver_available' => $hasDriver,
            'driver_configured' => $configured,
            'external_provider_call' => $this->callsExternalProvider($provider),
            'spends_provider_tokens' => $this->spendsProviderTokens($provider),
            'prompt_schema_version' => (string) ($prompt['schema_version'] ?? 'atlas.forge.provider_invocation_prompt.v1'),
            'prompt_hash' => $this->promptHash($prompt),
            'context' => $context,
            'driver_blocker' => $blocker,
            'note' => 'Plan-only · no provider runtime was contacted.',
        ];
    }

    /**
     * Detailed driver plan via the concrete driver (or atlas-local synth).
     *
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function driverPlan(?string $provider, array $request): array
    {
        if ($provider === self::DRIVER_ATLAS_LOCAL) {
            return $this->atlasLocalPlan($request);
        }
        if (! $this->supports($provider)) {
            return [
                'schema_version' => 'atlas.forge.provider_driver_plan.v1',
                'provider' => $provider,
                'plan_safe' => false,
                'blockers' => [self::BLOCKER_PROVIDER_DRIVER_MISSING],
            ];
        }
        if (! $this->hasRuntimeDriver($provider)) {
            return [
                'schema_version' => 'atlas.forge.provider_driver_plan.v1',
                'provider' => $provider,
                'plan_safe' => false,
                'blockers' => [self::BLOCKER_PROVIDER_INVOCATION_NOT_CONFIGURED],
            ];
        }
        if ($this->isClaudeCodexCouncil($provider)) {
            return $this->claudeCodexCouncilPlan($request);
        }
        if ($this->isHermesCli($provider)) {
            return $this->hermesDriver()->plan($request);
        }

        return $this->drivers[$provider]->plan($request);
    }

    /**
     * Legacy invoke — used by Invocation Service to keep the compact contract.
     *
     * @param  array<string,mixed>  $prompt
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function invoke(?string $provider, ?string $model, array $prompt, array $context = []): array
    {
        if ($provider === self::DRIVER_ATLAS_LOCAL) {
            return $this->invokeAtlasLocal($model, $prompt, $context);
        }
        if ($this->isClaudeCodexCouncil($provider)) {
            return $this->blockedCompact(
                $provider,
                $model,
                self::BLOCKER_PROVIDER_INVOCATION_NOT_CONFIGURED,
                'claude_codex council execution is routed by AiGatewayService dual-review, not a single Forge CLI driver.',
            );
        }
        if (! $this->supports($provider)) {
            return $this->blockedCompact($provider, $model, self::BLOCKER_PROVIDER_DRIVER_MISSING,
                "Provider {$provider} is not a canonical Atlas Forge driver.");
        }
        if (! $this->hasRuntimeDriver($provider)) {
            return $this->blockedCompact($provider, $model, self::BLOCKER_PROVIDER_INVOCATION_NOT_CONFIGURED,
                "Driver for {$provider} is not registered for runtime execution.");
        }
        if (! $this->isConfigured($provider)) {
            return $this->blockedCompact($provider, $model, self::BLOCKER_PROVIDER_DRIVER_NOT_CONFIGURED,
                "Driver for {$provider} is registered but not configured on this host (binary or auth missing).");
        }

        $driver = $this->isHermesCli($provider) ? $this->hermesDriver() : $this->drivers[$provider];
        $result = $driver->invoke([
            'provider' => $provider,
            'model' => $model,
            'prompt' => $prompt,
            'cwd' => $context['cwd'] ?? null,
            'timeout_seconds' => $context['timeout_seconds'] ?? 120,
            'max_output_chars' => $context['max_output_chars'] ?? 12000,
            'obra_id' => $context['obra_id'] ?? null,
            'role' => $context['role'] ?? null,
            'dispatch_id' => $context['dispatch_id'] ?? null,
            'decision_receipt_id' => $context['decision_receipt_id'] ?? null,
            'decision_receipt_hash' => $context['decision_receipt_hash'] ?? null,
        ]);

        return [
            'schema_version' => 'atlas.forge.provider_invocation_driver_result.v1',
            'provider' => $provider,
            'model' => $model,
            'provider_called' => (bool) ($result['provider_called'] ?? false),
            'external_provider_call' => (bool) ($result['external_provider_call'] ?? false),
            'spends_provider_tokens' => $result['provider_tokens_spent'] ?? 'unknown',
            'exit_code' => $result['exit_code'] ?? null,
            'duration_ms' => $result['duration_ms'] ?? null,
            'stdout' => $result['stdout_excerpt'] ?? '',
            'stderr' => $result['stderr_excerpt'] ?? '',
            'stdout_hash' => $result['stdout_hash'] ?? null,
            'stderr_hash' => $result['stderr_hash'] ?? null,
            'output_excerpt' => $result['stdout_excerpt'] ?? null,
            'output_excerpt_hash' => $result['stdout_hash'] ?? null,
            'artifacts' => $result['artifacts'] ?? [],
            'changed_files' => $result['changed_files'] ?? [],
            'performance_signal' => $result['performance_signal'] ?? null,
            'classification' => $result['classification'] ?? null,
            'blocker' => $result['blockers'][0] ?? null,
            'note' => (string) ($result['note'] ?? 'driver invocation finished'),
        ];
    }

    /**
     * Detailed invoke — used by tests / future evidence pack integration.
     *
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function driverInvoke(?string $provider, array $request): array
    {
        if ($provider === self::DRIVER_ATLAS_LOCAL) {
            return $this->invokeAtlasLocal(
                $request['model'] ?? null,
                is_array($request['prompt'] ?? null) ? $request['prompt'] : [],
                $request,
            );
        }
        if (! $this->supports($provider) || ! $this->hasRuntimeDriver($provider)) {
            return $this->blockedCompact(
                $provider,
                $request['model'] ?? null,
                $this->supports($provider) ? self::BLOCKER_PROVIDER_INVOCATION_NOT_CONFIGURED : self::BLOCKER_PROVIDER_DRIVER_MISSING,
                'Driver unavailable.',
            );
        }
        if ($this->isClaudeCodexCouncil($provider)) {
            return $this->blockedCompact(
                $provider,
                $request['model'] ?? null,
                self::BLOCKER_PROVIDER_INVOCATION_NOT_CONFIGURED,
                'claude_codex council execution is routed by AiGatewayService dual-review, not a single Forge CLI driver.',
            );
        }
        if ($this->isHermesCli($provider)) {
            return $this->hermesDriver()->invoke($request);
        }

        return $this->drivers[$provider]->invoke($request);
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    private function atlasLocalPlan(array $request): array
    {
        return [
            'schema_version' => 'atlas.forge.provider_driver_plan.v1',
            'provider' => self::DRIVER_ATLAS_LOCAL,
            'model' => $request['model'] ?? null,
            'argv_preview' => ['atlas-runtime'],
            'configured' => true,
            'allowlist_passed' => true,
            'allowlist_blockers' => [],
            'config_blockers' => [],
            'blockers' => [],
            'plan_safe' => true,
            'provider_called' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'note' => 'atlas-local plan: deterministic local executor.',
        ];
    }

    /**
     * @param  array<string,mixed>  $prompt
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function invokeAtlasLocal(?string $model, array $prompt, array $context): array
    {
        $started = microtime(true);
        $summaryLines = [
            'Atlas Forge atlas-local executor · plan-only output',
            'obra_id='.((string) ($context['obra_id'] ?? '—')),
            'role='.((string) ($context['role'] ?? '—')),
            'dispatch_id='.((string) ($context['dispatch_id'] ?? '—')),
            'decision_receipt_id='.((string) ($context['decision_receipt_id'] ?? '—')),
            'prompt_hash='.$this->promptHash($prompt),
            'note=this output is deterministic local placeholder; review humanly before promoting.',
        ];
        $stdout = implode("\n", $summaryLines);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $maxChars = (int) ($context['max_output_chars'] ?? 12000);
        if ($maxChars > 0 && strlen($stdout) > $maxChars) {
            $stdout = substr($stdout, 0, $maxChars);
        }

        return [
            'schema_version' => 'atlas.forge.provider_invocation_driver_result.v1',
            'provider' => self::DRIVER_ATLAS_LOCAL,
            'model' => $model,
            'provider_called' => false,
            'external_provider_call' => false,
            'spends_provider_tokens' => false,
            'exit_code' => 0,
            'duration_ms' => $durationMs,
            'stdout' => $stdout,
            'stderr' => '',
            'stdout_hash' => hash('sha256', $stdout),
            'stderr_hash' => hash('sha256', ''),
            'output_excerpt' => $this->excerpt($stdout, 600),
            'output_excerpt_hash' => hash('sha256', $this->excerpt($stdout, 600)),
            'blocker' => null,
            'note' => 'atlas-local executor finished without invoking any external provider.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedCompact(?string $provider, ?string $model, string $blocker, string $note): array
    {
        return [
            'schema_version' => 'atlas.forge.provider_invocation_driver_result.v1',
            'provider' => $provider,
            'model' => $model,
            'provider_called' => false,
            'external_provider_call' => false,
            'spends_provider_tokens' => false,
            'exit_code' => null,
            'duration_ms' => 0,
            'stdout' => '',
            'stderr' => '',
            'stdout_hash' => hash('sha256', ''),
            'stderr_hash' => hash('sha256', ''),
            'output_excerpt' => null,
            'output_excerpt_hash' => null,
            'classification' => null,
            'blocker' => $blocker,
            'note' => $note,
        ];
    }

    /**
     * @param  array<string,mixed>  $prompt
     */
    private function promptHash(array $prompt): string
    {
        $canonical = json_encode($prompt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', (string) $canonical);
    }

    private function excerpt(string $value, int $maxLength): string
    {
        if ($value === '') {
            return '';
        }
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength).'…';
    }

    private function isClaudeCodexCouncil(?string $provider): bool
    {
        return $provider === self::DRIVER_CLAUDE_CODEX;
    }

    private function isHermesCli(?string $provider): bool
    {
        return $provider === self::DRIVER_HERMES_CLI;
    }

    /**
     * Resolve the Hermes adapter lazily from the container.
     *
     * Hermes is wired additively: the adapter delegates to
     * {@see \App\Services\Ai\HermesCliProvider} via the AiProviderManager and is
     * NOT part of the constructor-injected $drivers map, so the router's
     * constructor signature stays byte-identical (existing claude/codex/gemini/
     * cursor/minimax/antigravity registrations are untouched).
     */
    private function hermesDriver(): AtlasForgeHermesCliInvocationDriver
    {
        return app(AtlasForgeHermesCliInvocationDriver::class);
    }

    private function claudeCodexCouncilArmsReady(): bool
    {
        return isset($this->drivers[self::DRIVER_CLAUDE_CLI], $this->drivers[self::DRIVER_CODEX_CLI]);
    }

    /**
     * @return array<string,mixed>
     */
    private function claudeCodexCouncilStatus(): array
    {
        $configured = $this->isConfigured(self::DRIVER_CLAUDE_CODEX);
        $blockers = [];
        if (! $this->claudeCodexCouncilArmsReady()) {
            $blockers[] = self::BLOCKER_PROVIDER_INVOCATION_NOT_CONFIGURED;
        } elseif (! $configured) {
            $blockers[] = self::BLOCKER_PROVIDER_DRIVER_NOT_CONFIGURED;
        }

        return [
            'schema_version' => 'atlas.forge.provider_driver_config_status.v1',
            'provider' => self::DRIVER_CLAUDE_CODEX,
            'configured' => $configured,
            'runtime_present' => $this->claudeCodexCouncilArmsReady(),
            'binary_path' => null,
            'auth_state' => $configured ? 'configured' : 'missing',
            'model_prefixes' => [],
            'allowed_binaries' => [],
            'blockers' => $blockers,
            'external_provider_call_possible' => true,
            'provider_tokens_may_be_spent' => true,
            'note' => 'Composite council provider (claude_cli + codex_cli); Forge router plan-only.',
        ];
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    private function claudeCodexCouncilPlan(array $request): array
    {
        $configured = $this->isConfigured(self::DRIVER_CLAUDE_CODEX);

        return [
            'schema_version' => 'atlas.forge.provider_driver_plan.v1',
            'provider' => self::DRIVER_CLAUDE_CODEX,
            'model' => $request['model'] ?? null,
            'configured' => $configured,
            'allowlist_passed' => true,
            'allowlist_blockers' => [],
            'config_blockers' => $configured ? [] : [self::BLOCKER_PROVIDER_DRIVER_NOT_CONFIGURED],
            'blockers' => $configured ? [] : [self::BLOCKER_PROVIDER_DRIVER_NOT_CONFIGURED],
            'plan_safe' => $configured,
            'provider_called' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'note' => 'claude_codex council plan-only; execution delegated to AiGateway dual-review.',
        ];
    }
}
