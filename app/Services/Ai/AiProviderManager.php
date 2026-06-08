<?php

namespace App\Services\Ai;

use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\Caching\AiCallCostGuard;
use App\Services\Ai\Caching\CachingAiProvider;
use App\Services\Ai\Caching\EfficiencyOutcomeRecorder;
use App\Services\Ai\Telemetry\AiCostEstimator;
use App\Services\Ai\Tokens\AtlasTokenEconomyBudgetPolicyService;
use Closure;
use InvalidArgumentException;

class AiProviderManager
{
    /**
     * Opt-in ADML consultation hook (Patamar 4 wiring).
     *
     * Set via {@see self::setGatewayConsultation()} during AppServiceProvider
     * resolving. When null, {@see self::getRecommended()} falls back to the
     * default provider — zero break on existing callers of {@see self::get()}.
     */
    private ?AtlasDecideGatewayConsultationService $gatewayConsultation = null;

    /**
     * Opt-in response-cache decorator dependencies (H1 + H4 wiring).
     *
     * Wired via {@see self::setCacheDecoration()} during AppServiceProvider
     * resolving, exactly like {@see self::$gatewayConsultation}. When null —
     * the default, and the case in the existing 7-arg unit construction —
     * {@see self::maybeWrapWithCache()} returns the resolved provider UNCHANGED,
     * so {@see self::get()} / {@see self::getRecommended()} stay byte-identical.
     */
    private ?AiCallCostGuard $cacheCostGuard = null;

    private ?EfficiencyOutcomeRecorder $cacheEfficiencyGovernor = null;

    private ?AiCostEstimator $cacheCostEstimator = null;

    private ?AtlasTokenEconomyBudgetPolicyService $cacheTokenEconomy = null;

    /**
     * Open provider registry: provider key => Closure(): AiProvider.
     *
     * Seeded with the built-in drivers (byte-identical keys/instances to the
     * previous hardcoded match) and then extended — at construction — from
     * config('atlas.ai.provider_drivers'). A NEW provider is therefore
     * onboarded by *registration* (a config entry + a driver class), never by
     * editing this class. Configured drivers resolve lazily from the
     * container so their own dependencies are injected on first use.
     *
     * @var array<string, Closure(): AiProvider>
     */
    private array $registry = [];

    public function __construct(
        private readonly ClaudeCliProvider $claude,
        private readonly CodexCliProvider $codex,
        private readonly GeminiCliProvider $gemini,
        private readonly JarvisMlxProvider $jarvis,
        private readonly HermesCliProvider $hermes,
        private readonly MinimaxM27CliProvider $minimax,
        private readonly AtlasAiRuntimeSettings $runtimeSettings,
    ) {
        // Built-in drivers — identical keys and order to the prior match arm.
        $this->registry = [
            'claude_cli' => fn (): AiProvider => $this->claude,
            'codex_cli' => fn (): AiProvider => $this->codex,
            'gemini_cli' => fn (): AiProvider => $this->gemini,
            'jarvis_mlx' => fn (): AiProvider => $this->jarvis,
            'hermes_cli' => fn (): AiProvider => $this->hermes,
            'minimax_m27_cli' => fn (): AiProvider => $this->minimax,
        ];

        $this->registerConfiguredDrivers();
    }

    public function get(?string $provider = null): AiProvider
    {
        $provider = $provider ?: $this->runtimeSettings->defaultProvider();

        $factory = $this->registry[$provider] ?? null;
        if ($factory === null) {
            throw new InvalidArgumentException("Unsupported AI provider [{$provider}].");
        }

        $instance = $factory();
        if (! $instance instanceof AiProvider) {
            throw new InvalidArgumentException("Provider driver for [{$provider}] did not resolve to an AiProvider.");
        }

        return $this->maybeWrapWithCache($instance);
    }

    /**
     * Opt-in setter wired by AppServiceProvider — keeps the manager backwards
     * compatible when caching is not wired (the resolved instance is returned
     * unchanged). Mirrors {@see self::setGatewayConsultation()}.
     */
    public function setCacheDecoration(
        AiCallCostGuard $costGuard,
        EfficiencyOutcomeRecorder $efficiencyGovernor,
        AiCostEstimator $costEstimator,
        AtlasTokenEconomyBudgetPolicyService $tokenEconomy,
    ): void {
        $this->cacheCostGuard = $costGuard;
        $this->cacheEfficiencyGovernor = $efficiencyGovernor;
        $this->cacheCostEstimator = $costEstimator;
        $this->cacheTokenEconomy = $tokenEconomy;
    }

