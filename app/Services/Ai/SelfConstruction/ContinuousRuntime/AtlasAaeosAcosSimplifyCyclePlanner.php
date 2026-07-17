<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\Governance\AtlasAaeosAcosLaneScope;

/**
 * Pure bounded plan for one AAEOS+ACOS elite simplify tick.
 *
 * Does NOT call providers, git, or Artisan. The CLI/scheduler executes the
 * returned command list (or stops at dry-run). Honest: external workers remain
 * the muscle — this is orchestration, not zero-operator sovereignty.
 */
final class AtlasAaeosAcosSimplifyCyclePlanner
{
    public const SCHEMA = 'atlas.aaeos_acos.simplify_cycle_plan.v1';

    public const SCOPE = AtlasAaeosAcosLaneScope::SLUG;

    /**
     * @param  array{
     *   dry_run?: bool,
     *   php_bin?: string,
     *   client?: string,
     *   queue_claimable?: int,
     *   replenish_target?: int,
     * }  $opts
     * @return array<string,mixed>
     */
    public function plan(array $opts = []): array
    {
        $dryRun = (bool) ($opts['dry_run'] ?? true);
        $php = trim((string) ($opts['php_bin'] ?? 'php')) ?: 'php';
        $client = trim((string) ($opts['client'] ?? 'aaeos-acos-cycle'));
        $claimable = max(0, (int) ($opts['queue_claimable'] ?? 0));
        $target = max(1, (int) ($opts['replenish_target'] ?? 5));
        $scope = self::SCOPE;

        $steps = [
            [
                'id' => 'brain_next',
                'command' => "{$php} artisan atlas:brain:next {$scope} --actor={$client} --json",
                'role' => 'originate',
                'notes' => 'author≠judge; writes docs/ledger only',
            ],
            [
                'id' => 'brain_seed_dry',
                'command' => "{$php} artisan atlas:brain:seed --scope={$scope} --actor={$client} --dry-run --json",
                'role' => 'gate',
                'notes' => 'seed-gate must admit before real enqueue',
            ],
            [
                'id' => 'brain_seed',
                'command' => "{$php} artisan atlas:brain:seed --scope={$scope} --actor={$client} --json",
                'role' => 'enqueue',
                'notes' => 'only after dry-run admits; anti-proxy enforced',
                'skip_when_dry_run' => true,
            ],
        ];

        if ($claimable < $target) {
            $steps[] = [
                'id' => 'replenish_if_dry',
                'command' => $this->replenishCommand($php, $target),
                'role' => 'top_up',
                'notes' => 'structures from AAEOS/ACOS roots only; never mint proxy/faxina',
                'trigger' => 'queue_claimable_below_target',
            ];
        }

        $steps[] = [
            'id' => 'brain_worker_prompt',
            'command' => "{$php} artisan atlas:brain:worker-prompt --scope={$scope} --client={$client}-brain --json",
            'role' => 'external_brain_loop',
            'notes' => 'paste into external AI session; never edits app/',
        ];

        $steps[] = [
            'id' => 'task_worker_prompt',
            'command' => "{$php} artisan atlas:task:worker-prompt --client={$client}-muscle --json",
            'role' => 'external_muscle_loop',
            'notes' => 'implement allowed_files; report --commit scoped',
        ];

        return [
            'schema_version' => self::SCHEMA,
            'scope' => $scope,
            'lane' => AtlasAaeosAcosLaneScope::definition(),
            'dry_run' => $dryRun,
            'elite_contract' => [
                'shrink_proven' => true,
                'consumers_intact' => true,
                'elev31_compared' => true,
                'anti_proxy' => true,
                'forbidden_kpis' => ['loc_raw', 'task_volume', 'queue_depth_cosmetic'],
            ],
            'queue' => [
                'claimable' => $claimable,
                'replenish_target' => $target,
                'needs_top_up' => $claimable < $target,
            ],
            'steps' => $steps,
            'executable_steps' => array_values(array_filter(
                $steps,
                static fn (array $s): bool => ! ($dryRun && (($s['skip_when_dry_run'] ?? false) === true)),
            )),
            'kill_switches' => [
                'ATLAS_BRAIN_MASTER_ENABLED',
                'ATLAS_TASK_SERVING_ENABLED',
                'ATLAS_AAEOS_ACOS_SIMPLIFY_CYCLE_SCHEDULE_ENABLED',
            ],
            'sovereignty_claim' => 'orchestration_only_external_workers_required',
        ];
    }

    private function replenishCommand(string $php, int $target): string
    {
        $roots = AtlasAaeosAcosLaneScope::CODE_ROOTS;
        // Replenish comprehends one root at a time; prefer Aaeos as primary signal root.
        $primary = $roots[0];
        $docs = implode(' ', array_map(
            static fn (string $d): string => '--docs-root='.escapeshellarg($d),
            AtlasAaeosAcosLaneScope::DOCS_ROOTS,
        ));

        return "{$php} artisan atlas:task:replenish --scope=".escapeshellarg($primary)
            ." --target={$target} {$docs} --json";
    }
}
