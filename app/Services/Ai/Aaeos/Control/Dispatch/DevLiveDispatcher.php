<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control\Dispatch;

use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Aaeos\Spine\AaeosEngineeringSpine;

/**
 * Dev live path: elite session pack + next commands. Provider runs are opt-in.
 */
final class DevLiveDispatcher implements AaeosModeLiveDispatcher
{
    public function __construct(
        private readonly AaeosEngineeringSpine $spine = new AaeosEngineeringSpine,
    ) {}

    public function mode(): string
    {
        return AaeosExecutorMode::DEV;
    }

    public function liveDispatch(array $cyclePlan, array $options = []): array
    {
        $objective = (string) ($cyclePlan['objective']['objective'] ?? $cyclePlan['objective']['raw'] ?? '');
        $spine = $this->spine->contractForMode(AaeosExecutorMode::DEV);
        $pack = [
            'schema' => 'atlas.aaeos.dev_session_pack.v1',
            'objective' => $objective,
            'difficulty' => $cyclePlan['difficulty'] ?? [],
            'admission' => $cyclePlan['admission'] ?? [],
            'world' => $cyclePlan['world'] ?? [],
            'elite_same_bar' => true,
            'human_in_engineering_loop' => true,
            'spine' => $spine,
            'recommended_flow' => [
                '1_bootstrap' => 'php artisan atlas:ai:session-bootstrap --task='.escapeshellarg(mb_substr($objective, 0, 120)).' --json',
                '2_place' => 'php artisan atlas:ai:place-feature '.escapeshellarg(mb_substr($objective, 0, 120)).' --json',
                '3_dev' => 'php artisan atlas:cli:dev --help',
                '4_senior_loop' => 'php artisan atlas:dev:senior-loop:run --help',
                '5_review' => 'php artisan atlas:cli:cockpit',
            ],
            'verification_moat' => 'Prefer proof/gates/review over inline edit theatre',
        ];

        $effects = [['kind' => 'dev_session_pack', 'pack' => $pack]];
        $providerCalls = 0;

        if ((bool) ($options['execute_provider'] ?? false)) {
            $effects[] = [
                'kind' => 'provider_opt_in_noted',
                'note' => 'Run senior-loop/real-smoke explicitly; gateway does not auto-burn providers',
            ];
        }

        return [
            'status' => 'dispatched_live',
            'effects' => $effects,
            'next_commands' => [
                'php artisan atlas:ai:session-bootstrap --json',
                'php artisan atlas:cli:dev',
                'php artisan atlas:cli:cockpit',
            ],
            'session_pack' => $pack,
            'provider_calls' => $providerCalls,
            'human_in_engineering_loop' => true,
        ];
    }
}