    /**
     * Wrap a resolved provider with the response-cache decorator — CONFIG-GATED,
     * conservative default, backward compatible.
     *
     * Returns the instance UNCHANGED unless ALL hold:
     *   - cache decoration deps are wired (otherwise it is impossible, and the
     *     manager is in its default, untouched mode);
     *   - config('atlas.ai.cache.enabled') is true (default false);
     *   - the instance is not already a CachingAiProvider (no double-wrap).
     *
     * The decorator itself is conservative: even when enabled it caches only
     * provably-deterministic jobs and is byte-identical to the inner provider on
     * every MISS / non-cacheable / streaming path.
     */
    private function maybeWrapWithCache(AiProvider $instance): AiProvider
    {
        if ($instance instanceof CachingAiProvider) {
            return $instance;
        }
        if ($this->cacheCostGuard === null
            || $this->cacheEfficiencyGovernor === null
            || $this->cacheCostEstimator === null
            || $this->cacheTokenEconomy === null) {
            return $instance;
        }

        $config = function_exists('config') ? config('atlas.ai.cache', []) : [];
        if (! is_array($config) || ($config['enabled'] ?? false) !== true) {
            return $instance;
        }

        return new CachingAiProvider(
            $instance,
            $this->cacheCostGuard,
            $this->cacheEfficiencyGovernor,
            $this->cacheCostEstimator,
            $this->cacheTokenEconomy,
            $config,
        );
    }

    /**
     * Register (or, for runtime/test wiring, override) a provider driver.
     *
     * Accepts an already-resolved AiProvider, a Closure(): AiProvider, or an
     * FQCN string implementing AiProvider. This is the open extension seam:
     * providers added at runtime (new engine, self-hosted model, test double)
     * become resolvable through {@see self::get()} and visible in
     * {@see self::keys()} without any edit to this class.
     */
    public function registerDriver(string $key, AiProvider|Closure|string $driver): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Provider key cannot be empty.');
        }

        if ($driver instanceof AiProvider) {
            $this->registry[$key] = static fn (): AiProvider => $driver;
        } elseif ($driver instanceof Closure) {
            $this->registry[$key] = $driver;
        } else {
            $this->registry[$key] = static fn (): AiProvider => self::resolveDriverClass($driver);
        }
    }

    /**
     * Opt-in setter wired by AppServiceProvider — keeps the manager
     * backwards compatible when consultation is not bound.
     */
    public function setGatewayConsultation(?AtlasDecideGatewayConsultationService $consult): void
    {
        $this->gatewayConsultation = $consult;
    }

    /**
     * Ask ADML for the learned route before resolving a provider.
     *
     * Returns the learned provider only when:
     *   - consultation is wired (otherwise default);
     *   - verdict === VERDICT_FOLLOW_LEARNED;
     *   - active_route.provider is a known key.
     *
     * Otherwise falls back to {@see self::get()} default. Never throws on
     * consultation errors — ADML is advisory, not authoritative.
     *
     * @return array{provider:AiProvider, key:string, verdict:string, consulted:bool, consultation:?array<string,mixed>}
     */
    public function getRecommended(
        string $taskCategory,
        string $role,
        ?string $framework = null,
        string $privacyClass = 'normal',
        ?string $actor = 'ai_provider_manager',
    ): array {
        $defaultKey = $this->runtimeSettings->defaultProvider();
        $consultation = null;
        $verdict = 'no_consultation';
        $chosenKey = $defaultKey;

        if ($this->gatewayConsultation !== null) {
            try {
                $consultation = $this->gatewayConsultation->consult([
                    'task_category' => $taskCategory,
                    'role' => $role,
                    'framework' => $framework,
                    'privacy_class' => $privacyClass,
                    'actor' => (string) $actor,
                ]);
                $verdict = (string) ($consultation['verdict'] ?? 'no_consultation');

                if ($verdict === AtlasDecideGatewayConsultationService::VERDICT_FOLLOW_LEARNED) {
                    $candidate = $consultation['active_route']['provider'] ?? null;
                    if (is_string($candidate) && in_array($candidate, $this->keys(), true)) {
                        $chosenKey = $candidate;
                    }
                }
            } catch (\Throwable $e) {
                // Defensive degradation — ADML must never break the gateway.
                $verdict = 'consultation_error';
            }
        }

        return [
            'provider' => $this->get($chosenKey),
            'key' => $chosenKey,
            'verdict' => $verdict,
            'consulted' => $consultation !== null,
            'consultation' => $consultation,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_values(array_keys($this->registry));
    }

    /**
     * Register provider drivers declared in config('atlas.ai.provider_drivers').
     *
     * Map shape: ['provider_key' => FQCN implementing AiProvider, ...].
     * Built-in keys are never overridden by config. Resolution is lazy.
     */
    private function registerConfiguredDrivers(): void
    {
        if (! function_exists('config')) {
            return;
        }

        $configured = config('atlas.ai.provider_drivers', []);
        if (! is_array($configured)) {
            return;
        }

        foreach ($configured as $key => $class) {
            if (! is_string($key) || $key === '' || ! is_string($class) || $class === '') {
                continue;
            }
            if (isset($this->registry[$key])) {
                continue; // never override a built-in driver
            }
            $this->registry[$key] = static fn (): AiProvider => self::resolveDriverClass($class);
        }
    }

    private static function resolveDriverClass(string $class): AiProvider
    {
        $instance = function_exists('app') ? app($class) : new $class;
        if (! $instance instanceof AiProvider) {
            throw new InvalidArgumentException("Provider driver [{$class}] must implement AiProvider.");
        }

        return $instance;
    }
}
