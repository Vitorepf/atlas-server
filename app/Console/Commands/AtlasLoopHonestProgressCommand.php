<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Metrics\AtlasLoopE2ECycleLiveness;
use App\Services\Ai\AutonomousEvolution\Metrics\AtlasLoopPrimitiveArmedRatio;
use App\Services\Ai\AutonomousEvolution\Metrics\AtlasLoopSupplyLaneGenuineYield;
use App\Services\Ai\AutonomousEvolution\Metrics\AtlasLoopThrashLossRate;
use App\Services\Ai\Support\DatabaseTableAvailability;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * HONEST PROGRESS — print the FOUR honest loop metrics side by side, each in its OWN section with its OWN
 * schema, and NEVER fold them into a single super-score.
 *
 * ANTI-GOODHART (the operator's explicit rule): there is NO composite / weighted_average / overall_grade /
 * health_score here. A single scalar would be the proxy reborn — the operator reads four independent honest
 * numbers and judges, exactly as the canonical loop definition demands. Read-only + provider-free: this CLI
 * only relays what the four metric classes compute; it invents nothing and reads no extra proxy (LOC,
 * coverage, cyclomatic). Each metric is wrapped fail-open so a missing table degrades one section to
 * `unavailable` rather than crashing the dashboard.
 */
final class AtlasLoopHonestProgressCommand extends Command
{
    protected $signature = 'atlas:loop:honest-progress {--json : Emit the canonical JSON envelope}';

    protected $description = 'Print the 4 honest loop metrics (primitive-armed / e2e-liveness / supply-yield / thrash-loss) — NEVER an aggregate.';

    public function handle(): int
    {
        $now = Carbon::now()->toImmutable();

        $payload = [
            'schema' => 'atlas.loop.honest_progress.v1',
            'primitive_armed_ratio' => $this->safe(static fn (): array => (new AtlasLoopPrimitiveArmedRatio)->measure($now)),
            'e2e_cycle_liveness' => $this->e2eCycleLiveness($now),
            'supply_lane_genuine_yield' => $this->safe(static fn (): array => (new AtlasLoopSupplyLaneGenuineYield)->measure($now)),
            'thrash_loss_rate' => $this->safe(static fn (): array => (new AtlasLoopThrashLossRate)->measure($now)),
            'computed_at' => $now->format(DateTimeImmutable::ATOM),
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->renderHuman($payload);

        return self::SUCCESS;
    }

    /**
     * Run ONE metric fail-open: a missing table / any error degrades that section to `unavailable` rather than
     * crashing the whole dashboard (observability must never wedge).
     *
     * @param  callable():array<string,mixed>  $measure
     * @return array<string,mixed>
     */
    private function safe(callable $measure): array
    {
        try {
            return $measure();
        } catch (Throwable $e) {
            return ['status' => 'unavailable', 'reason' => mb_substr($e->getMessage(), 0, 160)];
        }
    }

    /**
     * E2E cycle-liveness per ACTIVE campaign. Missing campaigns table ⇒ [] + a stderr WARN (fail-open
     * observability — never crash).
     *
     * @return list<array<string,mixed>>
     */
    private function e2eCycleLiveness(DateTimeImmutable $now): array
    {
        if (! DatabaseTableAvailability::has('atlas_loop_campaigns')) {
            $this->warnStderr('atlas_loop_campaigns table missing; e2e_cycle_liveness=[] (fail-open).');

            return [];
        }

        try {
            $metric = new AtlasLoopE2ECycleLiveness;
            $out = [];
            foreach (
                DB::table('atlas_loop_campaigns')
                    ->whereNotIn('status', ['archived', 'cancelled'])
                    ->orderBy('id')
                    ->pluck('id') as $campaignId
            ) {
                $out[] = $metric->measure((string) $campaignId, $now);
            }

            return $out;
        } catch (Throwable $e) {
            $this->warnStderr('e2e_cycle_liveness failed: '.mb_substr($e->getMessage(), 0, 160));

            return [];
        }
    }

    private function warnStderr(string $message): void
    {
        $output = $this->output->getOutput();
        if (method_exists($output, 'getErrorOutput')) {
            $output->getErrorOutput()->writeln('<comment>WARN: '.$message.'</comment>');

            return;
        }
        $this->warn($message);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderHuman(array $payload): void
    {
        $this->components->info('Atlas Loop — honest progress (4 independent metrics, NO aggregate)');

        $this->section('PRIMITIVES ARMED', $payload['primitive_armed_ratio']);

        $this->line('');
        $this->line('== E2E CYCLE LIVENESS ==');
        $liveness = (array) $payload['e2e_cycle_liveness'];
        if ($liveness === []) {
            $this->line('  (no active campaigns / table unavailable)');
        }
        foreach ($liveness as $row) {
            $row = (array) $row;
            $this->line(sprintf(
                '  campaign=%s score=%s/5 first_dead=%s',
                (string) ($row['campaign_id'] ?? '?'),
                (string) ($row['score'] ?? '?'),
                (string) ($row['first_dead_phase'] ?? 'null'),
            ));
        }

        $this->section('SUPPLY LANE YIELD', $payload['supply_lane_genuine_yield']);
        $this->section('THRASH LOSS RATE', $payload['thrash_loss_rate']);

        $this->line('');
        $this->line('computed_at: '.(string) $payload['computed_at']);
    }

    /**
     * @param  mixed  $section
     */
    private function section(string $title, $section): void
    {
        $this->line('');
        $this->line('== '.$title.' ==');
        foreach ((array) $section as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $this->line(sprintf('  %s: %s', (string) $key, $value === null ? 'null' : (string) $value));
            } else {
                $this->line(sprintf('  %s: %s', (string) $key, (string) json_encode($value, JSON_UNESCAPED_SLASHES)));
            }
        }
    }
}
