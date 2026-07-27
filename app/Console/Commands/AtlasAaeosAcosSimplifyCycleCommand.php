<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasAaeosAcosSimplifyCyclePlanner;
use App\Services\Ai\SelfConstruction\Governance\AtlasAaeosAcosLaneScope;
use App\Services\Ai\SelfConstruction\Simplification\AtlasAaeosAcosSimplificationLane;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * AAEOS+ACOS elite simplify cycle — bounded orchestration tick.
 *
 * Default is plan/dry-run (no provider, no git). Emits the command sequence for
 * brain→seed→replenish→worker prompts. Schedule is default-OFF via
 * ATLAS_AAEOS_ACOS_SIMPLIFY_CYCLE_SCHEDULE_ENABLED.
 *
 * Does NOT claim zero-operator sovereignty — external workers remain required.
 */
final class AtlasAaeosAcosSimplifyCycleCommand extends Command
{
    use EmitsCanonicalJson;

    public const SCHEMA = 'atlas.aaeos_acos.simplify_cycle.cli.v1';

    protected $signature = 'atlas:acos:simplify-cycle
        {action=plan : plan|status|prompts}
        {--dry-run=1 : 1=plan only (default); 0 reserved for future execute}
        {--php=php : php binary for emitted commands}
        {--client=aaeos-acos-cycle : opaque client id}
        {--queue-claimable=0 : claimable queue depth hint}
        {--replenish-target=5 : top-up target when queue is dry}
        {--json : machine-readable JSON}';

    protected $description = 'AAEOS+ACOS elite simplify lane — plan one Autônomos tick (brain→seed→prompts). [was atlas:aaeos:*; TRI-HYGIENE rename]';

    /**
     * Deprecated name kept working natively. It used to need a whole forwarding
     * command class; Laravel applies this in Command::__construct.
     */
    protected $aliases = ['atlas:aaeos-acos:simplify-cycle'];

    public function handle(
        AtlasAaeosAcosSimplifyCyclePlanner $planner,
        AtlasAaeosAcosSimplificationLane $lane,
    ): int {
        $action = trim((string) $this->argument('action')) ?: 'plan';
        $json = (bool) $this->option('json');

        $payload = match ($action) {
            'plan', 'status' => $this->planPayload($planner, $lane),
            'prompts' => $this->promptsPayload($planner),
            default => null,
        };

        if ($payload === null) {
            $this->error("unknown action '{$action}' (plan|status|prompts)");

            return self::FAILURE;
        }

        if ($json) {
            $this->line($this->encode($payload));
        } else {
            $this->info('scope='.AtlasAaeosAcosLaneScope::SLUG.' dry_run='.(($payload['plan']['dry_run'] ?? true) ? '1' : '0'));
            foreach ((array) ($payload['plan']['executable_steps'] ?? []) as $step) {
                $this->line('- '.((string) ($step['id'] ?? '')).': '.((string) ($step['command'] ?? '')));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function planPayload(
        AtlasAaeosAcosSimplifyCyclePlanner $planner,
        AtlasAaeosAcosSimplificationLane $lane,
    ): array {
        $dryRun = ((string) $this->option('dry-run')) !== '0';
        $plan = $planner->plan([
            'dry_run' => $dryRun,
            'php_bin' => (string) $this->option('php'),
            'client' => (string) $this->option('client'),
            'queue_claimable' => (int) $this->option('queue-claimable'),
            'replenish_target' => (int) $this->option('replenish-target'),
        ]);

        // Empty campaign snapshot proves lane wiring without inventing work.
        $campaign = $lane->planCampaign([
            'redundancy_map' => ['clusters' => []],
            'equivalence_dossier' => ['behavior_equivalence_proven' => false],
            'deletion_plan' => ['safe' => false, 'targets' => []],
            'consumer_impact' => ['unsafe_consumers' => []],
            'parity_matrix' => ['parity_verified' => false],
            'rollback_receipts' => ['present' => false],
            'replay_plan' => ['ready' => false],
            'docs_sync' => ['required' => false],
            'candidates' => [],
        ]);

        return [
            'schema_version' => self::SCHEMA,
            'action' => 'plan',
            'plan' => $plan,
            'campaign' => $campaign,
            'schedule' => [
                'enabled' => (bool) config('atlas.brain.aaeos_acos_simplify_cycle.schedule_enabled', false),
                'cadence_minutes' => (int) config('atlas.brain.aaeos_acos_simplify_cycle.schedule_cadence_minutes', 30),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function promptsPayload(AtlasAaeosAcosSimplifyCyclePlanner $planner): array
    {
        $plan = $planner->plan([
            'dry_run' => true,
            'php_bin' => (string) $this->option('php'),
            'client' => (string) $this->option('client'),
            'queue_claimable' => (int) $this->option('queue-claimable'),
            'replenish_target' => (int) $this->option('replenish-target'),
        ]);

        $prompts = [];
        foreach ((array) $plan['steps'] as $step) {
            if (in_array((string) ($step['role'] ?? ''), ['external_brain_loop', 'external_muscle_loop'], true)) {
                $prompts[] = $step;
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'action' => 'prompts',
            'scope' => AtlasAaeosAcosLaneScope::SLUG,
            'prompts' => $prompts,
        ];
    }
}
