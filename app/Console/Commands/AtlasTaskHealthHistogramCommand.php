<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskServingSentinel;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroLeaseLifetimeHistogram;
use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroQueueAgeHistogram;
use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroReplenishUrgencyClassifier;
use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroWorkerIdlePredictor;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * The operator front door for the Maestro health LENSES — a single read-only Artisan command that routes to
 * one of three actions:
 *   - histogram → queue-age + lease-lifetime distributions (bins, oldest, p50/p95)
 *   - predict   → worker-idle predictor (serve rate, seconds-until-dry, projected idle)
 *   - urgency   → replenish urgency classifier (HIGH/MID/… label + reasons + inputs)
 *
 * Mirrors the read-only contract of {@see AtlasTaskHealthCommand} (`atlas:task:health`): it OBSERVES only —
 * never claims, releases, dispatches, or mutates the queue/lease state. It wires the four prior lens services
 * over the DEDICATED serving stack ({@see AtlasTaskServingStack}) through their public constructors, so health
 * reads the exact disk the serving CLI uses (no service-container coupling required).
 *
 * NOTE ON NAME: the intent is `atlas:task health:{action}`. A literal signature beginning `atlas:task ` would
 * register the command name `atlas:task` — colliding with {@see AtlasTaskCommand} (the live `atlas:task
 * next|report` worker contract) and silently overriding it. To honor the action-routed health intent WITHOUT
 * breaking the worker loop, the command is named with the sibling colon convention (`atlas:task:maestro-health`,
 * mirroring `atlas:task:maestro-projection`).
 */
final class AtlasTaskHealthHistogramCommand extends Command
{
    use EmitsCanonicalJson;

    /** The composite schema for the `histogram` action (which fuses the two distribution lenses). */
    public const HISTOGRAM_SCHEMA = 'atlas.maestro.health.histogram.v1';

    /** @var list<string> */
    private const ACTIONS = ['histogram', 'predict', 'urgency'];

    protected $signature = 'atlas:task:maestro-health {action : One of histogram|predict|urgency} {--json : Print machine-readable JSON}';

    protected $description = 'Read-only Maestro health lenses (histogram|predict|urgency): queue/lease age distributions, worker-idle prediction, replenish urgency.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, self::ACTIONS, true)) {
            $this->error('Unknown action "'.$action.'". Expected one of: '.implode('|', self::ACTIONS).'.');

