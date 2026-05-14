<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

/**
 * Atlas Forge Provider Invocation Driver Router.
 *
 * Maps a provider id (claude_cli, codex_cli, gemini_cli, claude_codex,
 * atlas-local) to a runtime driver. Only the local Atlas runtime is allowed
 * to actually execute work; external provider drivers must be explicitly
 * registered before they can run, otherwise the router returns a honest
 * `provider_driver_missing` blocker.
 *
 * No external provider is invoked by this class. Real provider invocation
 * happens elsewhere in the Atlas runtime and requires explicit operator +
 * budget approval. This router only exposes plan/invoke contracts.
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
 */
class AtlasForgeProviderInvocationDriverRouter
{
    public const DRIVER_ATLAS_LOCAL = 'atlas-local';
    public const DRIVER_CLAUDE_CLI = 'claude_cli';
    public const DRIVER_CODEX_CLI = 'codex_cli';
    public const DRIVER_GEMINI_CLI = 'gemini_cli';
    public const DRIVER_CLAUDE_CODEX = 'claude_codex';

    /** @var list<string> Drivers the Atlas Forge Continuum OS recognises. */
    public const CANONICAL_DRIVERS = [
        self::DRIVER_ATLAS_LOCAL,
        self::DRIVER_CLAUDE_CLI,
        self::DRIVER_CODEX_CLI,
        self::DRIVER_GEMINI_CLI,
        self::DRIVER_CLAUDE_CODEX,
    ];

    public const BLOCKER_PROVIDER_DRIVER_MISSING = 'provider_driver_missing';
    public const BLOCKER_PROVIDER_INVOCATION_NOT_CONFIGURED = 'provider_invocation_not_configured';

    /**
     * Does the router recognise this provider at all?
     */
    public function supports(?string $provider): bool
    {
        return $provider !== null && in_array($provider, self::CANONICAL_DRIVERS, true);
    }

    /**
     * Does the router have a runtime driver configured to actually invoke
     * this provider? Today only `atlas-local` has a real (safe, deterministic
     * local) executor wired here.
     */
    public function hasRuntimeDriver(?string $provider): bool
    {
        return $provider === self::DRIVER_ATLAS_LOCAL;
    }

    /**
     * Whether running this driver causes an external provider call.
     * `atlas-local` runs only inside the Atlas runtime; everything else, when
     * eventually configured, will be marked as external.
     */
    public function callsExternalProvider(?string $provider): bool
    {
        if ($provider === null) {
            return false;
        }

        return $provider !== self::DRIVER_ATLAS_LOCAL;
    }

    /**
     * Whether this driver spends external provider tokens. Always false for
     * atlas-local. The router never claims tokens were spent unless a real
     * external driver is wired (which is gated by operator + budget approval
     * at the Service layer, not here).
     */
    public function spendsProviderTokens(?string $provider): bool
    {
        return $this->callsExternalProvider($provider);
    }

    /**
     * Produce an invocation plan (no execution). Always safe: never reaches
     * provider runtime, never spends tokens, never leaks prompts.
     *
     * @param  array<string,mixed>  $prompt   Canonical prompt packet (`atlas.forge.provider_invocation_prompt.v1`).
     * @param  array<string,mixed>  $context  Optional metadata (obra_id, decision_receipt_id, role, timeout, max output chars).
     * @return array<string,mixed>
     */
    public function plan(?string $provider, ?string $model, array $prompt, array $context = []): array
    {
        $supports = $this->supports($provider);
        $hasDriver = $supports && $this->hasRuntimeDriver($provider);

        return [
            'schema_version' => 'atlas.forge.provider_invocation_plan.v1',
            'provider' => $provider,
            'model' => $model,
            'supports' => $supports,
            'driver_available' => $hasDriver,
            'external_provider_call' => $this->callsExternalProvider($provider),
            'spends_provider_tokens' => $this->spendsProviderTokens($provider),
            'prompt_schema_version' => (string) ($prompt['schema_version'] ?? 'atlas.forge.provider_invocation_prompt.v1'),
            'prompt_hash' => $this->promptHash($prompt),
            'context' => $context,
            'driver_blocker' => $hasDriver ? null : ($supports ? self::BLOCKER_PROVIDER_INVOCATION_NOT_CONFIGURED : self::BLOCKER_PROVIDER_DRIVER_MISSING),
            'note' => 'Plan-only · no provider runtime was contacted.',
        ];
    }

    /**
     * Execute the invocation against the resolved runtime driver. Returns a
     * structured result with stdout/stderr/exit_code/duration hashes — never
     * raw prompt content. Only `atlas-local` is wired; everything else
     * returns `provider_driver_missing` and `provider_called=false`.
     *
     * @param  array<string,mixed>  $prompt
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function invoke(?string $provider, ?string $model, array $prompt, array $context = []): array
    {
        if (! $this->supports($provider)) {
            return $this->blockedResult(
                provider: $provider,
                model: $model,
                blocker: self::BLOCKER_PROVIDER_DRIVER_MISSING,
                note: "Provider {$provider} is not a canonical Atlas Forge driver.",
            );
        }

        if (! $this->hasRuntimeDriver($provider)) {
            return $this->blockedResult(
                provider: $provider,
                model: $model,
                blocker: self::BLOCKER_PROVIDER_INVOCATION_NOT_CONFIGURED,
                note: "Driver for {$provider} is not configured for runtime execution yet. Use atlas-local or wait for a governed external driver.",
            );
        }

        return $this->invokeAtlasLocal($model, $prompt, $context);
    }

    /**
     * @param  array<string,mixed>  $prompt
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function invokeAtlasLocal(?string $model, array $prompt, array $context): array
    {
        $started = microtime(true);
        // Atlas-local executor is intentionally minimal: it summarises the
        // canonical prompt + dispatch + role into a deterministic payload so
        // tests can prove "no provider call, no tokens, no fake stdout".
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
    private function blockedResult(?string $provider, ?string $model, string $blocker, string $note): array
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
            'blocker' => $blocker,
            'note' => $note,
        ];
    }

    /**
     * @param  array<string,mixed>  $prompt
     */
    private function promptHash(array $prompt): string
    {
        // Stable canonical hash so replay/audit can diff prompts without leaking content.
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
}
