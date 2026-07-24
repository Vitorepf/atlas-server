<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Autonomy\AtlasSelfConstructionAutonomyStopGoGovernor;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only autonomy stop/go decision runner. Drives
 * {@see AtlasSelfConstructionAutonomyStopGoGovernor} from live queue-health
 * and origination facts (passed as CLI options) so the brain decides when to
 * self-heal, replenish, consolidate, call muscles, or pause — instead of
 * blindly creating more tasks while the queue is injured.
 *
 * Never enqueues, mutates the queue, or calls a provider.
 */
final class AtlasSelfConstructionAutonomyStopGoCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:self-construction:autonomy-stop-go
        {--queue-health= : healthy|degraded|dry (default healthy)}
        {--value-trend= : low|medium|high (default medium)}
        {--sprawl-pressure= : low|high (default low)}
        {--malformed-risk : mark the queue as carrying malformed-packet risk}
        {--give-back-repeated : mark repeated give_back events as detected}
        {--dry-queue : mark the queue as dry regardless of queue-health}
        {--worker-capacity-available : mark spare worker capacity as available}
        {--claimable-per-active-worker= : float, worker-feed floor guard}
    ';

    /** @var string */
    protected $description = 'Read-only autonomy stop/go decision (self_heal|replenish|consolidate|call_muscles|create|pause), fail-closed on queue injury or worker-feed starvation.';

    public function handle(AtlasSelfConstructionAutonomyStopGoGovernor $governor): int
    {
        $decision = $governor->decide($this->buildInputs());

        $this->line($this->encode($decision));

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildInputs(): array
    {
        $input = [];

        foreach ([
            'queue-health' => 'queue_health',
            'value-trend' => 'value_trend',
            'sprawl-pressure' => 'sprawl_pressure',
        ] as $option => $key) {
            $value = $this->option($option);
            if ($value !== null) {
                $input[$key] = (string) $value;
            }
        }

        $claimablePerActiveWorker = $this->option('claimable-per-active-worker');
        if ($claimablePerActiveWorker !== null) {
            $input['claimable_per_active_worker'] = (float) $claimablePerActiveWorker;
        }

        $input['malformed_risk'] = (bool) $this->option('malformed-risk');
        $input['give_back_repeated'] = (bool) $this->option('give-back-repeated');
        $input['dry_queue'] = (bool) $this->option('dry-queue');
        $input['worker_capacity_available'] = (bool) $this->option('worker-capacity-available');

        return $input;
    }
}
