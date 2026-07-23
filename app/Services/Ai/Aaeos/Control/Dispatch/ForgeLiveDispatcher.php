<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control\Dispatch;

use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Aaeos\Spine\AaeosEngineeringSpine;
use App\Services\Ai\Aaeos\Spine\AaeosSpineGate;

/**
 * Forge live path: spine-stamped obra intake envelope + next commands.
 * Does not start provider live-execute unless execute_provider.
 */
final class ForgeLiveDispatcher implements AaeosModeLiveDispatcher
{
    public function __construct(
        private readonly AaeosEngineeringSpine $spine = new AaeosEngineeringSpine,
        private readonly AaeosSpineGate $spineGate = new AaeosSpineGate,
    ) {}

    public function mode(): string
    {
        return AaeosExecutorMode::FORGE;
    }

    public function liveDispatch(array $cyclePlan, array $options = []): array
    {
        $objective = (string) ($cyclePlan['objective']['objective'] ?? $cyclePlan['objective']['raw'] ?? '');
        $intake = [
            'schema' => 'atlas.aaeos.forge_intake_envelope.v1',
            'objective' => $objective,
            'difficulty' => $cyclePlan['difficulty'] ?? [],
            'admission' => $cyclePlan['admission'] ?? [],
            'multi_packet' => true,
            'sdd_required' => true,
            'elite_same_bar' => true,
            'human_in_planning' => true,
            'human_in_engineering_loop' => false,
        ];
        $intake = $this->spineGate->stamp($intake, AaeosExecutorMode::FORGE);
        $contract = $this->spine->contractForMode(AaeosExecutorMode::FORGE);

        $effects = [[
            'kind' => 'forge_intake_envelope',
            'intake' => $intake,
            'spine_contract' => $contract,
        ]];

        if ((bool) ($options['execute_provider'] ?? false)) {
            $effects[] = [
                'kind' => 'provider_opt_in_noted',
                'note' => 'Use atlas:forge:live-execute / forge-fast-path with explicit confirm; not auto-run',
            ];
        }

        return [
            'status' => 'dispatched_live',
            'effects' => $effects,
            'next_commands' => [
                'php artisan atlas:code:forge-intake --help',
                'php artisan atlas:code:forge-fast-path --help',
                'php artisan atlas:code:forge-ux --help',
                'php artisan atlas:cli:cockpit',
            ],
            'forge_intake' => $intake,
            'provider_calls' => 0,
            'human_in_engineering_loop' => false,
            'human_in_planning' => true,
        ];
    }
}
