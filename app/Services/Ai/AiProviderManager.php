<?php

namespace App\Services\Ai;

use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
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

    public function __construct(
        private readonly ClaudeCliProvider $claude,
        private readonly CodexCliProvider $codex,
        private readonly GeminiCliProvider $gemini,
        private readonly JarvisMlxProvider $jarvis,
        private readonly HermesCliProvider $hermes,
        private readonly MinimaxM27CliProvider $minimax,
        private readonly AtlasAiRuntimeSettings $runtimeSettings,
    ) {}

    public function get(?string $provider = null): AiProvider
    {
        $provider = $provider ?: $this->runtimeSettings->defaultProvider();

        return match ($provider) {
            'claude_cli' => $this->claude,
            'codex_cli' => $this->codex,
            'gemini_cli' => $this->gemini,
            'jarvis_mlx' => $this->jarvis,
            'hermes_cli' => $this->hermes,
            'minimax_m27_cli' => $this->minimax,
            default => throw new InvalidArgumentException("Unsupported AI provider [{$provider}]."),
        };
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
        return ['claude_cli', 'codex_cli', 'gemini_cli', 'jarvis_mlx', 'hermes_cli', 'minimax_m27_cli'];
    }
}
