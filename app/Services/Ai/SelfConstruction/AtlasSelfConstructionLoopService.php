<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\RealExecution\MissionDeliveryOrchestrator;

/**
 * Self-construction loop — "Atlas builds Atlas", governed.
 *
 * Points the Mission e2e pipe at the Atlas codebase itself:
 *
 *   detect (AtlasSelfConstructionDetector: TODO/FIXME + operator gaps)
 *     → for each signal, build a request
 *     → MissionDeliveryOrchestrator.deliver  (request → certified code → branch)
 *     → a self-construction record (branch + review commands)
 *
 * GOVERNANCE (non-negotiable): the loop NEVER merges and NEVER touches main — every
 * result is a branch the OPERATOR reviews and merges (the merge is the human's act,
 * inherited from the materializer's invariants). It is BOUNDED (max signals) so a
 * single run cannot fan out unbounded work. The closing learn-step (outcome → memory)
 * stays the existing compounding flywheel; this service produces the branches it feeds.
 */
final class AtlasSelfConstructionLoopService
{
    public const SCHEMA = 'atlas.ai.self_construction_loop.v1';

    public function __construct(
        private readonly AtlasSelfConstructionDetector $detector,
        private readonly MissionDeliveryOrchestrator $orchestrator,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function run(array $options = []): array
    {
        $root = (string) ($options['repo_dir'] ?? base_path());
        $max = max(1, (int) ($options['max'] ?? 1));
        $extra = array_values(array_filter((array) ($options['requests'] ?? []), 'is_string'));
        $deliveryOptions = (array) ($options['delivery'] ?? []);

        $signals = $this->detector->detect($root, $max, $extra);

        $deliveries = [];
        foreach ($signals as $signal) {
            $request = (string) $signal['request'];
            $id = 'selfconstruct-'.substr(hash('sha256', $request), 0, 10);
            $delivery = $this->orchestrator->deliver(
                $request,
                array_merge($deliveryOptions, ['repo_dir' => $root, 'id' => $id]),
            );

            $deliveries[] = [
                'signal' => $signal,
                'delivered' => (bool) ($delivery['delivered'] ?? false),
                'branch' => $delivery['branch'] ?? null,
                'stage' => $delivery['stage'] ?? null,
                'reason' => $delivery['reason'] ?? null,
                'main_untouched' => (bool) ($delivery['main_untouched'] ?? true),
                'review_commands' => $delivery['review_commands'] ?? [],
            ];
        }

        $branches = array_values(array_filter(array_map(
            static fn (array $d): ?string => $d['branch'] ?? null,
            $deliveries,
        )));

        return [
            'schema_version' => self::SCHEMA,
            'detected' => count($signals),
            'delivered_count' => count($branches),
            'branches' => $branches,
            'deliveries' => $deliveries,
            'never_merged' => true,
            'main_untouched' => true,
            'operator_action' => 'review + merge the branches you approve — Atlas built them; the merge is yours',
            'receipt_hash' => hash('sha256', (string) json_encode([
                'schema' => self::SCHEMA, 'branches' => $branches, 'detected' => count($signals),
            ], JSON_UNESCAPED_SLASHES)),
        ];
    }
}
