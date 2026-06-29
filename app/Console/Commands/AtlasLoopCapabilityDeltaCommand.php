<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Attribution\AtlasLoopCapabilityDeltaAttributionService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Throwable;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopCapabilityDeltaAttributionService::attribute()} at the operator surface:
 * gathers recent deliveries and emits, per shape_token, the net-behavior delta attribution (samples, mean delta,
 * Wilson lower bound, credited) as deterministic facts — which delivery shapes actually moved capability.
 * Read-only. Deliveries come from an injectable seam (best-effort serving-store read by default).
 */
final class AtlasLoopCapabilityDeltaCommand extends Command
{
    /** Container key for an injected delivery source (test/integration seam): list<array>|callable():list<array>. */
    private const DELIVERIES_BINDING = 'atlas.loop.capability_delta.deliveries';

    protected $signature = 'atlas:loop:capability-delta-attribution {--json}';

    protected $description = 'Read-only capability-delta attribution by delivery shape (which shapes moved capability).';

    public function handle(AtlasLoopCapabilityDeltaAttributionService $service): int
    {
        $this->line((string) json_encode($service->attribute($this->deliveries()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function deliveries(): array
    {
        $app = $this->getLaravel();
        if ($app->bound(self::DELIVERIES_BINDING)) {
            $bound = $app->make(self::DELIVERIES_BINDING);
            if (is_callable($bound)) {
                $bound = $bound();
            }
            if (is_array($bound)) {
                return array_values(array_filter($bound, 'is_array'));
            }
        }

        return $this->fromServingStore();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fromServingStore(): array
    {
        try {
            $deliveries = [];
            foreach (['resolved', 'released'] as $status) {
                foreach (AtlasTaskServingStack::queueRepo()->list(['status' => $status]) as $row) {
                    $deliveries[] = [
                        'shape_token' => (string) (data_get($row, 'task_packet.shape_token') ?? data_get($row, 'shape_token') ?? ''),
                        'originator_id' => (string) (data_get($row, 'task_packet_id') ?? ''),
                        'net_behavior_delta' => (float) (data_get($row, 'net_behavior_delta') ?? 0),
                        'objective_class' => (string) (data_get($row, 'task_packet.objective_class') ?? ''),
                    ];
                }
            }

            return $deliveries;
        } catch (Throwable) {
            return [];
        }
    }
}
