<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control\Dispatch;

use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Live Autônomos path: brain:next (+ optional seed). Does not reimplement muscle.
 * Default does NOT run task worker or expensive providers.
 */
final class AutonomosLiveDispatcher implements AaeosModeLiveDispatcher
{
    public function mode(): string
    {
        return AaeosExecutorMode::AUTONOMOS;
    }

    public function liveDispatch(array $cyclePlan, array $options = []): array
    {
        $maxSeeds = max(0, (int) ($options['max_seeds'] ?? 0));
        $runBrainNext = (bool) ($options['run_brain_next'] ?? true);
        $effects = [];
        $next = ['atlas:task next'];
        $providerCalls = 0;

        if (! class_exists(Artisan::class)) {
            return [
                'status' => 'plan_only',
                'effects' => [['kind' => 'skipped', 'reason' => 'no_artisan']],
                'next_commands' => ['atlas:brain:next', 'atlas:brain:seed', 'atlas:task next'],
                'provider_calls' => 0,
                'note' => 'container_unavailable_plan_only',
            ];
        }

        if ($runBrainNext) {
            $brain = $this->callArtisan('atlas:brain:next', $this->brainNextArgs($options));
            $effects[] = ['kind' => 'brain_next', 'result' => $brain];
            if (($brain['exit_code'] ?? 1) !== 0) {
                return [
                    'status' => 'dispatch_failed',
                    'effects' => $effects,
                    'next_commands' => ['atlas:brain:next', 'atlas:cli:cockpit'],
                    'provider_calls' => $providerCalls,
                    'error' => 'brain_next_failed',
                ];
            }
        }

        if ($maxSeeds > 0) {
            $seed = $this->callArtisan('atlas:brain:seed', array_filter([
                '--max' => $maxSeeds,
                '--json' => true,
            ], static fn ($v) => $v !== null && $v !== false));
            // if --max unsupported, retry bare
            if (($seed['exit_code'] ?? 1) !== 0) {
                $seed = $this->callArtisan('atlas:brain:seed', ['--json' => true]);
            }
            $effects[] = ['kind' => 'brain_seed', 'result' => $seed, 'max_seeds' => $maxSeeds];
        } else {
            $effects[] = ['kind' => 'brain_seed_skipped', 'reason' => 'max_seeds_0_use_flag'];
            $next = array_merge(['atlas:brain:seed'], $next);
        }

        if ((bool) ($options['run_worker_once'] ?? false)) {
            $task = $this->callArtisan('atlas:task', ['action' => 'next', '--json' => true]);
            $effects[] = ['kind' => 'task_next', 'result' => $task];
        }

        return [
            'status' => 'dispatched_live',
            'effects' => $effects,
            'next_commands' => $next,
            'provider_calls' => $providerCalls,
            'seed_gate_required' => true,
            'scoped_commit_required' => true,
            'human_in_engineering_loop' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function brainNextArgs(array $options): array
    {
        $args = ['--json' => true];
        if (! empty($options['scope'])) {
            $args['--scope'] = (string) $options['scope'];
        }

        return $args;
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function callArtisan(string $command, array $params = []): array
    {
        try {
            $code = Artisan::call($command, $params);
            $out = Artisan::output();

            return [
                'command' => $command,
                'exit_code' => $code,
                'output_excerpt' => mb_substr(trim($out), 0, 2000),
            ];
        } catch (Throwable $e) {
            return [
                'command' => $command,
                'exit_code' => 1,
                'error' => $e->getMessage(),
            ];
        }
    }
}
