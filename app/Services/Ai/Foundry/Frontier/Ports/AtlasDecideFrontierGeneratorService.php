<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Ports;

use App\Services\Ai\AtlasDecideService;
use App\Services\Ai\Provider\Drivers\ProviderDriverRegistry;
use InvalidArgumentException;

/**
 * REAL Frontier generator. Premium provider via AtlasDecide + ProviderDriver seam.
 *
 * Real-or-blocked: there is NO real provider execution path today (driver ceiling
 * is prepare_only), so a real 'generated' result cannot exist. The service BLOCKS
 * honestly with the single blocker 'premium_provider_real_execution_bridge_missing'
 * and NEVER fabricates a proposal.
 *
 * Input boundary: generate() receives ONLY the dossier ($dossier) — the dossier is
 * the sole payload forwarded to prepareRequest as prompt.payload.harvester_dossier.
 * No raw input / ledger context leaks in.
 */
final class AtlasDecideFrontierGeneratorService implements FrontierGeneratorPort
{
    public const GENERATION_FLOW = 'frontier_generation';

    public const GENERATION_DOMAIN = 'self_directed_evolution';

    public const PREMIUM_PROVIDER = 'claude_cli';

    public const COMPUTE_EFFORT = 'premium';

    public const BLOCKER_EXECUTION_BRIDGE_MISSING = 'premium_provider_real_execution_bridge_missing';

    public const BLOCKER_PROVIDER_DRIVER_MISSING = 'provider_driver_missing';

    public function __construct(
        private readonly AtlasDecideService $decide,
        private readonly ProviderDriverRegistry $drivers,
    ) {}

    public function generate(array $dossier, int $count, array $context = []): array
    {
        $premiumProvider = (string) ($context['premium_provider'] ?? self::PREMIUM_PROVIDER);

        $decision = $this->decide->operationalDecision([
            'payload' => [
                'domain' => self::GENERATION_DOMAIN,
                'flow' => self::GENERATION_FLOW,
                'compute_effort' => self::COMPUTE_EFFORT,
                'operator_requested_provider' => $premiumProvider,
            ],
        ])->toArray();

        $resolvedProvider = (string) data_get($decision, 'provider_selection.selected_provider', $premiumProvider);
        $resolvedModel = data_get($decision, 'provider_selection.selected_model');
        $resolvedModel = is_string($resolvedModel) && $resolvedModel !== '' ? $resolvedModel : null;
        $generatorLabel = 'real:'.$resolvedProvider.':'.($resolvedModel ?? 'unresolved');

        $executionAllowed = data_get($decision, 'kernel_contracts.execution_allowed');
        $blockingErrors = data_get($decision, 'kernel_contracts.blocking_errors', []);
        $blockingErrors = is_array($blockingErrors) ? array_values($blockingErrors) : [];

        // AtlasDecide refused execution: surface its blockers verbatim. No fabrication.
        if ($executionAllowed !== true || $blockingErrors !== []) {
            return $this->blocked(
                reasons: $blockingErrors !== [] ? array_map('strval', $blockingErrors) : ['atlas_decide_execution_not_allowed'],
                generatorLabel: $generatorLabel,
                resolvedProvider: $resolvedProvider,
                resolvedModel: $resolvedModel,
            );
        }

        // Resolve the provider driver. Missing => honest block (never fabricate).
        try {
            $driver = $this->drivers->get($resolvedProvider);
        } catch (InvalidArgumentException) {
            return $this->blocked(
                reasons: [self::BLOCKER_PROVIDER_DRIVER_MISSING],
                generatorLabel: $generatorLabel,
                resolvedProvider: $resolvedProvider,
                resolvedModel: $resolvedModel,
            );
        }

        // Prepare the request feeding the dossier as the SOLE payload input.
        $prepared = $driver->prepareRequest([
            'model' => $resolvedModel,
            'payload' => [
                'harvester_dossier' => $dossier,
            ],
        ]);

        // The driver ceiling is prepare_only: there is NO real provider execution
        // path today, so a real 'generated' result cannot exist. Block honestly.
        $executionMode = (string) data_get($prepared, 'execution_policy.mode', 'prepare_only');
        if ($executionMode === 'prepare_only'
            || data_get($prepared, 'execution_policy.provider_real_execution_allowed') !== true) {
            return $this->blocked(
                reasons: [self::BLOCKER_EXECUTION_BRIDGE_MISSING],
                generatorLabel: $generatorLabel,
                resolvedProvider: $resolvedProvider,
                resolvedModel: $resolvedModel,
            );
        }

        // Unreachable today (no real execution bridge). Kept explicit so the real
        // 'generated' path is real-or-blocked, never fabricated.
        return $this->blocked(
            reasons: [self::BLOCKER_EXECUTION_BRIDGE_MISSING],
            generatorLabel: $generatorLabel,
            resolvedProvider: $resolvedProvider,
            resolvedModel: $resolvedModel,
        );
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string,mixed>
     */
    private function blocked(array $reasons, string $generatorLabel, string $resolvedProvider, ?string $resolvedModel): array
    {
        return [
            'status' => 'blocked',
            'proposals' => [],
            'provenance' => [],
            'generator_label' => $generatorLabel,
            'generator_provider_resolved' => $resolvedProvider,
            'generator_model_resolved' => $resolvedModel,
            'generator_blocked_reasons' => array_values($reasons),
            'claim_policy' => [
                'provider_invoked' => false,
            ],
        ];
    }
}