            return self::FAILURE;
        }

        $payload = match ($action) {
            'histogram' => $this->histogramPayload(),
            'predict' => $this->predictor()->project(),
            'urgency' => $this->urgencyWithWorkerFloor(),
        };

        if ($this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        return $this->renderHuman($action, $payload);
    }

    /**
     * @return array{schema:string, queue_age:array<string,mixed>, lease_lifetime:array<string,mixed>}
     */
    private function histogramPayload(): array
    {
        return [
            'schema' => self::HISTOGRAM_SCHEMA,
            'queue_age' => $this->queueAge()->histogram(),
            'lease_lifetime' => $this->leaseLifetime()->histogram(),
        ];
    }

    private function queueAge(): AtlasMaestroQueueAgeHistogram
    {
        return new AtlasMaestroQueueAgeHistogram(AtlasTaskServingStack::queueRepo());
    }

    private function leaseLifetime(): AtlasMaestroLeaseLifetimeHistogram
    {
        return new AtlasMaestroLeaseLifetimeHistogram(AtlasTaskServingStack::leaseRepo());
    }

    private function predictor(): AtlasMaestroWorkerIdlePredictor
    {
        return new AtlasMaestroWorkerIdlePredictor(
            AtlasTaskServingStack::coordinationHealth(),
            new AtlasTaskServingSentinel,
        );
    }

    private function urgency(): AtlasMaestroReplenishUrgencyClassifier
    {
        return new AtlasMaestroReplenishUrgencyClassifier(
            $this->queueAge(),
            $this->leaseLifetime(),
            $this->predictor(),
        );
    }

    /**
     * Cross-checks the urgency classifier's verdict against the LIVE worker_drain_forecast facts
     * from atlas:task:health so this command can never report passive monitoring while active
     * workers are actually at or below the claimable worker floor. Read-only: only reads
     * coordination health, never claims/releases/enqueues/mutates a lease.
     *
     * @return array<string,mixed>
     */
    private function urgencyWithWorkerFloor(): array
    {
        $classifier = $this->urgency();
        $payload = $classifier->classify();

        $snapshot = AtlasTaskServingStack::coordinationHealth()->snapshot();
        $forecast = (array) ($snapshot['worker_drain_forecast'] ?? []);

        $floorVerdict = $classifier->classifyWorkerFloor([
            'active_leases' => (int) ($forecast['active_leases'] ?? 0),
            'claimable_per_active_worker' => $forecast['claimable_per_active_worker'] ?? null,
        ]);

        $replenishRecommendation = (string) ($forecast['replenish_recommendation'] ?? '');
        $floorTriggeredByRecommendation = $replenishRecommendation === 'replenish_soon' || $replenishRecommendation === 'replenish_urgently';

        if (in_array($payload['next_action'], ['wait', 'monitor_idle_supply'], true)
            && ((int) ($forecast['active_leases'] ?? 0) > 0)
            && (! in_array($floorVerdict['replenish_action'], ['wait', 'monitor_idle_supply'], true) || $floorTriggeredByRecommendation)
        ) {
            $payload['next_action'] = 'originate';
            $payload['replenish_action'] = ! in_array($floorVerdict['replenish_action'], ['wait', 'monitor_idle_supply'], true) ? $floorVerdict['replenish_action'] : 'replenish_soon';
            $payload['reasons'] = array_values(array_unique(array_merge(
                array_filter($payload['reasons'], static fn (string $r): bool => $r !== 'no_replenish_pressure'),
                [AtlasMaestroReplenishUrgencyClassifier::REASON_WORKER_FLOOR],
            )));
        }

        // AC: recoverable backlog — when coordination health reports recoverable tasks,
        // urgency must NOT advise passive wait/monitor. The backlog self-heals on the next
        // claimNext, so the operator should be pointed to atlas:acp:reap-leases.
        $recoverable = (array) ($snapshot['recoverable'] ?? []);
        $recoverableTotal = (int) ($recoverable['total'] ?? 0);
        $recoverableByClassification = (array) ($recoverable['by_classification'] ?? []);

        if ($recoverableTotal > 0 && in_array($payload['next_action'], ['wait', 'monitor_idle_supply'], true)) {
            $payload['next_action'] = 'reap_recoverable_backlog';
            $payload['replenish_action'] = 'replenish_soon';
            $payload['reasons'] = array_values(array_unique(array_merge(
                array_filter($payload['reasons'], static fn (string $r): bool => $r !== 'no_replenish_pressure'),
                ['recoverable_backlog'],
            )));
        }

        $payload['worker_drain_forecast'] = $forecast;
        $payload['recoverable'] = [
            'total' => $recoverableTotal,
            'by_classification' => $recoverableByClassification,
        ];

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderHuman(string $action, array $payload): int
    {
        $this->line('');
        $this->line('  <fg=cyan>MAESTRO HEALTH — '.strtoupper($action).'</>');

        match ($action) {
            'histogram' => $this->renderHistogram($payload),
            'predict' => $this->renderPredict($payload),
            'urgency' => $this->renderUrgency($payload),
            default => null,
        };

        $this->line('');

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderHistogram(array $payload): void
    {
        foreach (['queue_age' => 'QUEUE AGE (claimable)', 'lease_lifetime' => 'LEASE LIFETIME (active)'] as $key => $title) {
            $lens = (array) ($payload[$key] ?? []);
            $this->line('  <fg=yellow>'.$title.'</>  total='.($lens['total_claimable'] ?? $lens['total_active'] ?? 0)
                .'  oldest='.($lens['oldest_seconds'] ?? 0).'s  p50='.($lens['p50_seconds'] ?? 0).'s  p95='.($lens['p95_seconds'] ?? 0).'s');
            foreach ((array) ($lens['bins'] ?? []) as $bin) {
                $this->line('    '.str_pad((string) ($bin['label'] ?? '?'), 8).' '.($bin['count'] ?? 0));
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderPredict(array $payload): void
    {
        $this->line('  claimable_depth='.($payload['claimable_depth'] ?? 0)
            .'  active_workers='.($payload['active_workers'] ?? 'n/a')
            .'  serve_rate/min='.($payload['serve_rate_per_minute'] ?? 0)
            .'  seconds_until_dry='.($payload['seconds_until_dry'] ?? 'n/a')
            .'  confidence='.($payload['confidence'] ?? 'unknown'));
        $this->line('  projected_idle_at='.($payload['projected_idle_at_iso8601'] ?? 'n/a'));
        if (array_key_exists('reason', $payload)) {
            $this->line('  reason='.($payload['reason'] ?? 'n/a'));
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderUrgency(array $payload): void
    {
        $reasons = (array) ($payload['reasons'] ?? []);
        $this->line('  urgency=<fg=magenta>'.($payload['urgency'] ?? 'unknown').'</>'
            .'  next_action=<fg=magenta>'.($payload['next_action'] ?? 'unknown').'</>'
            .'  replenish_action=<fg=magenta>'.($payload['replenish_action'] ?? 'unknown').'</>');
        $this->line('  reasons: '.($reasons === [] ? 'none' : implode(', ', array_map('strval', $reasons))));
        if (in_array(AtlasMaestroReplenishUrgencyClassifier::REASON_WORKER_FLOOR, $reasons, true)) {
            $forecast = (array) ($payload['worker_drain_forecast'] ?? []);
            $this->line('  <fg=red>worker_floor</> active_leases='.($forecast['active_leases'] ?? 'n/a')
                .' claimable_per_active_worker='.($forecast['claimable_per_active_worker'] ?? 'n/a')
                .' replenish_recommendation='.($forecast['replenish_recommendation'] ?? 'n/a'));
        }
    }
}
